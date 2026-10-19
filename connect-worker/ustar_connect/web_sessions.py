"""Bounded volatile onboarding sessions, not an Academy user/role database."""
from dataclasses import dataclass, field
import hashlib
import secrets
import threading

COOKIE = '__Host-ustar_connect'


class WebError(Exception):
    def __init__(self, reason, status=403):
        super().__init__(reason)
        self.reason, self.status = reason, status


@dataclass(repr=False)
class Session:
    expires: int
    csrf: str = field(default_factory=lambda: secrets.token_urlsafe(32))
    browser: str = field(default_factory=lambda: 'ub1_'+secrets.token_urlsafe(32))
    command: str = field(default_factory=lambda: 'ur1_'+secrets.token_urlsafe(32))
    entry: str | None = None
    actor: str | None = None
    actor_absolute: int = 0
    activation_uncertain: bool = False
    link_claim: str | None = None  # Volatile receipt only: claimed/uncertain, never Academy auth.
    attempts: dict = field(default_factory=dict)
    window: int = 0
    requests: int = 0
    lock: object = field(default_factory=threading.Lock)


class SessionStore:
    def __init__(self, capacity=500):
        self.capacity, self._items = capacity, {}
        self._lock = threading.Lock()
        self._window, self._created = 0, 0

    @staticmethod
    def _hash(cookie):
        return hashlib.sha256(cookie.encode()).hexdigest()

    def lookup(self, cookie, now):
        with self._lock:
            session = self._items.get(self._hash(cookie)) if cookie else None
            if session is None or session.expires <= now:
                raise WebError('session_expired')
            return session

    def create(self, now):
        with self._lock:
            self._items = {key: item for key, item in self._items.items() if item.expires > now}
            if now >= self._window+60:
                self._window, self._created = now, 0
            if len(self._items) >= self.capacity or self._created >= 30:
                raise WebError('rate_limited', 429)
            self._created += 1
            cookie = secrets.token_urlsafe(32)
            session = Session(now+120, window=now)
            self._items[self._hash(cookie)] = session
            return cookie, session

    def rotate(self, cookie, session):
        with self._lock:
            self._items.pop(self._hash(cookie), None)
            cookie = secrets.token_urlsafe(32)
            session.csrf = secrets.token_urlsafe(32)
            self._items[self._hash(cookie)] = session
            return cookie

    def remove(self, cookie):
        with self._lock:
            self._items.pop(self._hash(cookie), None)

    def owns(self, cookie, session, now):
        return self.lookup(cookie, now) is session

    @staticmethod
    def limit(session, operation, now):
        if now >= session.window+60:
            session.window, session.requests = now, 0
        session.requests += 1
        if session.requests > 60:
            raise WebError('rate_limited', 429)
        if operation in ('launch', 'register'):
            session.attempts[operation] = session.attempts.get(operation, 0)+1
            if session.attempts[operation] > (3 if operation == 'launch' else 5):
                raise WebError('rate_limited', 429)
