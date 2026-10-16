"""Fixed read probes, independent of browser requests. The web user cannot invoke this service."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import json
import os
from pathlib import Path
import re
import selectors
import signal
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

from .data import check, epoch, safe_json, stamp, open_regular

HDD = Path('/srv/ustar-storage')
HDD_UUID = '359a2bae-4e79-461a-ab72-1597f605d801'
BACKUP = Path('/var/lib/ustar-backup')
LAB = Path('/var/lib/ustar-restore-test/20261014/state.json')
ARCHIVE = re.compile(r'ustar-recovery-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}\.tar\.gz\.age')
CONTAINERS = ('ustar_moodle', 'ustar_postgres')
UNITS = ('ustar-hdd-backup.timer', 'ustar-hdd-backup.service', 'apache2.service')
PROPERTIES = ('LoadState', 'ActiveState', 'SubState', 'Result', 'NextElapseUSecRealtime')
DOCKER_FORMAT = ('{{json .State.Status}}|{{json .State.Running}}|{{json .State.OOMKilled}}|'
                 '{{json .RestartCount}}|{{json .HostConfig.RestartPolicy.Name}}|{{json .State.StartedAt}}')


def command(argv, timeout=2, limit=65536):
    """No shell, inherited credentials, arbitrary argv, unbounded pipes or descendant processes."""
    process = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                               env={'PATH': '/usr/bin:/bin', 'LC_ALL': 'C'}, start_new_session=True)
    output = bytearray()
    deadline = time.monotonic()+timeout
    try:
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            while True:
                remaining = deadline-time.monotonic()
                if remaining <= 0 or not selector.select(remaining):
                    raise TimeoutError('Probe timed out')
                chunk = os.read(process.stdout.fileno(), 8192)
                if not chunk:
                    break
                output.extend(chunk)
                if len(output) > limit:
                    raise ValueError('Probe output limit')
        if process.wait(timeout=max(.01, deadline-time.monotonic())):
            raise ValueError('Probe failed')
        return output.decode('utf-8')
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
        process.wait()
        process.stdout.close()


def memory(text):
    fields = {line.split(':')[0]: int(line.split()[1])*1024 for line in text.splitlines() if ':' in line}
    total, available = fields['MemTotal'], fields['MemAvailable']
    if not 0 <= available <= total or total <= 0:
        raise ValueError('Invalid memory')
    return dict(total_bytes=total, available_bytes=available, available_percent=100*available/total,
                cache_bytes=fields.get('Cached'), swap_used_bytes=fields.get('SwapTotal', 0)-fields.get('SwapFree', 0))


def cpu_values():
    # guest/guest_nice are already included in user/nice.
    values = [int(value) for value in Path('/proc/stat').read_text().splitlines()[0].split()[1:9]]
    return sum(values), values[3]+values[4]


def host():
    first = cpu_values()
    time.sleep(.2)
    second = cpu_values()
    delta = second[0]-first[0]
    cpu = 100*(1-(second[1]-first[1])/delta) if delta > 0 else None
    fs = os.statvfs('/')
    return dict(memory=memory(Path('/proc/meminfo').read_text()), cpu_percent=cpu,
                cores=os.cpu_count(), load=list(os.getloadavg()), uptime_seconds=float(Path('/proc/uptime').read_text().split()[0]),
                disk=dict(total_bytes=fs.f_blocks*fs.f_frsize, available_bytes=fs.f_bavail*fs.f_frsize,
                          free_inodes=fs.f_favail), hostname=socket.gethostname())


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def http_probe():
    # Fixed local endpoint; no redirects, proxy env, bodies, Moodle bootstrap, login or cookies.
    # /login/index.php may create a session/access log entry; this probe is explicitly optional.
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    started = time.monotonic()
    request = urllib.request.Request('http://127.0.0.1:8082/login/index.php', method='HEAD')
    try:
        with opener.open(request, timeout=2) as response:
            code = response.status
        return dict(status='ok' if code == 200 else 'critical', code=code,
                    total_ms=round((time.monotonic()-started)*1000, 1))
    except (OSError, urllib.error.HTTPError, urllib.error.URLError):
        return dict(status='critical', code=None, total_ms=None)


def containers():
    rows = []
    for name in CONTAINERS:
        try:
            values = [json.loads(value) for value in command(['/usr/bin/docker', 'inspect', '--type=container',
                       '--format', DOCKER_FORMAT, name]).strip().split('|')]
            state, running, oom, restarts, policy, started = values
            if state not in ('created', 'restarting', 'running', 'removing', 'paused', 'exited', 'dead'):
                raise ValueError('Unknown container state')
            rows.append(dict(name=name, state=state, running=running, oom=oom, restarts=restarts,
                             policy=policy, started_at=started))
        except (OSError, ValueError, TimeoutError, subprocess.SubprocessError):
            rows.append(dict(name=name, state='no_data'))
    return rows


def mount():
    data = json.loads(command(['/usr/bin/findmnt', '-J', '--mountpoint', str(HDD), '-o', 'TARGET,UUID,OPTIONS']))
    rows = data.get('filesystems', [])
    if len(rows) != 1:
        raise ValueError('Mount missing')
    row = rows[0]
    valid = row.get('target') == str(HDD) and row.get('uuid') == HDD_UUID and 'rw' in row.get('options','').split(',')
    if not valid:
        raise ValueError('Wrong mount')
    fs = os.statvfs(HDD)
    return dict(uuid=HDD_UUID, available_bytes=fs.f_bavail*fs.f_frsize, total_bytes=fs.f_blocks*fs.f_frsize)


def backup(now=None):
    now = time.time() if now is None else now
    saved = safe_json(BACKUP/'last-hdd-success.json', owner=0)
    if saved.get('success') is not True or saved.get('phase') != 'complete':
        raise ValueError('Unsupported success receipt')
    manifest = saved['manifest']
    name = manifest['file']
    completed = epoch(saved['finished_utc'])
    if (not isinstance(name, str) or not ARCHIVE.fullmatch(name) or type(manifest['bytes']) is not int
            or manifest['bytes'] <= 0 or not re.fullmatch(r'[0-9a-f]{64}', manifest.get('sha256',''))
            or manifest.get('hdd_uuid') != HDD_UUID or manifest.get('readback_verified') is not True
            or completed > now+5):
        raise ValueError('Unsupported manifest')
    archive = HDD/'backups/managed'/name
    # Only metadata and receipt; no gigabyte rehash on a 15s scrape.
    descriptor, info = open_regular(archive, owner=0)
    try:
        import stat
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_nlink != 1
                or info.st_size != manifest['bytes'] or info.st_mtime > completed+5):
            raise ValueError('Archive metadata changed')
    finally:
        os.close(descriptor)
    age = max(0, now-completed)
    result = dict(file=name, bytes=manifest['bytes'], sha256=manifest['sha256'],
                  completed_at=saved['finished_utc'], age_seconds=round(age),
                  verification='creation_receipt_only', restore='not_accepted',
                  status='critical' if age > 172800 else 'warning' if age > 93600 else 'ok')
    try:
        attempt = safe_json(BACKUP/'last-hdd-attempt.json', owner=0)
        phase = attempt.get('phase')
        result['attempt_phase'] = phase if phase in ('prepare','capture','verify','readiness','complete','failed') else 'unknown'
        if attempt.get('success') is not True and phase == 'failed':
            result['status'] = 'warning' if result['status'] == 'ok' else result['status']
    except (OSError, ValueError):
        result['attempt_phase'] = 'no_data'
    try:
        os.lstat(BACKUP/'moodle-was-running.json')
        result['recovery_marker_present'], result['status'] = True, 'critical'
    except FileNotFoundError:
        result['recovery_marker_present'] = False
    return result


def lab():
    try:
        value = safe_json(LAB, owner=0)
    except FileNotFoundError:
        return dict(phase='no_data', evidence='state_file_missing')
    phase = value.get('phase')
    allowed = ('extracting','restoring_database','ready','stopped','failed','failed_stopped','cleanup_required')
    if phase not in allowed or type(value.get('ready')) is not bool:
        raise ValueError('Unsupported lab state')
    expires = value.get('expires_at')
    if expires is not None:
        epoch(expires)
    return dict(phase=phase, ready=value['ready'], unlimited=value.get('unlimited') is True,
                expires_at=expires, evidence='controller_receipt_only', availability='not_measured')


def recovery():
    try:
        (BACKUP/'moodle-was-running.json').lstat()
        return {'status':'critical', 'marker_present':True}
    except FileNotFoundError:
        return {'status':'ok', 'marker_present':False}


def units():
    rows = []
    for name in UNITS:
        try:
            text = command(['/usr/bin/systemctl', 'show', name, '--no-pager',
                            '--property='+','.join(PROPERTIES)])
            fields = dict(line.split('=',1) for line in text.splitlines() if '=' in line)
            rows.append(dict(name=name, state=fields.get('ActiveState','unknown') if fields.get('LoadState')=='loaded' else 'no_data',
                             result=fields.get('Result','unknown')))
        except (OSError, ValueError, TimeoutError, subprocess.SubprocessError):
            rows.append(dict(name=name, state='no_data', result='unavailable'))
    return rows


def collect(local_http=False):
    result = dict(version=1, observed_at=stamp(), checks=[], host={}, backup={}, lab={}, containers=[], events=[])
    probes = {'host':host, 'containers':containers, 'hdd':mount, 'backup':backup, 'lab':lab, 'units':units, 'recovery':recovery}
    if local_http:
        probes['http_local'] = http_probe
    with ThreadPoolExecutor(max_workers=7) as pool:
        futures = {key:pool.submit(fn) for key,fn in probes.items()}
        for key, future in futures.items():
            domain = 'data' if key in ('hdd','backup','recovery') else 'academy' if key in ('containers','http_local') else 'labs' if key=='lab' else 'server'
            try:
                value = future.result()
                result[key] = value
                status, reason = 'ok', 'measured'
                if key == 'backup':
                    status, reason = value['status'], 'backup_receipt'
                if key == 'recovery':
                    status, reason = value['status'], 'recovery_required' if value['marker_present'] else 'measured'
                if key == 'lab':
                    status, reason = 'no_data', 'controller_receipt_only'
                if key == 'http_local':
                    status, reason = value['status'], 'head_local_only'
                if key == 'containers':
                    status = 'no_data' if any(row['state']=='no_data' for row in value) else (
                        'critical' if any(not row['running'] or row['oom'] for row in value) else 'ok')
                if key == 'units':
                    status = 'no_data' if any(row['state']=='no_data' for row in value) else (
                        'warning' if any(row['state']=='failed' for row in value) else 'ok')
                result['checks'].append(check(key, key, domain, status, reason, key))
            except (OSError, ValueError, KeyError, TypeError, TimeoutError, subprocess.SubprocessError):
                # A missing/wrong HDD is a failed required check, not missing telemetry.
                result['checks'].append(check(key,key,domain,'critical' if key=='hdd' else 'no_data',
                                              'probe_failed',key))
    if not local_http:
        result['checks'].append(check('http_local','Академия: локальный HEAD','academy'))
    result['checks'].append(check('collector','Сборщик','monitoring','ok','measured','collector'))
    result['observed_at'] = stamp()
    return result


def write_snapshot(value, target):
    # systemd creates this root-owned directory; never create an alternative on SSD.
    parent = target.parent
    descriptor, temporary = tempfile.mkstemp(prefix='.snapshot-', dir=parent)
    try:
        with os.fdopen(descriptor, 'w') as stream:
            os.fchmod(stream.fileno(), 0o640)
            # Preserve the API read group across atomic replacement.
            os.fchown(stream.fileno(), 0, parent.stat().st_gid)
            json.dump(value, stream, ensure_ascii=False, allow_nan=False)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, target)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--local-http', action='store_true', help='Opt in to a fixed HEAD probe after reviewing its session/log impact')
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise SystemExit('Collector must be run by its restricted root service')
    write_snapshot(collect(args.local_http), Path('/run/ustar-console-data/snapshot.json'))


if __name__ == '__main__':
    main()
