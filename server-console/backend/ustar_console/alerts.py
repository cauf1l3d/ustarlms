"""Durable transition rules and Telegram outbox, without privileged host access."""
import json
from contextlib import contextmanager
import os
from pathlib import Path
import re
import sqlite3
import time
import urllib.error
import urllib.request

from .data import snapshot, safe_json, stamp

WATCH = {'collector','host','containers','hdd','backup','cron','recovery','http_local','database',
         'ssd_space','ssd_inodes','hdd_space','hdd_inodes','observer','http_external','smart','clock'}
LABELS = {'critical':'АВАРИЯ','warning':'ВНИМАНИЕ','no_data':'НЕТ ДАННЫХ','ok':'ВОССТАНОВЛЕНО','stopped':'ОСТАНОВЛЕНО'}


class Queue:
    def __init__(self, path, chats, clock=time.time):
        self.path, self.chats, self.clock = path, chats, clock
        if path.exists() and (path.is_symlink() or (os.name!='nt' and
                (path.stat().st_uid != os.geteuid() or path.stat().st_mode & 0o077))):
            raise ValueError('Untrusted notification state')
        with self.connect() as db:
            os.chmod(path,0o600)
            db.executescript('''
                CREATE TABLE IF NOT EXISTS rules(key TEXT PRIMARY KEY,state TEXT,since REAL,emitted TEXT,sequence INTEGER,last REAL,sent REAL);
                CREATE TABLE IF NOT EXISTS outbox(id INTEGER PRIMARY KEY,key TEXT UNIQUE,chat INTEGER,text TEXT,
                    attempts INTEGER DEFAULT 0,next REAL,status TEXT DEFAULT 'pending',error TEXT);
                CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY,value TEXT);
            ''')
            columns={r[1] for r in db.execute('PRAGMA table_info(rules)')}
            for column in ('last','sent'):
                if column not in columns: db.execute('ALTER TABLE rules ADD COLUMN '+column+' REAL DEFAULT 0')

    @contextmanager
    def connect(self):
        db = sqlite3.connect(self.path,timeout=2)
        db.row_factory = sqlite3.Row
        try:
            with db: yield db
        finally:
            db.close()

    def enqueue(self, db, key, text, chats=None):
        if db.execute("SELECT count(*) FROM outbox WHERE status='pending'").fetchone()[0]>=5000:
            raise ValueError('Outbox capacity reached')
        for chat in self.chats if chats is None else chats:
            db.execute('INSERT OR IGNORE INTO outbox(key,chat,text,next) VALUES(?,?,?,?)',
                       (key+':'+str(chat),chat,text[:3000],self.clock()))

    def evaluate(self, data, maintenance=None):
        now = self.clock()
        checks = [c.copy() for c in data['checks'] if c['id'] in WATCH and c['reason_code'] not in ('not_configured','disabled')]
        host = data.get('host', {}) if not data.get('stale') else {}
        if host:
            cpu, memory = host.get('cpu_percent'),host.get('memory',{})
            if cpu is not None:
                checks.append(dict(id='cpu_sustained',label='CPU >85% в течение 10 минут',status='warning' if cpu>85 else 'ok'))
            if memory.get('total_bytes'):
                free = memory['available_bytes']/memory['total_bytes']
                checks.append(dict(id='ram_sustained',label='Мало свободной RAM',status='critical' if free<.08 else 'warning' if free<.15 else 'ok'))
        for key in (() if data.get('scope')=='independent_observer' else ('cpu_sustained','ram_sustained')):
            if not any(c['id']==key for c in checks):
                checks.append(dict(id=key,label='CPU' if key.startswith('cpu') else 'RAM',status='no_data'))
        oom=any(c.get('oom') is True for c in data.get('containers',[]))
        if data.get('scope')!='independent_observer':
            checks.append(dict(id='container_oom',label='OOM контейнера Академии',status='critical' if oom else 'no_data' if data.get('stale') else 'ok'))
        muted = set()
        if maintenance and maintenance.get('action') in ('manual_backup','academy_restart'):
            start,end = maintenance.get('started',0),maintenance.get('until',0)
            if 0 <= now-start <= 2700 and start <= now < end <= start+2700:
                muted = {'cron','http_local','containers'}
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            for check in checks:
                key,status = check['id'],check['status']
                old = db.execute('SELECT * FROM rules WHERE key=?',(key,)).fetchone()
                if key in muted:
                    # Pause candidates, never change the last delivered incident/recovery state.
                    if old:
                        db.execute('UPDATE rules SET since=? WHERE key=?',(now,key))
                    continue
                gap=90 if data.get('scope')=='independent_observer' else 45
                since = old['since'] if old and old['state']==status and 0<=now-old['last']<=gap else now
                emitted = old['emitted'] if old else 'ok'
                sequence = old['sequence'] if old else 0
                sent=old['sent'] if old else 0
                hold = 60 if status=='no_data' else 30 if status=='ok' else 0
                if key=='cpu_sustained' and status=='warning': hold=600
                if key=='ram_sustained' and status in ('warning','critical'): hold=180 if status=='critical' else 300
                if key in ('ssd_space','ssd_inodes','hdd_space','hdd_inodes') and status in ('warning','critical'):
                    hold=180 if status=='critical' else 600
                if key=='http_local' and status=='critical': hold=30
                if key in ('http_external','observer') and status in ('critical','ok'): hold=60
                repeat=status in ('critical','warning','no_data') and status==emitted and now-sent>=(1800 if status=='critical' else 14400)
                if (status != emitted or repeat) and now-since >= hold:
                    sequence += 1
                    self.enqueue(db,f'rule:{key}:{sequence}',
                        f"USTAR · {LABELS.get(status,'НЕТ ДАННЫХ')}\n{check.get('label',key)}\n{stamp(now)}\nИсточник: {key}. Показания: пульт.")
                    emitted = status
                    sent=now
                db.execute('INSERT OR REPLACE INTO rules VALUES(?,?,?,?,?,?,?)',(key,status,since,emitted,sequence,now,sent))
            # Retain incidents while pending; bound completed-message history.
            db.execute("DELETE FROM outbox WHERE status='sent' AND id NOT IN (SELECT id FROM outbox ORDER BY id DESC LIMIT 2000)")

    def deliver(self, api, limit=2):
        now = self.clock()
        with self.connect() as db:
            rows = db.execute("SELECT * FROM outbox WHERE status='pending' AND next<=? ORDER BY id LIMIT ?",(now,limit)).fetchall()
        for row in rows:
            try:
                api('sendMessage',dict(chat_id=row['chat'],text=row['text'],protect_content=True,link_preview_options={'is_disabled':True}))
                with self.connect() as db:
                    db.execute("UPDATE outbox SET status='sent',error=NULL WHERE id=?",(row['id'],))
            except DeliveryError as error:
                attempts = row['attempts']+1
                delay = max(error.retry_after,min(3600,5*2**min(attempts,10)))
                with self.connect() as db:
                    db.execute('UPDATE outbox SET attempts=?,next=?,error=? WHERE id=?',
                               (attempts,now+delay,error.code,row['id']))

    def status(self):
        with self.connect() as db:
            pending = db.execute("SELECT count(*) FROM outbox WHERE status='pending'").fetchone()[0]
            failures = db.execute("SELECT count(*) FROM outbox WHERE status='pending' AND attempts>0").fetchone()[0]
            rules = [dict(r) for r in db.execute('SELECT key,state,emitted FROM rules ORDER BY key')]
        return dict(pending=pending,delivery_failures=failures,rules=rules,observed_at=stamp(self.clock()),
                    status='warning' if failures else 'ok',transport='telegram',delivery='at_least_once')


class DeliveryError(Exception):
    def __init__(self, code, retry_after=0):
        self.code,self.retry_after = code,max(0,min(86400,int(retry_after)))


class Telegram:
    def __init__(self, token):
        if not re.fullmatch(r'[0-9]{5,20}:[A-Za-z0-9_-]{20,100}',token):
            raise ValueError('Invalid token format')
        self.token=token

    def __call__(self, method, data):
        if method not in ('sendMessage','getUpdates','getWebhookInfo','getMe'):
            raise ValueError('Unsupported Bot API method')
        from .collector import NoRedirect
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
        request=urllib.request.Request('https://api.telegram.org/bot'+self.token+'/'+method,
                   data=json.dumps(data).encode(),headers={'Content-Type':'application/json'})
        try:
            with opener.open(request,timeout=8) as reply:
                raw=reply.read(262145)
            if len(raw)>262144:
                raise ValueError('Response too large')
            value=json.loads(raw)
        except urllib.error.HTTPError as error:
            code=error.code
            try:
                value=json.loads(error.read(4096))
                retry=value.get('parameters',{}).get('retry_after',0)
            except (OSError,ValueError,TypeError):
                retry=0
            finally:
                error.close()
            raise DeliveryError('rate_limited' if code==429 else 'api_rejected',retry) from None
        except (OSError,ValueError,urllib.error.URLError):
            # Never log token-bearing URLs or remote response bodies.
            raise DeliveryError('transport_unavailable') from None
        if value.get('ok') is not True:
            raise DeliveryError('api_rejected')
        return value.get('result')


def command_reply(text,data,origin):
    command=text.strip().split('@')[0]
    if command in ('/start','/help'):
        return 'USTAR: /status /metrics /academy /labs /backup latest. Изменения выполняются в пульте после подтверждения.'
    if command=='/status':
        return 'USTAR\n'+'\n'.join(f'{k}: {LABELS.get(v,v)}' for k,v in data['domains'].items())+'\n'+origin
    if command=='/metrics':
        host=data.get('host',{}) if not data.get('stale') else {}
        cpu=host.get('cpu_percent')
        return f"CPU: {round(cpu,1) if cpu is not None else 'нет данных'}%\nДанные: {data.get('observed_at') or 'нет данных'}\n{origin}"
    if command=='/academy':
        return '\n'.join(f"{c['label']}: {LABELS.get(c['status'],c['status'])}" for c in data['checks'] if c['domain']=='academy')[:2500]
    if command=='/labs':
        lab=data.get('lab',{})
        return f"Стенд: {lab.get('phase','нет данных')}\nTTL: {lab.get('expires_at') or 'не подтверждён'}\n{origin}/#labs"
    if text.strip()=='/backup latest':
        backup=data.get('backup',{})
        if data.get('stale') or not backup.get('file'):
            return 'Нет свежих проверенных показаний последней completed копии. '+origin+'/#backups'
        return (f"Последняя завершённая копия: {backup.get('completed_at')}\n{backup['file']}\n"
                f"Байт: {backup.get('bytes')}\nSHA256: {backup.get('sha256')}\n"
                f"Зашифрованный архив: {origin}/#backups (вход оператора). Ключ передаётся отдельно.")
    return None


def updates(queue, api, users, chats, data, origin):
    with queue.connect() as db:
        row=db.execute("SELECT value FROM settings WHERE key='offset'").fetchone()
    offset=int(row[0]) if row else 0
    batch=api('getUpdates',dict(offset=offset,limit=20,timeout=0,allowed_updates=['message']))
    if not isinstance(batch,list) or len(batch)>20:
        raise DeliveryError('invalid_updates')
    for update in batch:
        uid=update.get('update_id')
        if type(uid) is not int or uid<offset:
            continue
        message=update.get('message',{})
        actor,chat=message.get('from',{}),message.get('chat',{})
        allowed=(actor.get('id') in users and chat.get('id') in chats and chat.get('type')=='private'
                 and actor.get('id')==chat.get('id') and not actor.get('is_bot')
                 and not message.get('forward_origin') and not message.get('sender_chat'))
        reply=command_reply(message.get('text','')[:256],data,origin) if allowed and isinstance(message.get('text',''),str) else None
        # Reply enqueue and update offset commit together: replay cannot duplicate it.
        with queue.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            if reply:
                previous=db.execute('SELECT value FROM settings WHERE key=?',('user:'+str(chat['id']),)).fetchone()
                if not previous or queue.clock()-float(previous[0])>=10:
                    queue.enqueue(db,'command:'+str(uid),reply,[chat['id']])
                    db.execute('INSERT OR REPLACE INTO settings VALUES(?,?)',('user:'+str(chat['id']),str(queue.clock())))
            db.execute("INSERT OR REPLACE INTO settings VALUES('offset',?)",(str(uid+1),))
        offset=uid+1


def config(path):
    # LoadCredential copies a root-private token to a private per-service path.
    info=path.lstat()
    if not path.is_file() or path.is_symlink() or info.st_uid not in (0,os.geteuid()) or info.st_mode&0o077 or info.st_size>8192:
        raise ValueError('Untrusted Telegram credentials')
    value=json.loads(path.read_text())
    if set(value)!={'token','users','chats'}:
        raise ValueError('Invalid Telegram configuration')
    for key in ('users','chats'):
        ids=value[key]
        if not isinstance(ids,list) or not 1<=len(ids)<=10 or any(type(i) is not int or not 0<i<2**52 for i in ids):
            raise ValueError('Numeric private user/chat IDs required')
    if set(value['users'])!=set(value['chats']):
        raise ValueError('First deployment supports explicitly allowed private chats only')
    return value


def iteration(queue,api,users,chats,data,origin,maintenance=None):
    evaluation='ok'
    try:
        queue.evaluate(data,maintenance)
    except ValueError:
        evaluation='queue_capacity'
    # Outgoing incidents continue even if command polling fails or the queue was full.
    queue.deliver(api)
    try:
        updates(queue,api,users,chats,data,origin)
        polling='ok'
    except (DeliveryError,ValueError):
        polling='no_data'
    status=queue.status()
    status.update(polling=polling,evaluation=evaluation)
    if evaluation!='ok': status['status']='warning'
    return status


def main():
    from .config import load
    settings=load()
    credential=Path(os.environ.get('CREDENTIALS_DIRECTORY','/nonexistent'))/'telegram'
    value=config(credential)
    api=Telegram(value['token'])
    if api('getWebhookInfo',{}).get('url'):
        raise SystemExit('Existing webhook detected; use a dedicated bot. Nothing was changed.')
    queue=Queue(Path('/var/lib/ustar-console-notify/outbox.sqlite3'),value['chats'])
    while True:
        data=snapshot(settings.snapshot)
        try:
            operations=safe_json(Path('/run/ustar-console-ops/status.json'),owner=0)
            maintenance=operations.get('maintenance')
        except (OSError,ValueError):
            maintenance=None
        status=iteration(queue,api,value['users'],value['chats'],data,settings.origin,maintenance)
        target=Path('/run/ustar-console-notify/status.json')
        temporary=target.with_suffix('.tmp')
        temporary.write_text(json.dumps(status))
        os.chmod(temporary,0o640)
        os.replace(temporary,target)
        time.sleep(10)
