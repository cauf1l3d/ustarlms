import argparse
from contextlib import contextmanager
import getpass
import hashlib
import hmac
import os
import re
import secrets
import sqlite3
import stat
import time

from argon2 import PasswordHasher
from argon2.exceptions import VerificationError

from .config import load

HASHER = PasswordHasher(time_cost=3, memory_cost=32768, parallelism=1)
DUMMY_HASH = HASHER.hash(secrets.token_urlsafe(32))
USERNAME = re.compile(r'[a-z][a-z0-9_.-]{0,47}')


class Denied(Exception):
    def __init__(self, retry=0):
        self.retry = retry


def digest(value):
    return hashlib.sha256(value.encode()).hexdigest()


class Auth:
    def __init__(self, settings, clock=time.time):
        self.settings, self.clock = settings, clock
        if not settings.database.parent.is_dir():
            raise ValueError('Private state directory must already exist')
        descriptor = os.open(settings.database, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        try:
            info = os.fstat(descriptor)
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != os.geteuid():
                raise ValueError('Untrusted auth database')
            os.fchmod(descriptor, 0o600)
        finally:
            os.close(descriptor)
        with self.connection() as db:
            db.executescript('''
                PRAGMA journal_mode=WAL;
                CREATE TABLE IF NOT EXISTS users(
                  username TEXT PRIMARY KEY, password TEXT NOT NULL, role TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS sessions(
                  token TEXT PRIMARY KEY, username TEXT NOT NULL, csrf TEXT NOT NULL,
                  created REAL NOT NULL, seen REAL NOT NULL);
                CREATE TABLE IF NOT EXISTS attempts(
                  bucket TEXT PRIMARY KEY, failures INTEGER NOT NULL,
                  started REAL NOT NULL, blocked_until REAL NOT NULL);
                CREATE TABLE IF NOT EXISTS audit(
                  id INTEGER PRIMARY KEY, at REAL NOT NULL, event TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS factors(username TEXT PRIMARY KEY,secret TEXT NOT NULL,counter INTEGER NOT NULL);
            ''')

    @contextmanager
    def connection(self):
        db = sqlite3.connect(self.settings.database, timeout=10, isolation_level=None)
        db.row_factory = sqlite3.Row
        try:
            yield db
        finally:
            db.close()

    def audit(self, db, event):
        db.execute('INSERT INTO audit(at,event) VALUES (?,?)', (self.clock(), event))
        # Bounded security history, without IP/password/request/query text.
        db.execute('DELETE FROM audit WHERE id <= (SELECT max(id)-10000 FROM audit)')

    def set_user(self, username, password, role):
        if not USERNAME.fullmatch(username) or role not in ('viewer', 'operator', 'owner'):
            raise ValueError('Invalid local user or role')
        if not 14 <= len(password) <= 256:
            raise ValueError('Password must contain 14–256 characters')
        encoded = HASHER.hash(password)
        with self.connection() as db:
            db.execute('BEGIN IMMEDIATE')
            db.execute('INSERT INTO users VALUES(?,?,?) ON CONFLICT(username) DO UPDATE '
                       'SET password=excluded.password, role=excluded.role', (username, encoded, role))
            db.execute('DELETE FROM sessions WHERE username=?', (username,))
            db.execute('DELETE FROM attempts WHERE bucket=?', ('user:'+digest(username),))
            self.audit(db, 'user_updated_sessions_revoked')
            db.commit()

    def login(self, username, password, peer, otp=''):
        now = self.clock()
        buckets = ('user:'+digest(username), 'peer:'+digest(peer))
        with self.connection() as db:
            db.execute('BEGIN IMMEDIATE')
            db.execute('DELETE FROM attempts WHERE started < ?', (now-900,))
            db.execute('DELETE FROM sessions WHERE created < ? OR seen < ?',
                       (now-self.settings.absolute_seconds, now-self.settings.idle_seconds))
            rows = [db.execute('SELECT * FROM attempts WHERE bucket=?', (key,)).fetchone()
                    for key in buckets]
            retry = max([int(row['blocked_until']-now)+1 for row in rows if row and row['blocked_until'] > now] or [0])
            if retry:
                db.commit()
                raise Denied(retry)
            user = db.execute('SELECT * FROM users WHERE username=?', (username,)).fetchone()
            try:
                valid = HASHER.verify(user['password'] if user else DUMMY_HASH, password)
            except VerificationError:
                valid = False
            valid = self.factor(db,username,otp) if valid else False
            if not valid or not user:
                for key, row in zip(buckets, rows):
                    failures = (row['failures'] if row else 0)+1
                    delay = min(300, 2**min(failures-3, 9)) if failures >= 4 else 0
                    db.execute('INSERT OR REPLACE INTO attempts VALUES(?,?,?,?)',
                               (key, failures, row['started'] if row else now, now+delay))
                self.audit(db, 'login_failed')
                db.commit()
                raise Denied()
            db.execute('DELETE FROM attempts WHERE bucket=?', (buckets[0],))
            token, csrf = secrets.token_urlsafe(32), secrets.token_urlsafe(32)
            # Keep at most five active sessions per identity.
            db.execute('DELETE FROM sessions WHERE token IN '
                       '(SELECT token FROM sessions WHERE username=? ORDER BY created DESC LIMIT -1 OFFSET 4)', (username,))
            db.execute('INSERT INTO sessions VALUES(?,?,?,?,?)', (digest(token), username, csrf, now, now))
            self.audit(db, 'login_succeeded')
            db.commit()
        return token

    def factor(self,db,username,otp):
        from .totp import verify
        row=db.execute('SELECT * FROM factors WHERE username=?',(username,)).fetchone()
        if row is None:
            return True
        counter=verify(row['secret'],otp,self.clock(),row['counter'])
        if counter is None:
            return False
        db.execute('UPDATE factors SET counter=? WHERE username=?',(counter,username))
        return True

    def authorize(self,session,password,otp=''):
        """Fresh credentials per privileged request; no reusable elevation token."""
        with self.connection() as db:
            db.execute('BEGIN IMMEDIATE')
            key='reauth:'+digest(session['username'])
            row=db.execute('SELECT * FROM attempts WHERE bucket=? AND started>?',(key,self.clock()-900)).fetchone()
            if row and row['blocked_until']>self.clock():
                raise Denied(int(row['blocked_until']-self.clock())+1)
            user=db.execute('SELECT password FROM users WHERE username=?',(session['username'],)).fetchone()
            try:
                valid=bool(user) and HASHER.verify(user['password'],password)
            except VerificationError:
                valid=False
            valid=self.factor(db,session['username'],otp) if valid else False
            if not valid:
                failures=(row['failures'] if row else 0)+1
                delay=min(300,2**min(failures-3,9)) if failures>=4 else 0
                db.execute('INSERT OR REPLACE INTO attempts VALUES(?,?,?,?)',
                    (key,failures,row['started'] if row else self.clock(),self.clock()+delay))
                self.audit(db,'reauth_failed');db.commit()
                raise Denied()
            db.execute('DELETE FROM attempts WHERE bucket=?',(key,))
            self.audit(db,'privileged_request_authorized');db.commit()

    def session(self, token):
        if not token or len(token) > 128:
            raise Denied()
        with self.connection() as db:
            row = db.execute('SELECT s.*, u.role FROM sessions s JOIN users u USING(username) WHERE token=?',
                             (digest(token),)).fetchone()
        now = self.clock()
        if (not row or now < row['created'] or now-row['created'] >= self.settings.absolute_seconds
                or now-row['seen'] >= self.settings.idle_seconds):
            raise Denied()
        return dict(row)

    def touch(self, session):
        with self.connection() as db:
            db.execute('UPDATE sessions SET seen=? WHERE token=?', (self.clock(), session['token']))

    def logout(self, session):
        with self.connection() as db:
            db.execute('DELETE FROM sessions WHERE token=?', (session['token'],))
            self.audit(db, 'logout')

    def password(self, session, current, replacement, otp=''):
        # Compare and update in one transaction; concurrent CLI resets cannot be undone.
        with self.connection() as db:
            db.execute('BEGIN IMMEDIATE')
            key='reauth:'+digest(session['username'])
            previous=db.execute('SELECT * FROM attempts WHERE bucket=? AND started>?',
                                (key,self.clock()-900)).fetchone()
            if previous and previous['blocked_until']>self.clock():
                raise Denied(int(previous['blocked_until']-self.clock())+1)
            row = db.execute('SELECT password FROM users WHERE username=?', (session['username'],)).fetchone()
            try:
                valid = bool(row) and HASHER.verify(row['password'], current)
            except VerificationError:
                valid = False
            valid=self.factor(db,session['username'],otp) if valid else False
            if not valid:
                failures=(previous['failures'] if previous else 0)+1
                delay=min(300,2**min(failures-3,9)) if failures>=4 else 0
                db.execute('INSERT OR REPLACE INTO attempts VALUES(?,?,?,?)',
                           (key,failures,previous['started'] if previous else self.clock(),self.clock()+delay))
                self.audit(db,'reauth_failed')
                db.commit()
                raise Denied()
            db.execute('UPDATE users SET password=? WHERE username=?',
                       (HASHER.hash(replacement), session['username']))
            db.execute('DELETE FROM sessions WHERE username=?', (session['username'],))
            db.execute('DELETE FROM attempts WHERE bucket=?',(key,))
            self.audit(db, 'password_changed_sessions_revoked')
            db.commit()

    def events(self):
        with self.connection() as db:
            return [dict(row) for row in db.execute('SELECT at,event FROM audit ORDER BY id DESC LIMIT 100')]


def main():
    parser = argparse.ArgumentParser(description='Local console user reset; revokes previous sessions')
    parser.add_argument('username')
    parser.add_argument('--role', choices=('viewer', 'operator', 'owner'), default='owner')
    parser.add_argument('--totp',choices=('enroll','disable'))
    args = parser.parse_args()
    auth=Auth(load())
    if args.totp:
        if not os.isatty(0) or not os.isatty(1):
            raise SystemExit('TOTP setup requires a local interactive terminal')
        with auth.connection() as db:
            if not db.execute('SELECT 1 FROM users WHERE username=?',(args.username,)).fetchone():
                raise SystemExit('Create the user first')
            if args.totp=='enroll':
                from .totp import secret, verify
                key=secret()
                print('Local authenticator setup key (do not paste into chat): '+key)
                supplied=getpass.getpass('Code from authenticator: ')
                counter=verify(key,supplied,time.time())
                if counter is None: raise SystemExit('Code rejected; nothing changed')
                db.execute('INSERT OR REPLACE INTO factors VALUES(?,?,?)',(args.username,key,counter))
            else:
                db.execute('DELETE FROM factors WHERE username=?',(args.username,))
            db.execute('DELETE FROM sessions WHERE username=?',(args.username,))
            auth.audit(db,'totp_updated_sessions_revoked')
        print('TOTP updated; previous sessions revoked.')
        return
    password = getpass.getpass('New console password (14+ characters): ')
    if not hmac.compare_digest(password, getpass.getpass('Repeat password: ')):
        raise SystemExit('Passwords differ')
    auth.set_user(args.username, password, args.role)
    print('User updated; previous sessions revoked.')
