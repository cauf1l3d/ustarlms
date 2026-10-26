"""Disposable PG16 container: real grants/function, never an existing database."""
import json
import os
from pathlib import Path
import subprocess
import time

if not os.environ.get('GITHUB_ACTIONS'):
    raise SystemExit('Disposable GitHub Actions fixture only')
NAME='ustar_console_test_pg'
SQL=Path(__file__).resolve().parents[1]/'deploy/database-monitor.sql'


def run(*argv,**kwargs):
    return subprocess.run(argv,check=kwargs.pop('check',True),capture_output=True,text=True,timeout=180,**kwargs)


def query(sql,user='moodle',check=True):
    return run('docker','exec',NAME,'psql','-X','-w','-At','-U',user,'-d','moodle','-v','ON_ERROR_STOP=1','-c',sql,check=check)


existing=run('docker','inspect',NAME,check=False)
if existing.returncode==0: raise SystemExit('Refusing existing test container')
try:
    # Isolated network, no published TCP listener and no host mount/production target.
    run('docker','run','--rm','-d','--name',NAME,'--network','none',
        '-e','POSTGRES_HOST_AUTH_METHOD=trust','-e','POSTGRES_USER=moodle','-e','POSTGRES_DB=moodle','postgres:16.15')
    for _ in range(40):
        if query('SELECT 1',check=False).returncode==0: break
        time.sleep(.5)
    else: raise AssertionError('PG fixture not ready')
    query('CREATE TABLE public.private_fixture(secret text); INSERT INTO public.private_fixture VALUES (\'never exposed\')')
    # Setup is idempotent, while privileges remain bounded.
    for _ in range(2):
        run('docker','exec','-i',NAME,'psql','-X','-w','-U','moodle','-d','moodle',input=SQL.read_text())
    value=json.loads(query('SELECT ustar_console.sample()','ustar_console_monitor').stdout)
    assert set(value)=={'connections','active','idle_in_transaction','waiting','max_connections','oldest_transaction_seconds','size_bytes'}
    assert value['connections']>=1 and value['max_connections']>=1
    assert query('SELECT * FROM public.private_fixture','ustar_console_monitor',check=False).returncode!=0
    assert query('INSERT INTO public.private_fixture VALUES (\'blocked\')','ustar_console_monitor',check=False).returncode!=0
    assert query('SET ROLE ustar_console_stats','ustar_console_monitor',check=False).returncode!=0
    assert query("SELECT pg_has_role('ustar_console_monitor','pg_read_all_stats','MEMBER')").stdout.strip()=='f'
    assert query('SHOW default_transaction_read_only','ustar_console_monitor').stdout.strip()=='on'
    query('CREATE ROLE uninvolved_fixture LOGIN')
    assert query('SELECT ustar_console.sample()','uninvolved_fixture',check=False).returncode!=0
    print('CONSOLE_POSTGRES=PASS; aggregate-only monitor; application rows/role escalation/writes denied')
finally:
    run('docker','rm','-f',NAME,check=False)
