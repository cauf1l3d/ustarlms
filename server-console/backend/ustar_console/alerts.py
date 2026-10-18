"""Durable transition rules and Telegram outbox, without privileged host access."""
import json
import http.client
import math
from contextlib import contextmanager
import os
from pathlib import Path
import re
import sqlite3
import stat
import struct
import time

from .data import snapshot, safe_json, stamp
from .telegram_transport import exchange, validate_proxy
from .telegram_views import reply as command_reply, keyboard, incident, source

WATCH = {'collector','host','containers','hdd','backup','cron','recovery','http_local','database',
         'ssd_space','ssd_inodes','hdd_space','hdd_inodes','observer','http_external','smart','clock','units'}


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
        host = source(data,'host')
        if host:
            cpu, memory = host.get('cpu_percent'),host.get('memory',{})
            if type(cpu) in (int,float) and math.isfinite(cpu) and 0<=cpu<=100:
                checks.append(dict(id='cpu_sustained',label='CPU >85% в течение 10 минут',status='warning' if cpu>85 else 'ok'))
            total,available=memory.get('total_bytes'),memory.get('available_bytes')
            if (all(type(n) in (int,float) and math.isfinite(n) for n in (total,available))
                    and 0<=available<=total and total>0):
                free = memory['available_bytes']/memory['total_bytes']
                checks.append(dict(id='ram_sustained',label='Мало свободной RAM',status='critical' if free<.08 else 'warning' if free<.15 else 'ok'))
        for key in (() if data.get('scope')=='independent_observer' else ('cpu_sustained','ram_sustained')):
            if not any(c['id']==key for c in checks):
                checks.append(dict(id=key,label='CPU' if key.startswith('cpu') else 'RAM',status='no_data'))
        container_rows=data.get('containers',[])
        stale_containers=(data.get('stale') or any(c['id']=='containers' and c['status']=='no_data'
                          and c.get('reason_code')!='measured' for c in data['checks']))
        measured_containers=(bool(container_rows) and not data.get('stale') and
                             all(type(c.get('oom')) is bool for c in container_rows) and
                             not any(c['id']=='containers' and c['status']=='no_data' for c in data['checks']))
        # A confirmed OOM remains an incident even if the other container could not be read.
        # Complete fresh telemetry is required only to assert healthy/recovered.
        oom=not stale_containers and any(c.get('oom') is True for c in container_rows)
        if data.get('scope')!='independent_observer':
            checks.append(dict(id='container_oom',label='OOM контейнера Академии',status='critical' if oom else 'ok' if measured_containers else 'no_data'))
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
                    self.enqueue(db,f'rule:{key}:{sequence}',incident(check,data,now))
                    emitted = status
                    sent=now
                db.execute('INSERT OR REPLACE INTO rules VALUES(?,?,?,?,?,?,?)',(key,status,since,emitted,sequence,now,sent))
            # Retain incidents while pending; bound completed-message history.
            db.execute("DELETE FROM outbox WHERE status='sent' AND id NOT IN (SELECT id FROM outbox ORDER BY id DESC LIMIT 2000)")

    def deliver(self, api, limit=2, reply_markup=None):
        now = self.clock()
        with self.connect() as db:
            rows = db.execute("SELECT * FROM outbox WHERE status='pending' AND next<=? ORDER BY id LIMIT ?",(now,limit)).fetchall()
        for row in rows:
            try:
                payload=dict(chat_id=row['chat'],text=row['text'],protect_content=True,link_preview_options={'is_disabled':True})
                if reply_markup is not None: payload['reply_markup']=reply_markup
                try:
                    api('sendMessage',payload)
                except DeliveryError as error:
                    # An unsupported native button must not hold the incident itself.
                    # Retry once without markup only after a definite API rejection.
                    if error.code!='api_rejected' or 'reply_markup' not in payload: raise
                    payload.pop('reply_markup')
                    api('sendMessage',payload)
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
    def __init__(self, token, proxy=None):
        if not re.fullmatch(r'[0-9]{5,20}:[A-Za-z0-9_-]{20,100}',token):
            raise ValueError('Invalid token format')
        self.token=token
        self.proxy=validate_proxy(proxy)

    def __call__(self, method, data):
        if method not in ('sendMessage','getUpdates','getWebhookInfo','getMe'):
            raise ValueError('Unsupported Bot API method')
        try:
            status,raw=exchange('/bot'+self.token+'/'+method,json.dumps(data).encode(),self.proxy)
            if status!=200:
                retry=0
                try:
                    retry=json.loads(raw[:4096]).get('parameters',{}).get('retry_after',0)
                except (ValueError,TypeError,AttributeError):
                    pass
                raise DeliveryError('rate_limited' if status==429 else 'api_rejected',retry)
            value=json.loads(raw)
            if not isinstance(value,dict): raise ValueError('Invalid response')
        except (OSError,ValueError,http.client.HTTPException):
            # Never log token-bearing URLs or remote response bodies.
            raise DeliveryError('transport_unavailable') from None
        if value.get('ok') is not True:
            raise DeliveryError('api_rejected')
        return value.get('result')


def poll(queue, api):
    with queue.connect() as db:
        row=db.execute("SELECT value FROM settings WHERE key='offset'").fetchone()
    offset=int(row[0]) if row else 0
    # Telegram returns immediately when a message arrives. The bounded wait is
    # shorter than our 8s HTTP socket timeout and keeps incident evaluation frequent.
    batch=api('getUpdates',dict(offset=offset,limit=20,timeout=5,allowed_updates=['message']))
    if not isinstance(batch,list) or len(batch)>20:
        raise DeliveryError('invalid_updates')
    return batch


def updates(queue, api, users, chats, data, origin, batch=None):
    if batch is None: batch=poll(queue,api)
    with queue.connect() as db:
        row=db.execute("SELECT value FROM settings WHERE key='offset'").fetchone()
    offset=int(row[0]) if row else 0
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
                count=db.execute('SELECT value FROM settings WHERE key=?',('burst:'+str(chat['id']),)).fetchone()
                fresh=not previous or not 0<=queue.clock()-float(previous[0])<10
                used=0 if fresh else int(count[0]) if count else 1
                if used<4:
                    queue.enqueue(db,'command:'+str(uid),reply,[chat['id']])
                    if fresh: db.execute('INSERT OR REPLACE INTO settings VALUES(?,?)',('user:'+str(chat['id']),str(queue.clock())))
                    db.execute('INSERT OR REPLACE INTO settings VALUES(?,?)',('burst:'+str(chat['id']),str(used+1)))
            db.execute("INSERT OR REPLACE INTO settings VALUES('offset',?)",(str(uid+1),))
        offset=uid+1


def private_credential(descriptor, info):
    mode = stat.S_IMODE(info.st_mode)
    if mode in (0o400, 0o600):
        return True
    # systemd254+ grants the service UID read access through a named-user ACL.
    # Its ACL mask appears as group-read in stat, while group:: and other:: are ---.
    if mode != 0o440:
        return False
    try:
        acl = os.getxattr(descriptor, 'system.posix_acl_access')
    except (OSError, AttributeError):
        return False
    if len(acl) not in (44, 52) or struct.unpack_from('<I', acl)[0] != 2:
        return False
    required = {1: 4, 4: 0, 16: 4, 32: 0}
    base, readers = {}, set()
    for tag, permissions, principal in struct.iter_unpack('<HHI', acl[4:]):
        if tag == 2:
            if principal not in (0, os.geteuid()) or principal in readers or permissions != 4:
                return False
            readers.add(principal)
        elif (tag not in required or tag in base or principal != 0xffffffff
              or permissions != required[tag]):
            return False
        else:
            base[tag] = permissions
    return bool(readers) and base == required


def config(path):
    # Inspect and read the same regular inode; never follow the final symlink.
    try:
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        raise ValueError('Untrusted Telegram credentials') from None
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if (not stat.S_ISREG(info.st_mode) or info.st_nlink != 1
                or info.st_uid not in (0, os.geteuid()) or info.st_size > 8192
                or not private_credential(stream.fileno(), info)):
            raise ValueError('Untrusted Telegram credentials')
        raw = stream.read(8193)
        if len(raw) > 8192:
            raise ValueError('Untrusted Telegram credentials')
    value=json.loads(raw)
    if (not isinstance(value,dict) or not {'token','users','chats'}<=set(value)
            or set(value)-{'token','users','chats','proxy','mini_app'}
            or ('mini_app' in value and type(value['mini_app']) is not bool)):
        raise ValueError('Invalid Telegram configuration')
    validate_proxy(value.get('proxy'))
    for key in ('users','chats'):
        ids=value[key]
        if not isinstance(ids,list) or not 1<=len(ids)<=10 or any(type(i) is not int or not 0<i<2**52 for i in ids):
            raise ValueError('Numeric private user/chat IDs required')
    if set(value['users'])!=set(value['chats']):
        raise ValueError('First deployment supports explicitly allowed private chats only')
    return value


def iteration(queue,api,users,chats,data,origin,maintenance=None,mini_app=False,refresh=None,refresh_maintenance=None):
    # Pending incidents leave before the long poll, even if receiving commands fails.
    markup=keyboard(origin if mini_app else None)
    queue.deliver(api,reply_markup=markup)
    try:
        batch=poll(queue,api)
        polling='ok'
    except (DeliveryError,ValueError):
        batch=[]
        polling='no_data'
    # Read after waiting: an old pre-poll snapshot can cross its freshness limit.
    if refresh is not None: data=refresh()
    if refresh_maintenance is not None: maintenance=refresh_maintenance()
    evaluation='ok'
    try:
        queue.evaluate(data,maintenance)
    except ValueError:
        evaluation='queue_capacity'
    display=dict(data,notifications=queue.status())
    display['checks']=[c.copy() for c in data['checks']]
    for row in display['checks']:
        if row['id'] in ('rules','resource_rules'):
            row.update(status=display['notifications']['status'] if row['id']=='rules' else
                       'ok' if evaluation=='ok' else 'no_data',reason_code='notification_worker')
    for row in display['notifications']['rules']:
        if row['key'] in ('cpu_sustained','ram_sustained','container_oom'):
            display['checks'].append(dict(id=row['key'],label=row['key'],domain='server' if row['key']!='container_oom' else 'academy',
                status=row['state'],reason_code='sustained_rules'))
    if polling=='ok':
        try:
            updates(queue,api,users,chats,display,origin,batch=batch)
        except (DeliveryError,ValueError):
            polling='no_data'
    queue.deliver(api,reply_markup=markup)
    status=queue.status()
    status.update(polling=polling,evaluation=evaluation)
    if evaluation!='ok': status['status']='warning'
    return status


def read_maintenance():
    try:
        return safe_json(Path('/run/ustar-console-ops/status.json'),owner=0).get('maintenance')
    except (OSError,ValueError):
        return None


def main():
    from .config import load
    settings=load()
    credential=Path(os.environ.get('CREDENTIALS_DIRECTORY','/nonexistent'))/'telegram'
    value=config(credential)
    api=Telegram(value['token'],proxy=value.get('proxy'))
    if api('getWebhookInfo',{}).get('url'):
        raise SystemExit('Existing webhook detected; use a dedicated bot. Nothing was changed.')
    queue=Queue(Path('/var/lib/ustar-console-notify/outbox.sqlite3'),value['chats'])
    while True:
        status=iteration(queue,api,value['users'],value['chats'],None,settings.origin,mini_app=value.get('mini_app',False),
                         refresh=lambda:snapshot(settings.snapshot),refresh_maintenance=read_maintenance)
        target=Path('/run/ustar-console-notify/status.json')
        temporary=target.with_suffix('.tmp')
        temporary.write_text(json.dumps(status))
        os.chmod(temporary,0o640)
        os.replace(temporary,target)
        # Successful long polls provide the idle wait. Back off when reception fails;
        # a short yield also bounds a stream of immediate/unsupported updates.
        time.sleep(.2 if status['polling']=='ok' else 10)
