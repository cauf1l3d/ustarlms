"""Ubuntu CI native fixture only. Never run against server1 as its installation check."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import time

ROOT=Path(__file__).resolve().parents[1]


def run(*args,check=True):
    return subprocess.run(args,check=check,text=True,capture_output=True,timeout=180)


if os.geteuid()!=0 or not Path('/run/systemd/system').is_dir() or not os.environ.get('GITHUB_ACTIONS'):
    raise SystemExit('Requires disposable Ubuntu root GitHub Actions runner; no skip fallback')
if Path('/opt/ustar-console').exists():
    raise SystemExit('Refusing to replace an existing console')
with tempfile.TemporaryDirectory(prefix='ustar-console-install-',dir='/root') as directory:
    with tarfile.open(ROOT/'dist/ustar-console.tar.gz') as archive:
        archive.extractall(directory,filter='data')
    run('bash',str(Path(directory)/'ustar-console/deploy/install.sh'))
    run('systemd-analyze','verify','/etc/systemd/system/ustar-console.service',
        '/etc/systemd/system/ustar-console-collect.service','/etc/systemd/system/ustar-console-collect.timer')
    try:
        # No production containers or backup data exist on this disposable runner.
        run('systemctl','start','ustar-console.service','ustar-console-collect.service')
        ready=False
        for attempt in range(20):
            reply=run('curl','--fail','--silent','--max-time','1','http://127.0.0.1:8098/healthz',check=False)
            if reply.returncode==0:
                ready=True
                break
            time.sleep(.2)
        assert ready, run('journalctl','-u','ustar-console.service','-n','30','--no-pager').stdout
        path=Path('/run/ustar-console-data/snapshot.json')
        assert path.is_file() and path.stat().st_uid==0 and path.stat().st_mode&0o777==0o640
        value=json.loads(path.read_text())
        assert value['host']['memory']['total_bytes']>0
        state_directory = Path('/var/lib/ustar-console-collector')
        assert state_directory.is_dir() and state_directory.stat().st_uid == 0
        assert state_directory.stat().st_mode & 0o777 == 0o700
        assert run('runuser','-u','ustar-console','--','test','-x',str(state_directory),check=False).returncode != 0
        assert any(c['id']=='hdd' and c['status']=='critical' for c in value['checks'])
        assert any(c['id']=='containers' and c['status']=='no_data' for c in value['checks'])
        assert any(c['id']=='cron' and c['status']=='no_data' for c in value['checks'])
        # Web identity can read sanitized snapshot but cannot read the Docker socket.
        run('runuser','-u','ustar-console','--','cat',str(path))
        assert run('runuser','-u','ustar-console','--','test','-r','/run/docker.sock',check=False).returncode != 0
        properties=run('systemctl','show','ustar-console.service','-p','User','-p','Group').stdout
        assert 'User=ustar-console' in properties and 'Group=ustar-console' in properties
        print('CONSOLE_SYSTEMD=PASS; API unprivileged; real host sample; Docker/backup unavailable distinguished')
    finally:
        run('systemctl','stop','ustar-console.service','ustar-console-collect.service','ustar-console-collect.timer',check=False)
