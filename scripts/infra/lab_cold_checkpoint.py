#!/usr/bin/env python3
"""Create a paired cold checkpoint and boot an independent patch workspace.

Default is plan only. --check repeats the pinned read-only planning guard.
--prepare-stage stops only the retained lab for copying code/config/data/PG,
verifies the cold copy, restarts the same source containers, and boots a second
offline lab from a separate copy. No core upgrade, production exec/SQL/outage,
image pull/load, archive/key reads, cron, file deletion or checkpoint overwrite.
"""
import argparse
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import signal
import socket
import stat
import sys
import time
import urllib.request
import uuid
from datetime import datetime, timezone

PREFLIGHT = Path('/usr/local/sbin/USTAR_LAB_PATCH_PREFLIGHT_20261005.py')
PREFLIGHT_SHA = '075c454aa58be4209466864dae734ea140c024d7ed5b82863ebfac7e08cc3d8b'
SCRIPT = Path('/usr/local/sbin/USTAR_LAB_COLD_CHECKPOINT_20261005.py')
SOURCE = Path('/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72')
CHECKPOINT = SOURCE / 'patch-rollback-20261005'
WORK = SOURCE / 'patch-stage-20261005'
NAMES = {'pg': 'ustar_patch_stage_20261005_pg', 'web': 'ustar_patch_stage_20261005_web'}
UNIT = 'ustar-patch-stage-20261005-http.service'
PORT = 18085
LABEL = 'ustar.patch.stage'
CONTROLS = ('state.json', 'pg.env', 'lab.ini', 'apache.conf', 'report.json')
TREES = ('site/public', 'site/moodledata', 'postgres')
GIB = 1024 ** 3
RESERVE = 4 * GIB


class CheckpointError(Exception):
    pass


def require(ok, message):
    if not ok:
        raise CheckpointError(message)


def guarded(call, message):
    try:
        return call()
    except Exception:
        raise CheckpointError(message) from None


def installed(path):
    for current in (path, *path.parents):
        info = current.lstat()
        require((stat.S_ISREG(info.st_mode) if current == path else stat.S_ISDIR(info.st_mode))
                and info.st_uid == 0 and not info.st_mode & 0o022
                and (current != path or info.st_nlink == 1), 'Untrusted installed checkpoint input')


def load_preflight():
    installed(PREFLIGHT)
    data = PREFLIGHT.read_bytes()
    require(hashlib.sha256(data).hexdigest() == PREFLIGHT_SHA, 'Planning dependency checksum mismatch')
    spec = importlib.util.spec_from_file_location('ustar_cold_checked_preflight', PREFLIGHT)
    p = importlib.util.module_from_spec(spec)
    exec(compile(data, str(PREFLIGHT), 'exec'), p.__dict__)
    require(p.ROOT == SOURCE, 'Planning dependency namespace mismatch')
    return p


def stage_engine(resume):
    lab, _ = guarded(resume.load_engine, 'Pinned stage engine validation failed')
    lab.ROOT, lab.STATE, lab.SCRIPT = WORK, WORK / 'state.json', SCRIPT
    lab.NAMES, lab.UNIT, lab.PORT, lab.LABEL = dict(NAMES), UNIT, PORT, LABEL
    require(set(NAMES.values()).isdisjoint(lab.PRODUCTION), 'Stage namespace overlaps production')
    return lab


def absent(path):
    require(not path.exists() and not path.is_symlink(), 'Checkpoint/workspace already exists; no overwrite')


def reserve():
    require(shutil.disk_usage(SOURCE).free >= RESERVE, 'Free space reached the 4 GiB reserve')


def workspace_budget(plan):
    old = plan['storage_plan']
    # A frozen rollback plus a full mutable restore workspace are separate copies.
    needed = old['required_free_bytes'] + old['rollback_copy_allowance_bytes']
    entries = sum(v['files'] + v['directories'] for v in plan['sizes_live_non_atomic'].values())
    inodes = old['required_free_inodes'] + entries
    free = shutil.disk_usage(SOURCE).free
    free_inodes = os.statvfs(SOURCE).f_favail
    require(free >= needed, 'Insufficient space for checkpoint + restore workspace + candidate/work + reserve')
    require(free_inodes >= inodes, 'Insufficient inodes for checkpoint and restore workspace')
    return {'required_free_bytes': needed, 'available_free_bytes': free,
            'minimum_free_after_bytes': RESERVE, 'required_free_inodes': inodes,
            'available_free_inodes': free_inodes, 'additional_restore_workspace_bytes': old['rollback_copy_allowance_bytes'],
            'planning_estimate_not_capacity_guarantee': True}


def precheck(p, resume, source, template, profile, stage):
    require(p.ROOT == SOURCE and source.ROOT == SOURCE
            and set(source.NAMES.values()).isdisjoint(source.PRODUCTION),
            'Source namespace mismatch')
    plan = guarded(lambda: p.check(resume, source, template, profile),
                   'Source planning check failed; repeat the installed planning helper --check for diagnostics')
    absent(CHECKPOINT)
    absent(WORK)
    require(all(stage.inspect(name, absent_ok=True) is None for name in NAMES.values()),
            'Stage container name occupied; refused operation')
    stage.relay_unit_absent()
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', PORT))
    available = int(Path('/proc/meminfo').read_text().split('MemAvailable:')[1].split()[0]) * 1024
    require(available >= 6 * GIB, 'Insufficient RAM for both labs and host reserve')
    for kind, image in source.load_state()['images'].items():
        rows = json.loads(source.command(['docker', 'image', 'inspect', image]).stdout)
        require(len(rows) == 1 and rows[0]['Id'] == image and rows[0]['Os'] == 'linux'
                and rows[0]['Architecture'] == 'amd64'
                and rows[0]['Config'].get('Entrypoint') ==
                (['docker-entrypoint.sh'] if kind == 'pg' else ['docker-php-entrypoint'])
                and rows[0]['Config'].get('Cmd') == (['postgres'] if kind == 'pg' else ['apache2-foreground']),
                'Source immutable image missing or unsupported')
    return plan, workspace_budget(plan)


def metadata(info, directory=False, digest=None):
    result = {'type': 'directory' if directory else 'file', 'uid': info.st_uid, 'gid': info.st_gid,
              'mode': stat.S_IMODE(info.st_mode)}
    if not directory:
        result.update(size=info.st_size, sha256=digest)
    return result


def signature(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns)


def copy_file(src, dest, info):
    reserve()
    fd = os.open(src, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        require(signature(os.fstat(fd)) == signature(info), 'Cold source file changed before copy')
        outfd = os.open(dest, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(outfd, 'wb') as out:
            digest, size = hashlib.sha256(), 0
            with os.fdopen(os.dup(fd), 'rb') as incoming:
                for block in iter(lambda: incoming.read(1024 ** 2), b''):
                    size += len(block)
                    require(size <= info.st_size, 'Cold source file grew during copy')
                    out.write(block)
                    digest.update(block)
                    reserve()
            require(size == info.st_size and signature(os.fstat(fd)) == signature(info),
                    'Cold source file changed during copy')
            os.fchown(out.fileno(), info.st_uid, info.st_gid)
            os.fchmod(out.fileno(), stat.S_IMODE(info.st_mode))
            out.flush()
            os.fsync(out.fileno())
        return metadata(info, digest=digest.hexdigest())
    finally:
        os.close(fd)


def create_directory(dest, info):
    dest.mkdir(mode=0o700)
    os.chown(dest, info.st_uid, info.st_gid)
    os.chmod(dest, stat.S_IMODE(info.st_mode))
    return metadata(info, directory=True)


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def copy_tree(p, src, dest, entries, prefix, kind):
    def checked(path, directory):
        if kind == 'moodledata':
            return p.moodledata_entry(path, directory=directory)
        return p.trusted(path, directory=directory, root_owned=kind == 'code')
    first = checked(src, True)
    entries[prefix] = create_directory(dest, first)
    def onerror(_):
        raise CheckpointError('Cold source scan incomplete')
    for base, dirs, files in os.walk(src, followlinks=False, onerror=onerror):
        for name in dirs + files:
            path = Path(base) / name
            info = checked(path, name in dirs)
            require(info.st_dev == first.st_dev, 'Nested filesystem in cold source')
            relative = path.relative_to(src)
            target = dest / relative
            key = (PurePosixPath(prefix) / relative.as_posix()).as_posix()
            entries[key] = create_directory(target, info) if name in dirs else copy_file(path, target, info)


def copy_source(p):
    CHECKPOINT.mkdir(mode=0o700)
    entries = {'site': create_directory(CHECKPOINT / 'site', p.trusted(SOURCE / 'site', directory=True))}
    for name, kind in zip(TREES, ('code', 'moodledata', 'postgres')):
        copy_tree(p, SOURCE / name, CHECKPOINT / name, entries, name, kind)
    for name in CONTROLS:
        entries[name] = copy_file(SOURCE / name, CHECKPOINT / name, p.trusted(SOURCE / name, private=True))
    verify_entries(p, CHECKPOINT, entries)
    for key in sorted(entries, key=lambda value: len(PurePosixPath(value).parts), reverse=True):
        if entries[key]['type'] == 'directory':
            sync_directory(CHECKPOINT / key)
    sync_directory(CHECKPOINT)
    sync_directory(SOURCE)
    return entries


def relative(value):
    require(isinstance(value, str) and value, 'Invalid checkpoint entry path')
    path = PurePosixPath(value)
    require(not path.is_absolute() and path.as_posix() == value and '\\' not in value
            and not {'.', '..'} & set(path.parts) and '\0' not in value, 'Invalid checkpoint entry path')
    return path


def verify_entries(p, root, entries, extra=()):
    p.trusted(root, directory=True, private=True)
    require(isinstance(entries, dict) and 0 < len(entries) <= 100000, 'Unsupported checkpoint inventory')
    actual = set()
    def onerror(_):
        raise CheckpointError('Checkpoint verification scan incomplete')
    for base, dirs, files in os.walk(root, followlinks=False, onerror=onerror):
        for name in dirs + files:
            path = Path(base) / name
            key = path.relative_to(root).as_posix()
            if key in extra:
                continue
            actual.add(key)
            require(key in entries, 'Unexpected entry in checkpoint copy')
            expected = entries[key]
            info = path.lstat()
            directory = expected.get('type') == 'directory'
            require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
                    and info.st_uid == expected['uid'] and info.st_gid == expected['gid']
                    and stat.S_IMODE(info.st_mode) == expected['mode']
                    and (directory or info.st_nlink == 1) and info.st_dev == root.stat().st_dev,
                    'Checkpoint metadata or filesystem differs')
            if not directory:
                require(info.st_size == expected['size'] and p.file_hash(path) == expected['sha256'],
                        'Checkpoint file hash differs')
    require(actual == set(entries), 'Checkpoint entries missing')


def verify_checkpoint(p):
    p.trusted(CHECKPOINT / 'manifest.json', private=True)
    require((CHECKPOINT / 'manifest.json').stat().st_size <= 32 * 1024 ** 2, 'Checkpoint manifest too large')
    report = json.loads((CHECKPOINT / 'manifest.json').read_bytes())
    require(report.get('schema_version') == 1 and report.get('complete') is True
            and report.get('source') == str(SOURCE) and report.get('source_core_db_version') == '2025100601.03',
            'No complete recognized cold checkpoint')
    for key in report['entries']:
        relative(key)
    verify_entries(p, CHECKPOINT, report['entries'], extra=('manifest.json',))
    return report


def restart_source(resume, lab, state):
    errors = []
    for kind in ('pg', 'web'):
        try:
            container = lab.owned(kind, state)
            if not container['State']['Running']:
                lab.command(['docker', 'start', container['Id']], timeout=120)
            if kind == 'pg':
                resume.wait_database(lab, state)
        except BaseException as error:
            errors.append(type(error).__name__)
    require(not errors, 'Source lab restart incomplete; use --resume-source with the same helper')
    require(all(lab.owned(kind, state)['State']['Running'] for kind in ('pg', 'web')),
            'Source lab did not resume')
    lab.assert_offline(state)
    resume.check_database(lab, state)


def cold_checkpoint(p, resume, lab, state, before):
    try:
        for kind in ('web', 'pg'):
            c = lab.owned(kind, state)
            require(c['State']['Running'], 'Source lab unexpectedly stopped')
            lab.command(['docker', 'stop', '--time', '60', c['Id']], timeout=90)
            stopped = lab.owned(kind, state)['State']
            require(not stopped['Running'] and stopped['ExitCode'] == 0
                    and not stopped.get('OOMKilled', False) and not stopped.get('Error'),
                    'Source container did not stop cleanly; no cold copy accepted')
        require(not (SOURCE / 'postgres/postmaster.pid').exists()
                and not (SOURCE / 'postgres/postmaster.pid').is_symlink(),
                'PostgreSQL shutdown marker still present; no cold copy accepted')
        print('COPYING_COLD_LAB; code/config/Moodledata/PostgreSQL; production remains running', flush=True)
        entries = copy_source(p)
        manifest = {'schema_version': 1, 'complete': True, 'source': str(SOURCE),
                    'source_core_db_version': '2025100601.03', 'created_at': datetime.now(timezone.utc).isoformat(),
                    'source_lab_cleanly_stopped_for_copy': True, 'entries': entries}
        lab.write_private(CHECKPOINT / 'manifest.json', json.dumps(manifest, sort_keys=True))
        sync_directory(CHECKPOINT)
        verify_checkpoint(p)
        return manifest
    finally:
        restart_source(resume, lab, state)
        require(lab.production_state() == before, 'Production identity/start time changed during checkpoint')


def copy_workspace(p, manifest):
    WORK.mkdir(mode=0o700)
    entries = manifest['entries']
    for key in sorted(entries, key=lambda value: (len(relative(value).parts), value)):
        expected = entries[key]
        path = Path(relative(key))
        src, target = CHECKPOINT / path, WORK / path
        info = src.lstat()
        directory = expected['type'] == 'directory'
        require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
                and metadata(info, directory=directory, digest=expected.get('sha256')) == expected,
                'Checkpoint metadata changed before workspace copy')
        if expected['type'] == 'directory':
            create_directory(target, info)
        else:
            copied = copy_file(src, target, info)
            require(copied == expected, 'Checkpoint changed during workspace copy')
    verify_entries(p, WORK, entries)


def stage_config(p, lab):
    path = WORK / 'site/public/config.php'
    text = path.read_text()
    replacements = {"$CFG->wwwroot = 'http://127.0.0.1:18084';": "$CFG->wwwroot = 'http://127.0.0.1:18085';",
                    "$CFG->sessioncookie = 'USTAR_RECOVERY_LAB_20261004';":
                    "$CFG->sessioncookie = 'USTAR_PATCH_STAGE_20261005';"}
    for old, new in replacements.items():
        require(text.count(old) == 1, 'Unexpected copied lab config')
        text = text.replace(old, new)
    # The immutable checkpoint retains the original; replace only this new workspace file.
    temp = WORK / 'stage-config.tmp'
    lab.write_private(temp, text)
    os.chown(temp, 0, 33)
    os.chmod(temp, 0o640)
    os.replace(temp, path)
    require(path.read_text() == text, 'Stage configuration write differs')


def http_check():
    base = 'http://127.0.0.1:18085/'
    class LocalRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, request, fp, code, message, headers, url):
            require(url.startswith(base), 'Stage attempted an external redirect')
            return super().redirect_request(request, fp, code, message, headers, url)
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), LocalRedirect())
    for _ in range(30):
        try:
            with opener.open(base + 'login/index.php', timeout=10) as response:
                body = response.read(2 * 1024 ** 2 + 1)
                require(response.geturl().startswith(base) and len(body) <= 2 * 1024 ** 2
                        and response.status == 200 and re.search(rb'name=[\'\"]username[\'\"]', body)
                        and re.search(rb'name=[\'\"]password[\'\"]', body), 'Stage login form validation failed')
                return 200
        except OSError:
            time.sleep(1)
    raise CheckpointError('Stage login form readiness timeout')


def start_workspace(p, resume, stage, state, profile, before, manifest, planned):
    # New state/labels/namespace; never mount or start the frozen checkpoint itself.
    original = state
    state = json.loads(json.dumps(original))
    state.update(token=uuid.uuid4().hex, containers={}, ready=False, stopped=False, relay_requested=False)
    stage.save_state(state)
    log = WORK / 'prepare-private.log'
    stage.write_private(log, b'')
    stage._LOG = log.open('ab')
    try:
        stage.create_container('pg', state)
        resume.wait_database(stage, state)
        p.readonly_database(stage, state, profile)
        resume.check_database(stage, state)
        references = guarded(lambda: stage.check_file_references(state), 'Restored workspace file references differ')
        stage.create_container('web', state)
        stage.assert_offline(state)
        stage.lab_exec('web', state, ['php', '-l', '/var/www/html/config.php'], user='33:33')
        php = "define('CLI_SCRIPT',true); require '/var/www/html/config.php'; " \
              "require_once($CFG->libdir.'/upgradelib.php'); echo json_encode([" \
              "'dbhost'=>$CFG->dbhost,'dbname'=>$CFG->dbname,'wwwroot'=>$CFG->wwwroot," \
              "'noemail'=>(bool)$CFG->noemailever,'cron'=>(int)$CFG->cron_enabled," \
              "'needs_upgrade'=>moodle_needs_upgrading()]);"
        boot = json.loads(stage.lab_exec('web', state, ['php', '-r', php], user='33:33').stdout)
        require(boot == {'dbhost': '127.0.0.1', 'dbname': 'ustar_recovery_lab',
                         'wwwroot': 'http://127.0.0.1:18085', 'noemail': True, 'cron': 0, 'needs_upgrade': False},
                'Restored workspace baseline bootstrap differs')
        stage.start_relay(state)
        login = http_check()
        stage.command(['systemctl', 'is-active', '--quiet', UNIT])
        require(stage.production_state() == before, 'Production identity/start time changed during stage restore')
        verify_checkpoint(p)  # The workspace runs independently; rollback remains unchanged.
        report = {'kind': 'lab_cold_checkpoint_and_patch_workspace', 'checkpoint': 'PASS',
                  'cold_copy_file_hashes': 'PASS', 'cold_copy_restore_bootstrap': 'PASS',
                  'verified_file_contenthashes': references, 'http_login': login,
                  'lab_network': 'loopback_only', 'email_disabled': True, 'cron_enabled': False,
                  'source_lab_running': True, 'production_containers_unchanged': True,
                  'ustar_matching_files': len(profile['application_files']),
                  'addon_metadata_matching': len(profile['additional_components']),
                  'core_db_version': profile['core_db_version'], 'core_upgrade_executed': False,
                  'patch_upgrade_rollback_verified': False, 'full_addon_compatibility_verified': False,
                  'checkpoint_entries': len(manifest['entries']), 'storage_plan': planned,
                  'relay_expires_after_seconds': 7200, 'manual_login_roles_and_scorm': 'NOT_TESTED'}
        # report.json is a workspace control; the original report is frozen in the checkpoint.
        temp = WORK / 'stage-report.tmp'
        stage.write_private(temp, json.dumps(report, indent=2))
        os.replace(temp, WORK / 'report.json')
        state.update(ready=True, stopped=False, last_prepare_report=str(WORK / 'report.json'))
        stage.save_state(state)
        return report
    except BaseException:
        print('PATCH_WORKSPACE=FAIL; preserving checkpoint/files; stopping only owned stage containers', flush=True)
        try:
            stage.stop_lab(state)
        except BaseException:
            print('STAGE_CLEANUP=INCOMPLETE; source lab and checkpoint remain separate', file=sys.stderr, flush=True)
        raise
    finally:
        stage._LOG.close()
        stage._LOG = None


def prepare(p, resume, source, template, profile, stage):
    _, planned = precheck(p, resume, source, template, profile, stage)
    state, before = source.load_state(), source.production_state()
    began = time.monotonic()
    manifest = cold_checkpoint(p, resume, source, state, before)
    print('LAB_COLD_CHECKPOINT=PASS; source lab resumed; preparing independent restore workspace', flush=True)
    verify_checkpoint(p)
    copy_workspace(p, manifest)
    stage_config(p, stage)
    report = start_workspace(p, resume, stage, state, profile, before, manifest, planned)
    try:
        report['elapsed_seconds'] = round(time.monotonic() - began, 3)
        report['checkpoint_manifest_sha256'] = p.file_hash(CHECKPOINT / 'manifest.json')
        require(source.production_state() == before, 'Production containers changed')
        require(all(source.owned(kind, state)['State']['Running'] for kind in ('pg', 'web')),
                'Source lab stopped during workspace preparation')
        temp = WORK / 'stage-report-final.tmp'
        stage.write_private(temp, json.dumps(report, indent=2))
        os.replace(temp, WORK / 'report.json')
        return report
    except BaseException:
        # A late verification/report error must not leave a stage accepted/running.
        try:
            stage.stop_lab(stage.load_state())
        except BaseException:
            print('STAGE_CLEANUP=INCOMPLETE; source lab and checkpoint remain separate', file=sys.stderr, flush=True)
        raise


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group()
    for name in ('check', 'prepare-stage', 'verify-checkpoint', 'resume-source', 'serve-lab', 'relay-child'):
        modes.add_argument('--' + name, action='store_true', help=argparse.SUPPRESS if name in ('serve-lab', 'relay-child') else None)
    parser.add_argument('--token', default='', help=argparse.SUPPRESS)
    args = parser.parse_args()
    if not any((args.check, args.prepare_stage, args.verify_checkpoint, args.resume_source, args.serve_lab, args.relay_child)):
        print('PLAN_ONLY; --check reads; --prepare-stage creates a cold checkpoint and independent baseline workspace')
        return
    require(os.geteuid() == 0, 'Run with sudo')
    os.umask(0o077)
    sys.dont_write_bytecode = True
    p = load_preflight()
    resume, source, template = p.load_resume()
    stage = stage_engine(resume)
    if args.relay_child:
        stage.relay_child()
        return
    if args.serve_lab:
        stage.serve_lab(args.token)
        return
    installed(SCRIPT)
    p.trusted(SOURCE, directory=True, private=True)
    p.trust_parents(SOURCE)
    p.trusted(p.LOCK, private=True)
    fd = os.open(p.LOCK, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o077
                and info.st_nlink == 1, 'Untrusted coordination lock')
        fcntl.flock(fd, (fcntl.LOCK_SH if args.check or args.verify_checkpoint else fcntl.LOCK_EX) | fcntl.LOCK_NB)
        if args.verify_checkpoint:
            report = verify_checkpoint(p)
            print('LAB_COLD_CHECKPOINT_VERIFY=PASS; entries=' + str(len(report['entries'])))
        elif args.resume_source:
            state, before = source.load_state(), source.production_state()
            for name in CONTROLS:
                p.trusted(SOURCE / name, private=True)
            resume.check_tree(SOURCE / 'site/public', root_owned=True)
            p.check_sources(source, p.load_profile())
            resume.check_config(source, template)
            restart_source(resume, source, state)
            require(source.production_state() == before, 'Production containers changed')
            print('SOURCE_LAB_RESUME=PASS; existing owned containers only')
        else:
            profile = p.load_profile()
            if args.check:
                _, planned = precheck(p, resume, source, template, profile, stage)
                print('LAB_COLD_CHECKPOINT_PREFLIGHT=PASS; read-only; no container/file changes')
                print(json.dumps(planned, indent=2, sort_keys=True))
            else:
                def interrupted(*_):
                    raise KeyboardInterrupt()
                for signum in (signal.SIGTERM, signal.SIGHUP):
                    signal.signal(signum, interrupted)
                report = prepare(p, resume, source, template, profile, stage)
                print('LAB_PATCH_STAGE_BASELINE=PASS; HTTP_LOGIN=200; no core upgrade; production unchanged')
                print('LAB_URL=http://127.0.0.1:18085/; RELAY_SECONDS=7200')
                print(json.dumps(report, indent=2, sort_keys=True))
    finally:
        os.close(fd)


if __name__ == '__main__':
    try:
        main()
    except BaseException as error:
        message = str(error) if isinstance(error, CheckpointError) else type(error).__name__
        print('LAB_COLD_CHECKPOINT_ERROR: ' + message, file=sys.stderr, flush=True)
        sys.exit(1)
