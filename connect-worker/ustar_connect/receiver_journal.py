"""Private local transport receipts/cursor. No message bodies or Academy user state."""
import fcntl
import os
import re
import sqlite3
import stat
import time

from .private_files import private_directory

TERMINAL = {'sent', 'ignored', 'rejected', 'uncertain'}
PHASES = {'received', 'creating', 'sending'} | TERMINAL
KINDS = {'launch', 'link', 'help', 'disabled', 'ignored', 'ack', 'bot'}


class JournalError(Exception):
    pass


def _private_file(path):
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1:
        os.close(fd)
        raise JournalError('unsafe_journal_file')
    return fd


class Journal:
    def __init__(self, directory, tenant, *, clock=time.time):
        self._db, self._lock = None, None
        self._clock = clock
        try:
            directory = private_directory(directory)
            if not isinstance(tenant, str) or not re.fullmatch(r'[1-9][0-9]{0,19}', tenant):
                raise JournalError('invalid_tenant')
            self._lock = _private_file(directory/'receiver.lock')
            try:
                fcntl.flock(self._lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise JournalError('journal_busy') from None
            dbpath = directory/'receiver.sqlite3'
            fd = _private_file(dbpath)
            try:
                fresh = os.fstat(fd).st_size == 0
                if os.fstat(fd).st_size > 67108864:
                    raise JournalError('journal_full')
            finally:
                os.close(fd)
            self._db = sqlite3.connect(dbpath, timeout=1)
            self._db.row_factory = sqlite3.Row
            version = self._db.execute('PRAGMA user_version').fetchone()[0]
            if not fresh and version != 1:
                raise JournalError('journal_schema_mismatch')
            self._db.execute('PRAGMA journal_mode=WAL')
            self._db.execute('PRAGMA synchronous=FULL')
            self._db.execute('PRAGMA max_page_count=16384')
            if fresh:
                self._db.executescript('''
                    CREATE TABLE state(id INTEGER PRIMARY KEY CHECK(id=1), tenant TEXT NOT NULL,
                        next_offset INTEGER NOT NULL DEFAULT 0, last_poll INTEGER NOT NULL DEFAULT 0,
                        backoff_until INTEGER NOT NULL DEFAULT 0, failures INTEGER NOT NULL DEFAULT 0,
                        gap_possible INTEGER NOT NULL DEFAULT 1);
                    CREATE TABLE events(update_id INTEGER PRIMARY KEY, identityhash TEXT,
                        kind TEXT NOT NULL, state TEXT NOT NULL, createdat INTEGER NOT NULL,
                        reason TEXT NOT NULL DEFAULT '', message_id INTEGER);
                    CREATE INDEX events_identity_time ON events(identityhash,createdat);
                    CREATE INDEX events_time ON events(createdat);
                    PRAGMA user_version=1;
                ''')
                with self._db:
                    self._db.execute('INSERT INTO state(id,tenant) VALUES(1,?)', (tenant,))
            if self._db.execute('SELECT tenant FROM state WHERE id=1').fetchone()[0] != tenant:
                raise JournalError('journal_tenant_mismatch')
        except Exception as error:
            self.close()
            if isinstance(error, JournalError):
                raise
            raise JournalError('journal_unavailable') from None

    def close(self):
        if self._db is not None:
            self._db.close(); self._db = None
        if self._lock is not None:
            os.close(self._lock); self._lock = None

    def recover(self):
        with self._db:
            highest = self._db.execute("SELECT MAX(update_id) FROM events WHERE state IN ('received','creating','sending')").fetchone()[0]
            self._db.execute("UPDATE events SET state='uncertain',reason='worker_restarted' WHERE state IN ('received','creating','sending')")
            if highest is not None:
                self._db.execute('UPDATE state SET next_offset=MAX(next_offset,?) WHERE id=1', (highest+1,))

    def prepare_poll(self):
        now = int(self._clock())
        with self._db:
            row = self._db.execute('SELECT * FROM state WHERE id=1').fetchone()
            if row['last_poll'] and now-row['last_poll'] >= 86400:
                self._db.execute('UPDATE state SET next_offset=0,gap_possible=1 WHERE id=1')
            self._db.execute('DELETE FROM events WHERE update_id IN '
                '(SELECT update_id FROM events WHERE createdat<=? AND state IN (\'sent\',\'ignored\',\'rejected\',\'uncertain\') LIMIT 500)', (now-604800,))
        return self.health()

    def polled(self, empty):
        with self._db:
            self._db.execute('UPDATE state SET last_poll=?,failures=0,backoff_until=0 WHERE id=1', (int(self._clock()),))
            if empty:
                self._db.execute('UPDATE state SET next_offset=0 WHERE id=1')

    def begin(self, uid, identityhash, kind):
        if (type(uid) is not int or not 0 <= uid < 2**63-1 or kind not in KINDS
                or (identityhash is not None and not re.fullmatch(r'[a-f0-9]{64}', identityhash))):
            raise JournalError('invalid_event_metadata')
        with self._db:
            existing = self._db.execute('SELECT state FROM events WHERE update_id=?', (uid,)).fetchone()
            if existing:
                if existing['state'] not in TERMINAL:
                    raise JournalError('incomplete_event')
                self._db.execute('UPDATE state SET next_offset=MAX(next_offset,?) WHERE id=1', (uid+1,))
                return False
            if self._db.execute('SELECT COUNT(*) FROM events').fetchone()[0] >= 10000:
                raise JournalError('journal_full')
            self._db.execute('INSERT INTO events(update_id,identityhash,kind,state,createdat) VALUES(?,?,?,\'received\',?)',
                             (uid, identityhash, kind, int(self._clock())))
            return True

    def allowed(self, identityhash, kind):
        window, limit = (600, 3) if kind in ('launch', 'link') else ((60, 40) if kind == 'bot' else ((60, 10) if kind == 'ack' else (60, 1)))
        count = self._db.execute("SELECT COUNT(*) FROM events WHERE identityhash=? AND kind=? AND createdat>? "
                                "AND state IN ('creating','sending','sent','uncertain','rejected')",
                                (identityhash, kind, int(self._clock())-window)).fetchone()[0]
        return count < limit

    def phase(self, uid, state):
        if state not in ('creating', 'sending'):
            raise JournalError('invalid_event_phase')
        with self._db:
            self._db.execute('UPDATE events SET state=? WHERE update_id=?', (state, uid))

    def finish(self, uid, state, reason='', message_id=None):
        if (state not in TERMINAL or not re.fullmatch(r'[a-z_]{0,48}', reason)
                or (message_id is not None and (type(message_id) is not int or not 0 < message_id < 2**63))):
            raise JournalError('invalid_event_metadata')
        with self._db:
            self._db.execute('UPDATE events SET state=?,reason=?,message_id=? WHERE update_id=?', (state, reason, message_id, uid))
            self._db.execute('UPDATE state SET next_offset=MAX(next_offset,?) WHERE id=1', (uid+1,))

    def backoff(self, delay):
        if type(delay) is not int or not 1 <= delay <= 86400:
            raise JournalError('invalid_backoff')
        with self._db:
            self._db.execute('UPDATE state SET failures=failures+1,backoff_until=MAX(backoff_until,?) WHERE id=1',
                             (int(self._clock())+delay,))

    def forget_identity(self, identityhash):
        with self._db:
            self._db.execute('UPDATE events SET identityhash=NULL WHERE identityhash=?', (identityhash,))

    def health(self):
        row = self._db.execute('SELECT next_offset,last_poll,backoff_until,failures,gap_possible FROM state WHERE id=1').fetchone()
        counts = {value['state']:value['n'] for value in self._db.execute('SELECT state,COUNT(*) n FROM events GROUP BY state')}
        return {'next_offset':row['next_offset'],'last_poll':row['last_poll'],
                'backoff_until':row['backoff_until'],'failures':row['failures'],
                'history_gap_possible':bool(row['gap_possible']),'states':counts}
