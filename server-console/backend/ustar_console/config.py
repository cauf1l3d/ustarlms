import json
import os
import ipaddress
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlsplit


@dataclass(frozen=True)
class Settings:
    origin: str
    database: Path = Path('/var/lib/ustar-console/auth.sqlite3')
    snapshot: Path = Path('/run/ustar-console-data/snapshot.json')
    prometheus: str | None = None
    idle_seconds: int = 1800
    absolute_seconds: int = 43200
    listen: str = '127.0.0.1'
    port: int = 8098
    tls: bool = False
    local_http: bool = False
    database_probe: bool = False
    native_history: bool = False

    def __post_init__(self):
        url = urlsplit(self.origin)
        if (url.scheme != 'https' or not url.hostname or url.username or url.password
                or url.path or url.query or url.fragment or self.origin.endswith('/')):
            raise ValueError('origin must be an HTTPS origin without a path')
        if self.prometheus not in (None, 'http://127.0.0.1:9090'):
            raise ValueError('Only the local fixed Prometheus endpoint is supported')
        if not 1 <= self.idle_seconds <= self.absolute_seconds <= 43200:
            raise ValueError('Invalid session lifetime')
        address = ipaddress.ip_address(self.listen)
        if address.version != 4 or not address.is_private or address.is_unspecified or address.is_multicast:
            raise ValueError('An explicit private IPv4 listen address is required')
        if type(self.port) is not int or not 1024 <= self.port <= 65535:
            raise ValueError('Invalid unprivileged port')
        if self.listen != '127.0.0.1' and not self.tls:
            raise ValueError('LAN access requires native TLS')
        if self.tls and (url.hostname != self.listen or (url.port or 443) != self.port):
            raise ValueError('TLS origin must match the selected listen address and port')
        if any(type(v) is not bool for v in (self.tls, self.local_http, self.database_probe, self.native_history)):
            raise ValueError('Boolean settings required')


def trusted_config(path: Path):
    """Service config/code must never be writable by the web identity."""
    import stat
    for current in (*path.parents, path):
        info = current.lstat()
        expected = stat.S_ISREG if current == path else stat.S_ISDIR
        if info.st_uid != 0 or info.st_mode & 0o022 or not expected(info.st_mode):
            raise ValueError('Untrusted configuration path')
    if path.stat().st_size > 65536:
        raise ValueError('Configuration too large')
    return json.loads(path.read_text())


def load():
    data = trusted_config(Path(os.environ.get('USTAR_CONSOLE_CONFIG', '/etc/ustar-console/config.json')))
    allowed = {'origin','prometheus','listen','port','tls','local_http','database_probe','native_history'}
    if set(data)-allowed:
        raise ValueError('Unknown configuration key')
    return Settings(**data)
