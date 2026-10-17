"""One fixed aggregate function; no Moodle data, query text or application superuser."""
import json
from .collector import command

SQL = 'SELECT ustar_console.sample()::text;'


def database():
    raw = command(['/usr/bin/docker', 'exec',
                   '-e', 'PGOPTIONS=-c default_transaction_read_only=on -c statement_timeout=1500 -c lock_timeout=300',
                   'ustar_postgres', 'psql', '-X', '-w', '-A', '-t',
                   '-U', 'ustar_console_monitor', '-d', 'moodle',
                   '-v', 'ON_ERROR_STOP=1', '-c', SQL], timeout=2.5, limit=4096)
    value = json.loads(raw)
    keys = {'connections','active','idle_in_transaction','waiting','max_connections','oldest_transaction_seconds','size_bytes'}
    if (not isinstance(value, dict) or set(value) != keys
            or any(type(n) not in (int, float) or not 0 <= n < 10**16 for n in value.values())
            or value['max_connections'] < 1):
        raise ValueError('Invalid database aggregates')
    usage = value['connections']/value['max_connections']
    value['status'] = ('critical' if usage >= .9 or value['oldest_transaction_seconds'] >= 600
                       else 'warning' if usage >= .75 or value['oldest_transaction_seconds'] >= 120
                       or value['waiting'] > 0 else 'ok')
    return value
