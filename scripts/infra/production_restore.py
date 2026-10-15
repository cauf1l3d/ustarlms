#!/usr/bin/env python3
"""Permanent USTAR recovery on an empty host; source freeze is a separate mode.

No core upgrade. Import authenticates the kit via pinned restore helpers;
activation requires the operator's source-stopped declaration. Runtime commands
operate only on containers recorded with this transaction's label and IDs.
"""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import stat
import subprocess
import sys
import time
import types
import urllib.parse
import uuid

SCRIPT = Path('/usr/local/sbin/USTAR_PRODUCTION_RESTORE_20261015.py')
CONTROLLER = Path('/usr/local/sbin/USTAR_BACKUP_RESTORE_TEST_20261014.py')
CONTROLLER_SHA = '4804c27aada3d5f431ccf19cfb174dd8deb1bc6f991d3d9793d6a89b7be9b022'
BACKUP = Path('/usr/local/sbin/USTAR_HDD_BACKUP_20261006.py')
BACKUP_SHA = '75ca974f1d763f6fc84281501a99936894f1b487c1cc4d0c1d71697e67c79d75'
ROOT = Path('/var/lib/ustar-production-restore')
STATE = ROOT / 'state.json'
TARGET = Path('/opt/ustar')
CODE = TARGET / 'data/moodle/public'
DATA = TARGET / 'data/moodle/moodledata'
PGDATA = TARGET / 'data/postgres'
SOURCE_ROOT = Path('/var/lib/ustar-production-transfer')
SOURCE_STATE = SOURCE_ROOT / 'source.json'
NAMES = {'pg': 'ustar_postgres', 'web': 'ustar_moodle'}
NETWORK = 'ustar_academy'
LABEL = 'ustar.production.restore'
UNIT_DIR = Path('/etc/systemd/system')
RUNTIME_UNIT = 'ustar-academy.service'
CRON_UNIT = 'ustar-academy-cron.service'
CRON_TIMER = 'ustar-academy-cron.timer'
MANAGED = '# USTAR_PRODUCTION_RECOVERY_V1\n'
GIB = 1024 ** 3
LOG = None
RUN = Path('/run')
SOURCE_CRON_LOCK = Path('/run/ustar-cron/lock')
LATEST = Path('/var/lib/ustar-backup/last-hdd-success.json')


class ProductionError(Exception):
    pass


def require(ok, code):
    if not ok:
        raise ProductionError(code)


def trusted(path, directory=False, private=False):
    require(path.is_absolute(), 'ABSOLUTE_PATH_REQUIRED')
    for p in (*reversed(path.parents), path):
        s = p.lstat()
        isdir = p != path or directory
        require((stat.S_ISDIR(s.st_mode) if isdir else stat.S_ISREG(s.st_mode))
                and s.st_uid == 0 and not s.st_mode & (0o077 if private and p == path else 0o022)
                and (isdir or s.st_nlink == 1), 'UNTRUSTED_PATH')
    return path


def mkdir_private(path):
    trusted(path.parent, directory=True)
    if not path.exists():
        path.mkdir(mode=0o700)
    trusted(path, directory=True, private=True)


def save(path, value):
    trusted(path.parent, directory=True, private=True)
    tmp = path.parent / ('.state-' + uuid.uuid4().hex)
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as f:
        json.dump(value, f, indent=2)
        f.flush(); os.fsync(f.fileno())
    os.replace(tmp, path)
    fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try: os.fsync(fd)
    finally: os.close(fd)


def read(path):
    trusted(path, private=True)
    require(path.stat().st_size < 4 * 1024 ** 2, 'STATE_TOO_LARGE')
    def unique(items):
        d = {}
        for k, v in items:
            require(k not in d, 'DUPLICATE_STATE_KEY'); d[k] = v
        return d
    return json.loads(path.read_bytes(), object_pairs_hook=unique)


def checked(path, sha):
    trusted(path)
    require(hashlib.sha256(path.read_bytes()).hexdigest() == sha, 'HELPER_SHA_MISMATCH')


def controller():
    checked(CONTROLLER, CONTROLLER_SHA)
    m = types.ModuleType('ustar_production_recovery_dependency')
    m.__file__ = str(CONTROLLER)
    exec(compile(CONTROLLER.read_bytes(), str(CONTROLLER), 'exec'), m.__dict__)
    m.ROOT, m.STATE = ROOT, STATE
    return m


def command(args, data=None, source=None, timeout=60, optional=False):
    require(data is None or source is None, 'AMBIGUOUS_STDIN')
    p = subprocess.run(args, input=data, stdin=source, capture_output=True, timeout=timeout)
    if LOG:
        LOG.write(p.stdout + p.stderr); LOG.flush()
    require(optional or p.returncode == 0, 'COMMAND_FAILED_' + Path(args[0]).name.upper())
    return p


@contextmanager
def lock(name):
    path = RUN / name
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        s = os.fstat(fd)
        require(stat.S_ISREG(s.st_mode) and s.st_uid == 0 and s.st_nlink == 1
                and not s.st_mode & 0o077, 'UNTRUSTED_LOCK')
        try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: raise ProductionError('OPERATION_BUSY') from None
        yield
    finally: os.close(fd)


def endpoint(url, bind='0.0.0.0', port=80, behind_proxy=False):
    require(isinstance(url, str) and len(url) <= 255 and not re.search(r"[\s'\"%\\$]", url), 'INVALID_URL')
    u = urllib.parse.urlsplit(url)
    require(u.scheme in ('http', 'https') and u.hostname and not u.username and not u.password
            and u.path in ('', '/') and not u.query and not u.fragment
            and re.fullmatch(r'[A-Za-z0-9.-]+(?::[0-9]{1,5})?', u.netloc), 'URL_MUST_BE_ORIGIN')
    try: urlport = u.port
    except ValueError: raise ProductionError('INVALID_URL_PORT') from None
    require(type(port) is int and 1 <= port <= 65535 and bind in ('0.0.0.0', '127.0.0.1'), 'INVALID_HTTP_BIND')
    require(not urlport or 1 <= urlport <= 65535, 'INVALID_URL_PORT')
    if behind_proxy:
        require(bind == '127.0.0.1', 'PROXY_BACKEND_MUST_BE_LOOPBACK')
    else:
        require(u.scheme == 'http' and (urlport or 80) == port, 'HTTPS_NEEDS_CONFIGURED_PROXY')
    return {'url': url.rstrip('/'), 'bind': bind, 'port': port, 'behind_proxy': bool(behind_proxy)}


def inspect(name, absent=False):
    p = command(['docker', 'inspect', name], optional=True)
    if p.returncode:
        require(absent and b'No such' in p.stderr, 'DOCKER_INSPECTION_FAILED')
        return None
    rows = json.loads(p.stdout)
    require(len(rows) == 1, 'INVALID_DOCKER_INSPECTION')
    return rows[0]


def mounts(kind):
    if kind == 'pg': return {str(PGDATA): ('/var/lib/postgresql/data', True)}
    return {str(CODE): ('/var/www/html', False), str(DATA): ('/var/www/moodledata', True),
            str(ROOT / 'production.ini'): ('/usr/local/etc/php/conf.d/zz-ustar-production.ini', False),
            str(ROOT / 'apache.conf'): ('/etc/apache2/conf-enabled/zz-ustar-production.conf', False)}


def owned(kind, state, absent=False, adopt=False):
    c = inspect(NAMES[kind], absent)
    if c is None: return None
    h = c['HostConfig']
    require(c['Name'] == '/' + NAMES[kind]
            and c['Config'].get('Labels', {}).get(LABEL) == state['token']
            and c['Image'] == state['images'][kind]
            and (c['Id'] == state['containers'].get(kind) or adopt and kind not in state['containers'])
            and {m['Source']: (m['Destination'], m['RW']) for m in c['Mounts']} == mounts(kind)
            and h['NetworkMode'] == NETWORK and not h.get('Privileged')
            and not h.get('PidMode') and not h.get('Binds')
            and h['RestartPolicy']['Name'] in ('no', 'unless-stopped'), 'CONTAINER_OWNERSHIP_OR_PLAN_MISMATCH')
    expected = {} if kind == 'pg' else {'80/tcp': [{'HostIp': state['endpoint']['bind'],
                                                  'HostPort': str(state['endpoint']['port'])}]}
    require((h.get('PortBindings') or {}) == expected, 'PUBLIC_PORT_PLAN_MISMATCH')
    return c


def owned_network(state):
    p = command(['docker', 'network', 'inspect', NETWORK])
    rows = json.loads(p.stdout)
    require(len(rows) == 1 and rows[0]['Driver'] == 'bridge'
            and rows[0].get('Labels', {}).get(LABEL) == state['token'], 'NETWORK_OWNERSHIP_MISMATCH')


def exec_owned(kind, state, args, data=None, source=None, user=None, timeout=120):
    c = owned(kind, state)
    require(c['State']['Running'], 'OWNED_CONTAINER_STOPPED')
    cmd = ['docker', 'exec']
    if data is not None or source is not None: cmd.append('-i')
    if user: cmd += ['--user', user]
    return command(cmd + [c['Id']] + args, data, source, timeout)


def sql(state, statement, database='ustar'):
    return exec_owned('pg', state, ['psql', '-X', '-qAt', '-v', 'ON_ERROR_STOP=1',
                                  '-U', 'ustar_restore_admin', '-d', database],
                      data=statement.encode()).stdout.decode().strip()


def pg_ready(state):
    for _ in range(90):
        c = owned('pg', state)
        require(c['State']['Running'], 'POSTGRES_EXITED')
        # The temporary initialization server is not PID1=postgres.
        p = command(['docker', 'exec', c['Id'], 'sh', '-c',
                     'test "$(cat /proc/1/comm)" = postgres && pg_isready -U ustar_restore_admin -d postgres'], optional=True)
        if not p.returncode: return
        time.sleep(1)
    raise ProductionError('POSTGRES_READY_TIMEOUT')


def create_container(kind, state):
    cmd = ['docker', 'create', '--name', NAMES[kind], '--label', LABEL + '=' + state['token'],
           '--restart', 'no', '--network', NETWORK, '--log-opt', 'max-size=10m', '--log-opt', 'max-file=3']
    for src, (dst, rw) in mounts(kind).items():
        cmd += ['--mount', 'type=bind,src=' + src + ',dst=' + dst + ('' if rw else ',readonly')]
    if kind == 'pg': cmd += ['--env-file', str(ROOT / 'pg.env')]
    else: cmd += ['--publish', state['endpoint']['bind'] + ':' + str(state['endpoint']['port']) + ':80']
    cmd += ['--pull', 'never', state['images'][kind]]
    if kind == 'web': cmd += ['apache2-foreground']
    try:
        result = command(cmd).stdout.decode().strip()
        require(re.fullmatch('[0-9a-f]{64}', result), 'INVALID_CONTAINER_ID')
        state['containers'][kind] = result; save(STATE, state)
        owned(kind, state)
    except BaseException:
        c = owned(kind, state, absent=True, adopt=True)
        if c: state['containers'][kind] = c['Id']; save(STATE, state)
        raise
    command(['docker', 'start', state['containers'][kind]])


def write_private(path, value, mode=0o600, gid=0):
    require(not path.exists() and not path.is_symlink(), 'OUTPUT_ALREADY_EXISTS')
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    with os.fdopen(fd, 'wb') as f:
        os.fchmod(f.fileno(), mode); os.fchown(f.fileno(), 0, gid)
        f.write(value.encode() if isinstance(value, str) else value); f.flush(); os.fsync(f.fileno())


def write_config(lab, original, password, ep, active=False):
    setup = "require_once(__DIR__ . '/lib/setup.php');"
    require(len(original.encode()) <= 128 * 1024 and original.count(setup) == 1, 'UNSUPPORTED_BACKUP_CONFIG')
    before, after = original.split(setup)
    require(not lab.strip_php_comments(after).strip(), 'CONFIG_SUFFIX_HAS_EXECUTABLE_CODE')
    active_text = lab.strip_php_comments(original)
    urls = re.findall(r'\$CFG\s*->\s*wwwroot\s*=\s*[\'"]([^\'"\\$\r\n]+)[\'"]\s*;', active_text)
    prefixes = re.findall(r'\$CFG\s*->\s*prefix\s*=\s*[\'"]([^\'"\\$\r\n]+)[\'"]\s*;', active_text)
    require(len(urls) == 1 and prefixes == ['mdl_'], 'CONFIG_NEEDS_ONE_STATIC_URL_AND_MDL_PREFIX')
    oldurl = urls[0].rstrip('/')
    require(re.fullmatch(r'https?://[A-Za-z0-9.:-]+(?:/[A-Za-z0-9_./-]+)?', oldurl), 'UNSUPPORTED_SOURCE_URL')
    # Preserve original code, salts, theme, homepage and integration settings.
    # Override only environment-bound runtime parameters before setup.php.
    overrides = {
        'dbtype': "'pgsql'", 'dblibrary': "'native'", 'dbhost': "'ustar_postgres'", 'dbname': "'ustar'",
        'dbuser': "'ustar_app'", 'dbpass': "'" + password + "'", 'prefix': "'mdl_'",
        'dboptions': "['dbpersist'=>false,'dbport'=>5432,'dbsocket'=>false]",
        'wwwroot': "'" + ep['url'] + "'", 'dataroot': "'/var/www/moodledata'", 'directorypermissions': '0700',
        'cachedir': "'/var/www/moodledata/ustar-production-runtime/cache'",
        'localcachedir': "'/var/www/moodledata/ustar-production-runtime/localcache'",
        'tempdir': "'/var/www/moodledata/ustar-production-runtime/temp'",
        'altcacheconfigpath': "'/var/www/moodledata/ustar-production-runtime/muc'",
        'session_handler_class': "'\\\\core\\\\session\\\\file'",
        'session_file_save_path': "'/var/www/moodledata/ustar-production-runtime/sessions'",
        'file_lock_root': "'/var/www/moodledata/ustar-production-runtime/locks'",
        'lock_factory': "'\\\\core\\\\lock\\\\file_lock_factory'", 'sessioncookie': "'USTAR_ACADEMY'",
        'cookiesecure': 'true' if ep['url'].startswith('https:') else 'false', 'cookiehttponly': 'true',
        'sslproxy': 'true' if ep['behind_proxy'] and ep['url'].startswith('https:') else 'false',
        'reverseproxy': 'false',
        'noemailever': 'false' if active else 'true', 'cron_enabled': '1' if active else '0',
        'cronclionly': 'true', 'pathtophp': "'/usr/local/bin/php'", 'task_adhoc_concurrency_limit': '3',
    }
    if not active: overrides['maintenance_enabled'] = '1'
    text = before + '\n// Environment settings for the recovered production host.\n'
    if active: text += 'unset($CFG->maintenance_enabled);\n'
    text += ''.join('$CFG->' + k + ' = ' + v + ';\n' for k, v in overrides.items()) + setup + after
    temporary = CODE / ('.production-config-' + uuid.uuid4().hex)
    write_private(temporary, text, 0o640, 33)
    os.replace(temporary, CODE / 'config.php')
    return oldurl


def verify_database(state):
    versions = sql(state, "SELECT plugin||'='||value FROM mdl_config_plugins WHERE name='version' AND plugin IN ('local_ustar','theme_ustar') ORDER BY plugin;").splitlines()
    require(sorted(versions) == sorted(state['preflight']['application_versions']), 'RESTORED_PLUGIN_VERSIONS_MISMATCH')
    counts = json.loads(sql(state, "SELECT json_build_object('users',(SELECT count(*) FROM mdl_user),'courses',(SELECT count(*) FROM mdl_course),'files',(SELECT count(*) FROM mdl_files),'scheduled_tasks',(SELECT count(*) FROM mdl_task_scheduled),'enabled_tasks',(SELECT count(*) FROM mdl_task_scheduled WHERE disabled=0),'adhoc_tasks',(SELECT count(*) FROM mdl_task_adhoc),'task_flags_md5',(SELECT md5(string_agg(id::text||':'||disabled::text,',' ORDER BY id)) FROM mdl_task_scheduled));"))
    require(counts['users'] >= 2 and counts['courses'] >= 1, 'RESTORED_BASELINE_MISSING')
    return counts


def check_files(state):
    rows = sql(state, "SELECT contenthash||'|'||min(filesize)||'|'||max(filesize) FROM mdl_files WHERE filename<>'.' AND filesize>0 AND referencefileid IS NULL GROUP BY contenthash;").splitlines()
    for row in rows:
        h, lo, hi = row.split('|')
        require(re.fullmatch('[0-9a-f]{40}', h) and lo == hi, 'INVALID_FILE_REFERENCE')
        path = DATA / 'filedir' / h[:2] / h[2:4] / h
        require(path.is_file() and not path.is_symlink() and path.stat().st_size == int(lo), 'RESTORED_FILE_MISSING')
        with path.open('rb') as f: require(hashlib.file_digest(f, 'sha1').hexdigest() == h, 'RESTORED_FILE_HASH_MISMATCH')
    return len(rows)


def health(state):
    ep = state['endpoint']
    host = urllib.parse.urlsplit(ep['url']).netloc
    for _ in range(30):
        c = None
        try:
            c = http.client.HTTPConnection('127.0.0.1', ep['port'], timeout=5)
            c.request('GET', '/login/index.php', headers={'Host': host, 'X-Forwarded-Proto': urllib.parse.urlsplit(ep['url']).scheme})
            r = c.getresponse(); body = r.read(2 * 1024 ** 2 + 1)
            if r.status == 200 and len(body) <= 2 * 1024 ** 2 and re.search(rb'name=[\'"]username[\'"]', body) and re.search(rb'name=[\'"]password[\'"]', body):
                return
        except (OSError, http.client.HTTPException): pass
        finally:
            if c: c.close()
        time.sleep(1)
    raise ProductionError('PRODUCTION_LOGIN_HEALTH_FAILED')


def units():
    runtime = MANAGED + '[Unit]\nDescription=USTAR Academy production\nRequires=docker.service\nAfter=docker.service network-online.target\nWants=network-online.target ustar-academy-cron.timer\n\n[Service]\nType=oneshot\nRemainAfterExit=yes\nTimeoutStartSec=180\nTimeoutStopSec=180\nUMask=0077\nExecStart=/usr/bin/python3 -I -B ' + str(SCRIPT) + ' --resume-production\nExecStop=/usr/bin/python3 -I -B ' + str(SCRIPT) + ' --stop-production\n\n[Install]\nWantedBy=multi-user.target\n'
    cron = MANAGED + '[Unit]\nDescription=USTAR Academy cron\nPartOf=' + RUNTIME_UNIT + '\nRequires=' + RUNTIME_UNIT + '\nAfter=' + RUNTIME_UNIT + '\n\n[Service]\nType=oneshot\nUMask=0077\nTimeoutStartSec=30min\nExecStart=/usr/bin/python3 -I -B ' + str(SCRIPT) + ' --cron-production\n'
    timer = MANAGED + '[Unit]\nDescription=USTAR Academy minute schedule\nPartOf=' + RUNTIME_UNIT + '\n\n[Timer]\nOnCalendar=*-*-* *:*:00\nAccuracySec=1s\nPersistent=false\nUnit=' + CRON_UNIT + '\n\n[Install]\nWantedBy=timers.target\n'
    return {RUNTIME_UNIT: runtime, CRON_UNIT: cron, CRON_TIMER: timer}


def install_units():
    trusted(UNIT_DIR, directory=True)
    for name, content in units().items():
        path = UNIT_DIR / name
        if path.exists() or path.is_symlink():
            trusted(path)
            require(path.read_text().startswith(MANAGED), 'UNOWNED_SERVICE_COLLISION')
        tmp = UNIT_DIR / ('.ustar-' + uuid.uuid4().hex)
        write_private(tmp, content, 0o644)
        os.replace(tmp, path)
    command(['systemctl', 'daemon-reload'])


def load_state():
    s = read(STATE)
    require(s.get('purpose') == 'production' and re.fullmatch('[0-9a-f]{32}', s.get('token', '')),
            'NOT_PRODUCTION_STATE')
    require(endpoint(**s['endpoint']) == s['endpoint'], 'INVALID_SAVED_ENDPOINT')
    return s


def stop_owned(state):
    for kind in ('web', 'pg'):
        if kind not in state['containers']: continue
        c = owned(kind, state, absent=True)
        if c:
            command(['docker', 'update', '--restart', 'no', c['Id']])
            if c['State']['Running']: command(['docker', 'stop', '--time', '60', c['Id']], timeout=90)


def runtime(mode):
    global LOG
    with lock('ustar-production-cron.lock' if mode == 'cron' else 'ustar-production-runtime.lock'):
        state = load_state()
        trusted(ROOT, directory=True, private=True)
        LOG = (ROOT / 'runtime-private.log').open('ab')
        try:
            owned_network(state)
            if mode == 'stop':
                stop_owned(state); state['phase'] = 'stopped'; save(STATE, state)
            elif mode == 'resume':
                require(state['phase'] in ('ready', 'stopped', 'activating'), 'RESTORE_NOT_ACTIVATED')
                for kind in ('pg', 'web'):
                    c = owned(kind, state)
                    command(['docker', 'update', '--restart', 'unless-stopped', c['Id']])
                    if not c['State']['Running']: command(['docker', 'start', c['Id']])
                    if kind == 'pg': pg_ready(state)
                health(state); state['phase'] = 'ready'; save(STATE, state)
            elif mode == 'cron':
                require(state['phase'] == 'ready', 'PRODUCTION_NOT_READY')
                pg_ready(state)
                exec_owned('web', state, ['php', '/var/www/html/public/admin/cli/cron.php'], user='33:33', timeout=1700)
                state['last_cron_success_utc'] = datetime.now(timezone.utc).isoformat(); save(STATE, state)
                print('PRODUCTION_CRON=PASS')
        finally: LOG.close(); LOG = None


def restore(args):
    global LOG
    ep = endpoint(args.url, args.bind, args.http_port, args.behind_proxy)
    require(args.source_stopped, 'DECLARE_SOURCE_STOPPED_BEFORE_PRODUCTION_ACTIVATION')
    require(not ROOT.exists() and not ROOT.is_symlink() and not TARGET.exists() and not TARGET.is_symlink(),
            'EXISTING_PRODUCTION_DATA_NO_OVERWRITE')
    require(not command(['docker', 'ps', '-aq']).stdout.strip(), 'EMPTY_DOCKER_HOST_REQUIRED')
    require(command(['docker', 'network', 'ls', '--filter', 'name=^' + NETWORK + '$', '-q']).stdout.strip() == b'', 'NETWORK_COLLISION')
    for name in units():
        require(not (UNIT_DIR / name).exists() and not (UNIT_DIR / name).is_symlink(), 'PRODUCTION_UNIT_COLLISION')
    with socket.socket() as probe: probe.bind(('127.0.0.1', ep['port']))
    r = controller()
    selected = r.selection(argparse.Namespace(manifest=None, input_directory=args.kit, identity=str(Path(args.kit) / 'recovery.key')))
    lab = r.engine(selected)
    preflight = r.verify(lab)
    images = r.local_images(lab, preflight)
    needed = preflight['payload_bytes'] + preflight['site']['expanded_file_bytes'] + max(2 * GIB, 8 * preflight['database_dump_bytes']) + 4 * GIB + 2 * preflight['images_archive_bytes']
    require(shutil.disk_usage(ROOT.parent).free >= needed, 'INSUFFICIENT_PRODUCTION_SPACE')
    require(os.statvfs(ROOT.parent).f_favail >= preflight['site']['regular_files'] + preflight['site']['directories'] + 10000, 'INSUFFICIENT_PRODUCTION_INODES')
    require(int(Path('/proc/meminfo').read_text().split('MemAvailable:')[1].split()[0]) * 1024 >= 5 * GIB, 'INSUFFICIENT_PRODUCTION_MEMORY')
    mkdir_private(ROOT)
    LOG = (ROOT / 'restore-private.log').open('xb'); os.chmod(ROOT / 'restore-private.log', 0o600)
    lab._LOG = LOG
    state = {'purpose': 'production', 'phase': 'extracting', 'token': uuid.uuid4().hex,
             'endpoint': ep, 'containers': {}, 'images': images, 'selection': selected,
             'preflight': preflight, 'started_utc': datetime.now(timezone.utc).isoformat()}
    save(STATE, state)
    try:
        print('PRODUCTION_RESTORE_PHASE=extracting', flush=True)
        lab.extract_payload(); lab.extract_site(preflight['site'])
        if len(images) != 2:
            require(not command(['docker', 'ps', '-aq']).stdout.strip(), 'DOCKER_HOST_NO_LONGER_EMPTY')
            with (ROOT / 'payload/images.tar').open('rb') as f: command(['docker', 'load'], source=f, timeout=900)
            state['images'] = r.local_images(lab, preflight)
            require(len(state['images']) == 2, 'ARCHIVED_IMAGES_MISSING_AFTER_LOAD')
        mkdir_private(TARGET); (TARGET / 'data').mkdir(mode=0o700); (TARGET / 'data/moodle').mkdir(mode=0o700)
        require(os.stat(ROOT).st_dev == os.stat(TARGET).st_dev, 'RESTORE_AND_TARGET_MUST_SHARE_FILESYSTEM')
        os.rename(ROOT / 'site/public', CODE); os.rename(ROOT / 'site/moodledata', DATA)
        PGDATA.mkdir(mode=0o700)
        original = (CODE / 'config.php').read_text()
        require((CODE / 'lib/setup.php').is_file() and (CODE / 'public/login/index.php').is_file(), 'UNSUPPORTED_PACKAGE_STRUCTURE')
        runtime_dir = DATA / 'ustar-production-runtime'
        require(not runtime_dir.exists(), 'RUNTIME_PATH_COLLISION')
        runtime_dir.mkdir(mode=0o700); os.chown(runtime_dir, 33, 33)
        for name in ('cache', 'localcache', 'temp', 'muc', 'sessions', 'locks'):
            (runtime_dir / name).mkdir(mode=0o700); os.chown(runtime_dir / name, 33, 33)
        password = uuid.uuid4().hex + uuid.uuid4().hex
        oldurl = write_config(lab, original, password, ep)
        # The public web entry must use exactly the rewritten root config.
        public_config = CODE / 'public/config.php'
        require(public_config.is_file() and not public_config.is_symlink(), 'PUBLIC_CONFIG_MISSING')
        temporary = public_config.parent / ('.production-bridge-' + uuid.uuid4().hex)
        write_private(temporary, "<?php\nrequire_once(dirname(__DIR__) . '/config.php');\n", 0o640, 33)
        os.replace(temporary, public_config)
        write_private(ROOT / 'pg.env', 'POSTGRES_USER=ustar_restore_admin\nPOSTGRES_DB=postgres\nPOSTGRES_PASSWORD=' + uuid.uuid4().hex + uuid.uuid4().hex + '\nPOSTGRES_INITDB_ARGS=--auth-host=scram-sha-256\n')
        write_private(ROOT / 'production.ini', 'memory_limit=512M\nmax_input_vars=5000\ndisplay_errors=Off\nlog_errors=On\n')
        write_private(ROOT / 'apache.conf', 'ServerName localhost\n<VirtualHost *:80>\nDocumentRoot /var/www/html/public\n<Directory /var/www/html/public>\nRequire all granted\nAllowOverride All\n</Directory>\nErrorLog /proc/self/fd/2\nCustomLog /proc/self/fd/1 combined\n</VirtualHost>\n')
        require(not command(['docker', 'ps', '-aq']).stdout.strip(), 'DOCKER_HOST_NO_LONGER_EMPTY')
        command(['docker', 'network', 'create', '--driver', 'bridge', '--label', LABEL + '=' + state['token'], NETWORK])
        owned_network(state)
        state['phase'] = 'restoring_database'; save(STATE, state)
        print('PRODUCTION_RESTORE_PHASE=database', flush=True)
        create_container('pg', state); pg_ready(state)
        sql(state, "CREATE ROLE ustar_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD '" + password + "';", 'postgres')
        exec_owned('pg', state, ['createdb', '-U', 'ustar_restore_admin', '-T', 'template0', '-O', 'ustar_app', 'ustar'])
        with (ROOT / 'payload/database.dump').open('rb') as f:
            exec_owned('pg', state, ['pg_restore', '--exit-on-error', '--single-transaction', '--no-owner', '--no-acl', '--no-tablespaces', '--no-subscriptions', '--no-publications', '--role=ustar_app', '-U', 'ustar_restore_admin', '-d', 'ustar'], source=f, timeout=900)
        state['counts_before'] = verify_database(state)
        state['file_references_verified'] = check_files(state)
        # Preserve task enabled/disabled flags, queued tasks, siteidentifier and business data.
        sql(state, 'DELETE FROM mdl_sessions;')
        create_container('web', state)
        exec_owned('web', state, ['php', '-l', '/var/www/html/config.php'], user='33:33')
        boot = json.loads(exec_owned('web', state, ['php', '-r', "define('CLI_SCRIPT',true);require '/var/www/html/config.php';require_once($CFG->libdir.'/upgradelib.php');echo json_encode(['needs_upgrade'=>moodle_needs_upgrading(),'url'=>$CFG->wwwroot]);"], user='33:33').stdout)
        require(boot == {'needs_upgrade': False, 'url': ep['url']}, 'BOOTSTRAP_OR_VERSION_MISMATCH_NO_UPGRADE')
        if oldurl != ep['url']:
            exec_owned('web', state, ['php', '/var/www/html/public/admin/tool/replace/cli/replace.php', '--search=' + oldurl, '--replace=' + ep['url'], '--shorten', '--non-interactive'], user='33:33', timeout=900)
        exec_owned('web', state, ['php', '/var/www/html/public/admin/cli/purge_caches.php'], user='33:33', timeout=180)
        require(verify_database(state) == state['counts_before'], 'RESTORED_COUNTS_OR_TASK_FLAGS_CHANGED')
        # Moodle's API updates both the DB and its configuration cache.
        exec_owned('web', state, ['php', '-r', "define('CLI_SCRIPT',true);require '/var/www/html/config.php';set_config('maintenance_enabled',0);"], user='33:33')
        write_config(lab, original, password, ep, active=True)
        effective = json.loads(exec_owned('web', state, ['php', '-r', "define('CLI_SCRIPT',true);require '/var/www/html/config.php';echo json_encode(['url'=>$CFG->wwwroot,'cron'=>(int)$CFG->cron_enabled,'mail'=>(bool)$CFG->noemailever,'maintenance'=>(bool)$CFG->maintenance_enabled]);"], user='33:33').stdout)
        require(effective == {'url': ep['url'], 'cron': 1, 'mail': False, 'maintenance': False}, 'PRODUCTION_CONFIG_NOT_EFFECTIVE')
        health(state)
        state.update(phase='activating', old_url=oldurl, task_flags_preserved=True, core_upgrade_executed=False)
        save(STATE, state); install_units()
        command(['systemctl', 'enable', RUNTIME_UNIT, CRON_TIMER])
        # Import lock and runtime lock are distinct; this cannot deadlock ExecStart.
        command(['systemctl', 'start', RUNTIME_UNIT], timeout=180)
        command(['systemctl', 'start', CRON_TIMER])
        state = read(STATE); require(state['phase'] == 'ready', 'RUNTIME_ACTIVATION_NOT_READY')
        print('PRODUCTION_RESTORE=PASS; PERMANENT; BOOT_ENABLED; CRON_ENABLED', flush=True)
        print(json.dumps({'url': ep['url'], 'file_references_verified': state['file_references_verified'],
                          'task_flags_preserved': True, 'manual_browser_and_SMTP': 'PENDING'}))
    except BaseException as exc:
        state['phase'] = 'failed'; state['failure_type'] = type(exc).__name__; save(STATE, state)
        # Disable boot/cron first if activation failed part-way. Never delete recovered data.
        command(['systemctl', 'disable', '--now', CRON_TIMER], optional=True)
        command(['systemctl', 'disable', RUNTIME_UNIT], optional=True)
        try: stop_owned(state)
        except Exception: state['phase'] = 'cleanup_required'
        save(STATE, state)
        raise
    finally: LOG.close(); LOG = None; lab._LOG = None


def source_identity(c):
    return {k: c[k] for k in ('Id', 'Image', 'Mounts')}


def source_exec(state, args):
    c = inspect('ustar_moodle')
    require(source_identity(c) == state['container'], 'SOURCE_CONTAINER_CHANGED')
    return command(['docker', 'exec', '--user', '33:33', c['Id']] + args, timeout=180)


def resume_source(target_stopped):
    require(target_stopped, 'DECLARE_TARGET_STOPPED_BEFORE_RESUMING_SOURCE')
    state = read(SOURCE_STATE)
    require(state.get('phase') in ('maintenance', 'freezing', 'frozen', 'resume_required'), 'SOURCE_NOT_FROZEN_OR_RECOVERABLE')
    c = inspect('ustar_moodle'); require(source_identity(c) == state['container'], 'SOURCE_CONTAINER_CHANGED')
    policy = state['restart_policy']
    require(policy.get('Name') in ('no', 'always', 'unless-stopped', 'on-failure'), 'UNSUPPORTED_SOURCE_RESTART_POLICY')
    name = policy['Name']
    if name == 'on-failure' and policy.get('MaximumRetryCount'): name += ':' + str(policy['MaximumRetryCount'])
    command(['docker', 'update', '--restart', name, c['Id']])
    if not c['State']['Running']: command(['docker', 'start', c['Id']])
    source_exec(state, ['php', '/var/www/html/public/admin/cli/maintenance.php', '--disable'])
    state['phase'] = 'resumed'; save(SOURCE_STATE, state)
    print('PRODUCTION_SOURCE=RESUMED')


def freeze_source():
    """Drain the known cron lock, maintenance, fresh backup, stop only source web.

    Refuses an in-flight cron rather than killing it. The shared backup adapter
    authenticates the HDD and exact source images. A frozen source stays frozen
    if packaging fails; --resume-source is the explicit abort path.
    """
    global LOG
    checked(BACKUP, BACKUP_SHA)
    mkdir_private(SOURCE_ROOT)
    if SOURCE_STATE.exists():
        previous = read(SOURCE_STATE)
        if previous['phase'] == 'frozen':
            c = inspect('ustar_moodle')
            require(source_identity(c) == previous['container'] and not c['State']['Running'], 'FROZEN_SOURCE_CHANGED')
            return previous
        require(previous['phase'] == 'resumed', 'SOURCE_RECOVERY_REQUIRED')
    cronlock = SOURCE_CRON_LOCK
    trusted(cronlock, private=True)
    fd = os.open(cronlock, os.O_RDWR | os.O_NOFOLLOW)
    try:
        try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError: raise ProductionError('SOURCE_CRON_BUSY_NO_STOP') from None
        c = inspect('ustar_moodle'); require(c['State']['Running'], 'SOURCE_WEB_NOT_RUNNING')
        old_success = read(LATEST)
        require(old_success.get('success') is True, 'SOURCE_BASELINE_BACKUP_MISSING')
        old_sha = old_success['manifest']['sha256']
        state = {'phase': 'preparing', 'container': source_identity(c),
                 'restart_policy': c['HostConfig']['RestartPolicy'], 'started_utc': datetime.now(timezone.utc).isoformat()}
        p = source_exec(state, ['php', '-r', "define('CLI_SCRIPT',true);require '/var/www/html/config.php';echo !empty($CFG->maintenance_enabled)?'1':'0';"])
        require(p.stdout.strip() == b'0', 'SOURCE_ALREADY_IN_MAINTENANCE')
        save(SOURCE_STATE, state)
        LOG = (SOURCE_ROOT / ('freeze-' + uuid.uuid4().hex + '.log')).open('xb')
        try:
            state['phase'] = 'maintenance'; save(SOURCE_STATE, state)
            source_exec(state, ['php', '/var/www/html/public/admin/cli/maintenance.php', '--enable'])
            maintenance = source_exec(state, ['php', '-r', "define('CLI_SCRIPT',true);require '/var/www/html/config.php';echo !empty($CFG->maintenance_enabled)?'1':'0';"])
            require(maintenance.stdout.strip() == b'1', 'SOURCE_MAINTENANCE_NOT_EFFECTIVE')
            print('PRODUCTION_SOURCE_PHASE=fresh_backup_in_maintenance', flush=True)
            state['backup_started'] = True; save(SOURCE_STATE, state)
            command(['python3', '-I', '-B', '-u', str(BACKUP), '--backup', '--acknowledge-outage'], timeout=1800)
            state['phase'] = 'freezing'; save(SOURCE_STATE, state)
            command(['docker', 'update', '--restart', 'no', c['Id']])
            command(['docker', 'stop', '--time', '60', c['Id']], timeout=90)
            current = inspect('ustar_moodle')
            require(source_identity(current) == state['container'] and not current['State']['Running'], 'SOURCE_NOT_FROZEN')
            latest = read(LATEST)
            require(latest.get('success') is True and latest.get('phase') == 'complete'
                    and latest['manifest']['sha256'] != old_sha, 'FRESH_BACKUP_NOT_COMPLETE')
            state.update(phase='frozen', archive_sha256=latest['manifest']['sha256'],
                         frozen_utc=datetime.now(timezone.utc).isoformat())
            save(SOURCE_STATE, state)
            print('PRODUCTION_SOURCE=FROZEN; KEEP_STOPPED_UNTIL_CUTOVER_OR_EXPLICIT_ABORT', flush=True)
            return state
        except BaseException:
            # A failed backup cannot silently leave the original site in maintenance.
            if state['phase'] in ('maintenance', 'freezing'):
                try:
                    if state.get('backup_started'):
                        command(['python3', '-I', '-B', '-u', str(BACKUP), '--recover'], timeout=180)
                    resume_source(True)
                except Exception:
                    state['phase'] = 'resume_required'; save(SOURCE_STATE, state)
            raise
        finally: LOG.close(); LOG = None
    finally: os.close(fd)


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    modes = ap.add_mutually_exclusive_group(required=True)
    for name in ('restore-production', 'resume-production', 'stop-production', 'cron-production', 'production-status', 'resume-source'):
        modes.add_argument('--' + name, action='store_true')
    ap.add_argument('--kit'); ap.add_argument('--url'); ap.add_argument('--bind', default='0.0.0.0')
    ap.add_argument('--http-port', type=int, default=80); ap.add_argument('--behind-proxy', action='store_true')
    ap.add_argument('--source-stopped', action='store_true'); ap.add_argument('--target-stopped', action='store_true')
    args = ap.parse_args()
    require(os.geteuid() == 0, 'RUN_WITH_SUDO'); os.umask(0o077)
    if args.production_status:
        if not STATE.exists(): print(json.dumps({'phase': 'not_started'})); return
        s = load_state()
        print(json.dumps({k: s.get(k) for k in ('phase', 'endpoint', 'task_flags_preserved', 'file_references_verified', 'last_cron_success_utc', 'failure_type')}, indent=2)); return
    def interrupted(*_): raise KeyboardInterrupt()
    for sig in (signal.SIGTERM, signal.SIGHUP): signal.signal(sig, interrupted)
    if args.restore_production:
        require(args.kit and args.url, 'KIT_AND_URL_REQUIRED')
        with lock('ustar-production-import.lock'): restore(args)
    elif args.resume_source:
        with lock('ustar-source-transfer.lock'): resume_source(args.target_stopped)
    else:
        runtime('resume' if args.resume_production else 'stop' if args.stop_production else 'cron')


if __name__ == '__main__':
    try: main()
    except (Exception, KeyboardInterrupt) as exc:
        code = str(exc) if isinstance(exc, ProductionError) else type(exc).__name__
        print('PRODUCTION_RECOVERY_ERROR=' + code, file=sys.stderr); sys.exit(1)
