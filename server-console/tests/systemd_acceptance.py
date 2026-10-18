"""Ubuntu CI native fixture only. Never run against server1 as its installation check."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import time
import grp
import sys
import sqlite3
import textwrap
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'backend'))
from ustar_console.setup import certificate,save

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
        # A disposable loop filesystem exercises the actual read-only namespace,
        # PID1 mount table/UUID resolution and retained write protection together.
        fixture_mount=Path('/run/ustar-console-mount-fixture')
        if fixture_mount.exists(): raise AssertionError('Mount fixture already exists')
        fixture_mount.mkdir(mode=0o700)
        image=Path(directory)/'mount-fixture.ext4'
        with image.open('xb') as stream: stream.truncate(64*2**20)
        from ustar_console.collector import HDD_UUID
        run('/usr/sbin/mkfs.ext4','-q','-U',HDD_UUID,str(image))
        mounted=False
        try:
            run('/usr/bin/mount','-o','loop,nosuid,nodev,noexec',str(image),str(fixture_mount))
            mounted=True
            script=("from pathlib import Path; import errno,json; from ustar_console import collector as c; "
                    "c.HDD=Path('/run/ustar-console-mount-fixture'); print(json.dumps(c.mount()))\n"
                    "try: (c.HDD/'must-not-write').write_text('forbidden')\n"
                    "except OSError as error: assert error.errno in (errno.EROFS,errno.EACCES,errno.EPERM)\n"
                    "else: raise AssertionError('Read-only service wrote to host filesystem')\n")
            argv=('systemd-run','--quiet','--wait','--pipe','--collect',
                  '--property=ProtectSystem=strict','--property=ProtectHome=yes',
                  '--property=Group=ustar-console',
                  '--property=CapabilityBoundingSet=',
                  '/opt/ustar-console/venv/bin/python','-c',script)
            result=run(*argv)
            assert HDD_UUID in result.stdout and not (fixture_mount/'must-not-write').exists()
            run('/usr/bin/mount','-o','remount,ro',str(fixture_mount))
            assert run(*argv,check=False).returncode!=0
            print('CONSOLE_HOST_MOUNT_NAMESPACE=PASS; real UUID; host rw/local ro accepted; host ro rejected; service writes denied')
        finally:
            if mounted: run('/usr/bin/umount',str(fixture_mount))
            fixture_mount.rmdir()
        # Web identity can read sanitized snapshot but cannot read the Docker socket.
        run('runuser','-u','ustar-console','--','cat',str(path))
        assert run('runuser','-u','ustar-console','--','test','-r','/run/docker.sock',check=False).returncode != 0
        properties=run('systemctl','show','ustar-console.service','-p','User','-p','Group').stdout
        assert 'User=ustar-console' in properties and 'Group=ustar-console' in properties
        run('systemctl','stop','ustar-console.service')
        group=grp.getgrnam('ustar-console').gr_gid
        certificate('127.0.0.1',group)
        save(Path('/etc/ustar-console/config.json'),dict(origin='https://127.0.0.1:8443',listen='127.0.0.1',port=8443,
            tls=True,native_history=True,database_probe=False,local_http=False),0o640,group)
        run('systemd-analyze','verify','/etc/systemd/system/ustar-console-ops.service',
            '/etc/systemd/system/ustar-console-notify.service','/etc/systemd/system/ustar-console-smart.service',
            '/etc/systemd/system/ustar-console-smart.timer')
        run('systemctl','start','ustar-console-ops.service','ustar-console.service','ustar-console-collect.service')
        ready=False
        for attempt in range(30):
            reply=run('curl','--fail','--silent','--max-time','1','--cacert','/etc/ustar-console/ustar-console-ca.crt',
                      'https://127.0.0.1:8443/healthz',check=False)
            if reply.returncode==0: ready=True;break
            time.sleep(.2)
        assert ready,run('journalctl','-u','ustar-console.service','-n','30','--no-pager').stdout
        assert Path('/run/ustar-console-data/history.json').is_file()
        # Exercise real PID1 LoadCredential as the static notifier user with the
        # shared console group. Network replies are synthetic; credentials/ACL,
        # state directories, snapshot access and the first worker cycle are real.
        credential=Path(directory)/'telegram-fixture.json'
        credential.write_text(json.dumps(dict(token='123456789:'+('x'*35),users=[123],chats=[123])))
        credential.chmod(0o600)
        notifier_probe=textwrap.dedent('''
            import json,os,stat
            from pathlib import Path
            from ustar_console import alerts
            credential=Path(os.environ['CREDENTIALS_DIRECTORY'])/'telegram'
            value=alerts.config(credential)
            assert value['users']==value['chats']==[123]
            assert os.geteuid()!=0
            mode=stat.S_IMODE(credential.stat().st_mode)
            assert mode in (0o400,0o440)
            calls=[]
            def api(self,method,data):
                calls.append(method)
                if method=='getWebhookInfo': return {'url':''}
                if method=='getUpdates': return []
                if method=='sendMessage': return {'message_id':1}
                raise AssertionError('Unexpected fixture method')
            alerts.Telegram.__call__=api
            class EndCycle(Exception): pass
            def stop(seconds): raise EndCycle
            alerts.time.sleep=stop
            try: alerts.main()
            except EndCycle: pass
            assert calls[0]=='getWebhookInfo' and 'getUpdates' in calls
            status=json.loads(Path('/run/ustar-console-notify/status.json').read_text())
            assert status['polling']==status['evaluation']=='ok'
            print('CONSOLE_NOTIFIER_LOADCREDENTIAL=PASS; static service user; first cycle; mode='+oct(mode))
        ''')
        probe=run('systemd-run','--quiet','--wait','--pipe','--collect',
            '--unit=ustar-console-notifier-credential-fixture',
            '--property=User=ustar-notify','--property=Group=ustar-console',
            '--property=LoadCredential=telegram:'+str(credential),
            '--property=UMask=0077','--property=ProtectSystem=strict',
            '--property=ProtectHome=yes','--property=NoNewPrivileges=yes',
            '--property=CapabilityBoundingSet=',
            '--property=StateDirectory=ustar-console-notify','--property=StateDirectoryMode=0700',
            '--property=RuntimeDirectory=ustar-console-notify','--property=RuntimeDirectoryMode=0750',
            '/opt/ustar-console/venv/bin/python','-c',notifier_probe)
        assert 'CONSOLE_NOTIFIER_LOADCREDENTIAL=PASS' in probe.stdout
        print(probe.stdout.strip())
        credential.unlink()
        for private in ('/etc/ustar-console/authority/ca.key','/var/lib/ustar-console-ops/jobs.sqlite3'):
            assert run('runuser','-u','ustar-console','--','test','-r',private,check=False).returncode!=0
        reply=run('runuser','-u','ustar-console','--','/opt/ustar-console/venv/bin/python','-c',
            "from ustar_console.operations import rpc; print(rpc('status',actor='fixture')['jobs'])")
        assert reply.stdout.strip()=='[]'
        denied=run('runuser','-u','ustar-notify','--','/opt/ustar-console/venv/bin/python','-c',
            "from ustar_console.operations import rpc; rpc('status',actor='fixture')",check=False)
        assert denied.returncode!=0
        root_denied=run('/opt/ustar-console/venv/bin/python','-c',
            "from ustar_console.operations import rpc; rpc('status',actor='fixture')",check=False)
        assert root_denied.returncode!=0
        wrong_name=run('curl','--silent','--noproxy','*','--max-time','2','--cacert','/etc/ustar-console/ustar-console-ca.crt',
                      '--resolve','wrong.invalid:8443:127.0.0.1','https://wrong.invalid:8443/healthz',check=False)
        assert wrong_name.returncode==60
        # Updates must refuse active jobs before stopping the web service.
        updater=str(Path(directory)/'ustar-console/deploy/update.sh')
        jobs='/var/lib/ustar-console-ops/jobs.sqlite3'
        run('systemctl','stop','ustar-console-ops.service')
        for state in ('queued','running'):
            with sqlite3.connect(jobs) as db:
                db.execute("INSERT INTO jobs(id,actor,action,revision,idempotency,state,created) VALUES(?,?,?,?,?,?,?)",
                           ('update-fixture','fixture','manual_backup','fixture','fixture',state,time.time()))
            refused=run('bash',updater,check=False)
            assert refused.returncode!=0 and 'update refused' in refused.stdout+refused.stderr
            assert run('systemctl','is-active','ustar-console.service').stdout.strip()=='active'
            with sqlite3.connect(jobs) as db: db.execute("DELETE FROM jobs WHERE id='update-fixture'")
        run('systemctl','start','ustar-console-ops.service','ustar-console-collect.timer')
        run('runuser','-u','ustar-console','--','/opt/ustar-console/venv/bin/python','-c',
            "from ustar_console.auth import Auth; from ustar_console.config import load; "
            "Auth(load()).set_user('fixture','ci-only-update-password-12345','viewer')")
        auth='/var/lib/ustar-console/auth.sqlite3'
        with sqlite3.connect(auth) as db: before_user=db.execute("SELECT * FROM users WHERE username='fixture'").fetchone()
        preserved=(Path('/etc/ustar-console/config.json'),Path('/etc/ustar-console/authority/ca.key'),
                   Path('/etc/ustar-console/tls/server.crt'))
        before={path:path.read_bytes() for path in preserved}
        ca=Path('/etc/ustar-console/ustar-console-ca.crt');original_ca=ca.read_bytes()
        module=Path('/opt/ustar-console/venv/lib/python3.12/site-packages/ustar_console/alerts.py')
        old_module=module.read_bytes()+b'\n# CI rollback preservation marker\n'
        module.write_bytes(old_module)
        # Model a previously configured console before graphical cards existed.
        # The wheel-only updater must install the new locked dependency and
        # restore its prior absence when final validation rolls the venv back.
        run('/opt/ustar-console/venv/bin/python','-m','pip','uninstall','-y','Pillow')
        # Force the final trust check to fail after wheel replacement. Rollback
        # must restore the exact previous installation, including local patches.
        ca.write_text('invalid public CA fixture\n')
        failed=run('bash',updater,check=False)
        assert failed.returncode!=0 and 'CONSOLE_UPDATE=ROLLED_BACK' in failed.stdout
        assert module.read_bytes()==old_module
        run('/opt/ustar-console/venv/bin/python','-c',
            "import importlib.util; assert importlib.util.find_spec('PIL') is None")
        ca.write_bytes(original_ca)
        upgraded=run('bash',updater)
        assert 'CONSOLE_UPDATE=PASS' in upgraded.stdout
        assert b'CI rollback preservation marker' not in module.read_bytes()
        run('/opt/ustar-console/venv/bin/python','-c',"import PIL; assert PIL.__version__=='13.0.0'")
        assert all(path.read_bytes()==content for path,content in before.items()) and ca.read_bytes()==original_ca
        with sqlite3.connect(auth) as db: assert db.execute("SELECT * FROM users WHERE username='fixture'").fetchone()==before_user
        assert run('systemctl','is-active','ustar-console-notify.service',check=False).stdout.strip()=='inactive'
        assert run('systemctl','is-active','ustar-console-collect.timer').stdout.strip()=='active'
        print('CONSOLE_UPDATE=PASS; queued/running refusal; rollback preserved local patch; auth/CA/config unchanged; notifier state preserved')
        print('CONSOLE_LAN_TLS_BROKER=PASS; CA/SAN verified; peer credentials; private keys and job state denied')
        print('CONSOLE_SYSTEMD=PASS; API unprivileged; real host sample; Docker/backup unavailable distinguished')
    finally:
        run('systemctl','stop','ustar-console.service','ustar-console-ops.service','ustar-console-notify.service',
            'ustar-console-smart.timer','ustar-console-collect.service','ustar-console-collect.timer',check=False)
