#!/usr/bin/env python3
"""Restore a completed USTAR backup with a chosen runtime or without a timer.

Uses the reviewed private restore engine and bundle verifier, SHA-pinned below.
Selects the last successful HDD manifest or an explicit copied archive/sidecar.
Only new lab containers/data are modified. No production SQL/exec/stop, upgrade,
cron, source permissions changes, network pull or archive/data deletion.
Missing images may be loaded only when Docker has no containers at all.
Run through the documented systemd service: ExecStopPost is crash cleanup.
"""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import stat
import sys
import time
import types
import urllib.request
import uuid

ENGINE = Path('/usr/local/sbin/USTAR_RESTORE_LAB_20261004.py')
ENGINE_SHA = '71f6a1c45c25bfe97fe0be7d0a362bb5a72e742b77c167f3783f526c85950dac'
CHECKER = Path('/usr/local/lib/ustar-recovery/USTAR_RESTORE_PREFLIGHT_20261004.py')
CHECKER_SHA = 'e00577aefcbcdd8ae3ebc1f30f98c899c3efd3b2b4e593b6ce0d26d09e2756da'
SCRIPT = Path('/usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py')
ROOT = Path('/var/lib/ustar-restore-test/20261014')
STATE = ROOT / 'state.json'
LOCK = Path('/run/ustar-restore-test-20261014.lock')
HDD = Path('/srv/ustar-storage')
HDD_UUID = '359a2bae-4e79-461a-ab72-1597f605d801'
EXPORT = HDD / 'backups/managed'
LATEST = Path('/var/lib/ustar-backup/last-hdd-success.json')
IDENTITY = Path('/etc/ustar-backup/recovery.key')
NAMES = {'pg': 'ustar_restore_test_20261014_pg', 'web': 'ustar_restore_test_20261014_web'}
UNIT = 'ustar-restore-test-20261014-http.service'
LABEL = 'ustar.restore.experiment'
PORT = 18086
URL = 'http://127.0.0.1:' + str(PORT)
HOLD_SECONDS = 3600
PREPARATION_SECONDS = 3600
GIB = 1024 ** 3
ARCHIVE_NAME = re.compile(r'ustar-recovery-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}\.tar\.gz\.age')


class RestoreTestError(Exception):
    pass


def require(value, code):
    if not value:
        raise RestoreTestError(code)


def duration_seconds(value):
    """0 means no time limit; otherwise accept 30m, 2h or 1d (up to 30d)."""
    if value == '0':
        return 0
    match = re.fullmatch(r'([1-9][0-9]{0,5})([mhd])', value)
    if not match:
        raise argparse.ArgumentTypeError('Use 30m, 2h, 1d or 0 (no timer)')
    result = int(match[1]) * {'m': 60, 'h': 3600, 'd': 86400}[match[2]]
    if result > 30 * 86400:
        raise argparse.ArgumentTypeError('Maximum finite duration is 30d; use 0 for no timer')
    return result


def start_relay(lab, state, seconds):
    """Same reviewed ownership/resource guards, lifetime follows the controller."""
    trusted(SCRIPT)
    lab.relay_unit_absent()
    state['relay_requested'] = True
    lab.save_state(state)
    lab.command(['systemd-run', '--unit', UNIT.removesuffix('.service'), '--collect',
                 '--property=RuntimeMaxSec=' + str(seconds + 600 if seconds else 0),
                 '--property=MemoryMax=512M', '--property=TasksMax=128',
                 '--property=CPUQuota=50%', '--property=KillMode=control-group',
                 '--property=UMask=0077', '/usr/bin/python3', str(SCRIPT),
                 '--serve-lab', '--token', state['token']])


def now():
    return datetime.now(timezone.utc).isoformat()


def trusted(path, directory=False, private=False):
    path = Path(path)
    for item in (*reversed(path.parents), path):
        s = item.lstat()
        is_dir = item != path or directory
        require((stat.S_ISDIR(s.st_mode) if is_dir else stat.S_ISREG(s.st_mode))
                and s.st_uid == 0 and not s.st_mode & (0o077 if item == path and private else 0o022)
                and (is_dir or s.st_nlink == 1), 'UNTRUSTED_INPUT')
    return path


def read_json(path):
    trusted(path)
    require(path.stat().st_size <= 4 * 1024 * 1024, 'METADATA_TOO_LARGE')
    def unique(items):
        out = {}
        for key, value in items:
            require(key not in out, 'DUPLICATE_METADATA_KEY')
            out[key] = value
        return out
    return json.loads(path.read_bytes(), object_pairs_hook=unique)


def load_module(path, expected, name):
    trusted(path)
    source = path.read_bytes()
    require(hashlib.sha256(source).hexdigest() == expected, 'DEPENDENCY_SHA_MISMATCH')
    module = types.ModuleType(name)
    module.__file__ = str(path)
    exec(compile(source, str(path), 'exec'), module.__dict__)
    return module


def engine(selection=None):
    require(ENGINE.is_file(), 'PINNED_RESTORE_ENGINE_MISSING')
    require(CHECKER.is_file(), 'PINNED_BUNDLE_VERIFIER_MISSING')
    lab = load_module(ENGINE, ENGINE_SHA, 'ustar_restore_test_engine')
    check = load_module(CHECKER, CHECKER_SHA, 'ustar_restore_test_checker')
    require(lab.CHECKER_SHA == CHECKER_SHA, 'ENGINE_CHECKER_BINDING_MISMATCH')
    lab.ROOT, lab.STATE, lab.SCRIPT = ROOT, STATE, SCRIPT
    lab.NAMES, lab.UNIT, lab.PORT, lab.LABEL = dict(NAMES), UNIT, PORT, LABEL
    require(set(NAMES.values()).isdisjoint(lab.PRODUCTION), 'PRODUCTION_NAMESPACE_OVERLAP')
    lab._CHECK = check
    if selection:
        check.ARCHIVE, check.EXPECTED, check.IDENTITY = (
            selection['archive'], selection['sha256'], selection['identity'])
    return lab


def selection(args):
    if args.manifest or args.input_directory:
        if args.input_directory:
            base = trusted(Path(args.input_directory), directory=True, private=True)
            sidecars = [p for p in base.glob('*.tar.gz.age.json') if ARCHIVE_NAME.fullmatch(p.name[:-5])]
            require(len(sidecars) == 1, 'INPUT_DIRECTORY_NEEDS_EXACTLY_ONE_BACKUP')
            sidecar = sidecars[0]
        else:
            sidecar = Path(args.manifest)
        manifest = read_json(sidecar)
        base = sidecar.parent
    else:
        saved = read_json(LATEST)
        require(saved.get('success') is True and saved.get('phase') == 'complete', 'NO_COMPLETED_BACKUP')
        manifest = saved.get('manifest')
        require(isinstance(manifest, dict), 'BACKUP_MANIFEST_MISSING')
        require(manifest.get('hdd_uuid') == HDD_UUID, 'BACKUP_HDD_UUID_MISMATCH')
        # Require the real HDD to be mounted, rather than falling through to SSD.
        mount = json.loads(lab_command(['findmnt', '-J', '--mountpoint', str(HDD),
                                       '-o', 'TARGET,UUID']).stdout)
        rows = mount.get('filesystems', [])
        require(len(rows) == 1 and rows[0].get('target') == str(HDD)
                and rows[0].get('uuid') == HDD_UUID, 'BACKUP_HDD_NOT_MOUNTED')
        base = EXPORT
        name = manifest.get('file')
        require(isinstance(name, str) and ARCHIVE_NAME.fullmatch(name), 'INVALID_ARCHIVE_NAME')
        sidecar = base / (name + '.json')
        # The HDD adapter appends this absolute locator to its last-success
        # report; the published sidecar contains the original manifest only.
        require(manifest.get('archive') == str(base / name), 'BACKUP_ARCHIVE_LOCATOR_MISMATCH')
        require(read_json(sidecar) == {k: v for k, v in manifest.items() if k != 'archive'},
                'BACKUP_MANIFEST_SIDECAR_MISMATCH')
    name, checksum, size = manifest.get('file'), manifest.get('sha256'), manifest.get('bytes')
    require(isinstance(name, str) and ARCHIVE_NAME.fullmatch(name), 'INVALID_ARCHIVE_NAME')
    require(sidecar.name == name + '.json', 'SIDECAR_NAME_MISMATCH')
    require(isinstance(checksum, str) and re.fullmatch(r'[0-9a-f]{64}', checksum)
            and type(size) is int and size > 0 and manifest.get('readback_verified') is True,
            'BACKUP_NOT_VERIFIED_COMPLETE')
    require((base / name).is_file(), 'ENCRYPTED_ARCHIVE_MISSING')
    archive = trusted(base / name)
    require(archive.stat().st_size == size, 'ENCRYPTED_ARCHIVE_SIZE_MISMATCH')
    require(Path(args.identity).is_file(), 'PRIVATE_RECOVERY_IDENTITY_MISSING')
    key = trusted(Path(args.identity), private=True)
    return {'archive': str(archive), 'sha256': checksum, 'bytes': size, 'identity': str(key)}


def lab_command(args, timeout=30):
    import subprocess
    p = subprocess.run(args, capture_output=True, timeout=timeout)
    require(p.returncode == 0, 'READ_ONLY_HOST_PROBE_FAILED')
    return p


def production_state(lab):
    result = {}
    for name in lab.PRODUCTION:
        c = lab.inspect(name, absent_ok=True)
        if c is not None:
            require(c['State']['Running'], 'EXISTING_PRODUCTION_NOT_RUNNING')
            result[name] = {k: c[k] for k in ('Id', 'Image', 'Mounts')}
            result[name]['started'] = c['State']['StartedAt']
            result[name]['restart_policy'] = c['HostConfig']['RestartPolicy']
    require(len(result) in (0, 2), 'PARTIAL_PRODUCTION_PAIR')
    return result


def local_images(lab, preflight):
    import subprocess
    result = {}
    for kind, name in (('pg', 'ustar_postgres'), ('web', 'ustar_moodle')):
        archived = preflight['images'][name]
        require(archived['standard_postgres' if kind == 'pg' else 'standard_php_apache'],
                'NONSTANDARD_ARCHIVED_IMAGE')
        # An OCI export's manifest ID and its config ID are cryptographically
        # linked by the pinned checker. Classic stores may retain the config ID.
        for identifier in dict.fromkeys((archived['id'], archived['config_id'])):
            p = subprocess.run(['docker', 'image', 'inspect', identifier], capture_output=True, timeout=20)
            if p.returncode:
                continue
            rows = json.loads(p.stdout)
            require(len(rows) == 1, 'LOCAL_IMAGE_INSPECTION_INVALID')
            image = rows[0]
            require(image['Id'] in (archived['id'], archived['config_id'])
                    and image['Os'] == 'linux' and image['Architecture'] == 'amd64',
                    'LOCAL_IMAGE_ID_OR_PLATFORM_MISMATCH')
            require(image['Config'].get('Entrypoint') ==
                    (['docker-entrypoint.sh'] if kind == 'pg' else ['docker-php-entrypoint'])
                    and image['Config'].get('Cmd') == (['postgres'] if kind == 'pg' else ['apache2-foreground']),
                    'LOCAL_IMAGE_ENTRYPOINT_MISMATCH')
            result[kind] = image['Id']
            break
    return result


PRESENTATION = {
    # Production 5.1.8 keeps stock public/index.php; the USTAR entry is this
    # config.php setting. The pinned engine regenerates config.php, so without
    # carrying it over the copy opens the stock Moodle front page.
    'defaulthomepage': re.compile(r'(?:[0-3]|/local/ustar/[a-z_]+\.php)'),
    'theme': re.compile(r'[a-z][a-z0-9_]{0,48}'),
}
SETUP = "require_once(__DIR__ . '/lib/setup.php');\n"
PRESENTATION_RESULT = {}


def presentation_settings(original):
    """Only exact, unconditional quoted literals from the backup config.

    No PHP is executed and no other line (credentials, URL, mail, cron) is read.
    Ambiguous or unexpected values are not carried and are reported by name.
    """
    carried, skipped = {}, []
    for name, allowed in PRESENTATION.items():
        found = [a or b for a, b in re.findall(
            r"(?m)^[ \t]*\$CFG->" + name + r"\s*=\s*(?:'([^'\\\n$]*)'|\"([^\"\\\n$]*)\")\s*;[ \t]*(?://[^\n]*)?\r?$",
            original)]
        mentioned = sum(1 for line in original.splitlines()
                        if re.search(r'\$CFG->' + name + r'\b', line)
                        and not line.lstrip().startswith(('//', '#', '*', '/*')))
        if not mentioned:
            continue
        if len(found) == 1 and mentioned == 1 and allowed.fullmatch(found[0]):
            carried[name] = found[0]
        else:
            skipped.append(name)
    theme = carried.get('theme')
    if theme is not None and not any((ROOT / 'site/public' / base / 'theme' / theme / 'config.php').is_file()
                                     for base in ('public', '.')):
        del carried['theme']
        skipped.append('theme')
    return carried, sorted(skipped)


def prepare_config(lab, password):
    path = ROOT / 'site/public/config.php'
    # Read the extracted backup config before the engine replaces it.
    original = path.read_text(errors='replace') if path.is_file() and not path.is_symlink() else ''
    require(len(original) <= 128 * 1024, 'BACKUP_CONFIG_TOO_LARGE')
    carried, skipped = presentation_settings(original)
    count = lab.prepare_config(password)
    text = path.read_text()
    for old, new in {
        "$CFG->wwwroot = 'http://127.0.0.1:18084';": "$CFG->wwwroot = '" + URL + "';",
        "$CFG->sessioncookie = 'USTAR_RECOVERY_LAB_20261004';":
            "$CFG->sessioncookie = 'USTAR_RESTORE_TEST_20261014';",
    }.items():
        require(text.count(old) == 1, 'GENERATED_LAB_CONFIG_MISMATCH')
        text = text.replace(old, new)
    require(text.endswith(SETUP) and text.count(SETUP) == 1
            and all('$CFG->' + name not in text for name in PRESENTATION), 'GENERATED_LAB_CONFIG_MISMATCH')
    text = (text[:-len(SETUP)] + ''.join("$CFG->%s = '%s';\n" % (k, v) for k, v in sorted(carried.items()))
            + SETUP)
    PRESENTATION_RESULT.clear()
    PRESENTATION_RESULT.update(carried=carried, skipped=skipped)
    temporary = path.parent / ('test-config-' + uuid.uuid4().hex + '.tmp')
    lab.write_private(temporary, text)
    os.chown(temporary, 0, 33)
    os.chmod(temporary, 0o640)
    os.replace(temporary, path)
    ini = ROOT / 'lab.ini'
    require(ini.read_text() == 'memory_limit=512M\nsendmail_path=/bin/false\ndisplay_errors=Off\nlog_errors=On\n',
            'GENERATED_LAB_INI_MISMATCH')
    ini.write_text(ini.read_text() + 'max_input_vars=5000\n')
    return count


def http_check():
    class LocalRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, request, fp, code, message, headers, url):
            require(url.startswith(URL + '/'), 'EXTERNAL_HTTP_REDIRECT_REFUSED')
            return super().redirect_request(request, fp, code, message, headers, url)
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), LocalRedirect())
    for _ in range(30):
        try:
            with opener.open(URL + '/login/index.php', timeout=10) as response:
                body = response.read(2 * 1024 * 1024 + 1)
                require(response.geturl().startswith(URL + '/') and response.status == 200
                        and len(body) <= 2 * 1024 * 1024
                        and re.search(rb'name=[\'"]username[\'"]', body)
                        and re.search(rb'name=[\'"]password[\'"]', body), 'LOGIN_FORM_CHECK_FAILED')
                return 200
        except OSError:
            time.sleep(1)
    raise RestoreTestError('LOGIN_FORM_TIMEOUT')


@contextmanager
def locked():
    fd = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        s = os.fstat(fd)
        require(stat.S_ISREG(s.st_mode) and s.st_uid == 0 and not s.st_mode & 0o077
                and s.st_nlink == 1, 'UNTRUSTED_RESTORE_LOCK')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise RestoreTestError('RESTORE_EXPERIMENT_BUSY') from None
        yield
    finally:
        os.close(fd)


def saved_engine():
    if not STATE.exists() and not STATE.is_symlink():
        require(not ROOT.exists(), 'LAB_STATE_MISSING')
        return None, None
    trusted(ROOT, directory=True, private=True)
    state = read_json(STATE)
    require(state.get('purpose') == 'latest_backup_one_hour_restore'
            and isinstance(state.get('selection'), dict), 'EXPERIMENT_STATE_MISMATCH')
    lab = engine(state['selection'])
    lab.load_state()  # Original token/archive binding and private-state guards.
    return lab, state


def stop():
    lab, state = saved_engine()
    if lab is None:
        print('RESTORE_TEST_STOP=NOT_STARTED')
        return
    lab.stop_lab(state)
    state.update(phase='stopped', stopped_at=now())
    lab.save_state(state)
    print('RESTORE_TEST_STOP=PASS; DATA_AND_BACKUP_RETAINED')


def verify(lab):
    with lab.decrypted() as stream:
        report = lab.checker().verify_bundle(stream)
    require(report['site']['symlinks'] == report['site']['hardlinks'] == 0, 'SITE_LINKS_NOT_SUPPORTED')
    return report


def run(args):
    hold_seconds = getattr(args, 'duration', HOLD_SECONDS)
    require(type(hold_seconds) is int and 0 <= hold_seconds <= 30 * 86400, 'INVALID_DURATION')
    for name in ('docker', 'age', 'nsenter', 'systemd-run', 'systemctl', 'findmnt'):
        require(shutil.which(name), 'MISSING_DEPENDENCY_' + name.upper().replace('-', '_'))
    selected = selection(args)
    lab = engine(selected)
    before = production_state(lab)
    require(not ROOT.exists() and not ROOT.is_symlink(), 'EXPERIMENT_ALREADY_EXISTS_NO_OVERWRITE')
    require(all(lab.inspect(n, absent_ok=True) is None for n in NAMES.values()), 'LAB_NAME_OCCUPIED')
    lab.relay_unit_absent()
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', PORT))
    print('RESTORE_TEST_PHASE=verifying_encrypted_bundle', flush=True)
    preflight = verify(lab)
    images = local_images(lab, preflight)
    missing = set(NAMES) - set(images)
    if missing:
        require(args.load_images, 'IMAGES_MISSING_USE_LOAD_IMAGES_ON_EMPTY_DOCKER_HOST')
        require(not before and not lab_command(['docker', 'ps', '-aq']).stdout.strip(),
                'IMAGE_LOAD_REQUIRES_EMPTY_DOCKER_HOST')
    parent = ROOT.parent if ROOT.parent.exists() else ROOT.parent.parent
    needed = (preflight['payload_bytes'] + preflight['site']['expanded_file_bytes']
              + max(2 * GIB, 8 * preflight['database_dump_bytes']) + 4 * GIB
              + (2 * preflight['images_archive_bytes'] if missing else 0))
    require(shutil.disk_usage(parent).free >= needed, 'INSUFFICIENT_RESTORE_SPACE')
    require(os.statvfs(parent).f_favail >= preflight['site']['regular_files']
            + preflight['site']['directories'] + 10000, 'INSUFFICIENT_RESTORE_INODES')
    memory = int(Path('/proc/meminfo').read_text().split('MemAvailable:')[1].split()[0]) * 1024
    require(memory >= 5 * GIB, 'INSUFFICIENT_AVAILABLE_MEMORY')
    require(production_state(lab) == before, 'PRODUCTION_CHANGED_DURING_PREFLIGHT')
    if args.check:
        print(json.dumps({'result': 'PASS', 'archive': selected['archive'], 'sha256': selected['sha256'],
                          'snapshot_completed_utc': preflight['snapshot_completed_utc'],
                          'required_free_bytes': needed, 'images_present': images,
                          'image_load_required': bool(missing), 'restore_executed': False}, indent=2))
        return
    trusted(SCRIPT)
    if not ROOT.parent.exists():
        ROOT.parent.mkdir(mode=0o700)
    trusted(ROOT.parent, directory=True, private=True)
    ROOT.mkdir(mode=0o700)
    log = ROOT / 'commands-private.log'
    lab.write_private(log, b'')
    lab._LOG = log.open('ab')
    state = {'purpose': 'latest_backup_one_hour_restore', 'token': uuid.uuid4().hex,
             'archive_sha256': selected['sha256'], 'selection': selected,
             'containers': {}, 'images': images, 'production_before': before,
             'preflight': preflight, 'relay_requested': False, 'phase': 'extracting',
             'started_at': now(), 'ready': False, 'stopped': False}
    lab.save_state(state)
    try:
        print('RESTORE_TEST_PHASE=extracting_private_copy', flush=True)
        lab.extract_payload()
        lab.extract_site(preflight['site'])
        if missing:
            # Repeat empty-daemon check immediately before the only image load.
            require(not lab_command(['docker', 'ps', '-aq']).stdout.strip(), 'DOCKER_HOST_NO_LONGER_EMPTY')
            with (ROOT / 'payload/images.tar').open('rb') as src:
                lab.command(['docker', 'load'], source=src, timeout=900)
            state['images'] = local_images(lab, preflight)
            require(set(state['images']) == set(NAMES), 'ARCHIVED_IMAGES_NOT_AVAILABLE_AFTER_LOAD')
            state['image_archive_loaded'] = True
            lab.save_state(state)
        password = uuid.uuid4().hex + uuid.uuid4().hex
        PRESENTATION_RESULT.clear()
        secrets = prepare_config(lab, password)
        (ROOT / 'postgres').mkdir(mode=0o700)
        lab.write_private(ROOT / 'pg.env', 'POSTGRES_USER=ustar_lab_admin\nPOSTGRES_PASSWORD='
                          + uuid.uuid4().hex + uuid.uuid4().hex
                          + '\nPOSTGRES_DB=postgres\nPOSTGRES_INITDB_ARGS=--auth-host=scram-sha-256\n')
        state['phase'] = 'restoring_database'
        lab.save_state(state)
        print('RESTORE_TEST_PHASE=restoring_new_database', flush=True)
        lab.create_container('pg', state)
        counts = lab.restore_database(state, password)
        references = lab.check_file_references(state)
        lab.create_container('web', state)
        lab.assert_offline(state)
        lab.lab_exec('web', state, ['php', '-l', '/var/www/html/config.php'], user='33:33')
        php = ("define('CLI_SCRIPT',true); require '/var/www/html/config.php'; "
               "require_once($CFG->libdir.'/upgradelib.php'); echo json_encode(["
               "'dbhost'=>$CFG->dbhost,'dbname'=>$CFG->dbname,'wwwroot'=>$CFG->wwwroot,"
               "'noemail'=>(bool)$CFG->noemailever,'cron'=>(int)$CFG->cron_enabled,"
               "'needs_upgrade'=>moodle_needs_upgrading(),"
               "'defaulthomepage'=>isset($CFG->defaulthomepage)?(string)$CFG->defaulthomepage:null,"
               "'theme'=>isset($CFG->theme)?(string)$CFG->theme:null]);")
        boot = json.loads(lab.lab_exec('web', state, ['php', '-r', php], user='33:33').stdout)
        carried = PRESENTATION_RESULT.get('carried', {})
        # theme/defaulthomepage may also live in the restored database; only the
        # values carried from the backup config.php must be effective exactly.
        effective = {k: boot.pop(k, None) for k in PRESENTATION}
        require(boot == {'dbhost': '127.0.0.1', 'dbname': 'ustar_recovery_lab', 'wwwroot': URL,
                         'noemail': True, 'cron': 0, 'needs_upgrade': False}, 'MOODLE_BOOTSTRAP_MISMATCH')
        require(all(effective[k] == v for k, v in carried.items()), 'PRESENTATION_SETTINGS_NOT_EFFECTIVE')
        state['presentation'] = effective
        lab.save_state(state)
        print('RESTORE_TEST_PRESENTATION=' + json.dumps(effective, sort_keys=True), flush=True)
        start_relay(lab, state, hold_seconds)
        code = http_check()
        require(production_state(lab) == before, 'PRODUCTION_IDENTITY_CHANGED')
        began = time.monotonic()
        state.update(phase='ready', ready=True, ready_at=now(),
                     hold_seconds=hold_seconds, unlimited=hold_seconds == 0,
                     expires_at=(datetime.fromtimestamp(time.time() + hold_seconds, timezone.utc).isoformat()
                                 if hold_seconds else None))
        signal.alarm(0)  # Preparation is complete; only the chosen runtime now applies.
        lab.save_state(state)
        report = {'result': 'PASS', 'archive': selected['archive'], 'snapshot_sha256': selected['sha256'],
                  'snapshot_completed_utc': preflight['snapshot_completed_utc'], 'database_restore': 'PASS',
                  'snapshot_table_counts': counts, 'verified_file_contenthashes': references,
                  'password_secret_settings_preserved': secrets, 'image_archive_loaded': bool(missing),
                  'presentation_settings_carried': sorted(PRESENTATION_RESULT.get('carried', {})),
                  'presentation_settings_skipped': PRESENTATION_RESULT.get('skipped', []),
                  'effective_theme': state['presentation']['theme'],
                  'effective_defaulthomepage': state['presentation']['defaulthomepage'],
                  'lab_url': URL + '/login/index.php', 'http_login': code, 'lab_network': 'loopback_only',
                  'ready_at': state['ready_at'], 'expires_at': state['expires_at'], 'hold_seconds': hold_seconds, 'unlimited': hold_seconds == 0,
                  'production_identities_unchanged': True, 'postgres_globals_executed': False,
                  'manual_login_roles_scorm': 'PENDING', 'clean_host_recovery': 'PENDING',
                  'whole_host_boot_restore': 'NOT_TESTED', 'lab_data_deleted': False}
        lab.write_private(ROOT / 'report.json', json.dumps(report, indent=2))
        print('RESTORE_TEST_READY=PASS; ACTIVE_FOR_SECONDS=' + (str(hold_seconds) if hold_seconds else 'UNLIMITED'), flush=True)
        print(json.dumps(report, indent=2), flush=True)
        while True:
            remaining = hold_seconds - (time.monotonic() - began) if hold_seconds else None
            if remaining is not None and remaining <= 0:
                break
            time.sleep(min(15, remaining) if remaining is not None else 15)
            lab.assert_offline(state)
        lab.stop_lab(state)
        state.update(phase='stopped', stopped_at=now(), duration_completed=True,
                     one_hour_completed=hold_seconds == 3600)
        lab.save_state(state)
        print('RESTORE_TEST_DURATION=COMPLETE; OWNED_LAB_STOPPED; DATA_RETAINED', flush=True)
        if hold_seconds == 3600:
            print('RESTORE_TEST_ONE_HOUR=COMPLETE', flush=True)
    except BaseException as exc:
        state.update(phase='failed', failure_type=type(exc).__name__)
        lab.save_state(state)
        try:
            lab.stop_lab(state)
            state['phase'] = 'failed_stopped'
            lab.save_state(state)
        except Exception:
            state['phase'] = 'cleanup_required'
            lab.save_state(state)
        raise
    finally:
        lab._LOG.close()
        lab._LOG = None


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    modes = ap.add_mutually_exclusive_group()
    for mode in ('check', 'restore-lab', 'stop-lab', 'status', 'serve-lab', 'relay-child'):
        modes.add_argument('--' + mode, action='store_true', help=argparse.SUPPRESS if mode in ('serve-lab', 'relay-child') else None)
    inputs = ap.add_mutually_exclusive_group()
    inputs.add_argument('--manifest', help='Explicit copied archive JSON sidecar; otherwise last successful HDD copy')
    inputs.add_argument('--input-directory', help='Root-private directory containing exactly one copied archive/sidecar pair')
    ap.add_argument('--identity', default=str(IDENTITY))
    ap.add_argument('--duration', type=duration_seconds, default=HOLD_SECONDS,
                    help='Runtime after readiness: 30m, 2h, 1d; 0 = no timer (default 1h)')
    ap.add_argument('--load-images', action='store_true', help='Allow checked image load only on an empty Docker host')
    ap.add_argument('--token', default='', help=argparse.SUPPRESS)
    args = ap.parse_args()
    require(os.geteuid() == 0, 'RUN_WITH_SUDO')
    os.umask(0o077)
    if args.relay_child:
        engine().relay_child()
    elif args.serve_lab:
        lab, _ = saved_engine()
        require(lab is not None, 'EXPERIMENT_NOT_STARTED')
        lab.serve_lab(args.token)
    elif args.status:
        if not STATE.exists():
            try:
                pid = 0
                for unit in ('ustar-backup-recovery-20261015.service', 'ustar-restore-test-20261014.service'):
                    p = lab_command(['systemctl', 'show', unit, '--property=MainPID', '--value'])
                    pid = max(pid, int(p.stdout.strip() or b'0'))
            except Exception:
                pid = 0
            print(json.dumps({'phase': 'preflight_running' if pid else 'not_started', 'main_pid': pid}))
            return
        state = read_json(STATE)
        print(json.dumps({k: state.get(k) for k in ('phase', 'ready', 'stopped', 'ready_at',
                         'expires_at', 'hold_seconds', 'unlimited', 'stopped_at', 'duration_completed', 'one_hour_completed', 'failure_type', 'presentation')}, indent=2))
    elif args.stop_lab:
        with locked():
            stop()
    elif args.restore_lab or args.check:
        def interrupted(*_):
            raise KeyboardInterrupt()
        for signum in (signal.SIGTERM, signal.SIGHUP):
            signal.signal(signum, interrupted)
        if args.check:
            run(args)
        else:
            with locked():
                def preparation_timeout(*_):
                    raise RestoreTestError('PREPARATION_TIMEOUT')
                signal.signal(signal.SIGALRM, preparation_timeout)
                signal.alarm(PREPARATION_SECONDS)
                try:
                    run(args)
                finally:
                    signal.alarm(0)
    else:
        print('PLAN_ONLY; --restore-lab --duration 2h; --duration 0 disables the runtime timer')


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        print('RESTORE_TEST_INTERRUPTED; CHECK_STATUS_AND_SERVICE', file=sys.stderr)
        sys.exit(130)
    except Exception as exc:
        message = str(exc) if isinstance(exc, RestoreTestError) else type(exc).__name__
        print('RESTORE_TEST_ERROR=' + message, file=sys.stderr)
        sys.exit(1)
