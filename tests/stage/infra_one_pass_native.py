#!/usr/bin/env python3
"""Disposable existing Ubuntu runtime job only: actual UID isolation and mount sandbox."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import uuid
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/infra'))
import infra_one_pass as m

def run(argv,check=True):
    result=subprocess.run(argv,capture_output=True,text=True,timeout=90)
    if check and result.returncode:raise RuntimeError('synthetic fixture command failed: '+result.stderr[-6000:])
    return result

if os.geteuid()!=0 or not os.environ.get('GITHUB_ACTIONS') or not Path('/run/systemd/system').is_dir():
    raise SystemExit('Requires disposable root systemd GitHub runner; no production or skip fallback')
if Path('/var/lib/ustar-code-hardening').exists():raise SystemExit('Refusing a populated production host')

with tempfile.TemporaryDirectory(prefix='ustar-infra-native-') as directory:
    base=Path(directory);base.chmod(0o755)
    home=base/'home/aduk';home.mkdir(parents=True);home.chmod(0o755)
    dump=home/'ustar-backup.dump';dump.write_bytes(b'synthetic-private-dump');dump.chmod(0o664);os.chown(dump,1000,1000)
    def outsider():
        return run(['setpriv','--reuid=65534','--regid=65534','--clear-groups','/usr/bin/python3','-c',
                    'import pathlib,sys;pathlib.Path(sys.argv[1]).read_bytes()',str(dump)],check=False).returncode
    assert outsider()==0
    tx=m.Exports(base/'private');tx.prepare(m.export_plan(base));tx.apply()
    assert outsider()!=0 and dump.stat().st_uid==1000 and dump.read_bytes()==b'synthetic-private-dump'
    tx.undo();assert outsider()==0 and dump.stat().st_mode&0o777==0o664
    print('INFRA_EXPORT_ACTUAL_UID=PASS; unrelated UID65534 denied; original UID1000 retained; undo verified')

    mount=Path('/run/ustar-infra-hdd-fixture-'+uuid.uuid4().hex[:8]);mount.mkdir(mode=0o700)
    image=base/'hdd.ext4'
    with image.open('xb') as stream:stream.truncate(64*1024**2)
    identity='030ad819-cf4e-44b8-996b-778f4b566102'
    run(['/usr/sbin/mkfs.ext4','-q','-U',identity,str(image)])
    mounted=False
    try:
        run(['/usr/bin/mount','-o','loop,nosuid,nodev,noexec',str(image),str(mount)]);mounted=True
        target=mount/'host-state';target.mkdir(mode=0o700)
        code=('import sys;from pathlib import Path;sys.path.insert(0,'+repr(str(ROOT/'scripts/infra'))+');'
              'import infra_one_pass as m;m.MOUNT=Path('+repr(str(mount))+');m.TARGET=Path('+repr(str(target))+');'
              'm.HDD_UUID='+repr(identity)+';m.HDD_RESERVE=2*1024**2;m.LIMIT=2*1024**2;'
              'm.hdd_target(m.Host(m.Store(Path("/not-used"))));'
              '(m.TARGET/"probe").write_bytes(b"verified");(m.TARGET/"probe").unlink();print("HDD_NAMESPACE_PASS")')
        argv=['systemd-run','--quiet','--wait','--pipe','--collect',
              '--property=ProtectSystem=strict','--property=ProtectHome=read-only',
              '--property=ReadWritePaths='+str(target),'/usr/bin/python3','-I','-B','-c',code]
        assert 'HDD_NAMESPACE_PASS' in run(argv).stdout
        # Run the real companion producer under the same protected filesystem
        # properties as its nightly service. Synthetic paths/keys/controllers;
        # root phase guards and flock, age authentication, disk writes are real.
        inputs=Path('/run/ustar-infra-input-'+uuid.uuid4().hex[:8]);inputs.mkdir(mode=0o700)
        created=[]
        try:
            key=inputs/'recovery.key';recipients=inputs/'recipients.txt'
            run(['age-keygen','-o',str(key)])
            recipients.write_text(run(['age-keygen','-y',str(key)]).stdout);recipients.chmod(0o600)
            source=inputs/'source.json';source.write_bytes(b'{"synthetic_private_control":true}');source.chmod(0o600)
            lock=inputs/'lock';lock.touch(mode=0o600)
            statebase=inputs/'state';statebase.mkdir(mode=0o700)
            for dirname,phase in (('/var/lib/ustar-code-hardening','active'),('/var/lib/ustar-postgres-app-handoff','app_role_active')):
                path=Path(dirname);assert not path.exists();path.mkdir(mode=0o700);created.append(path)
                state=path/'state.json';state.write_text(json.dumps({'phase':phase}));state.chmod(0o600)
            import hashlib
            producer=('import sys,json;from pathlib import Path;sys.path.insert(0,'+repr(str(ROOT/'scripts/infra'))+');'
                'import infra_one_pass as m;m.MOUNT=Path('+repr(str(mount))+');m.TARGET=Path('+repr(str(target))+');'
                'm.HDD_UUID='+repr(identity)+';m.HDD_RESERVE=2*1024**2;m.LIMIT=2*1024**2;'
                'm.KEY=Path('+repr(str(key))+');m.RECIPIENTS=Path('+repr(str(recipients))+');'
                'm.LOCKS=('+repr(str(lock).lstrip('/'))+',);m.SCOPE=('+repr(str(source).lstrip('/'))+',);'
                'm.CONTROLLERS={'+repr(str(source).lstrip('/'))+':'+repr(hashlib.sha256(source.read_bytes()).hexdigest())+'};'
                'store=m.Store(Path('+repr(str(statebase))+'));print(json.dumps(m.backup_controls(store,m.Host(store))))')
            producer_argv=['systemd-run','--quiet','--wait','--pipe','--collect',
                '--property=User=root','--property=Group=root','--property=UMask=0077',
                '--property=ProtectSystem=strict','--property=ProtectHome=read-only','--property=PrivateTmp=yes',
                '--property=NoNewPrivileges=yes','--property=MemoryMax=1G',
                '--property=ReadWritePaths='+str(target)+' '+str(statebase),
                '/usr/bin/python3','-I','-B','-c',producer]
            receipt=json.loads(run(producer_argv).stdout)
            assert receipt['readback_verified'] and receipt['phase']=='complete' and receipt['files']==1
            assert Path(receipt['archive']).stat().st_mode&0o777==0o600
            print('INFRA_COMPANION_SERVICE=PASS; real restricted systemd service; real flock/age/manifest/readback')
        finally:
            for path in reversed(created):(path/'state.json').unlink();path.rmdir()
            import shutil
            shutil.rmtree(inputs)
        run(['/usr/bin/mount','-o','remount,ro',str(mount)])
        assert run(argv,check=False).returncode!=0
        print('INFRA_HDD_NAMESPACE=PASS; actual UUID; host rw/local parent ro/target rw; host ro rejected')
    finally:
        if mounted:run(['/usr/bin/umount',str(mount)])
        mount.rmdir()
print('INFRA_ONE_PASS_NATIVE=PASS')
