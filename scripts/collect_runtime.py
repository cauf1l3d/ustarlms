#!/usr/bin/env python3
"""Measure runtime separately from Git. Outputs contain selected metadata only."""
import argparse
from datetime import datetime, timezone
import json
from pathlib import Path, PurePosixPath
import platform
import subprocess


def run(args, input=None):
    p = subprocess.run(args, input=input, text=True, stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE, timeout=90)
    if p.returncode:
        # Do not echo PHP errors, Docker env, credentials or arbitrary stderr.
        raise RuntimeError('probe_failed: ' + args[0])
    return p.stdout


def inspect(name):
    fmt = '{"name":{{json .Name}},"image":{{json .Config.Image}},"image_id":{{json .Image}},"mounts":{{json .Mounts}},"running":{{json .State.Running}},"status":{{json .State.Status}},"started_at":{{json .State.StartedAt}},"restart_count":{{json .RestartCount}},"health":{{if .State.Health}}{{json .State.Health.Status}}{{else}}"not_configured"{{end}}}'
    obj = json.loads(run(['docker','inspect','--format',fmt,name]))
    started = datetime.fromisoformat(obj['started_at'].replace('Z','+00:00'))
    obj['uptime_seconds'] = max(0, int((datetime.now(timezone.utc)-started).total_seconds())) if obj['running'] else 0
    return obj


def map_root(container_root, mounts):
    path = PurePosixPath(container_root)
    choices = []
    for m in mounts:
        dest = PurePosixPath(m['Destination'])
        if m['Type'] == 'bind' and path.is_relative_to(dest):
            choices.append((len(dest.parts), str(Path(m['Source']) / str(path.relative_to(dest)))))
    if not choices:
        raise ValueError('no_bind_mount_for_moodle_root')
    return max(choices)[1]


def probe_moodle(name, mounts):
    candidates = sorted({str(PurePosixPath(m['Destination']) / s) for m in mounts
                         for s in ('public/config.php','config.php')})
    # Discover config location from mounted destinations; report actual CFG dirroot.
    script = '''<?php
    define('CLI_SCRIPT', true);
    define('NO_MOODLE_COOKIES', true);
    define('CACHE_DISABLE_ALL', true);
    $candidates = json_decode($argv[1], true);
    $found = [];
    foreach ($candidates as $p) { if (is_file($p) && is_file(dirname($p).'/version.php') && is_dir(dirname($p).'/lib')) $found[] = $p; }
    if (count($found) !== 1) { exit(21); }
    require $found[0];
    // Subsequent version queries are forced read-only; no completion/sync APIs.
    $DB->execute('SET default_transaction_read_only = on');
    $coredb = $DB->get_field('config', 'value', ['name'=>'version']);
    $ustardb = $DB->get_field('config_plugins', 'value', ['plugin'=>'local_ustar','name'=>'version']);
    $postgres = $DB->get_field_sql('SHOW server_version');
    $version = null; $release = null;
    require $CFG->dirroot.'/version.php';
    $plugin = new stdClass();
    require $CFG->dirroot.'/local/ustar/version.php';
    echo "USTAR_PROBE=".json_encode(['dirroot'=>$CFG->dirroot, 'php'=>PHP_VERSION,
      'moodle_db'=>(string)$coredb, 'moodle_disk'=>(string)$version, 'moodle_release'=>$release,
      'ustar_db'=>(string)$ustardb, 'ustar_disk'=>(string)$plugin->version, 'postgres'=>$postgres])."\\n";
    '''
    # Pass code and argv separately; no shell and no credentials in command arguments.
    out = run(['docker','exec','-u','www-data',name,'php','-r',script.replace('<?php', '', 1),json.dumps(candidates)])
    lines = [line[len('USTAR_PROBE='):] for line in out.splitlines() if line.startswith('USTAR_PROBE=')]
    if len(lines) != 1:
        raise RuntimeError('missing_runtime_probe_result')
    return json.loads(lines[0])


def collect(repo, output, moodle='ustar_moodle', postgres='ustar_postgres'):
    output.mkdir(parents=True, exist_ok=True)
    target = output/'runtime.json'
    if target.exists():
        raise ValueError('output_already_exists')
    result = {'measured_at': datetime.now(timezone.utc).isoformat(),
              'git_source': {'commit': run(['git','-C',str(repo),'rev-parse','HEAD']).strip(),
                             'branch': run(['git','-C',str(repo),'branch','--show-current']).strip(),
                             'dirty': bool(run(['git','-C',str(repo),'status','--porcelain']).strip())},
              'server': {'hostname': platform.node(), 'kernel': platform.release()},
              'production_runtime': {}, 'errors': []}
    for role, name in [('moodle',moodle),('postgres',postgres)]:
        try:
            result['production_runtime'][role] = inspect(name)
        except (RuntimeError, ValueError, FileNotFoundError, subprocess.TimeoutExpired):
            result['errors'].append(role+'_container_probe_failed')
    runtime = result['production_runtime']
    if 'moodle' in runtime:
        try:
            values = probe_moodle(moodle, runtime['moodle']['mounts'])
            values['host_root'] = map_root(values['dirroot'],runtime['moodle']['mounts'])
            values['ustar_db_matches_disk'] = values['ustar_db'] == values['ustar_disk']
            result['moodle_versions'] = values
        except (RuntimeError, ValueError, FileNotFoundError, subprocess.TimeoutExpired):
            result['errors'].append('moodle_version_or_mount_probe_failed')
    # No service is started. Record matching unit definitions and process argv names only.
    try:
        units = run(['systemctl','list-unit-files','--type=service','--no-legend','--no-pager'])
        result['contextd_units'] = [s.split()[0] for s in units.splitlines() if 'contextd' in s.lower()]
    except (RuntimeError, FileNotFoundError, subprocess.TimeoutExpired):
        result['contextd_units'] = None
    try:
        processes = run(['ps','-eo','pid=,args='])
        result['contextd_pids'] = [int(s.split()[0]) for s in processes.splitlines()
                                 if 'harness/ustar-contextd/server.py' in s and 'grep ' not in s]
    except (RuntimeError, FileNotFoundError, subprocess.TimeoutExpired):
        result['contextd_pids'] = None
    with target.open('x') as f:
        json.dump(result, f, ensure_ascii=False, indent=2)
        f.write('\n')
    return result


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[1])
    p.add_argument('--output-dir', type=Path, required=True)
    a = p.parse_args()
    r = collect(a.repo, a.output_dir)
    print(json.dumps({'output':str(a.output_dir), 'errors':r['errors']}))
    raise SystemExit(2 if r['errors'] else 0)
