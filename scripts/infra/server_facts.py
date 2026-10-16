#!/usr/bin/env python3
"""Bounded server inventory. No Moodle bootstrap, HTTP, raw logs or query text.

Collection uses only the Python standard library; import needs the repo's PyYAML.
Only structured, allowlisted observations are exported. Failures remain evidence.
"""
import sys
sys.dont_write_bytecode = True
import argparse
from datetime import datetime, timezone
import hashlib
import io
import ipaddress
import json
import math
import os
from pathlib import Path, PurePosixPath
import pwd
import re
import selectors
import signal
import stat
import subprocess
import tarfile
import tempfile
import time
import zlib

VERSION = 1
LIMIT = 512 * 1024
FILES = ('facts.json', 'errors.json', 'README.md', 'manifest.json')
SAFE_ENV = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL': 'C',
            'LANG': 'C', 'DOCKER_CONFIG': '/nonexistent'}
REASONS = {'measured', 'unavailable', 'invalid_data', 'timeout', 'output_limit',
           'not_requested', 'dependency_unavailable', 'budget_exhausted', 'incomplete_source'}
STATE = ('ok', 'partial', 'error', 'not_collected')
HEX = r'[0-9a-f]{64}'
STAMP = r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ'
IDENT = r'[A-Za-z_][A-Za-z0-9_.-]{0,127}'
TOKEN = r'[A-Za-z0-9_ .:/+()=,\\@%\[\]-]{0,240}'
NULL = lambda x: ('nullable', x)
ROW = lambda fields: {'rows': [fields]}

# Closed shapes: an extra field such as Env, cookie, query or connection URI fails validation.
SCHEMAS = {
 'host': {'os_id': IDENT, 'os_version': TOKEN, 'kernel': TOKEN, 'cores': int,
          'memory_total_bytes': int, 'load1': float},
 'clock': {'timezone':TOKEN,'ntp_enabled':NULL(bool),'ntp_synchronized':NULL(bool)},
 'filesystems': {'items': [{'path': 'path', 'bytes_total': int, 'bytes_available': int,
                          'inodes_total': int, 'inodes_available': int,
                          'filesystem':IDENT,'uuid':NULL(r'[A-Fa-f0-9-]{4,64}'),
                          'mount_options':[IDENT]}]},
 'containers': {'items': [{'name': IDENT, 'image_id': r'sha256:[0-9a-f]{64}',
   'state': IDENT, 'running': bool, 'readonly_rootfs': bool, 'restart_policy': IDENT,
   'restart_count': int, 'oom_killed': bool, 'health': IDENT,
   'memory_limit': int, 'nano_cpus': int, 'pids_limit': NULL(int),
   'log_driver': IDENT, 'log_max_size': TOKEN, 'log_max_file': TOKEN,
   'mounts': [{'source': 'path', 'destination': 'path', 'rw': bool}]}]},
 'code_layout': {'public_root': 'path', 'config_path': NULL('path'), 'data_root': NULL('path'),
   'db_name': NULL(IDENT), 'db_role': NULL(IDENT), 'table_prefix': NULL(IDENT),
   'core_disk': NULL(TOKEN), 'plugin_disk': NULL(TOKEN), 'theme_disk': NULL(TOKEN),
   'config_keys_present': [IDENT],
   'flags': {'cookiesecure': NULL(bool), 'cookiehttponly': NULL(bool),
             'sslproxy': NULL(bool), 'reverseproxy': NULL(bool)}},
 'source_manifest': {'files': [{'path': 'relative', 'sha256': HEX}], 'skipped': int,
   'changed_during_read': int, 'scope': r'plugin_theme_only', 'not_atomic': bool},
 'code_access': {'items': [{'path': 'path', 'uid': int, 'gid': int, 'mode': r'[0-7]{3,4}',
   'www_data_writable': NULL(bool), 'acl_available': bool,
   'acl': [{'kind': r'(default_)?(user|group|mask|other)', 'id': NULL(int), 'permissions': r'[rwx-]{3}'}]}]},
 'php_cli': {'version': TOKEN, 'modules': [r'[A-Za-z][A-Za-z0-9_ ]{0,63}'], 'settings': [{'name': IDENT, 'value': TOKEN}]},
 'pg_roles': ROW({'name': IDENT, 'superuser': bool, 'create_role': bool, 'create_db': bool,
                 'login': bool, 'replication': bool, 'bypass_rls': bool, 'connection_limit': int}),
 'pg_databases': ROW({'name': IDENT, 'owner': IDENT, 'bytes': int}),
 'pg_settings': ROW({'name': IDENT, 'setting': TOKEN}),
 'pg_extensions': ROW({'name': IDENT, 'version': TOKEN}),
 'pg_owners': ROW({'schema': IDENT, 'owner': IDENT, 'tables': int}),
 'pg_tables': ROW({'name': IDENT, 'rows_estimate': int, 'dead_estimate': int, 'bytes': int,
                  'index_scans': int, 'sequence_scans': int}),
 'pg_query_stats': ROW({'query_id': r'-?\d+', 'calls': int, 'mean_ms': float, 'total_ms': float}),
 'pg_connections': ROW({'state': TOKEN, 'count': int}),
 'moodle_config': ROW({'name': IDENT, 'value': TOKEN}),
 'moodle_plugins': ROW({'name': IDENT, 'version': r'\d{6,14}'}),
 'scheduled_tasks': ROW({'classname': r'\\?[A-Za-z_][A-Za-z0-9_\\]{0,200}',
   'disabled': bool, 'last_run': int, 'next_run': int, 'fail_delay': int}),
 'adhoc_tasks': ROW({'total': int, 'failed': int, 'oldest_next': int}),
 'cron_receipt': {'present': bool, 'finished_at': NULL(STAMP), 'worker_exit_code': NULL(int),
   'new_task_failures':NULL(int),'scheduled_failed':NULL(int),'scheduled_overdue_10m':NULL(int),
   'adhoc_queued':NULL(int),'latest_task_at':NULL(STAMP),'ustar_error_lines':NULL(int),'sha256': NULL(HEX)},
 'backup_receipt': {'present': bool, 'finished_at': NULL(STAMP), 'success_reported': NULL(bool),
   'archive_name': NULL(r'ustar-[A-Za-z0-9_.-]{1,180}\.age'), 'bytes': NULL(int),
   'archive_sha256': NULL(HEX), 'readback_reported': NULL(bool),
   'archive_stat_matches': NULL(bool), 'recovery_marker_present': bool,
   'attempt_finished_at': NULL(STAMP), 'attempt_success': NULL(bool)},
 'systemd': {'units': [{'name': IDENT, 'load': IDENT, 'active': IDENT, 'sub': IDENT,
                       'result': IDENT, 'enabled': TOKEN, 'exit_status': NULL(int)}]},
 'listeners': {'items': [{'protocol': r'tcp|udp', 'port': int,
                         'scope': r'all|loopback|private|public|unknown'}]},
 'sshd_global': {'settings': [{'name': IDENT, 'value': TOKEN}], 'match_context_checked': bool},
 'firewall': {'backend': r'ufw|nft', 'active': NULL(bool), 'rules': int, 'policies': [r'accept|drop']},
 'apache': {'vhost_count': int, 'ports': [int], 'modules': [IDENT], 'tls_function_verified': bool},
 'certificates': {'items': [{'fingerprint': HEX, 'not_before': TOKEN, 'not_after': TOKEN}]},
 'packages': ROW({'name': IDENT, 'version': TOKEN}),
 'log_storage': {'journal_bytes': int, 'top_level_regular_log_bytes': int,
                 'recursive_usage_measured': bool},
 'smart': {'items': [{'device': r'/dev/[A-Za-z0-9_-]+', 'health_passed': NULL(bool),
                      'temperature': NULL(int), 'exit_status': int}]},
}

class Refusal(Exception):
    pass

def stamp():
    return datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')

def digest(data):
    return hashlib.sha256(data).hexdigest()

def encode(value):
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2, allow_nan=False) + '\n').encode()

def pairs(items):
    result = {}
    for key, value in items:
        if key in result:
            raise Refusal('duplicate_json_key')
        result[key] = value
    return result

def decode(data):
    return json.loads(data.decode('utf-8'), object_pairs_hook=pairs,
                      parse_constant=lambda _: (_ for _ in ()).throw(Refusal('nonfinite_number')))

def sensitive(value):
    if re.search(r'BEGIN [A-Z ]*PRIVATE KEY|\b(?:gh[pousr]_|github_pat_)[A-Za-z0-9_]{20,}|\b\d{7,14}:[A-Za-z0-9_-]{30,}|eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+', value):
        return True
    if re.search(r'(?:password|dbpass|secret|api[_-]?key|token)\s*[=:]\s*\S+', value, re.I):
        return True
    for word in re.findall(r'(?<![\w.])\d{1,3}(?:\.\d{1,3}){3}(?![\w.])|(?<![\w:])[a-fA-F0-9]*:[a-fA-F0-9:]+', value):
        try:
            ip = ipaddress.ip_address(word)
            if ip.is_private and not ip.is_loopback:
                return True
        except ValueError:
            pass
    return False

def shape(value, spec):
    if isinstance(spec, tuple):
        if value is not None:
            shape(value, spec[1])
    elif isinstance(spec, dict):
        if not isinstance(value, dict) or set(value) != set(spec):
            raise Refusal('unexpected_fields')
        for key, subspec in spec.items():
            shape(value[key], subspec)
    elif isinstance(spec, list):
        if not isinstance(value, list) or len(value) > 1500:
            raise Refusal('invalid_list')
        for item in value:
            shape(item, spec[0])
    elif spec in (int, bool, float):
        if spec is float:
            if type(value) not in (int, float) or not math.isfinite(value):
                raise Refusal('invalid_number')
        elif type(value) is not spec:
            raise Refusal('invalid_scalar')
        if spec is int and not -(2**63) <= value < 2**64:
            raise Refusal('integer_limit')
    else:
        if not isinstance(value, str) or len(value) > 300 or sensitive(value):
            raise Refusal('unsafe_string')
        if spec in ('path', 'relative'):
            path = PurePosixPath(value)
            if '..' in path.parts or not re.fullmatch(r'[A-Za-z0-9_./-]+', value):
                raise Refusal('unsafe_path')
            if spec == 'path' and not value.startswith(('/opt/ustar/', '/srv/ustar-storage', '/var/lib/ustar-', '/var/www/', '/etc/', '/')):
                raise Refusal('invalid_absolute_path')
            if spec == 'path' and not path.is_absolute() or spec == 'relative' and path.is_absolute():
                raise Refusal('path_kind')
        elif not re.fullmatch(spec, value):
            raise Refusal('invalid_string')

def read_regular(path, limit=LIMIT):
    """Anchor every component. No links, special files, oversized or changing input."""
    path = Path(path).absolute()
    if '..' in path.parts:
        raise Refusal('parent_path_forbidden')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY)
    try:
        for part in path.parts[1:-1]:
            next_fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = next_fd
        leaf = os.open(path.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd)
        try:
            before = os.fstat(leaf)
            if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_size > limit:
                raise Refusal('unsafe_input_file')
            data = bytearray()
            while len(data) <= limit:
                part = os.read(leaf, min(65536, limit + 1 - len(data)))
                if not part:
                    break
                data.extend(part)
            after = os.fstat(leaf)
            if len(data) > limit or (before.st_ino, before.st_size, before.st_mtime_ns) != (after.st_ino, after.st_size, after.st_mtime_ns):
                raise Refusal('input_changed_or_large')
            return bytes(data)
        finally:
            os.close(leaf)
    finally:
        os.close(fd)

def command(argv, timeout=4, accept_nonzero=False, include_stderr=False):
    """Fixed argv/launchers only. Bound both streams and the process group."""
    proc = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            env=SAFE_ENV, start_new_session=True)
    data = bytearray()
    count = 0
    deadline = time.monotonic() + timeout
    try:
        with selectors.DefaultSelector() as sel:
            sel.register(proc.stdout, selectors.EVENT_READ, True)
            sel.register(proc.stderr, selectors.EVENT_READ, False)
            while sel.get_map():
                if time.monotonic() >= deadline:
                    raise Refusal('timeout')
                for key, _ in sel.select(min(.1, max(0, deadline - time.monotonic()))):
                    part = os.read(key.fileobj.fileno(), 65536)
                    if not part:
                        sel.unregister(key.fileobj)
                    else:
                        count += len(part)
                        if count > LIMIT:
                            raise Refusal('output_limit')
                        if key.data or include_stderr:
                            data.extend(part)
            code = proc.wait(timeout=max(.01, deadline - time.monotonic()))
        if code and not accept_nonzero:
            raise Refusal('unavailable')
        return data.decode('utf-8'), code
    finally:
        if proc.poll() is None:
            try:
                os.killpg(proc.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            proc.wait()
        proc.stdout.close()
        proc.stderr.close()

def text(argv, **kwargs):
    return command(argv, **kwargs)[0]

def literal(config, key):
    # Never eval/require config.php. Dynamic, duplicate and commented assignments are unknown.
    code = re.sub(r'/\*.*?\*/|//[^\n]*|#[^\n]*', '', config, flags=re.S)
    found = re.findall(r'\$CFG->' + re.escape(key) + r'\s*=\s*([^;\n]+);', code)
    if len(found) != 1:
        return None
    value = found[0].strip()
    if value in ('true', 'false', '1', '0'):
        return value in ('true', '1')
    match = re.fullmatch(r"'([A-Za-z0-9_./-]+)'|\"([A-Za-z0-9_./-]+)\"", value)
    return next((v for v in match.groups() if v is not None), None) if match else None

def disk_version(path):
    try:
        found = re.search(rb'\$(?:plugin->)?version\s*=\s*([0-9.]+)', read_regular(path))
        return found[1].decode() if found else None
    except (OSError, Refusal):
        return None

def layout(containers, override=None):
    moodle = next(x for x in containers['items'] if x['name'] == 'ustar_moodle')
    candidates = set()
    for mount in moodle['mounts']:
        for suffix in ('', '/public'):
            path = Path(mount['source'] + suffix)
            if (path/'local/ustar/version.php').is_file() and (path/'theme/ustar/version.php').is_file():
                candidates.add(path)
    if override:
        root = Path(override)
        if root not in candidates:
            raise Refusal('invalid_data')
    elif len(candidates) == 1:
        root = candidates.pop()
    else:
        raise Refusal('dependency_unavailable')
    configs = []
    for candidate in (root/'config.php', root.parent/'config.php'):
        try:
            raw = read_regular(candidate).decode()
            if literal(raw, 'prefix') is not None:
                configs.append((candidate, raw))
        except (OSError, Refusal, UnicodeError):
            pass
    config, raw = configs[0] if len(configs) == 1 else (None, '')
    def ident(key):
        value = literal(raw, key)
        return value if isinstance(value, str) and re.fullmatch(IDENT, value) else None
    data_roots = [m['source'] for m in moodle['mounts'] if m['destination'] == '/var/www/moodledata']
    return {'public_root': str(root), 'config_path': str(config) if config else None,
      'data_root': data_roots[0] if len(data_roots) == 1 else None,
      'db_name': ident('dbname'), 'db_role': ident('dbuser'), 'table_prefix': ident('prefix'),
      'core_disk': disk_version(root/'version.php'), 'plugin_disk': disk_version(root/'local/ustar/version.php'),
      'theme_disk': disk_version(root/'theme/ustar/version.php'),
      'config_keys_present': sorted(set(re.findall(r'\$CFG->([A-Za-z_][A-Za-z_0-9]*)\s*=', raw))),
      'flags': {k: v if type(v := literal(raw, k)) is bool else None
                for k in ('cookiesecure', 'cookiehttponly', 'sslproxy', 'reverseproxy')}}

def containers():
    fmt = r'''{"name":{{json .Name}},"image_id":{{json .Image}},"state":{{json .State.Status}},"running":{{json .State.Running}},"readonly_rootfs":{{json .HostConfig.ReadonlyRootfs}},"restart_policy":{{json .HostConfig.RestartPolicy.Name}},"restart_count":{{json .RestartCount}},"oom_killed":{{json .State.OOMKilled}},"health":{{if .State.Health}}{{json .State.Health.Status}}{{else}}"not_configured"{{end}},"memory_limit":{{json .HostConfig.Memory}},"nano_cpus":{{json .HostConfig.NanoCpus}},"pids_limit":{{json .HostConfig.PidsLimit}},"log_driver":{{json .HostConfig.LogConfig.Type}},"log_max_size":{{json (index .HostConfig.LogConfig.Config "max-size")}},"log_max_file":{{json (index .HostConfig.LogConfig.Config "max-file")}},"mounts":[{{range $i,$m := .Mounts}}{{if $i}},{{end}}{"source":{{json $m.Source}},"destination":{{json $m.Destination}},"rw":{{json $m.RW}}}{{end}}]}'''
    rows = []
    for name in ('ustar_moodle', 'ustar_postgres'):
        row = decode(text(['docker', 'inspect', '--format', fmt, name]).encode())
        row['name'] = row['name'].lstrip('/')
        for key in ('log_max_size', 'log_max_file'):
            row[key] = row[key] or 'not_configured'
        # Named volumes may expose other paths. Keep only the two selected containers' mount locations.
        rows.append(row)
    return {'items': rows}

PSQL = '''export PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=3000 -c lock_timeout=500 -c search_path=pg_catalog,public'
db="${1:-${POSTGRES_DB:-${POSTGRES_USER:-postgres}}}"; shift
exec psql -X -w -qAt -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:-postgres}" -d "$db" "$@"
'''

def sql(query, db=''):
    output = text(['docker', 'exec', 'ustar_postgres', 'sh', '-c', PSQL, 'psql-facts', db,
                   '-c', 'SELECT COALESCE(json_agg(f),\'[]\'::json) FROM (' + query + ') f'])
    return {'rows': decode(output.encode())}

SQL = {
 'pg_roles': "SELECT rolname AS name, rolsuper AS superuser, rolcreaterole AS create_role, rolcreatedb AS create_db, rolcanlogin AS login, rolreplication AS replication, rolbypassrls AS bypass_rls, rolconnlimit AS connection_limit FROM pg_catalog.pg_roles ORDER BY rolname LIMIT 500",
 'pg_databases': "SELECT datname AS name, pg_get_userbyid(datdba) AS owner, pg_database_size(oid) AS bytes FROM pg_catalog.pg_database WHERE datallowconn ORDER BY datname LIMIT 100",
 'pg_settings': "SELECT name, CASE WHEN name='archive_command' THEN CASE WHEN setting='' THEN 'not_configured' ELSE 'configured' END ELSE setting END AS setting FROM pg_catalog.pg_settings WHERE name IN ('server_version','max_connections','shared_buffers','work_mem','maintenance_work_mem','effective_cache_size','wal_level','archive_mode','archive_command','max_wal_size','min_wal_size','checkpoint_timeout','autovacuum','autovacuum_vacuum_scale_factor','autovacuum_analyze_scale_factor','log_min_duration_statement') ORDER BY name",
 'pg_extensions': "SELECT extname AS name, extversion AS version FROM pg_catalog.pg_extension ORDER BY extname",
 'pg_owners': "SELECT schemaname AS schema, tableowner AS owner, count(*) AS tables FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema') GROUP BY schemaname,tableowner ORDER BY 1,2",
 'pg_tables': "SELECT relname AS name, n_live_tup AS rows_estimate, n_dead_tup AS dead_estimate, pg_total_relation_size(relid) AS bytes, coalesce(idx_scan,0) AS index_scans, seq_scan AS sequence_scans FROM pg_catalog.pg_stat_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 80",
 'pg_query_stats': "SELECT queryid::text AS query_id,calls,mean_exec_time AS mean_ms,total_exec_time AS total_ms FROM public.pg_stat_statements ORDER BY total_exec_time DESC LIMIT 30",
 'pg_connections': "SELECT coalesce(state,'unknown') AS state,count(*) FROM pg_catalog.pg_stat_activity GROUP BY state ORDER BY 1",
}

def moodle_queries(prefix):
    if not prefix or not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,40}', prefix):
        raise Refusal('dependency_unavailable')
    table = lambda name: 'public."' + prefix + name + '"'
    return {
      'moodle_config': 'SELECT name,value FROM ' + table('config') + " WHERE name IN ('version','release','cookiesecure','cookiehttponly','maintenance_enabled') ORDER BY name",
      'moodle_plugins': 'SELECT plugin AS name,value AS version FROM ' + table('config_plugins') + " WHERE name='version' AND plugin IN ('local_ustar','theme_ustar') ORDER BY plugin",
      'scheduled_tasks': 'SELECT classname,disabled::boolean AS disabled,coalesce(lastruntime,0) AS last_run,coalesce(nextruntime,0) AS next_run,coalesce(faildelay,0) AS fail_delay FROM ' + table('task_scheduled') + ' ORDER BY coalesce(lastruntime,0),classname LIMIT 500',
      'adhoc_tasks': 'SELECT count(*) AS total,coalesce(sum(CASE WHEN faildelay>0 THEN 1 ELSE 0 END),0) AS failed,coalesce(min(nextruntime),0) AS oldest_next FROM ' + table('task_adhoc'),
    }

def source_manifest(root):
    files, skipped, changed = [], 0, 0
    deadline = time.monotonic() + 20
    for scope in ('local/ustar', 'theme/ustar'):
        base = Path(root)/scope
        if not base.is_dir() or base.is_symlink():
            raise Refusal('unavailable')
        def unreadable(_):
            nonlocal skipped
            skipped += 1
        for parent, dirs, names in os.walk(base, followlinks=False,onerror=unreadable):
            for name in list(dirs):
                if name.startswith('.') or (Path(parent)/name).is_symlink():
                    dirs.remove(name)
                    skipped += 1
            for name in sorted(names):
                if time.monotonic() > deadline or len(files) >= 1500:
                    raise Refusal('budget_exhausted')
                path = Path(parent)/name
                relative = path.relative_to(root).as_posix()
                if (name.startswith('.') and relative != 'local/ustar/.org-roles-install.lock' or
                    name.lower() == 'config.php' and relative != 'theme/ustar/config.php' or
                    path.suffix.lower() in {'.key','.pem','.age','.dump','.sql'}):
                    skipped += 1
                    continue
                try:
                    shape(relative, 'relative')
                    data = read_regular(path, 8*1024*1024)
                    files.append({'path': relative, 'sha256': digest(data)})
                except (OSError, Refusal) as exc:
                    skipped += 1
                    if isinstance(exc,Refusal) and str(exc)=='input_changed_or_large':
                        changed += 1
    return {'files': sorted(files, key=lambda x:x['path']), 'skipped': skipped,
            'changed_during_read': changed, 'scope': 'plugin_theme_only', 'not_atomic': True}

def host():
    # /etc/os-release is commonly a symlink. Read only the two fixed conventional files.
    release_path = '/usr/lib/os-release' if Path('/etc/os-release').is_symlink() else '/etc/os-release'
    release = dict(re.findall(r'^([A-Z_]+)=(.*)$', read_regular(release_path).decode(), re.M))
    memory = re.search(rb'^MemTotal:\s+(\d+)', read_regular('/proc/meminfo'), re.M)
    return {'os_id': release.get('ID','unknown').strip('"'),
      'os_version': release.get('VERSION_ID','unknown').strip('"'),
      'kernel': os.uname().release, 'cores': os.cpu_count() or 0,
      'memory_total_bytes': int(memory[1])*1024 if memory else 0, 'load1': os.getloadavg()[0]}

def filesystems():
    items = []
    for path in ('/', '/srv/ustar-storage'):
        if path != '/' and not os.path.ismount(path):
            raise Refusal('dependency_unavailable')
        v = os.statvfs(path)
        fs=decode(text(['findmnt','--json','--target',path,'--output','FSTYPE,UUID,OPTIONS']).encode())['filesystems'][0]
        items.append({'path':path,'bytes_total':v.f_blocks*v.f_frsize,'bytes_available':v.f_bavail*v.f_frsize,
                      'inodes_total':v.f_files,'inodes_available':v.f_favail,
                      'filesystem':fs['fstype'],'uuid':fs.get('uuid'),
                      'mount_options':sorted(set(fs.get('options','').split(',')) &
                       {'rw','ro','nosuid','nodev','noexec','relatime','noatime','discard'})})
    return {'items':items}

def clock():
    raw=text(['timedatectl','show','--property=Timezone,NTP,NTPSynchronized'])
    values=dict(line.split('=',1) for line in raw.splitlines() if '=' in line)
    return {'timezone':values.get('Timezone','unknown'),
      'ntp_enabled':{'yes':True,'no':False}.get(values.get('NTP')),
      'ntp_synchronized':{'yes':True,'no':False}.get(values.get('NTPSynchronized'))}

def mapped(path, mounts):
    choices = [(len(Path(m['source']).parts), str(Path(m['destination'])/Path(path).relative_to(m['source'])))
               for m in mounts if Path(path).is_relative_to(m['source'])]
    return max(choices)[1] if choices else None

def code_access(info, cts):
    rows = []
    root = Path(info['public_root'])
    paths = [str(root), str(root/'local/ustar'), str(root/'theme/ustar'),
             info['config_path'], info['data_root'], '/opt/ustar/data/postgres']
    mounts = next(x for x in cts['items'] if x['name']=='ustar_moodle')['mounts']
    for value in filter(None, paths):
        path = Path(value)
        mode = path.lstat()
        if stat.S_ISLNK(mode.st_mode):
            raise Refusal('invalid_data')
        target = mapped(value, mounts)
        writable = None
        if target:
            try:
                result=text(['docker','exec','-u','www-data','ustar_moodle','sh','-c',
                  'if test -w "$1"; then printf writable; else printf readonly; fi','access-facts',target])
                writable = True if result=='writable' else False if result=='readonly' else None
            except (OSError,Refusal):
                pass
        acl, available = [], False
        try:
            output = text(['getfacl','--numeric','--omit-header','--',value])
            for line in output.splitlines():
                match = re.fullmatch(r'(default:)?(user|group|mask|other):(\d*):([rwx-]{3})(?:\s+#effective:[rwx-]{3})?',line)
                if match:
                    acl.append({'kind':('default_' if match[1] else '')+match[2],
                                'id':int(match[3]) if match[3] else None,'permissions':match[4]})
            available = True
        except (OSError, Refusal):
            pass
        rows.append({'path':value,'uid':mode.st_uid,'gid':mode.st_gid,'mode':oct(stat.S_IMODE(mode.st_mode))[2:],
                     'www_data_writable':writable,'acl_available':available,'acl':acl})
    return {'items':rows}

def iso(value):
    if not isinstance(value,str):
        return None
    try:
        parsed=datetime.fromisoformat(value.replace('Z','+00:00'))
        return parsed.astimezone(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ') if parsed.tzinfo else None
    except ValueError:
        return None

def optional_json(path):
    try:
        return decode(read_regular(path))
    except FileNotFoundError:
        return None

def cron_receipt():
    path='/var/lib/ustar-cron/last-run.json'
    value=optional_json(path)
    value=value or {}
    after=value.get('after') or {}
    integer=lambda item:item if type(item) is int else None
    return {'present':bool(value),'finished_at':iso(value.get('finished_utc')),
      'worker_exit_code':integer(value.get('cron_exit_code')),
      'new_task_failures':len(after['new_failures']) if isinstance(after.get('new_failures'),list) else None,
      'scheduled_failed':integer(after.get('scheduled_failed')),
      'scheduled_overdue_10m':integer(after.get('scheduled_overdue_10m')),
      'adhoc_queued':integer(after.get('adhoc_queued')),
      'latest_task_at':iso(after.get('latest_task_utc')),
      'ustar_error_lines':integer((value.get('ustar') or {}).get('error_lines')),
      'sha256':digest(read_regular(path)) if value else None}

def backup_receipt():
    value=optional_json('/var/lib/ustar-backup/last-hdd-success.json')
    attempt=optional_json('/var/lib/ustar-backup/last-hdd-attempt.json') or {}
    manifest=(value or {}).get('manifest') or {}
    name=manifest.get('file')
    if name is not None and not re.fullmatch(r'ustar-[A-Za-z0-9_.-]{1,180}\.age',name):
        raise Refusal('invalid_data')
    actual=None
    if name and os.path.ismount('/srv/ustar-storage'):
        try:
            p=Path('/srv/ustar-storage/backups/managed')/name
            actual=not p.is_symlink() and stat.S_ISREG(p.lstat().st_mode) and p.stat().st_size==manifest.get('bytes')
        except FileNotFoundError:
            actual=False
    return {'present':value is not None,'finished_at':iso((value or {}).get('finished_utc')),
      'success_reported':(value or {}).get('success'),'archive_name':name,'bytes':manifest.get('bytes'),
      'archive_sha256':manifest.get('sha256'),'readback_reported':manifest.get('readback_verified'),
      'archive_stat_matches':actual,'recovery_marker_present':os.path.lexists('/var/lib/ustar-backup/moodle-was-running.json'),
      'attempt_finished_at':iso(attempt.get('finished_utc')),'attempt_success':attempt.get('success')}

def php_cli():
    code='echo json_encode(["version"=>PHP_VERSION,"modules"=>get_loaded_extensions(),"settings"=>array_map(fn($k)=>["name"=>$k,"value"=>strval(ini_get($k))],["memory_limit","max_input_vars","upload_max_filesize","post_max_size","max_execution_time","opcache.enable","opcache.memory_consumption","display_errors"])]);'
    return decode(text(['docker','exec','ustar_moodle','php','-r',code]).encode())

def systemd():
    rows=[]
    for name in ('ustar-hdd-backup.timer','ustar-hdd-backup.service','ustar-hdd-backup-recover.service',
                 'docker.service','apache2.service','ssh.service'):
        raw=text(['systemctl','show',name,'--no-pager','--property=LoadState,ActiveState,SubState,Result,UnitFileState,ExecMainStatus'])
        d=dict(line.split('=',1) for line in raw.splitlines() if '=' in line)
        rows.append({'name':name,'load':d.get('LoadState') or 'unknown','active':d.get('ActiveState') or 'unknown',
          'sub':d.get('SubState') or 'unknown','result':d.get('Result') or 'not_reported',
          'enabled':d.get('UnitFileState') or 'not_reported','exit_status':int(d['ExecMainStatus']) if d.get('ExecMainStatus','').isdigit() else None})
    return {'units':rows}

def listeners():
    rows=[]
    for line in text(['ss','-H','-lntu']).splitlines():
        parts=line.split()
        if len(parts)<5 or parts[0] not in ('tcp','udp'):
            continue
        address,_,port=parts[4].rpartition(':')
        if not port.isdigit():
            continue
        address=address.strip('[]')
        try:
            ip=ipaddress.ip_address(address.split('%')[0])
            scope='all' if ip.is_unspecified else 'loopback' if ip.is_loopback else 'private' if ip.is_private else 'public'
        except ValueError:
            scope='all' if address=='*' else 'unknown'
        rows.append({'protocol':parts[0],'port':int(port),'scope':scope})
    return {'items':sorted(rows,key=lambda x:(x['protocol'],x['port'],x['scope']))}

def sshd():
    wanted={'permitrootlogin','passwordauthentication','pubkeyauthentication','permitemptypasswords',
            'allowtcpforwarding','x11forwarding','maxauthtries','port','logingracetime','strictmodes'}
    rows=[]
    for line in text(['sshd','-T']).splitlines():
        key,_,value=line.partition(' ')
        if key in wanted:
            rows.append({'name':key,'value':value})
    return {'settings':rows,'match_context_checked':False}

def firewall():
    try:
        raw=text(['ufw','status','verbose'])
        if 'Status: active' in raw or 'Status: inactive' in raw:
            return {'backend':'ufw','active':'Status: active' in raw,
                    'rules':len(re.findall(r'\b(?:ALLOW|DENY|REJECT|LIMIT)\b',raw)), 'policies':[]}
    except (OSError,Refusal):
        pass
    raw=decode(text(['nft','-j','list','ruleset']).encode())['nftables']
    return {'backend':'nft','active':None,'rules':sum('rule' in item for item in raw),
            'policies':sorted({item['chain']['policy'] for item in raw if 'chain' in item and item['chain'].get('policy') in ('accept','drop')})}

def apache():
    raw=text(['apache2ctl','-S'],include_stderr=True)
    modules=text(['apache2ctl','-M'],include_stderr=True)
    return {'vhost_count':len(re.findall(r'\bnamevhost\b',raw)),
            'ports':sorted({int(x) for x in re.findall(r'\bport (\d+)\b',raw)}),
            'modules':sorted(re.findall(r'^\s*([a-zA-Z0-9_]+_module)\b',modules,re.M)),
            'tls_function_verified':False}

def certificates():
    rows=[]
    for path in sorted(Path('/etc/letsencrypt/live').glob('*/cert.pem'))[:32]:
        resolved=path.resolve()
        if not resolved.is_relative_to('/etc/letsencrypt/archive') or 'key' in resolved.name.lower():
            raise Refusal('invalid_data')
        raw=text(['openssl','x509','-in',str(resolved),'-noout','-dates','-fingerprint','-sha256'])
        d=dict(line.split('=',1) for line in raw.splitlines() if '=' in line)
        fingerprint=next((v for k,v in d.items() if 'Fingerprint' in k),'').replace(':','').lower()
        rows.append({'fingerprint':fingerprint,'not_before':d.get('notBefore','unknown'),'not_after':d.get('notAfter','unknown')})
    return {'items':rows}

def packages():
    raw=text(['dpkg-query','-W','-f=${Package}|${Version}\n',
              'apache2','openssl','openssh-server','python3','docker-ce','containerd.io','caddy'],accept_nonzero=True)
    return {'rows':[{'name':a,'version':b} for line in raw.splitlines() if '|' in line for a,b in [line.split('|',1)]]}

def log_storage():
    raw=text(['journalctl','--disk-usage'])
    match=re.search(r'take up ([\d.]+)([KMGT]?)',raw)
    if not match:
        raise Refusal('invalid_data')
    journal=int(float(match[1])*1024**(' KMGT'.index(match[2]) if match[2] else 0))
    total=sum(p.lstat().st_size for p in Path('/var/log').iterdir() if stat.S_ISREG(p.lstat().st_mode))
    return {'journal_bytes':journal,'top_level_regular_log_bytes':total,'recursive_usage_measured':False}

def smart():
    raw=decode(text(['lsblk','--json','-o','NAME,TYPE']).encode())
    rows=[]
    for item in raw['blockdevices']:
        if item['type']!='disk' or not re.fullmatch(r'[A-Za-z0-9_-]+',item['name']):
            continue
        device='/dev/'+item['name']
        out,code=command(['smartctl','-j','-n','standby,0','-H','-A',device],accept_nonzero=True)
        data=decode(out.encode())
        rows.append({'device':device,'health_passed':data.get('smart_status',{}).get('passed'),
                     'temperature':data.get('temperature',{}).get('current'),'exit_status':code})
    return {'items':rows}

class Collector:
    def __init__(self, root_override=None, include_smart=False):
        self.blocks={}
        self.deadline=time.monotonic()+180
        self.root_override=root_override
        self.include_smart=include_smart

    def add(self, name, fn, requested=True):
        record={'status':'error','observed_at':stamp(),'reason':'unavailable','data':None}
        try:
            if not requested:
                record.update(status='not_collected',reason='not_requested')
            elif time.monotonic()>self.deadline:
                record.update(reason='budget_exhausted')
            else:
                value=fn()
                shape(value,SCHEMAS[name])
                record.update(status='ok',reason='measured',data=value)
                if name=='source_manifest' and value['skipped']:
                    record.update(status='partial',reason='incomplete_source')
        except Refusal as exc:
            record['reason']=str(exc) if str(exc) in REASONS else 'invalid_data'
        except (OSError,ValueError,KeyError,TypeError,AttributeError,IndexError,UnicodeError,StopIteration,subprocess.SubprocessError):
            record['reason']='unavailable'
        self.blocks[name]=record
        print(f'[{len(self.blocks)}/{len(SCHEMAS)}] {name}: {record["status"]}',flush=True)

    def get(self, name):
        row=self.blocks.get(name,{})
        if row.get('status')!='ok':
            raise Refusal('dependency_unavailable')
        return row['data']

    def collect(self):
        self.add('host',host)
        self.add('clock',clock)
        self.add('filesystems',filesystems)
        self.add('containers',containers)
        self.add('code_layout',lambda:layout(self.get('containers'),self.root_override))
        self.add('source_manifest',lambda:source_manifest(self.get('code_layout')['public_root']))
        self.add('code_access',lambda:code_access(self.get('code_layout'),self.get('containers')))
        self.add('php_cli',php_cli)
        for name,query in SQL.items():
            def probe(name=name,query=query):
                if name in {'pg_roles','pg_databases','pg_settings','pg_connections'}:
                    return sql(query)
                db=self.get('code_layout')['db_name']
                if not db:
                    raise Refusal('dependency_unavailable')
                return sql(query,db)
            self.add(name,probe)
        for name in ('moodle_config','moodle_plugins','scheduled_tasks','adhoc_tasks'):
            def application_probe(name=name):
                info=self.get('code_layout')
                if not info['db_name']:
                    raise Refusal('dependency_unavailable')
                return sql(moodle_queries(info['table_prefix'])[name],info['db_name'])
            self.add(name,application_probe)
        for name,fn in (('cron_receipt',cron_receipt),('backup_receipt',backup_receipt),('systemd',systemd),
                        ('listeners',listeners),('sshd_global',sshd),('firewall',firewall),('apache',apache),
                        ('certificates',certificates),('packages',packages),('log_storage',log_storage)):
            self.add(name,fn)
        self.add('smart',smart,self.include_smart)
        return {'schema_version':VERSION,'collected_utc':stamp(),'host_alias':'server1',
                'script_sha256':digest(read_regular(__file__,2*LIMIT)),'blocks':self.blocks}

MANIFEST_SCHEMA = {'schema_version': int, 'collected_utc': STAMP, 'script_sha256': HEX,
 'status': r'complete|partial', 'counts': {key:int for key in STATE},
 'files': {name:HEX for name in FILES if name!='manifest.json'}}
FACTS_SCHEMA = {'schema_version': int, 'collected_utc': STAMP, 'host_alias': r'server1',
                'script_sha256': HEX, 'blocks': dict}
REPORT_README = ('# Server facts\n\nStructured observations from a bounded script executed on the host.\n'
 'Owner-supplied evidence; not independent host authentication or functional acceptance.\n'
 'No application HTTP, Moodle bootstrap, raw config, logs, query text or backup content.\n'
 'Collection coverage is not operational health. Errors and exclusions are retained.\n').encode()

def validate_payload(payload):
    if set(payload)!=set(FILES) or any(not isinstance(v,bytes) or len(v)>LIMIT for v in payload.values()):
        raise Refusal('invalid_payload_files')
    manifest=decode(payload['manifest.json'])
    shape(manifest,MANIFEST_SCHEMA)
    facts=decode(payload['facts.json'])
    if set(facts)!=set(FACTS_SCHEMA) or facts.get('schema_version')!=VERSION or manifest['schema_version']!=VERSION:
        raise Refusal('unsupported_schema')
    for key in ('collected_utc','script_sha256','host_alias'):
        shape(facts[key],FACTS_SCHEMA[key])
    if (facts['collected_utc']!=manifest['collected_utc'] or facts['script_sha256']!=manifest['script_sha256']
        or payload['README.md']!=REPORT_README or set(facts['blocks'])!=set(SCHEMAS)):
        raise Refusal('inconsistent_manifest')
    counts={key:0 for key in STATE}
    errors=[]
    for key,row in facts['blocks'].items():
        if set(row)!={'status','observed_at','reason','data'} or row['status'] not in STATE or row['reason'] not in REASONS:
            raise Refusal('invalid_block')
        shape(row['observed_at'],STAMP)
        if row['status'] in ('ok','partial'):
            shape(row['data'],SCHEMAS[key])
            if row['status']=='ok' and row['reason']!='measured':
                raise Refusal('inconsistent_status')
        elif row['data'] is not None:
            raise Refusal('unexpected_error_output')
        if row['status']=='not_collected' and (key!='smart' or row['reason']!='not_requested'):
            raise Refusal('required_block_excluded')
        counts[row['status']]+=1
        if row['status']!='ok':
            errors.append({'block':key,'status':row['status'],'reason':row['reason']})
    errors.sort(key=lambda row:row['block'])
    if decode(payload['errors.json'])!=errors or manifest['counts']!=counts:
        raise Refusal('missing_or_modified_errors')
    if manifest['status']!=('partial' if counts['error'] or counts['partial'] else 'complete'):
        raise Refusal('inconsistent_completeness')
    for name,expected in manifest['files'].items():
        if digest(payload[name])!=expected:
            raise Refusal('checksum_mismatch')
    return facts,manifest

def read_payload(source):
    source=Path(source)
    if source.is_dir() and not source.is_symlink():
        names={p.name for p in source.iterdir()}
        if names-set(FILES)-{'report.tar.gz'} or not set(FILES)<=names:
            raise Refusal('unexpected_snapshot_files')
        return {name:read_regular(source/name) for name in FILES}
    raw=read_regular(source,4*LIMIT)
    payload={}
    # Bound decompression before tarfile can allocate a long PAX/GNU metadata member.
    inflater=zlib.decompressobj(16+zlib.MAX_WBITS)
    unpacked=inflater.decompress(raw,4*LIMIT+65536+1)
    if len(unpacked)>4*LIMIT+65536 or not inflater.eof or inflater.unused_data:
        raise Refusal('archive_expansion_limit_or_trailing_data')
    with tarfile.open(fileobj=io.BytesIO(unpacked),mode='r:') as archive:
        for member in archive:
            if member.name not in FILES or member.name in payload or not member.isfile() or not 0<=member.size<=LIMIT:
                raise Refusal('unsafe_archive_member')
            stream=archive.extractfile(member)
            payload[member.name]=stream.read(LIMIT+1)
            if len(payload[member.name])!=member.size:
                raise Refusal('truncated_archive')
    return payload

def export(facts,output,handoff_user=None):
    errors=[{'block':key,'status':row['status'],'reason':row['reason']}
            for key,row in facts['blocks'].items() if row['status']!='ok']
    errors.sort(key=lambda row:row['block'])
    counts={key:sum(r['status']==key for r in facts['blocks'].values()) for key in STATE}
    payload={'facts.json':encode(facts),'errors.json':encode(errors),'README.md':REPORT_README}
    payload['manifest.json']=encode({'schema_version':VERSION,'collected_utc':facts['collected_utc'],
      'script_sha256':facts['script_sha256'],'status':'partial' if counts['error'] or counts['partial'] else 'complete',
      'counts':counts,'files':{name:digest(data) for name,data in payload.items()}})
    _,manifest=validate_payload(payload)
    output=Path(output).absolute()
    if output.exists() or output.is_symlink() or not output.parent.is_dir():
        raise Refusal('new_output_directory_required')
    for parent in [output.parent,*output.parent.parents]:
        info=parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in {0,os.geteuid()}:
            raise Refusal('untrusted_output_parent')
        if info.st_mode & 0o022 and not (info.st_uid==0 and info.st_mode & stat.S_ISVTX):
            raise Refusal('writable_output_parent')
    owner=pwd.getpwnam(handoff_user) if handoff_user else None
    if owner and (owner.pw_uid==0 or os.geteuid() not in {0,owner.pw_uid}):
        raise Refusal('invalid_handoff_user')
    output.mkdir(mode=0o700)
    folder=os.open(output,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:
        packed=io.BytesIO()
        with tarfile.open(fileobj=packed,mode='w:gz',format=tarfile.USTAR_FORMAT) as archive:
            for name,data in payload.items():
                item=tarfile.TarInfo(name)
                item.mode=0o600
                item.size=len(data)
                archive.addfile(item,io.BytesIO(data))
        for name,data in {**payload,'report.tar.gz':packed.getvalue()}.items():
            fd=os.open(name,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=folder)
            with os.fdopen(fd,'wb') as stream:
                stream.write(data)
                stream.flush()
                os.fsync(stream.fileno())
            if owner:
                os.chown(name,owner.pw_uid,owner.pw_gid,dir_fd=folder,follow_symlinks=False)
        if owner:
            os.fchown(folder,owner.pw_uid,owner.pw_gid)
    finally:
        os.close(folder)
    print('REPORT='+str(output/'report.tar.gz'),flush=True)
    print('COLLECTION='+manifest['status'].upper(),flush=True)
    return 1 if manifest['status']=='partial' else 0

def import_report(source,repo):
    # Read and validate everything before touching the repository. Never rewrite evidence.
    payload=read_payload(source)
    facts,manifest=validate_payload(payload)
    import yaml
    repo=Path(repo).absolute()
    state_path=repo/'context/roadmap/STATE.yaml'
    state=yaml.safe_load(read_regular(state_path))
    previous=state.get('server_facts',{}).get('observed_at')
    if previous and previous>=manifest['collected_utc']:
        raise Refusal('older_than_current_snapshot')
    if datetime.fromisoformat(manifest['collected_utc'].replace('Z','+00:00')).timestamp()>time.time()+600:
        raise Refusal('server_clock_in_future')
    identifier=manifest['collected_utc'].replace('-','').replace(':','')+'-'+digest(payload['facts.json'])[:8]
    relative='context/runtime/server-facts/'+identifier
    target=repo/relative
    if target.exists() or any(p.is_symlink() for p in [target,*target.parents]):
        raise Refusal('snapshot_exists_or_symlink')
    target.parent.mkdir(parents=True,exist_ok=True)
    comparison_path=repo/('context/runtime/server_facts_source_comparison_'+identifier+'.json')
    if comparison_path.exists() or comparison_path.is_symlink():
        raise Refusal('comparison_already_exists')
    backups={p:read_regular(p,4*LIMIT) if p.exists() or p.is_symlink() else None for p in [state_path,comparison_path,
      repo/'context/current.yaml',repo/'.codex/checkpoints/CURRENT.md',repo/'context/index/context_index.json']}
    temporary=Path(tempfile.mkdtemp(prefix='.facts-',dir=target.parent))
    created=False
    try:
        for name,data in payload.items():
            (temporary/name).write_bytes(data)
        os.rename(temporary,target)
        created=True
        state['server_facts']={'status':manifest['status'],'snapshot':relative,
          'observed_at':manifest['collected_utc'],'method':'bounded_script_owner_supplied',
          'independently_authenticated_host':False,'script_sha256':manifest['script_sha256'],
          'counts':manifest['counts'],'functional_acceptance':False}
        expected_path=repo/'context/code_map/generated/source_manifest.json'
        if expected_path.exists():
            expected=decode(read_regular(expected_path,2*LIMIT))
            baseline={row['path'].removeprefix('moodle/'):row['sha256'] for row in expected['files']}
            observed={row['path']:row['sha256'] for row in (facts['blocks']['source_manifest']['data'] or {}).get('files',[])}
            complete=facts['blocks']['source_manifest']['status']=='ok'
            comparison={'baseline_commit':expected['source_commit'],'facts_snapshot':relative,
              'scope':'plugin_theme_only','not_an_atomic_snapshot':True,'evidence_complete':complete,
              'matching':sum(observed.get(name)==sha for name,sha in baseline.items()),
              'changed':sorted(name for name in observed.keys()&baseline.keys() if observed[name]!=baseline[name]),
              'only_in_snapshot':sorted(observed.keys()-baseline.keys()),
              'only_in_git':sorted(baseline.keys()-observed.keys())}
            comparison_path.write_bytes(encode(comparison))
            state['server_facts']['source_comparison']=str(comparison_path.relative_to(repo))
            state['server_facts']['application_source_matches']=bool(complete and observed==baseline) if complete else None
        state['updated_at']=datetime.now(timezone.utc).strftime('%Y-%m-%d')
        state_path.write_text(yaml.safe_dump(state,allow_unicode=True,sort_keys=False))
        for script in ('build_current_context.py','build_context_index.py','check_context.py','check_context_secrets.py'):
            subprocess.run([sys.executable,str(repo/'scripts'/script)],cwd=repo,check=True)
    except BaseException:
        import shutil
        if created:
            shutil.rmtree(target)
        elif temporary.exists():
            shutil.rmtree(temporary)
        for path,data in backups.items():
            if data is None:
                path.unlink(missing_ok=True)
            else:
                path.write_bytes(data)
        raise
    print('IMPORTED='+relative)
    return 0

def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    sub=parser.add_subparsers(dest='operation',required=True)
    collect=sub.add_parser('collect',help='Observe the host; no application HTTP or bootstrap')
    collect.add_argument('--output-dir',type=Path)
    collect.add_argument('--moodle-root',type=Path,help='Resolve ambiguity only; must match a discovered container mount')
    collect.add_argument('--smart',action='store_true',help='Read SMART only on already-awake disks; no self-test')
    collect.add_argument('--handoff-user',help='Hand the private report to this local user after writing it')
    validate=sub.add_parser('validate')
    validate.add_argument('source',type=Path)
    apply=sub.add_parser('import')
    apply.add_argument('source',type=Path)
    apply.add_argument('--repo',type=Path,default=Path(__file__).absolute().parents[2])
    args=parser.parse_args(argv)
    os.umask(0o077)
    if args.operation=='collect':
        output=args.output_dir or Path('/var/tmp')/('ustar-server-facts-'+datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-'+os.urandom(3).hex())
        return export(Collector(str(args.moodle_root) if args.moodle_root else None,args.smart).collect(),output,args.handoff_user)
    if args.operation=='validate':
        _,manifest=validate_payload(read_payload(args.source))
        print(json.dumps({'status':manifest['status'],'observed_at':manifest['collected_utc'],'counts':manifest['counts']}))
        return 0
    return import_report(args.source,args.repo)

if __name__=='__main__':
    try:
        raise SystemExit(main())
    except (Refusal,OSError,ValueError,KeyError,TypeError,zlib.error,tarfile.TarError,subprocess.CalledProcessError) as error:
        # No raw command output or suspicious values are ever printed to CI/terminal.
        print('FACTS_REFUSED='+type(error).__name__+(':'+str(error) if isinstance(error,Refusal) else ''),file=sys.stderr)
        raise SystemExit(2)
