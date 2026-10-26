"""Notifier-owned Telegram links/preferences and a bounded, credential-free local RPC.

This module deliberately never imports auth or opens the web authentication database.
"""
from contextlib import contextmanager
from datetime import datetime
import grp
import hashlib
import json
import math
import os
from pathlib import Path
import pwd
import re
import secrets
import socket
import socketserver
import sqlite3
import stat
import struct
import threading
import time
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from .telegram_views import NAMES

CONTROL_PATH = Path('/run/ustar-console-notify/control.sock')
MAX_REQUEST = 16384
CHECK_IDS = tuple(sorted({'collector','host','containers','hdd','backup','cron','recovery',
    'http_local','database','ssd_space','ssd_inodes','hdd_space','hdd_inodes','observer',
    'http_external','smart','clock','units','cpu_sustained','ram_sustained','container_oom'}))
CATALOG = tuple(dict(id=key,label=NAMES[key]) for key in CHECK_IDS)


class Refused(ValueError):
    def __init__(self, code='notifier_unavailable'):
        self.code=code
        super().__init__(code)


def defaults():
    return dict(enabled=True,check_ids=list(CHECK_IDS),statuses=['warning','critical','no_data'],
        repeat_minutes=240,quiet_start='',quiet_end='',timezone='Europe/Moscow',recovery=True,
        cpu_percent=85,ram_available_percent=15,hold_seconds=600)


def preferences(value):
    if not isinstance(value,dict) or set(value)!=set(defaults()): raise Refused('invalid_preferences')
    for key in ('enabled','recovery'):
        if type(value[key]) is not bool: raise Refused('invalid_preferences')
    for key,allowed in (('check_ids',set(CHECK_IDS)),('statuses',{'warning','critical','no_data'})):
        items=value[key]
        if (not isinstance(items,list) or any(type(item) is not str or item not in allowed for item in items)
                or len(items)!=len(set(items))): raise Refused('invalid_preferences')
    for key,low,high in (('repeat_minutes',15,1440),('hold_seconds',0,3600)):
        if type(value[key]) is not int or not low<=value[key]<=high: raise Refused('invalid_preferences')
    for key,low,high in (('cpu_percent',1,100),('ram_available_percent',1,99)):
        if type(value[key]) not in (int,float) or not math.isfinite(value[key]) or not low<=value[key]<=high:
            raise Refused('invalid_preferences')
    for key in ('quiet_start','quiet_end'):
        if type(value[key]) is not str or (value[key] and not re.fullmatch(r'(?:[01][0-9]|2[0-3]):[0-5][0-9]',value[key])):
            raise Refused('invalid_preferences')
    if bool(value['quiet_start'])!=bool(value['quiet_end']): raise Refused('invalid_preferences')
    zone=value['timezone']
    if type(zone) is not str or not 1<=len(zone)<=100: raise Refused('invalid_preferences')
    try: ZoneInfo(zone)
    except (ZoneInfoNotFoundError,ValueError): raise Refused('invalid_preferences') from None
    return json.loads(json.dumps(value,allow_nan=False))


def quiet(value,now):
    if not value['quiet_start']: return False
    minute=datetime.fromtimestamp(now,ZoneInfo(value['timezone'])).strftime('%H:%M')
    start,end=value['quiet_start'],value['quiet_end']
    return start<=minute<end if start<end else minute>=start or minute<end


def username(value):
    if type(value) is not str or not re.fullmatch(r'[a-zA-Z0-9_.@-]{1,64}',value):
        raise Refused('invalid_username')
    return value


class Store:
    def __init__(self,path,clock=time.time):
        self.path,self.clock,self.lock=Path(path),clock,threading.RLock()
        if self.path.exists():
            info=self.path.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_uid!=os.geteuid() or info.st_mode&0o077:
                raise Refused('untrusted_notifier_store')
        with self.connect() as db:
            os.chmod(self.path,0o600)
            db.executescript('''
                CREATE TABLE IF NOT EXISTS telegram_accounts(username TEXT PRIMARY KEY,
                    telegram_id INTEGER UNIQUE,preferences TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS telegram_codes(username TEXT PRIMARY KEY,
                    digest TEXT UNIQUE NOT NULL,expires REAL NOT NULL);
                CREATE TABLE IF NOT EXISTS personal_rules(username TEXT NOT NULL,key TEXT NOT NULL,
                    state TEXT,since REAL,emitted TEXT,sequence INTEGER,last REAL,sent REAL,
                    delivered TEXT DEFAULT 'ok', PRIMARY KEY(username,key));
            ''')
            columns={row[1] for row in db.execute('PRAGMA table_info(personal_rules)')}
            if 'delivered' not in columns:
                db.execute("ALTER TABLE personal_rules ADD COLUMN delivered TEXT DEFAULT 'ok'")
        self.transport_status={'status':'no_data'}

    @contextmanager
    def connect(self):
        with self.lock:
            db=sqlite3.connect(self.path,timeout=2);db.row_factory=sqlite3.Row
            try:
                with db: yield db
            finally: db.close()

    def _account(self,db,user):
        row=db.execute('SELECT * FROM telegram_accounts WHERE username=?',(user,)).fetchone()
        if row: return row
        db.execute('INSERT INTO telegram_accounts(username,preferences) VALUES(?,?)',
            (user,json.dumps(defaults())))
        return db.execute('SELECT * FROM telegram_accounts WHERE username=?',(user,)).fetchone()

    def get_account(self,user):
        user=username(user)
        with self.connect() as db: row=self._account(db,user)
        result=dict(linked=row['telegram_id'] is not None,preferences=json.loads(row['preferences']),
                    catalog=[dict(item) for item in CATALOG],transport_status=dict(self.transport_status))
        if result['linked']: result['telegram_id']=row['telegram_id']
        return result

    def create_link(self,user):
        user=username(user);code=secrets.token_urlsafe(32);expires=self.clock()+600
        with self.connect() as db:
            self._account(db,user)
            db.execute('DELETE FROM telegram_codes WHERE expires<=?',(self.clock(),))
            db.execute('INSERT OR REPLACE INTO telegram_codes VALUES(?,?,?)',
                       (user,hashlib.sha256(code.encode()).hexdigest(),expires))
        return dict(code=code,expires_at=expires,instruction='Отправьте боту в личном чате: /link '+code)

    @staticmethod
    def _cancel(db,chat):
        # Commands are also cancelled on unlink: no private queued data leaves after revocation.
        if chat is not None and db.execute("SELECT 1 FROM sqlite_master WHERE name='outbox'").fetchone():
            db.execute("DELETE FROM outbox WHERE chat=? AND status='pending'",(chat,))

    def link(self,code,telegram_id,db=None):
        if (type(code) is not str or not re.fullmatch(r'[A-Za-z0-9_-]{40,100}',code)
                or type(telegram_id) is not int or not 0<telegram_id<2**52): return False
        if db is None:
            with self.connect() as connection:
                connection.execute('BEGIN IMMEDIATE')
                return self.link(code,telegram_id,db=connection)
        digest=hashlib.sha256(code.encode()).hexdigest()
        row=db.execute('SELECT username FROM telegram_codes WHERE digest=? AND expires>?',
                       (digest,self.clock())).fetchone()
        if not row: return False
        user=row['username']
        occupied=db.execute('SELECT username FROM telegram_accounts WHERE telegram_id=?',(telegram_id,)).fetchone()
        if occupied and occupied['username']!=user: return False
        old=self._account(db,user)['telegram_id']
        self._cancel(db,old)
        # A newly linked bootstrap recipient must not receive old legacy pending incidents.
        self._cancel(db,telegram_id)
        db.execute('UPDATE telegram_accounts SET telegram_id=? WHERE username=?',(telegram_id,user))
        db.execute('DELETE FROM telegram_codes WHERE username=?',(user,))
        db.execute('DELETE FROM personal_rules WHERE username=?',(user,))
        return True

    def authorized(self,chat,db=None):
        if type(chat) is not int or not 0<chat<2**52: return False
        if db is not None:
            return db.execute('SELECT 1 FROM telegram_accounts WHERE telegram_id=?',(chat,)).fetchone() is not None
        with self.connect() as connection: return self.authorized(chat,connection)

    def recipients(self,db=None):
        if db is None:
            with self.connect() as connection: return self.recipients(connection)
        return [dict(username=row['username'],chat=row['telegram_id'],preferences=json.loads(row['preferences']))
                for row in db.execute('SELECT * FROM telegram_accounts WHERE telegram_id IS NOT NULL ORDER BY username')]

    def unlink(self,user):
        user=username(user)
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE');row=self._account(db,user)
            self._cancel(db,row['telegram_id'])
            db.execute('UPDATE telegram_accounts SET telegram_id=NULL WHERE username=?',(user,))
            db.execute('DELETE FROM telegram_codes WHERE username=?',(user,))
            db.execute('DELETE FROM personal_rules WHERE username=?',(user,))
        return {'linked':False}

    def revoke(self,user):
        self.unlink(user)
        return {'revoked':True}

    def set_preferences(self,user,value):
        user=username(user);value=preferences(value)
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE');row=self._account(db,user)
            self._cancel(db,row['telegram_id'])
            db.execute('UPDATE telegram_accounts SET preferences=? WHERE username=?',(json.dumps(value),user))
            # A preference change starts fresh candidates; stale events never resume later.
            db.execute('DELETE FROM personal_rules WHERE username=?',(user,))
        return {'preferences':value}

    def dispatch(self,request):
        if not isinstance(request,dict): raise Refused('invalid_request')
        method=request.get('method')
        fields={'get_account':{'username'},'create_link':{'username'},'unlink':{'username'},
                'revoke':{'username'},'set_preferences':{'username','preferences'}}
        if method not in fields or set(request)!=(fields[method]|{'method'}): raise Refused('invalid_request')
        if method=='set_preferences': return self.set_preferences(request['username'],request['preferences'])
        return getattr(self,method)(request['username'])


def peer_allowed(connection,web_uid):
    try:
        _,uid,_=struct.unpack('3i',connection.getsockopt(socket.SOL_SOCKET,socket.SO_PEERCRED,12))
        return uid in (0,web_uid)
    except (OSError,ValueError,struct.error): return False


def _decode(raw):
    def pairs(items):
        result={}
        for key,value in items:
            if key in result: raise ValueError('duplicate_key')
            result[key]=value
        return result
    return json.loads(raw,object_pairs_hook=pairs,parse_constant=lambda value:(_ for _ in ()).throw(ValueError()))


def _read(connection,limit,timeout=2):
    data=bytearray();deadline=time.monotonic()+timeout
    while len(data)<=limit:
        remaining=deadline-time.monotonic()
        if remaining<=0: raise TimeoutError('notifier_control_timeout')
        connection.settimeout(remaining)
        chunk=connection.recv(min(4096,limit+1-len(data)))
        if not chunk: raise Refused('invalid_request')
        data.extend(chunk)
        if b'\n' in chunk:
            if not data.endswith(b'\n') or data.count(b'\n')!=1: raise Refused('invalid_request')
            if len(data)>limit: break
            return _decode(bytes(data[:-1]))
    raise Refused('request_too_large')


class ControlServer:
    """One worker, eight queued connections, two seconds/request, no process spawning."""
    def __init__(self,store,path=None,web_uid=None,group_gid=None):
        self.store=store;self.path=Path(path) if path is not None else CONTROL_PATH
        self.web_uid=pwd.getpwnam('ustar-console').pw_uid if web_uid is None else web_uid
        self.group_gid=grp.getgrnam('ustar-console').gr_gid if group_gid is None else group_gid
        self.server=None;self.thread=None

    def __enter__(self):
        parent=self.path.parent
        parent.mkdir(mode=0o750,parents=True,exist_ok=True)
        info=parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid!=os.geteuid(): raise Refused('untrusted_control_directory')
        os.chmod(parent,0o750);os.chown(parent,-1,self.group_gid)
        if self.path.exists() or self.path.is_symlink():
            info=self.path.lstat()
            if not stat.S_ISSOCK(info.st_mode) or info.st_uid!=os.geteuid(): raise Refused('untrusted_control_socket')
            self.path.unlink()
        owner=self
        class Handler(socketserver.BaseRequestHandler):
            def handle(self):
                self.request.settimeout(2)
                if not peer_allowed(self.request,owner.web_uid): return
                try: result={'ok':True,'result':owner.store.dispatch(_read(self.request,MAX_REQUEST))}
                except Refused as error: result={'ok':False,'error':error.code}
                except (OSError,ValueError,TypeError,sqlite3.Error): result={'ok':False,'error':'invalid_request'}
                try: self.request.sendall(json.dumps(result,allow_nan=False).encode()+b'\n')
                except OSError: pass
        class Server(socketserver.UnixStreamServer):
            request_queue_size=8
        self.server=Server(str(self.path),Handler)
        os.chown(self.path,-1,self.group_gid);os.chmod(self.path,0o660)
        self.thread=threading.Thread(target=self.server.serve_forever,kwargs={'poll_interval':.1},daemon=True)
        self.thread.start()
        return self

    def __exit__(self,*args):
        if self.server:
            self.server.shutdown();self.server.server_close()
        if self.thread: self.thread.join(timeout=3)
        self.path.unlink(missing_ok=True)


def rpc(method,**parameters):
    """Web-side call. Socket location and allowed operations are never caller controlled."""
    request=dict(method=method,**parameters)
    try:
        raw=json.dumps(request,allow_nan=False).encode()+b'\n'
        if len(raw)>MAX_REQUEST: raise Refused('request_too_large')
        with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as connection:
            connection.settimeout(3);connection.connect(str(CONTROL_PATH));connection.sendall(raw)
            response=_read(connection,65536)
        if not isinstance(response,dict) or response.get('ok') is not True:
            error=response.get('error') if isinstance(response,dict) else None
            if type(error) is str and re.fullmatch(r'[a-z_]{1,64}',error): raise Refused(error)
            raise OSError('notifier_unavailable')
        return response['result']
    except Refused: raise
    except OSError: raise
    except (ValueError,TypeError,KeyError): raise OSError('notifier_unavailable') from None
