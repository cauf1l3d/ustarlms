"""Root broker: fixed plans/jobs and an already-open encrypted archive descriptor.

The API has no Docker/HDD access. Unix peer credentials restrict this protocol
to the console OS identity. Request fields never become command names or paths.
"""
import array
from contextlib import contextmanager
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import signal
import socket
import socketserver
import sqlite3
import struct
import subprocess
import sys
import threading
import time

from .collector import command, mount, backup, BACKUP, HDD
from .data import safe_json, open_regular, stamp

SOCKET=Path('/run/ustar-console-ops/broker.sock')
BACKUP_LOCK=Path('/run/ustar-backup/lock')
STATE=Path('/var/lib/ustar-console-ops/jobs.sqlite3')
PRODUCER=Path('/usr/local/sbin/USTAR_HDD_BACKUP_20261006.py')
PRODUCER_SHA='75ca974f1d763f6fc84281501a99936894f1b487c1cc4d0c1d71697e67c79d75'
LAB=Path('/usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py')
LAB_SHA='4804c27aada3d5f431ccf19cfb174dd8deb1bc6f991d3d9793d6a89b7be9b022'
LAB_UNIT='ustar-backup-recovery-20261015.service'
LAB_STATE=Path('/var/lib/ustar-restore-test/20261014/state.json')
MUTEX=threading.RLock()
ACTIONS={
    'manual_backup':('Академия → зашифрованная копия на HDD','Академия временно остановится. Продолжительность зависит от объёма; предел 45 минут.'),
    'academy_restart':('Только контейнер ustar_moodle','Краткая недоступность Академии. PostgreSQL, почта, DNS и Apache не перезапускаются.'),
    'lab_start':('Изолированный стенд из последней копии, TTL 2 часа','Восстановление только в namespace существующего lab controller. Production не заменяется. Существующие данные стенда не удаляются.'),
    'lab_stop':('Только стенд ustar-backup-recovery-20261015','Остановка стенда. Архив, production и сохранённые данные стенда остаются.'),
}


class Refused(ValueError):
    pass


def pinned(path, expected):
    fd,_=open_regular(path,owner=0,limit=262144)
    with os.fdopen(fd,'rb') as stream:
        if hashlib.sha256(stream.read()).hexdigest()!=expected:
            raise Refused('helper_version_mismatch')


def container_identity():
    raw=command(['/usr/bin/docker','inspect','--type=container','--format',
        '{{json .Id}}|{{json .Name}}|{{json .State.Running}}|{{json .State.StartedAt}}',
        'ustar_moodle'],limit=4096)
    identifier,name,running,started=[json.loads(part) for part in raw.strip().split('|')]
    if not re.fullmatch(r'[0-9a-f]{64}',identifier) or name!='/ustar_moodle' or type(running) is not bool:
        raise Refused('container_identity_missing')
    return dict(id=identifier,running=running,started=started)


def current(action, *, lock_held=False):
    if action not in ACTIONS:
        raise Refused('unsupported_action')
    result={'action':action}
    if action in ('manual_backup','academy_restart','lab_start'):
        if (BACKUP/'moodle-was-running.json').exists():
            raise Refused('recovery_required')
        result['container']=container_identity()
        if not result['container']['running']:
            raise Refused('academy_not_running')
        if not lock_held:
            fd,info=open_regular(BACKUP_LOCK,owner=0)
            try:
                fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            finally:
                os.close(fd)
    if action=='manual_backup':
        pinned(PRODUCER,PRODUCER_SHA)
        result['hdd_uuid']=mount()['uuid']
    if action.startswith('lab_'):
        pinned(LAB,LAB_SHA)
        if action=='lab_start':
            if LAB_STATE.exists() or LAB_STATE.is_symlink():
                raise Refused('lab_data_already_exists')
            result['archive']=backup()['file']
        else:
            value=safe_json(LAB_STATE,owner=0)
            result['lab_token_hash']=hashlib.sha256(str(value.get('token','')).encode()).hexdigest()
            result['phase']=value.get('phase')
    return result


def revision(value):
    return hashlib.sha256(json.dumps(value,sort_keys=True).encode()).hexdigest()


class Operations:
    def __init__(self,path=STATE,clock=time.time):
        self.path,self.clock=path,clock
        if path.exists() and (path.is_symlink() or path.stat().st_uid!=os.geteuid() or path.stat().st_mode&0o077):
            raise Refused('untrusted_jobs_state')
        with self.connect() as db:
            os.chmod(path,0o600)
            db.executescript('''
              CREATE TABLE IF NOT EXISTS plans(id TEXT PRIMARY KEY,actor TEXT,action TEXT,revision TEXT,expires REAL,used INTEGER DEFAULT 0);
              CREATE TABLE IF NOT EXISTS jobs(id TEXT PRIMARY KEY,actor TEXT,action TEXT,revision TEXT,idempotency TEXT,
                state TEXT,created REAL,started REAL,finished REAL,result TEXT,UNIQUE(actor,idempotency));
              CREATE TABLE IF NOT EXISTS grants(id TEXT PRIMARY KEY,session TEXT,expires REAL,archive TEXT,sha TEXT,size INTEGER);
              CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY,at REAL,actor TEXT,event TEXT,target TEXT);
            ''')

    @contextmanager
    def connect(self):
        db=sqlite3.connect(self.path,timeout=2)
        db.row_factory=sqlite3.Row
        try:
            with db: yield db
        finally:
            db.close()

    def audit(self,db,actor,event,target):
        db.execute('INSERT INTO audit(at,actor,event,target) VALUES(?,?,?,?)',(self.clock(),actor,event,target))
        db.execute('DELETE FROM audit WHERE id<=(SELECT max(id)-10000 FROM audit)')

    def plan(self,actor,action):
        value=current(action)
        identifier=secrets.token_hex(16)
        expires=self.clock()+90
        with self.connect() as db:
            db.execute('DELETE FROM plans WHERE expires<?',(self.clock(),))
            db.execute('DELETE FROM plans WHERE actor=? AND id NOT IN (SELECT id FROM plans WHERE actor=? ORDER BY expires DESC LIMIT 31)',(actor,actor))
            db.execute('INSERT INTO plans VALUES(?,?,?,?,?,0)',(identifier,actor,action,revision(value),expires))
            self.audit(db,actor,'plan_created',action)
        target,effect=ACTIONS[action]
        return dict(id=identifier,action=action,target=target,effect=effect,expires=expires,
                    revision=revision(value),confirmation=action,archive=value.get('archive'))

    def confirm(self,actor,plan_id,idempotency,confirmation):
        if not re.fullmatch(r'[0-9a-f-]{16,64}',idempotency):
            raise Refused('invalid_idempotency')
        with MUTEX,self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            old=db.execute('SELECT * FROM jobs WHERE actor=? AND idempotency=?',(actor,idempotency)).fetchone()
            if old:
                return dict(old)
            plan=db.execute('SELECT * FROM plans WHERE id=? AND actor=?',(plan_id,actor)).fetchone()
            if not plan or plan['used'] or not self.clock() < plan['expires'] or confirmation!=plan['action']:
                raise Refused('plan_expired_or_invalid')
            # A stop request may target the running lab. All other write jobs serialize.
            active=db.execute("SELECT action FROM jobs WHERE state IN ('queued','running')").fetchall()
            if active and not (plan['action']=='lab_stop' and all(r['action']=='lab_start' for r in active)):
                raise Refused('operation_in_progress')
            if revision(current(plan['action']))!=plan['revision']:
                raise Refused('target_changed_create_new_plan')
            identifier=secrets.token_hex(16)
            db.execute('INSERT INTO jobs(id,actor,action,revision,idempotency,state,created) VALUES(?,?,?,?,?,?,?)',
                (identifier,actor,plan['action'],plan['revision'],idempotency,'queued',self.clock()))
            db.execute('UPDATE plans SET used=1 WHERE id=?',(plan_id,))
            self.audit(db,actor,'job_queued',identifier)
        return {'id':identifier,'state':'queued','action':plan['action']}

    def status(self):
        with self.connect() as db:
            rows=[dict(r) for r in db.execute('SELECT id,actor,action,state,created,started,finished,result FROM jobs ORDER BY created DESC LIMIT 50')]
            events=[dict(r) for r in db.execute('SELECT at,actor,event,target FROM audit ORDER BY id DESC LIMIT 100')]
        active=next((r for r in rows if r['state']=='running' and r['action'] in ('manual_backup','academy_restart')),None)
        maintenance=({'action':active['action'],'started':active['started'],
                      'until':active['started']+(2700 if active['action']=='manual_backup' else 120)} if active else None)
        return dict(jobs=rows,events=events,maintenance=maintenance,observed_at=stamp(self.clock()))

    def grant(self,actor,session):
        mount()
        value=backup()
        if value.get('recovery_marker_present'):
            raise Refused('recovery_required')
        identifier=secrets.token_urlsafe(32)
        expires=self.clock()+120
        with self.connect() as db:
            db.execute('DELETE FROM grants WHERE expires<?',(self.clock(),))
            db.execute('DELETE FROM grants WHERE session=?',(session,))
            db.execute('INSERT INTO grants VALUES(?,?,?,?,?,?)',
                (hashlib.sha256(identifier.encode()).hexdigest(),session,expires,value['file'],value['sha256'],value['bytes']))
            self.audit(db,actor,'download_authorized',value['file'])
        return dict(token=identifier,expires=expires,file=value['file'],sha256=value['sha256'],bytes=value['bytes'])

    def archive(self,actor,session,token):
        with self.connect() as db:
            row=db.execute('SELECT * FROM grants WHERE id=? AND session=?',
                (hashlib.sha256(token.encode()).hexdigest(),session)).fetchone()
            if not row or not self.clock()<row['expires']:
                raise Refused('download_grant_expired')
            mount()
            value=backup()
            if (value['file'],value['sha256'],value['bytes'])!=(row['archive'],row['sha'],row['size']):
                raise Refused('archive_changed')
            fd,info=open_regular(HDD/'backups/managed'/row['archive'],owner=0)
            if info.st_size!=row['size']:
                os.close(fd)
                raise Refused('archive_changed')
            self.audit(db,actor,'download_opened',row['archive'])
        return {k:value[k] for k in ('file','sha256','bytes')},fd


def rpc(method,**parameters):
    """Client, including bounded FD receipt. Only API server calls this."""
    with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as channel:
        channel.settimeout(8)
        channel.connect(str(SOCKET))
        channel.sendall(json.dumps(dict(method=method,**parameters)).encode()+b'\n')
        raw,ancillary,flags,_=channel.recvmsg(262144,socket.CMSG_SPACE(array.array('i').itemsize))
        descriptors=[]
        try:
            for level,kind,value in ancillary:
                if level==socket.SOL_SOCKET and kind==socket.SCM_RIGHTS:
                    fds=array.array('i');fds.frombytes(value[:len(value)-len(value)%fds.itemsize]);descriptors.extend(fds)
            while not raw.endswith(b'\n') and len(raw)<262144:
                part=channel.recv(min(8192,262144-len(raw)))
                if not part: break
                raw+=part
            if flags&(socket.MSG_CTRUNC|socket.MSG_TRUNC) or not raw.endswith(b'\n'):
                raise Refused('broker_response_limit')
            value=json.loads(raw)
            if 'error' in value:
                raise Refused(value['error'])
            if method=='archive':
                if len(descriptors)!=1:
                    raise Refused('missing_archive_descriptor')
                value['_fd']=descriptors.pop()
            return value
        finally:
            for fd in descriptors: os.close(fd)


def run_job(identifier):
    if os.geteuid()!=0 or not re.fullmatch(r'[0-9a-f]{32}',identifier):
        raise SystemExit('Invalid root job')
    jobs=Operations()
    with jobs.connect() as db:
        db.execute('BEGIN IMMEDIATE')
        row=db.execute("SELECT * FROM jobs WHERE id=? AND state='queued'",(identifier,)).fetchone()
        if not row: return
        # Re-check revision immediately before execution, including helper hash/target.
        try:
            if time.time()-row['created']>90 or revision(current(row['action']))!=row['revision']:
                raise Refused('target_changed')
        except (ValueError,OSError,TimeoutError):
            db.execute("UPDATE jobs SET state='refused',finished=?,result='target_changed' WHERE id=?",(time.time(),identifier))
            return
        db.execute("UPDATE jobs SET state='running',started=? WHERE id=?",(time.time(),identifier))
    action=row['action']
    state,result='failed','helper_failed'
    lock=None
    try:
        if action=='manual_backup':
            argv=['/usr/bin/python3','-B',str(PRODUCER),'--backup','--acknowledge-outage']
        elif action=='academy_restart':
            # Existing backup EX lock excludes cron/backup while restarting only Moodle.
            lock=os.open(BACKUP_LOCK,os.O_RDWR|os.O_NOFOLLOW)
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            value=current(action,lock_held=True)
            if revision(value)!=row['revision']:
                raise Refused('target_changed')
            argv=['/usr/bin/docker','restart','--time','30',value['container']['id']]
        elif action=='lab_start':
            # Dedicated, fixed unit supports an explicit stop and ExecStopPost cleanup.
            argv=['/usr/bin/systemd-run','--unit='+LAB_UNIT,'--wait','--collect',
                '--property=RuntimeMaxSec=10800','--property=TimeoutStopSec=180',
                '--property=KillMode=control-group','--property=UMask=0077',
                '--property=ExecStopPost=/usr/bin/python3 -B '+str(LAB)+' --stop-lab',
                '/usr/bin/python3','-B',str(LAB),'--restore-lab','--duration','2h']
        elif action=='lab_stop':
            command(['/usr/bin/systemctl','stop',LAB_UNIT],timeout=180,limit=4096)
            argv=['/usr/bin/python3','-B',str(LAB),'--stop-lab']
        else:
            raise Refused('unsupported_action')
        # Full helper logs stay in root journal, never sent to browser/Telegram.
        process=subprocess.Popen(argv,env={'PATH':'/usr/bin:/bin','LC_ALL':'C'},start_new_session=True)
        try:
            code=process.wait(timeout=10830 if action=='lab_start' else 2700)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid,signal.SIGTERM)
            try: process.wait(timeout=180)
            except subprocess.TimeoutExpired:
                os.killpg(process.pid,signal.SIGKILL);process.wait()
            code=124
        state,result=('complete','exit_0') if code==0 else ('failed','helper_nonzero')
    except (OSError,ValueError,TimeoutError,subprocess.SubprocessError):
        state,result='failed','execution_refused_or_failed'
    finally:
        if lock is not None: os.close(lock)
        if action=='manual_backup' and (BACKUP/'moodle-was-running.json').exists():
            # Resume only the exact container owned by the reviewed backup marker;
            # this existing --recover contract never restores an archive.
            try:
                pinned(PRODUCER,PRODUCER_SHA)
                subprocess.run(['/usr/bin/python3','-B',str(PRODUCER),'--recover'],
                    timeout=180,env={'PATH':'/usr/bin:/bin','LC_ALL':'C'},check=False)
            except (OSError,ValueError,subprocess.SubprocessError):
                pass
        with jobs.connect() as db:
            db.execute('UPDATE jobs SET state=?,finished=?,result=? WHERE id=?',(state,time.time(),result,identifier))
            jobs.audit(db,row['actor'],'job_'+state,identifier)


def main():
    if len(sys.argv)==2:
        return run_job(sys.argv[1])
    if os.geteuid()!=0:
        raise SystemExit('Root broker only')
    jobs=Operations()
    allowed=pwd.getpwnam('ustar-console').pw_uid
    class Handler(socketserver.StreamRequestHandler):
        def handle(self):
            fd=None
            try:
                self.request.settimeout(8)
                _,uid,_=struct.unpack('3i',self.request.getsockopt(socket.SOL_SOCKET,socket.SO_PEERCRED,12))
                if uid!=allowed: return
                raw=self.rfile.readline(4097)
                if len(raw)>4096 or not raw.endswith(b'\n'): return
                value=json.loads(raw)
                method=value.pop('method')
                actor=value.get('actor','')
                if not re.fullmatch(r'[a-z][a-z0-9_.-]{0,47}',actor):
                    raise Refused('invalid_actor')
                if method=='status' and set(value)=={'actor'}:
                    result=jobs.status()
                elif method=='plan' and set(value)=={'actor','action'}:
                    result=jobs.plan(**value)
                elif method=='confirm' and set(value)=={'actor','plan_id','idempotency','confirmation'}:
                    result=jobs.confirm(**value)
                elif method=='grant' and set(value)=={'actor','session'} and re.fullmatch(r'[0-9a-f]{64}',value['session']):
                    result=jobs.grant(**value)
                elif method=='archive' and set(value)=={'actor','session','token'} and len(value['token'])<=128:
                    result,fd=jobs.archive(**value)
                else:
                    raise Refused('unsupported_request')
            except Exception:
                result={'error':'operation_refused'}
            try:
                payload=json.dumps(result).encode()+b'\n'
                ancillary=[(socket.SOL_SOCKET,socket.SCM_RIGHTS,array.array('i',[fd]))] if fd is not None else []
                sent=self.request.sendmsg([payload],ancillary)
                if sent<len(payload): self.request.sendall(payload[sent:])
            finally:
                if fd is not None: os.close(fd)
    class Server(socketserver.UnixStreamServer):
        request_queue_size=8
    if SOCKET.exists(): SOCKET.unlink()
    with Server(str(SOCKET),Handler) as server:
        os.chmod(SOCKET,0o660)
        server.timeout=1
        last=0
        while True:
            server.handle_request()
            if time.monotonic()-last<10: continue
            last=time.monotonic()
            with jobs.connect() as db:
                rows=db.execute("SELECT id,state FROM jobs WHERE state IN ('queued','running')").fetchall()
                for row in rows:
                    unit='ustar-console-job-'+row['id']+'.service'
                    try:
                        active=command(['/usr/bin/systemctl','show',unit,'--property=ActiveState','--value']).strip()
                        if active in ('active','activating','deactivating'): continue
                        if row['state']=='running':
                            db.execute("UPDATE jobs SET state='interrupted',finished=?,result='check_recovery_marker' WHERE id=?",(time.time(),row['id']))
                        else:
                            command(['/usr/bin/systemd-run','--unit='+unit,'--collect',
                                '--property=RuntimeMaxSec=11100','--property=TimeoutStopSec=180',
                                '--property=UMask=0077','--property=Nice=10','--property=IOSchedulingClass=idle',
                                '/opt/ustar-console/venv/bin/ustar-console-ops',row['id']],timeout=4)
                    except (OSError,ValueError,TimeoutError):
                        pass
            from .collector import write_snapshot
            write_snapshot(jobs.status(),SOCKET.parent/'status.json')
