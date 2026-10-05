#!/usr/bin/env python3
"""Resume the retained 20261004 recovery lab; never restore its archive again.

Default: plan only. --check: read-only file/config/image/container checks.
--resume-lab: recreate only the removed, owned lab containers and loopback relay.
Uses the reviewed, SHA-pinned installed engine. No production exec/SQL/stop,
image pull/load, upgrades, cron, archive/key reads or data-directory deletion.
"""

import argparse
import ast
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import signal
import socket
import stat
import sys
import time
from datetime import datetime, timezone
import uuid

ENGINE = Path('/usr/local/sbin/USTAR_RESTORE_LAB_20261004.py')
ENGINE_SHA = '71f6a1c45c25bfe97fe0be7d0a362bb5a72e742b77c167f3783f526c85950dac'
LOCK = Path('/run/ustar-restore-lab-20261004.lock')
ROOT = Path('/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72')
PG_VERSION = '16'
APACHE_CONFIG = '''ServerName 127.0.0.1
<IfModule mpm_prefork_module>
StartServers 2
MinSpareServers 1
MaxSpareServers 2
MaxRequestWorkers 8
MaxConnectionsPerChild 200
</IfModule>
<VirtualHost *:80>
DocumentRoot /var/www/html/public
<Directory /var/www/html/public>
Require all granted
AllowOverride All
</Directory>
ErrorLog /proc/self/fd/2
CustomLog /proc/self/fd/1 combined
</VirtualHost>
'''


class ResumeError(Exception):
    pass


def require(ok, message):
    if not ok:
        raise ResumeError(message)


def protected(path, directory=False, root_owned=True):
    info = path.lstat()
    require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
            and (not root_owned or info.st_uid == 0) and not info.st_mode & 0o022,
            'Untrusted retained file or directory: ' + str(path))
    return info


def config_template(source):
    """Read the generator's literal template, without executing prepare_config."""
    functions = [n for n in ast.parse(source).body
                 if isinstance(n, ast.FunctionDef) and n.name == 'prepare_config']
    require(len(functions) == 1, 'Missing reviewed config generator')
    templates = [n.value.left.value for n in functions[0].body
                 if isinstance(n, ast.Assign) and len(n.targets) == 1
                 and isinstance(n.targets[0], ast.Name) and n.targets[0].id == 'config'
                 and isinstance(n.value, ast.BinOp) and isinstance(n.value.op, ast.Mod)
                 and isinstance(n.value.left, ast.Constant)
                 and isinstance(n.value.left.value, str)]
    require(len(templates) == 1 and templates[0].count('%s') == 2,
            'Unsupported reviewed config template')
    return templates[0]


def load_engine():
    protected(ENGINE)
    for parent in ENGINE.parents:
        protected(parent, directory=True)
    source = ENGINE.read_bytes()
    require(hashlib.sha256(source).hexdigest() == ENGINE_SHA,
            'Installed restore engine checksum mismatch')
    template = config_template(source)
    spec = importlib.util.spec_from_file_location('ustar_resume_pinned_engine', ENGINE)
    lab = importlib.util.module_from_spec(spec)
    exec(compile(source, str(ENGINE), 'exec'), lab.__dict__)
    require(lab.ROOT == ROOT and lab.SCRIPT == ENGINE,
            'Unexpected engine namespace')
    lab.checker()  # Independently validates/imports its reviewed SHA-pinned checker.
    return lab, template


def check_config(lab, template):
    code = ROOT / 'site/public'
    text = (code / 'config.php').read_text()
    values = []
    for name, length in (('dbpass', 64), ('cronremotepassword', 32)):
        matches = re.findall(r"\$CFG->" + name + r" = '([0-9a-f]{" + str(length) + r"})';", text)
        require(len(matches) == 1, 'Retained configuration is not the generated offline lab config')
        values.append(matches[0])
    secrets = lab.static_login_secrets([text])
    expected = template % tuple(values)
    expected += ''.join('$CFG->' + k + ' = ' + v + ';\n' for k, v in sorted(secrets.items()))
    expected += "require_once(__DIR__ . '/lib/setup.php');\n"
    require(text == expected, 'Retained configuration changed; refused PHP execution')
    require((code / 'public/config.php').read_text() ==
            "<?php\nrequire_once(dirname(__DIR__) . '/config.php');\n",
            'Retained public config changed')
    require((ROOT / 'lab.ini').read_text() ==
            'memory_limit=512M\nsendmail_path=/bin/false\ndisplay_errors=Off\nlog_errors=On\n',
            'Retained PHP isolation settings changed')
    require((ROOT / 'apache.conf').read_text() == APACHE_CONFIG,
            'Retained Apache isolation settings changed')
    require(re.fullmatch(r'POSTGRES_USER=ustar_lab_admin\nPOSTGRES_PASSWORD=[0-9a-f]{64}\n'
                         r'POSTGRES_DB=postgres\nPOSTGRES_INITDB_ARGS=--auth-host=scram-sha-256\n',
                         (ROOT / 'pg.env').read_text()) is not None,
            'Retained PostgreSQL environment changed')


def check_tree(path, root_owned):
    """Reject links and writable code; never follow retained paths outside the lab."""
    protected(path, directory=True, root_owned=root_owned)
    for base, dirs, files in os.walk(path, followlinks=False, onerror=lambda _: fail_scan()):
        for name in dirs:
            protected(Path(base) / name, directory=True, root_owned=root_owned)
        for name in files:
            protected(Path(base) / name, root_owned=root_owned)


def fail_scan():
    raise ResumeError('Retained directory scan failed')


def check_saved_lab(lab, state, template):
    require(state.get('stopped') is True and state.get('ready') is False,
            'Expected an explicitly stopped lab; use the existing status/stop command first')
    require(set(state.get('images', {})) == {'pg', 'web'}
            and all(re.fullmatch(r'sha256:[0-9a-f]{64}', v)
                    for v in state['images'].values()), 'Invalid retained image identities')
    require(set(lab.NAMES.values()).isdisjoint(lab.PRODUCTION), 'Lab namespace overlaps production')
    require(all(lab.inspect(n, absent_ok=True) is None for n in lab.NAMES.values()),
            'A lab container name is occupied; refused recreation')
    lab.relay_unit_absent()
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', lab.PORT))
    for name in ('site', 'site/public'):
        protected(ROOT / name, directory=True)
    data = protected(ROOT / 'site/moodledata', directory=True, root_owned=False)
    require(data.st_uid == 33 and not data.st_mode & 0o077,
            'Unexpected retained Moodledata owner or permissions')
    protected(ROOT / 'site/moodledata/filedir', directory=True, root_owned=False)
    for name in ('pg.env', 'lab.ini', 'apache.conf', 'report.json'):
        info = protected(ROOT / name)
        require(not info.st_mode & 0o077, 'Retained host input is not private')
    check_tree(ROOT / 'site/public', root_owned=True)
    check_tree(ROOT / 'postgres', root_owned=False)
    require((ROOT / 'postgres/PG_VERSION').read_text().strip() == PG_VERSION,
            'Existing PostgreSQL cluster version mismatch; refused initialization')
    for name in ('base', 'global', 'pg_wal', 'pg_tblspc'):
        protected(ROOT / 'postgres' / name, directory=True, root_owned=False)
    report = json.loads((ROOT / 'report.json').read_bytes())
    require(report.get('snapshot_sha256') == state['archive_sha256']
            and report.get('database_restore') == 'PASS'
            and report.get('moodle_bootstrap') == 'PASS'
            and report.get('http_login') == 200
            and report.get('lab_network') == 'loopback_only', 'No successful retained restore report')
    check_config(lab, template)
    for kind, image in state['images'].items():
        expected_name = 'ustar_postgres' if kind == 'pg' else 'ustar_moodle'
        require(state['preflight']['images'][expected_name]['id'] == image,
                'Retained image disagrees with the verified snapshot')
        info = json.loads(lab.command(['docker', 'image', 'inspect', image]).stdout)[0]
        require(info['Id'] == image and info['Os'] == 'linux' and info['Architecture'] == 'amd64'
                and info['Config'].get('Entrypoint') ==
                (['docker-entrypoint.sh'] if kind == 'pg' else ['docker-php-entrypoint'])
                and info['Config'].get('Cmd') == (['postgres'] if kind == 'pg' else ['apache2-foreground']),
                'Required immutable lab image is missing or unsupported')
    lab.reserve_space()
    require(os.statvfs(ROOT).f_favail >= 10000, 'Insufficient free inodes for lab runtime')
    available = int(Path('/proc/meminfo').read_text().split('MemAvailable:')[1].split()[0]) * 1024
    require(available >= 4 * 1024 ** 3, 'Insufficient available RAM for the lab and host reserve')
    return lab.production_state()


def wait_database(lab, state):
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        c = lab.owned('pg', state)
        require(c['State']['Running'], 'Retained lab PostgreSQL exited')
        result = lab.command(['docker', 'exec', c['Id'], 'pg_isready',
                              '-U', 'ustar_lab_admin', '-d', 'ustar_recovery_lab'],
                             timeout=5, accept_failure=True)
        if result.returncode == 0:
            return
        time.sleep(1)
    raise ResumeError('Retained lab PostgreSQL readiness timeout')


def check_database(lab, state):
    versions = lab.sql(state, "BEGIN READ ONLY; SET LOCAL statement_timeout='15s'; "
                       "SELECT plugin||'='||value FROM mdl_config_plugins WHERE name='version' "
                       "AND plugin IN ('local_ustar','theme_ustar') ORDER BY plugin; COMMIT;").splitlines()
    require(sorted(versions) == sorted(state['preflight']['application_versions']),
            'Retained lab application versions changed')
    enabled = lab.sql(state, "BEGIN READ ONLY; SET LOCAL statement_timeout='15s'; "
                      "SELECT count(*) FROM mdl_task_scheduled WHERE disabled=0; COMMIT;")
    require(enabled == '0', 'Scheduled tasks are enabled in the retained lab')


def resume(lab, state, before):
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + uuid.uuid4().hex[:8]
    previous = ROOT / ('resume-state-' + stamp + '-before.json')
    lab.write_private(previous, json.dumps(state, sort_keys=True, indent=2))
    log_path = ROOT / ('resume-' + stamp + '-private.log')
    lab.write_private(log_path, b'')
    lab._LOG = log_path.open('ab')
    began = time.monotonic()
    try:
        state.update(containers={}, stopped=False, ready=False, relay_requested=False)
        lab.save_state(state)
        print('RESUMING_RETAINED_LAB; no archive restore or production outage', flush=True)
        lab.create_container('pg', state)
        wait_database(lab, state)
        check_database(lab, state)
        lab.create_container('web', state)
        lab.assert_offline(state)
        lab.lab_exec('web', state, ['php', '-l', '/var/www/html/config.php'], user='33:33')
        php = "define('CLI_SCRIPT',true); require '/var/www/html/config.php'; " \
              "require_once($CFG->libdir.'/upgradelib.php'); echo json_encode([" \
              "'dbhost'=>$CFG->dbhost,'dbname'=>$CFG->dbname,'wwwroot'=>$CFG->wwwroot," \
              "'noemail'=>(bool)$CFG->noemailever,'cron'=>(int)$CFG->cron_enabled," \
              "'needs_upgrade'=>moodle_needs_upgrading()]);"
        boot = json.loads(lab.lab_exec('web', state, ['php', '-r', php], user='33:33').stdout)
        require(boot == {'dbhost': '127.0.0.1', 'dbname': 'ustar_recovery_lab',
                         'wwwroot': 'http://127.0.0.1:18084', 'noemail': True,
                         'cron': 0, 'needs_upgrade': False}, 'Retained lab bootstrap mismatch')
        lab.start_relay(state)
        http = lab.http_check()
        lab.command(['systemctl', 'is-active', '--quiet', lab.UNIT])
        require(lab.production_state() == before, 'Production identity/start time changed')
        report = {'kind': 'retained_lab_resume', 'archive_restored_again': False,
                  'snapshot_sha256': state['archive_sha256'], 'offline_config': 'PASS',
                  'retained_database': 'PASS', 'moodle_bootstrap': 'PASS', 'http_login': http,
                  'lab_network': 'loopback_only', 'email_disabled': True, 'cron_enabled': False,
                  'production_container_ids_and_start_times_unchanged': True,
                  'elapsed_seconds': round(time.monotonic() - began, 3),
                  'relay_expires_after_seconds': 7200, 'core_upgrade_executed': False}
        report_path = ROOT / ('resume-report-' + stamp + '.json')
        lab.write_private(report_path, json.dumps(report, indent=2))
        state.update(ready=True, stopped=False, last_resume_report=str(report_path))
        lab.save_state(state)
        print('LAB_RESUME=PASS; HTTP_LOGIN=200; PRODUCTION_CONTAINERS_UNCHANGED=PASS', flush=True)
        print('LAB_URL=http://127.0.0.1:18084/; RELAY_SECONDS=7200', flush=True)
        print('REPORT=' + str(report_path), flush=True)
    except BaseException:
        print('LAB_RESUME=FAIL; stopping only owned lab containers; data retained', flush=True)
        try:
            lab.stop_lab(state)
        except BaseException as error:
            print('LAB_CLEANUP=INCOMPLETE: ' + type(error).__name__, file=sys.stderr, flush=True)
        raise
    finally:
        lab._LOG.close()
        lab._LOG = None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument('--check', action='store_true')
    modes.add_argument('--resume-lab', action='store_true')
    args = parser.parse_args()
    if not args.check and not args.resume_lab:
        print('PLAN_ONLY; --check reads retained inputs; --resume-lab recreates the retained lab')
        return
    require(os.geteuid() == 0, 'Run with sudo')
    os.umask(0o077)
    sys.dont_write_bytecode = True
    lab, template = load_engine()
    if args.check:
        check_saved_lab(lab, lab.load_state(), template)
        print('RETAINED_LAB_PREFLIGHT=PASS; read-only; no PHP, containers created or archive reads')
        return
    protected(Path(__file__).resolve())
    for parent in Path(__file__).resolve().parents:
        protected(parent, directory=True)
    lock = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(lock)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o077,
                'Untrusted restore lock')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        def interrupted(*_):
            raise KeyboardInterrupt()
        for signum in (signal.SIGTERM, signal.SIGHUP):
            signal.signal(signum, interrupted)
        state = lab.load_state()
        before = check_saved_lab(lab, state, template)
        resume(lab, state, before)
    finally:
        os.close(lock)


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        print('LAB_RESUME=INTERRUPTED; inspect retained private evidence', file=sys.stderr)
        sys.exit(130)
    except Exception as error:
        message = str(error) if isinstance(error, ResumeError) else type(error).__name__
        print('LAB_RESUME_ERROR: ' + message, file=sys.stderr)
        sys.exit(1)
