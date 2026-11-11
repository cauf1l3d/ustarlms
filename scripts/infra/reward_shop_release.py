#!/usr/bin/env python3
"""USTAR Reward Shop 2026111100: exact-source package builder and guarded owner installer.

Build: python3 reward_shop_release.py build --repo ... --output ...
Install: sudo python3 -I -B update.py --check | --upgrade | --finish
No network access is needed by the generated installation package.
"""
from __future__ import annotations
import argparse
from contextlib import contextmanager
import fcntl
import time
import datetime
import gzip
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile

BASE = '53fab3b6fa4a00f492ff355546a2948890ce7395'
TARGET = 'a7a34b4927ffd42db28a8d76e410d20a37d7a434'
BEFORE = 2026110200
AFTER = 2026111100
APP_REL = Path('moodle/local/ustar')
THEME_REL = Path('moodle/theme/ustar')
HOST_APP = Path('/opt/ustar/data/moodle/public/public')
CONTAINER_APP = '/var/www/html/public'
CONTAINER_CLI = '/var/www/html/admin/cli'
BACKUP_DIR = Path('/opt/ustar/backups')
STATE_DIR = Path('/var/lib/ustar-reward-shop-2026111100-cli-fix-v2')
GUARD_PATHS = [Path('/var/lib/ustar-connect-relink-53fab3b6fa4a/lock')] + [Path(p) for p in (
    '/var/lib/ustar-connect-academy-update/lock',
    '/var/lib/ustar-connect-chat-link-update/lock',
    '/var/lib/ustar-academy-https/lock',
    '/run/ustar-cron/lock', '/run/ustar-backup/lock', '/run/ustar-connect-install.lock')]
PREVIOUS_STATE = Path('/var/lib/ustar-reward-shop-2026111100/state.json')
RELINK = Path('/root/ustar-connect-relink-dc3a3c54/update.py')
EXPECTED = {
    'achievements.php', 'classes/privacy/provider.php', 'classes/reward_shop.php',
    'db/install.xml', 'db/upgrade.php', 'lang/en/local_ustar.php',
    'lang/ru/local_ustar.php', 'reward_shop.php', 'reward_shop_admin.php',
    'settings.php', 'templates/achievements.mustache',
    'templates/reward_shop.mustache', 'templates/reward_shop_admin.mustache',
    'tests/reward_shop_test.php', 'version.php',
}
TABLES = ('local_ustar_reward_shop_offer', 'local_ustar_reward_shop_order')

class Refused(Exception):
    pass

def require(condition, reason):
    if not condition:
        raise Refused(reason)

def digest(data):
    if isinstance(data, (str, Path)):
        return hashlib.sha256(Path(data).read_bytes()).hexdigest()
    return hashlib.sha256(data).hexdigest()

def safe_path(root, path):
    require(isinstance(path, str) and path and not path.startswith('/'), 'invalid_path')
    p = Path(path)
    require(all(piece not in ('', '.', '..') for piece in p.parts), 'invalid_path')
    base = root.resolve()
    dest = base.joinpath(*p.parts)
    require(dest.is_relative_to(base) and not dest.is_symlink(), 'unsafe_path')
    parent = dest.parent
    while parent != base.parent:
        require(not parent.is_symlink(), 'symlink_parent')
        if parent == base:
            break
        parent = parent.parent
    return dest

def scan(root):
    result = {}
    for folder, dirs, files in os.walk(root, followlinks=False):
        for d in dirs:
            require(not (Path(folder)/d).is_symlink(), 'symlink_in_source')
        for filename in files:
            path = Path(folder)/filename
            require(path.is_file() and not path.is_symlink(), 'unsupported_source_file')
            result[path.relative_to(root).as_posix()] = digest(path)
    return dict(sorted(result.items()))

def run(argv, *, input_file=None, output_file=None, timeout=240, check=True):
    with open(input_file, 'rb') if input_file else open(os.devnull,'rb') as inp:
        with open(output_file, 'wb') if output_file else open(os.devnull,'wb') as out:
            p = subprocess.run(argv, stdin=inp, stdout=out if output_file else subprocess.PIPE,
                stderr=subprocess.PIPE, timeout=timeout, check=False)
    if check and p.returncode:
        raise Refused('command_failed:' + Path(argv[0]).name + ':' + str(p.returncode) + ':' +
            p.stderr.decode('utf-8', 'replace')[-350:].replace('\n',' '))
    return p

def git(repo, *args):
    return subprocess.check_output(['git','-C',str(repo),*args], stderr=subprocess.PIPE, timeout=40).decode().strip()

def archive(repo, commit, dest):
    out = subprocess.check_output(['git','-C',str(repo),'archive',commit,'moodle/local/ustar','moodle/theme/ustar'], timeout=90)
    with tarfile.open(fileobj=io.BytesIO(out), mode='r:') as tar:
        for member in tar.getmembers():
            require(member.isfile() or member.isdir(), 'untrusted_git_entry')
            name = Path(member.name)
            require(not name.is_absolute() and '..' not in name.parts, 'untrusted_git_path')
        tar.extractall(dest, filter='data')

def build(repo, output):
    repo, output = Path(repo).resolve(), Path(output).resolve()
    require(git(repo, 'rev-parse', BASE+'^{commit}') == BASE, 'missing_exact_base')
    require(git(repo, 'rev-parse', TARGET+'^{commit}') == TARGET, 'missing_exact_target')
    self_content = Path(__file__).read_bytes()
    try:
        committed = subprocess.check_output(['git','-C',str(repo),'show','HEAD:scripts/infra/reward_shop_release.py'],timeout=30)
        require(committed == self_content, 'builder_not_committed_or_dirty')
    except subprocess.CalledProcessError:
        raise Refused('builder_not_committed')
    with tempfile.TemporaryDirectory(prefix='reward-shop-build-') as d:
        d = Path(d)
        archive(repo, BASE, d/'before')
        archive(repo, TARGET, d/'after')
        old = scan(d/'before'/APP_REL)
        new = scan(d/'after'/APP_REL)
        require(scan(d/'before'/THEME_REL) == scan(d/'after'/THEME_REL), 'theme_changed')
        require(set(old) <= set(new), 'deleted_app_file')
        changed = sorted(path for path in new if old.get(path) != new[path])
        require(set(changed) == EXPECTED, 'qualified_reward_shop_delta_changed:' +
            ','.join(sorted(set(changed)^EXPECTED)))
        current_version = (d/'after'/APP_REL/'version.php').read_text()
        require(re.search(r'\$plugin->version\s*=\s*2026111100\s*;', current_version) is not None,
            'target_version_mismatch')
        old_version = (d/'before'/APP_REL/'version.php').read_text()
        require(re.search(r'\$plugin->version\s*=\s*2026110200\s*;', old_version) is not None,
            'base_version_mismatch')
        xml = (d/'after'/APP_REL/'db/install.xml').read_text()
        upgrade = (d/'after'/APP_REL/'db/upgrade.php').read_text()
        require(all(name in xml and name in upgrade for name in TABLES), 'reward_schema_missing')
        for path in changed:
            if path.endswith('.php'):
                run(['php','-l',str(d/'after'/APP_REL/path)], timeout=15)
        payload = {path:(d/'after'/APP_REL/path).read_bytes() for path in changed}
        manifest = {
            'schema':1, 'baseline_commit':BASE, 'target_commit':TARGET,
            'before_version':BEFORE, 'after_version':AFTER,
            'application_root':str(HOST_APP), 'before':old, 'after':new,
            'changed':changed, 'payload_sha256':{p:digest(v) for p,v in payload.items()},
            'theme_touched':False, 'worker_touched':False,
            'installer_revision':'lock-fix-v3-20261111',
            'cli_root':CONTAINER_CLI,
            'managed_lock_policy':'bounded_readonly_retry_and_exclusive_update',
            'previous_journal_policy':'require_rolled_back_before_migration_or_absent',
            'change_scope':'installer_only_no_application_payload_change',
        }
        output.mkdir(parents=True, exist_ok=True)
        result = output/f'ustar-reward-shop-{TARGET[:8]}-lock-fix-v3.tar.gz'
        packfiles = {
            'update.py':self_content,
            'INSTALL.json':(json.dumps(manifest, ensure_ascii=False, sort_keys=True, indent=2)+'\n').encode(),
            **{'payload/'+p:data for p,data in payload.items()},
        }
        packfiles['SHA256SUMS'] = ''.join(f'{digest(b)}  {p}\n' for p,b in sorted(packfiles.items())).encode()
        with open(result,'wb') as f:
            with gzip.GzipFile(fileobj=f, mode='wb',filename='',mtime=0) as zipped:
                with tarfile.open(fileobj=zipped,mode='w') as tar:
                    for name, data in sorted(packfiles.items()):
                        entry=tarfile.TarInfo(name)
                        entry.uid=entry.gid=entry.mtime=0
                        entry.mode=0o600
                        entry.size=len(data)
                        tar.addfile(entry,io.BytesIO(data))
        result.with_suffix(result.suffix+'.sha256').write_text(digest(result)+'  '+result.name+'\n')
        return {'archive':result.name,'bytes':result.stat().st_size,'sha256':digest(result),
                'changed_files':len(changed),'baseline_files':len(old),
                'baseline_commit':BASE,'target_commit':TARGET}

def load_package(folder):
    folder=Path(folder)
    require(folder.is_dir() and not folder.is_symlink(),'invalid_package_dir')
    manifest_path=folder/'INSTALL.json'
    hashes_path=folder/'SHA256SUMS'
    require(manifest_path.is_file() and hashes_path.is_file(),'missing_package_manifest')
    require(not manifest_path.is_symlink() and not hashes_path.is_symlink(),'package_symlink')
    require(digest(Path(__file__)) in hashes_path.read_text(), 'operator_hash_mismatch')
    expected={}
    for line in hashes_path.read_text().splitlines():
        sha,sep,name=line.partition('  ')
        require(sep and re.fullmatch('[a-f0-9]{64}',sha) and name not in expected,'invalid_checksum_list')
        expected[name]=sha
    require('INSTALL.json' in expected and 'update.py' in expected,'missing_operator_checksums')
    for name,sha in expected.items():
        path=safe_path(folder,name)
        require(path.is_file() and not path.is_symlink() and digest(path)==sha,
            'package_digest_mismatch:'+name)
    manifest=json.loads(manifest_path.read_text())
    require(manifest['schema']==1 and manifest['baseline_commit']==BASE and
            manifest['target_commit']==TARGET and manifest['before_version']==BEFORE and
            manifest['after_version']==AFTER and manifest['application_root']==str(HOST_APP),
            'manifest_identity_mismatch')
    require(set(manifest['changed'])==EXPECTED and set(manifest['before']) <= set(manifest['after']) and
            set(manifest['changed'])=={p for p in manifest['after'] if manifest['before'].get(p)!=manifest['after'][p]},
            'manifest_diff_mismatch')
    require({p:manifest['after'][p] for p in manifest['changed']}==manifest['payload_sha256'],
            'payload_manifest_mismatch')
    require(set(expected)=={'INSTALL.json','update.py'}|{'payload/'+p for p in manifest['changed']},
            'package_payload_mismatch')
    return manifest

def db_status():
    php = '''define("CLI_SCRIPT",true); require "/var/www/html/public/config.php";
$names=["local_ustar_reward_shop_offer","local_ustar_reward_shop_order"];
$out=["version"=>(int)get_config("local_ustar","version"),"tables"=>[]];
foreach($names as $n){$out["tables"][$n]=$DB->get_manager()->table_exists(new xmldb_table($n));}
$v=get_config("local_ustar","reward_shop_settings");
$out["shop_setting"]=$v===false?null:$v;
echo json_encode($out,JSON_THROW_ON_ERROR);'''
    data=run(['docker','exec','-u','www-data','ustar_moodle','php','-r',php],timeout=30).stdout
    return json.loads(data.decode())

def code_status(manifest):
    local=HOST_APP/'local/ustar'
    require(local.is_dir() and not local.is_symlink(),'missing_live_plugin')
    curr=int(re.search(r'\$plugin->version\s*=\s*(\d+)',(local/'version.php').read_text()).group(1))
    def matches(index):
        drift=[]
        for p,sha in index.items():
            f=safe_path(local,p)
            if not f.is_file() or f.is_symlink() or digest(f)!=sha:
                drift.append(p)
                if len(drift)>6:break
        return drift
    return curr, matches

def relink_status():
    require(RELINK.is_file() and not RELINK.is_symlink(), 'missing_relink_operator')
    # Relink's --check acquires SH nonblocking managed locks. A concurrent
    # backup/cron/operator can temporarily hold EX; never bypass this gate.
    command=['/usr/bin/python3','-I','-B',str(RELINK),'--check']
    for attempt in range(5):
        p=run(command, timeout=60, check=False)
        try:
            status=json.loads(p.stdout.decode('utf-8','replace').splitlines()[-1])
        except (ValueError,IndexError):
            raise Refused('invalid_relink_check:'+str(p.returncode)) from None
        if p.returncode==0:
            require(status.get('phase')=='active','relink_not_active')
            return status
        reason=status.get('reason','unknown')
        require(isinstance(reason,str) and re.fullmatch('[a-zA-Z0-9_:/.-]+',reason),
            'relink_unexpected_refusal')
        if reason!='managed_operation_busy_retry_later':
            raise Refused('relink_check_refused:'+reason)
        if attempt==4:
            raise Refused('managed_operation_busy_retry_later')
        time.sleep((1, 2, 3, 4)[attempt])
    raise Refused('relink_check_unreachable')

@contextmanager
def exclusive_operator_guards():
    # Same ordered shared lock inventory as the installed Connect overlay.
    # Nonblocking EX must be held through the full backup/code/DB transaction.
    held=[]
    try:
        for p in GUARD_PATHS:
            # Relink's own lock is optional in check(), but its shared locks
            # are required. Match that exact contract without creating files.
            if p==GUARD_PATHS[0] and not os.path.lexists(p):
                continue
            require(p.is_file() and not p.is_symlink(),'managed_lock_missing:'+str(p))
            fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
            held.append((p,fd))
            current=os.fstat(fd);live=p.lstat()
            require(stat.S_ISREG(current.st_mode) and current.st_uid==os.geteuid()
                and current.st_nlink==1 and stat.S_IMODE(current.st_mode)==0o600
                and (current.st_dev,current.st_ino)==(live.st_dev,live.st_ino),
                'unsafe_managed_lock:'+str(p))
            try:
                fcntl.flock(fd, fcntl.LOCK_EX|fcntl.LOCK_NB)
            except BlockingIOError:
                raise Refused('managed_operation_busy_retry_later') from None
        yield
        for p,fd in held:
            current=os.fstat(fd);live=p.lstat()
            require((current.st_dev,current.st_ino)==(live.st_dev,live.st_ino),
                'managed_lock_replaced:'+str(p))
    finally:
        for _,fd in reversed(held):os.close(fd)

def inspect(manifest, *, exact_before=False):
    require(os.geteuid()==0,'root_required')
    require(Path('/proc/self/mountinfo').is_file(),'host_linux_required')
    require(shutil.which('docker') is not None,'docker_required')
    current, matches=code_status(manifest)
    db=db_status()
    if current==AFTER and db['version']==AFTER:
        require(not matches(manifest['after']),'target_code_drift')
        require(all(db['tables'].get(t) is True for t in TABLES),'target_schema_missing')
        return {'phase':'active','local_ustar':AFTER,'db_version':AFTER,'schema_changed':True,'shop_enabled':
            bool(json.loads(db['shop_setting']).get('enabled')) if db['shop_setting'] else False}
    require(current==BEFORE and db['version']==BEFORE,'unexpected_code_db_versions')
    drift=matches(manifest['before'])
    require(not drift,'baseline_code_drift:'+','.join(drift))
    require(all(db['tables'].get(t) is False for t in TABLES),'unexpected_reward_tables')
    relink_status()
    predecessor_status()
    cli_preflight()
    return {'phase':'ready','local_ustar':BEFORE,'db_version':BEFORE,'schema_changed':False,
        'code_drift':False,'relink':'active','cli_root':CONTAINER_CLI,'previous_attempt':predecessor_status()}

def write_state(record):
    STATE_DIR.mkdir(mode=0o700,parents=True,exist_ok=True)
    require(not STATE_DIR.is_symlink(),'unsafe_journal_directory')
    tmp=STATE_DIR/'state.json.tmp'
    with open(tmp,'w') as f:
        json.dump(record,f,sort_keys=True)
        f.flush();os.fsync(f.fileno())
    os.chmod(tmp,0o600)
    os.replace(tmp,STATE_DIR/'state.json')

def read_state():
    p=STATE_DIR/'state.json'
    return json.loads(p.read_text()) if p.is_file() and not p.is_symlink() else None

def cli_preflight():
    # Moodle's CLI lives outside the web-visible public/ document root.
    # Validate the actual container filesystem BEFORE taking a backup or enabling maintenance.
    scripts = ('maintenance.php','upgrade.php','purge_caches.php')
    for name in scripts:
        cmd=['docker','exec','-u','www-data','ustar_moodle','test','-f',CONTAINER_CLI+'/'+name]
        result=run(cmd,timeout=20,check=False)
        require(result.returncode==0,'moodle_cli_missing:'+CONTAINER_CLI+'/'+name)
    return CONTAINER_CLI

def predecessor_status():
    # Original installer attempted maintenance at the wrong path and must have rolled back.
    # Leave its journal/backup untouched, never pretend recovery_required was rolled back.
    if not PREVIOUS_STATE.exists() and not PREVIOUS_STATE.is_symlink():
        return 'none'
    require(PREVIOUS_STATE.is_file() and not PREVIOUS_STATE.is_symlink(),
        'previous_journal_not_regular')
    previous=json.loads(PREVIOUS_STATE.read_text())
    require(previous.get('phase')=='rolled_back_before_migration' and
        previous.get('database_upgrade_started') is False and
        previous.get('target_commit')==TARGET,
        'previous_attempt_requires_manual_review')
    backup_path=Path(previous.get('backup',''))
    require(backup_path.parent==BACKUP_DIR and backup_path.name.startswith('ustar-reward-shop-') and
        not backup_path.is_symlink() and backup_path.is_dir() and
        (backup_path/'postgres-before.dump').is_file() and
        (backup_path/'local-ustar-before.tar.gz').is_file(),
        'previous_attempt_backup_missing')
    return 'rolled_back_before_migration'

def cli(script, timeout=120):
    return run(['docker','exec','-u','www-data','ustar_moodle','php',
         cli_preflight()+'/'+script],timeout=timeout)

def backup(manifest):
    BACKUP_DIR.mkdir(mode=0o700,parents=True,exist_ok=True)
    require(not BACKUP_DIR.is_symlink(),'unsafe_backup_directory')
    timestamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    path=BACKUP_DIR/f'ustar-reward-shop-{timestamp}'
    path.mkdir(mode=0o700)
    (path/'INSTALL.json').write_text(json.dumps(manifest,sort_keys=True))
    os.chmod(path/'INSTALL.json',0o600)
    with tarfile.open(path/'local-ustar-before.tar.gz','w:gz',dereference=False) as tar:
        tar.add(HOST_APP/'local/ustar',arcname='local/ustar',recursive=True)
    dump=path/'postgres-before.dump'
    cmd=['docker','exec','ustar_postgres','sh','-ec',
         'exec pg_dump -Fc -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"']
    run(cmd,output_file=dump,timeout=600)
    require(dump.stat().st_size>512,'empty_database_dump')
    run(['docker','exec','-i','ustar_postgres','pg_restore','-l'],input_file=dump,timeout=120)
    os.chmod(dump,0o600)
    return str(path)

def replace_code(folder,manifest):
    root=HOST_APP/'local/ustar'
    written=[]
    for rel in manifest['changed']:
        src=safe_path(Path(folder)/'payload',rel)
        dst=safe_path(root,rel)
        dst.parent.mkdir(parents=True,exist_ok=True)
        info=dst.stat() if dst.exists() else dst.parent.stat()
        tmp=dst.with_name(dst.name+'.ustar-shop-tmp')
        require(not tmp.exists() and not tmp.is_symlink(),'temporary_target_exists')
        try:
            with open(src,'rb') as i,open(tmp,'xb') as o:
                shutil.copyfileobj(i,o)
                o.flush();os.fsync(o.fileno())
            os.chmod(tmp,stat.S_IMODE(info.st_mode) if dst.exists() else 0o644)
            os.chown(tmp,info.st_uid,info.st_gid)
            os.replace(tmp,dst)
        finally:
            if tmp.exists():tmp.unlink()
        written.append(rel)
    return written

def restore_code(backup_path, manifest):
    path=Path(backup_path)/'local-ustar-before.tar.gz'
    require(path.is_file(),'missing_code_backup')
    with tarfile.open(path,'r:gz') as tar:
        for member in tar:
            require(member.name.startswith('local/ustar/') or member.name=='local/ustar',
                'unexpected_backup_entry')
            require(member.isfile() or member.isdir(), 'unexpected_backup_type')
        tar.extractall(HOST_APP,filter='fully_trusted')
    for rel in manifest['changed']:
        if rel not in manifest['before']:
            f=safe_path(HOST_APP/'local/ustar', rel)
            if f.exists(): f.unlink()

def php_lint(manifest):
    for rel in manifest['changed']:
        if rel.endswith('.php'):
            run(['docker','exec','-u','www-data','ustar_moodle','php','-l',
                CONTAINER_APP+'/local/ustar/'+rel],timeout=25)

def upgrade(folder,manifest):
    pre=inspect(manifest)
    require(pre['phase']=='ready','not_ready')
    require(read_state() is None,'previous_journal_exists')
    predecessor_status()
    cli_preflight()
    with exclusive_operator_guards():
        return upgrade_locked(folder,manifest)

def upgrade_locked(folder,manifest):
    # Preflight was outside the exclusive lock, so revalidate the entire mutable
    # state under it to eliminate a race with a second operator after preflight.
    require(read_state() is None,'previous_journal_exists')
    version,matches=code_status(manifest)
    db=db_status()
    require(version==BEFORE and not matches(manifest['before']),
        'baseline_changed_while_acquiring_lock')
    require(db['version']==BEFORE and all(db['tables'].get(t) is False for t in TABLES),
        'db_changed_while_acquiring_lock')
    backup_path=backup(manifest)
    state={'phase':'backup_complete','backup':backup_path,'previous_attempt':predecessor_status(),'baseline_commit':BASE,
           'target_commit':TARGET,'database_upgrade_started':False}
    write_state(state)
    maintenance=False
    try:
        run(['docker','exec','-u','www-data','ustar_moodle','php',
            cli_preflight()+'/maintenance.php','--enable'],timeout=60)
        maintenance=True
        state['phase']='maintenance';write_state(state)
        replace_code(folder,manifest)
        state['phase']='code_applied';write_state(state)
        php_lint(manifest)
        state['phase']='migration_started';state['database_upgrade_started']=True;write_state(state)
        run(['docker','exec','-u','www-data','ustar_moodle','php',
            cli_preflight()+'/upgrade.php','--non-interactive'],timeout=900)
        state['phase']='migration_finished';write_state(state)
        after=inspect(manifest)
        require(after['phase']=='active' and not after['shop_enabled'],'unexpected_post_upgrade_state')
        run(['docker','exec','-u','www-data','ustar_moodle','php',
            cli_preflight()+'/purge_caches.php'],timeout=120)
        run(['docker','exec','-u','www-data','ustar_moodle','php',
            cli_preflight()+'/maintenance.php','--disable'],timeout=60)
        state['phase']='active';write_state(state)
        return {'phase':'active','backup':backup_path,'local_ustar':AFTER,
            'schema_changed':True,'shop_enabled':False,'theme_ustar':BEFORE,'worker_changed':False}
    except BaseException as error:
        state['error']=str(error)[:600]
        if not state['database_upgrade_started']:
            try:
                restore_code(backup_path,manifest)
                if maintenance:
                    run(['docker','exec','-u','www-data','ustar_moodle','php',
                         cli_preflight()+'/maintenance.php','--disable'],timeout=60)
                state['phase']='rolled_back_before_migration'
                write_state(state)
            except BaseException as rollback:
                state['phase']='recovery_required';state['rollback_error']=str(rollback)[:400]
                write_state(state)
        else:
            state['phase']='recovery_required';write_state(state)
        raise

def finish(manifest):
    state=read_state()
    require(state and state.get('database_upgrade_started'), 'no_migration_to_finish')
    result=inspect(manifest)
    require(result['phase']=='active' and not result['shop_enabled'], 'finish_requires_target_schema')
    run(['docker','exec','-u','www-data','ustar_moodle','php',
        cli_preflight()+'/purge_caches.php'],timeout=120)
    run(['docker','exec','-u','www-data','ustar_moodle','php',
        cli_preflight()+'/maintenance.php','--disable'],timeout=60)
    state['phase']='active';state.pop('error',None);write_state(state)
    return {'phase':'active','local_ustar':AFTER,'backup':state['backup']}

def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    sub=parser.add_subparsers(dest='mode',required=True)
    b=sub.add_parser('build');b.add_argument('--repo',required=True);b.add_argument('--output',required=True)
    for mode in ('verify','check','upgrade','finish'):
        cmd=sub.add_parser(mode)
        if mode in ('upgrade','finish'):
            cmd.add_argument('--acknowledge-outage',action='store_true')
    args=parser.parse_args(argv)
    os.umask(0o077)
    try:
        if args.mode in ('upgrade','finish'):
            require(args.acknowledge_outage,'outage_acknowledgement_required')
        if args.mode=='build':out=build(args.repo,args.output)
        else:
            manifest=load_package(Path(__file__).absolute().parent)
            if args.mode=='verify':out={'phase':'package_verified','baseline_files':len(manifest['before']),
                'target_files':len(manifest['after']),'changed_files':len(manifest['changed']),
                'baseline_commit':BASE,'target_commit':TARGET}
            elif args.mode=='check':out=inspect(manifest)
            elif args.mode=='upgrade':out=upgrade(Path(__file__).absolute().parent,manifest)
            else:out=finish(manifest)
        print(json.dumps(out,sort_keys=True,ensure_ascii=False),flush=True)
        return 0
    except BaseException as exc:
        state=read_state() if args.mode in ('upgrade','finish') else None
        status={'phase': 'recovery_required' if state and state.get('phase')=='recovery_required' else 'refused',
                'reason':str(exc)[:1000]}
        if state and 'backup' in state:status['backup']=state['backup']
        print(json.dumps(status,sort_keys=True,ensure_ascii=False),flush=True)
        return 2

if __name__=='__main__':sys.exit(main())