#!/usr/bin/env python3
"""Private root-filesystem recovery archive; selected services cold, no upgrade.

Run capture in a systemd service, not in an SSH foreground process. The SSD
marker supports resuming the original services after interruption; it does not
restore files or databases. The archive is deliberately root-private/plaintext.
"""
import argparse
from contextlib import ExitStack, contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import tarfile
import tempfile
import time
import urllib.request

HDD = Path('/srv/ustar-storage')
HDD_UUID = '359a2bae-4e79-461a-ab72-1597f605d801'
ROOT_UUID = 'fb5cc206-93f8-4601-a415-8d418970cf31'
STATE = Path('/var/lib/ustar-host-recovery-20261013')
MARKER = STATE / 'active.json'
BACKUP_LOCK = Path('/run/ustar-backup/lock')
APT_LOCKS = (Path('/var/lib/dpkg/lock-frontend'), Path('/var/lib/dpkg/lock'),
             Path('/var/lib/apt/lists/lock'))
NAMES = {'ustar_moodle', 'ustar_postgres', 'ustar_patch_stage_20261005_web',
         'ustar_patch_stage_20261005_pg', 'ustar_recovery_lab_20261004_web',
         'ustar_recovery_lab_20261004_pg'}
PG = {'ustar_postgres', 'ustar_patch_stage_20261005_pg', 'ustar_recovery_lab_20261004_pg'}
PROTECTED = ['/etc', '/usr/local', '/var/lib/mysql', '/var/vmail', '/var/mail',
             '/var/spool', '/var/lib/bind', '/var/www', '/var/snap']
EXCLUDED = ('proc', 'sys', 'dev', 'run', 'tmp', 'var/tmp', 'var/log',
            'srv/ustar-storage', 'lost+found')
SERVICE = re.compile(r'^(apache2|mariadb|mysql|postfix(?:@.*)?|dovecot|named|bind9|'
                     r'amavis|spamassassin|spamd|postgrey|memcached|caddy|cron|anacron|'
                     r'php[0-9.]+-fpm|docker|containerd|snap[.]lxd[.].*)[.]service$')
SOCKET = re.compile(r'^(docker|mariadb|mysql|dovecot|snap[.]lxd[.].*)[.]socket$')


class RecoveryError(Exception):
    pass


def need(condition, code):
    if not condition:
        raise RecoveryError(code)


def now():
    return datetime.now(timezone.utc).isoformat()


def trusted(path, directory=False, private=False):
    p = Path(path)
    for parent in reversed(p.parents):
        s = parent.lstat()
        need(stat.S_ISDIR(s.st_mode) and s.st_uid == 0 and not s.st_mode & 0o022,
             'UNTRUSTED_PARENT')
    s = p.lstat()
    need(s.st_uid == 0 and not s.st_mode & (0o077 if private else 0o022), 'UNTRUSTED_PATH')
    need(stat.S_ISDIR(s.st_mode) if directory else stat.S_ISREG(s.st_mode), 'PATH_TYPE')
    if not directory:
        need(s.st_nlink == 1, 'HARDLINK_CONTROL_FILE')
    return s


def private_dir(path):
    p = Path(path)
    if not p.exists():
        p.mkdir(mode=0o700)
    trusted(p, directory=True, private=True)


def write_json(path, value):
    p = Path(path)
    trusted(p.parent, directory=True, private=True)
    if p.exists() or p.is_symlink():
        trusted(p, private=True)
    fd, name = tempfile.mkstemp(prefix='.receipt-', dir=p.parent)
    try:
        with os.fdopen(fd, 'w') as out:
            json.dump(value, out, sort_keys=True)
            out.write('\n')
            out.flush()
            os.fsync(out.fileno())
        os.replace(name, p)
        parent = os.open(p.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(parent)
        finally:
            os.close(parent)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def read_json(path):
    s = trusted(path, private=True)
    need(s.st_size < 2 * 1024 * 1024, 'CONTROL_SIZE')
    value = json.loads(Path(path).read_text())
    need(isinstance(value, dict), 'CONTROL_TYPE')
    return value


class Runner:
    def __init__(self, log):
        self.log = Path(log)
        trusted(self.log.parent, directory=True, private=True)
        if self.log.exists() or self.log.is_symlink():
            trusted(self.log, private=True)

    def run(self, args, timeout=30):
        with self.log.open('ab') as err:
            try:
                p = subprocess.run(args, stdout=subprocess.PIPE, stderr=err, timeout=timeout)
            except subprocess.TimeoutExpired as e:
                raise RecoveryError('COMMAND_TIMEOUT') from e
        need(p.returncode == 0, 'COMMAND_FAILED')
        return p.stdout.decode()


def unit(runner, name):
    text = runner.run(['systemctl', 'show', name, '-p', 'LoadState', '-p', 'ActiveState',
                       '-p', 'SubState', '-p', 'Result', '-p', 'ExecMainStatus',
                       '-p', 'UnitFileState'])
    return dict(line.split('=', 1) for line in text.splitlines() if '=' in line)


def containers(runner):
    names = runner.run(['docker', 'ps', '-a', '--format', '{{.Names}}']).splitlines()
    need(set(names) == NAMES and len(names) == len(NAMES), 'CONTAINER_INVENTORY_DRIFT')
    form = ('{"name":{{json .Name}},"id":{{json .Id}},"image":{{json .Image}},'
            '"state":{{json .State}},"policy":{{json .HostConfig.RestartPolicy}},'
            '"mounts":{{json .Mounts}}}')
    raw = [json.loads(line) for line in runner.run(['docker', 'inspect', '--format', form,
                                                  *sorted(names)]).splitlines()]
    return {c['name'].lstrip('/'): {
        'id': c['id'], 'image': c['image'], 'running': c['state']['Running'],
        'oom': c['state'].get('OOMKilled', False), 'exit': c['state']['ExitCode'],
        'policy': c['policy'],
        'mounts': [{'type': m['Type'], 'source': m.get('Source'),
                    'destination': m['Destination'], 'rw': m.get('RW')} for m in c['mounts']]
    } for c in raw}


def same_containers(before, after, running=False):
    need(set(before) == set(after), 'CONTAINER_INVENTORY_DRIFT')
    for name, c in before.items():
        other = after[name]
        need(all(c[k] == other[k] for k in ('id', 'image', 'mounts')), 'CONTAINER_IDENTITY_DRIFT')
        if running:
            need(c['running'] == other['running'] and c['policy'] == other['policy'],
                 'CONTAINER_RESUME_MISMATCH')


@contextmanager
def locks():
    # Cron/HDD use flock; APT/dpkg use POSIX record locks. They are distinct APIs.
    with ExitStack() as stack:
        directory = BACKUP_LOCK.parent
        if not directory.exists():
            directory.mkdir(mode=0o700)
        trusted(directory, directory=True, private=True)
        specs = [(BACKUP_LOCK, True), *((p, False) for p in APT_LOCKS)]
        for path, flock in specs:
            fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
            stack.callback(os.close, fd)
            s = os.fstat(fd)
            need(stat.S_ISREG(s.st_mode) and s.st_uid == 0 and s.st_nlink == 1
                 and not s.st_mode & 0o022, 'UNTRUSTED_LOCK')
            try:
                if flock:
                    fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                else:
                    fcntl.lockf(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as e:
                raise RecoveryError('MAINTENANCE_LOCK_BUSY_NO_STOP') from e
        yield


def disk(runner):
    def mount(target):
        data = json.loads(runner.run(['findmnt', '-J', '--mountpoint', target, '-o',
                                     'TARGET,FSTYPE,UUID,OPTIONS']))['filesystems']
        need(len(data) == 1, 'MOUNT_COUNT')
        return data[0]
    root, hdd = mount('/'), mount(str(HDD))
    need(root['uuid'] == ROOT_UUID and root['fstype'] == 'ext4', 'ROOT_IDENTITY')
    need(hdd['target'] == str(HDD) and hdd['uuid'] == HDD_UUID and hdd['fstype'] == 'ext4'
         and 'rw' in hdd['options'].split(','), 'HDD_IDENTITY')
    trusted(HDD, directory=True, private=True)
    inventory = json.loads(runner.run(['findmnt', '-J', '-o', 'TARGET,FSTYPE,UUID']))['filesystems']
    pending = list(inventory)
    virtual = {'proc', 'sysfs', 'tmpfs', 'devtmpfs', 'devpts', 'cgroup', 'cgroup2',
               'securityfs', 'pstore', 'bpf', 'debugfs', 'tracefs', 'mqueue',
               'hugetlbfs', 'fusectl', 'efivarfs', 'autofs', 'binfmt_misc', 'nsfs'}
    while pending:
        m = pending.pop()
        pending.extend(m.get('children', []))
        target, kind = m['target'], m['fstype']
        if kind == 'ext4' and m.get('uuid') in (ROOT_UUID, HDD_UUID):
            continue
        if kind in virtual:
            continue
        if kind == 'squashfs' and target.startswith('/snap/'):
            continue
        if kind == 'overlay' and target.startswith(('/var/lib/docker/', '/var/lib/containerd/')):
            continue
        raise RecoveryError('ADDITIONAL_PERSISTENT_FILESYSTEM_NOT_COVERED')
    need(HDD.stat().st_dev != Path('/').stat().st_dev, 'SSD_FALLBACK_REFUSED')
    rootfs, hddfs = os.statvfs('/'), os.statvfs(HDD)
    budget = 2 * (rootfs.f_blocks - rootfs.f_bfree) * rootfs.f_frsize + 8 * 1024**3
    need(hddfs.f_bavail * hddfs.f_frsize > budget and hddfs.f_favail > 100, 'HDD_SPACE')
    return HDD.stat().st_dev, budget


def safe_archive_path(name):
    while name.startswith('./'):
        name = name[2:]
    need(not name.startswith('/') and '..' not in Path(name).parts, 'ARCHIVE_PATH')
    return name.rstrip('/')


def protected_paths(runner, before):
    rootdev = Path('/').stat().st_dev
    paths = list(PROTECTED)
    form = ('{"live_restore":{{json .LiveRestoreEnabled}},'
            '"swarm_state":{{json .Swarm.LocalNodeState}},"root":{{json .DockerRootDir}}}')
    data = json.loads(runner.run(['docker', 'info', '--format', form]))
    need(not data['live_restore'] and data['swarm_state'] == 'inactive',
         'UNEXPECTED_DOCKER_MODE')
    paths += [data['root'], '/var/lib/containerd']
    for c in before.values():
        for m in c['mounts']:
            need(m['type'] in ('bind', 'volume') and m['source'], 'UNSUPPORTED_MOUNT')
            paths.append(m['source'])
    present = []
    for text in sorted(set(paths)):
        p = Path(text)
        if not p.exists() and not p.is_symlink():
            continue
        resolved = p.resolve(strict=True)
        need(resolved.stat().st_dev == rootdev and str(resolved) != '/', 'EXTERNAL_SERVICE_DATA')
        name = str(resolved).lstrip('/')
        need(not any(name == x or name.startswith(x + '/') for x in EXCLUDED), 'EXCLUDED_SERVICE_DATA')
        present.append(str(p))
        if p.is_symlink():
            present.append(str(resolved))
    need('/var/lib/mysql' in present, 'MARIADB_PATH_MISSING')
    # Full root is archived. Only canonical in-root application paths are claimed cold.
    return sorted(set(present))


def manifest(paths):
    result = {}
    pending = list(paths)
    rootdev = Path('/').stat().st_dev
    while pending:
        p = Path(pending.pop())
        name = str(p).lstrip('/')
        if name in result:
            continue
        s = p.lstat()
        need(s.st_dev == rootdev, 'PROTECTED_FOREIGN_FILESYSTEM')
        entry = {'mode': stat.S_IMODE(s.st_mode), 'uid': s.st_uid, 'gid': s.st_gid,
                 'mtime_ns': s.st_mtime_ns, 'ctime_ns': s.st_ctime_ns, 'inode': s.st_ino}
        if stat.S_ISREG(s.st_mode):
            entry.update(kind='file', size=s.st_size)
            with p.open('rb') as f:
                entry['sha256'] = hashlib.file_digest(f, 'sha256').hexdigest()
        elif stat.S_ISDIR(s.st_mode):
            entry['kind'] = 'dir'
            pending.extend(str(child) for child in p.iterdir())
        elif stat.S_ISLNK(s.st_mode):
            entry.update(kind='link', target=os.readlink(p))
            # Data-directory symlinks must not silently escape the copied SSD.
            if p.exists():
                target = p.resolve(strict=True)
                relative = str(target).lstrip('/')
                if not any(relative == x or relative.startswith(x + '/') for x in EXCLUDED):
                    need(target.stat().st_dev == rootdev and target != Path('/'), 'EXTERNAL_LINK_DATA')
                    pending.append(str(target))
        elif stat.S_ISFIFO(s.st_mode):
            entry['kind'] = 'fifo'
        elif stat.S_ISCHR(s.st_mode) or stat.S_ISBLK(s.st_mode):
            entry.update(kind='char' if stat.S_ISCHR(s.st_mode) else 'block',
                         major=os.major(s.st_rdev), minor=os.minor(s.st_rdev))
        elif stat.S_ISSOCK(s.st_mode):
            # Unix sockets carry no persistent payload and GNU tar omits them.
            continue
        else:
            raise RecoveryError('PROTECTED_SPECIAL_FILE')
        result[name] = entry
    return result


def stop(runner, marker):
    for name in marker['timers'] + marker['sockets']:
        if name != 'docker.socket':
            runner.run(['systemctl', 'stop', name], timeout=120)
    for name in marker['services']:
        if name not in ('docker.service', 'containerd.service'):
            runner.run(['systemctl', 'stop', name], timeout=180)
            s = unit(runner, name)
            need(s['ActiveState'] == 'inactive' and s.get('Result') == 'success'
                 and s.get('ExecMainStatus', '0') == '0', 'SERVICE_UNCLEAN_STOP')
    current = containers(runner)
    same_containers(marker['containers'], current)
    for name, c in marker['containers'].items():
        if c['policy']['Name'] != 'no':
            runner.run(['docker', 'update', '--restart=no', c['id']])
    order = sorted(NAMES - PG) + sorted(PG)
    for name in order:
        if marker['containers'][name]['running']:
            runner.run(['docker', 'stop', '--time', '90', marker['containers'][name]['id']], timeout=120)
    stopped = containers(runner)
    same_containers(marker['containers'], stopped)
    for name, c in stopped.items():
        need(not c['running'] and not c['oom'], 'CONTAINER_NOT_STOPPED')
        if marker['containers'][name]['running']:
            need(c['exit'] == 0 if name in PG else c['exit'] in (0, 143), 'CONTAINER_UNCLEAN_STOP')
    for name in ('docker.service', 'containerd.service'):
        if name in marker['services']:
            runner.run(['systemctl', 'stop', name], timeout=180)
            need(unit(runner, name)['ActiveState'] == 'inactive', 'DAEMON_NOT_STOPPED')
        if name == 'docker.service' and 'docker.socket' in marker['sockets']:
            runner.run(['systemctl', 'stop', 'docker.socket'], timeout=90)
    # Confirm no corresponding host DB/web/mail process survived its service.
    for p in Path('/proc').glob('[0-9]*/exe'):
        try:
            executable = os.readlink(p).removesuffix(' (deleted)')
        except (FileNotFoundError, PermissionError):
            continue
        need(Path(executable).name not in {'mariadbd', 'mysqld', 'apache2', 'dovecot',
                                         'dockerd', 'containerd', 'containerd-shim-runc-v2'}
             and not executable.startswith('/usr/lib/postfix/sbin/'), 'SURVIVING_SERVICE_PROCESS')


def resume(runner, marker):
    services = marker['services']
    errors = []

    def attempt(args, timeout=90):
        try:
            runner.run(args, timeout=timeout)
            return True
        except RecoveryError:
            errors.append(args[1] if len(args) > 1 else 'command')
            return False

    first = [n for n in services if n in ('mariadb.service', 'mysql.service', 'memcached.service',
                                        'containerd.service', 'docker.service')]
    first.sort(key=lambda n: ('mariadb.service', 'mysql.service', 'memcached.service',
                             'containerd.service', 'docker.service').index(n))
    for name in first:
        attempt(['systemctl', 'start', name], timeout=180)
    try:
        current = containers(runner)
        same_containers(marker['containers'], current)
    except RecoveryError:
        current = None
        errors.append('container_identity_or_daemon')
    if current is not None:
        for name in sorted(PG) + sorted(NAMES - PG):
            original = marker['containers'][name]
            if original['running'] and not current[name]['running']:
                attempt(['docker', 'start', original['id']])
            if original['running'] and name in PG:
                deadline = time.monotonic() + 120
                while True:
                    try:
                        runner.run(['docker', 'exec', original['id'], 'pg_isready', '-q', '-t', '3'], timeout=10)
                        break
                    except RecoveryError:
                        if time.monotonic() >= deadline:
                            errors.append('postgres_ready')
                            break
                        time.sleep(1)
            policy = original['policy']
            value = policy['Name']
            if value == 'on-failure' and policy['MaximumRetryCount']:
                value += ':' + str(policy['MaximumRetryCount'])
            if current[name]['policy'] != policy:
                attempt(['docker', 'update', '--restart=' + value, original['id']])
    for name in services:
        if name not in first:
            attempt(['systemctl', 'start', name], timeout=180)
    for name in marker['sockets'] + marker['timers']:
        attempt(['systemctl', 'start', name])
    try:
        same_containers(marker['containers'], containers(runner), running=True)
    except RecoveryError:
        errors.append('container_resume')
    for name in services + marker['sockets'] + marker['timers']:
        try:
            s = unit(runner, name)
            if s['ActiveState'] != 'active' or s['UnitFileState'] != marker['units'][name]['UnitFileState']:
                errors.append('unit_resume')
        except RecoveryError:
            errors.append('unit_readback')
    deadline = time.monotonic() + 60
    while True:
        try:
            with urllib.request.urlopen('http://127.0.0.1:8082/login/index.php', timeout=10) as response:
                if response.status == 200:
                    break
        except Exception:
            pass
        if time.monotonic() >= deadline:
            errors.append('login_resume')
            break
        time.sleep(1)
    need(not errors, 'RESUME_VERIFICATION_FAILED')


def tar_command(root, excluded):
    return ['tar', '--create', '--file=-', '--format=pax', '--numeric-owner', '--acls',
            '--xattrs', '--xattrs-include=*', '--sparse', '--one-file-system',
            *['--exclude=./' + x for x in excluded], '-C', str(root), '.']


def copy_archive(args, output, log, maximum):
    digest = hashlib.sha256()
    size, report = 0, time.monotonic()
    with Path(log).open('ab') as err, Path(output).open('xb') as out:
        os.chmod(output, 0o600)
        p = subprocess.Popen(args, stdout=subprocess.PIPE, stderr=err)
        try:
            while data := p.stdout.read(1024 * 1024):
                size += len(data)
                need(size < maximum, 'ARCHIVE_BUDGET_EXCEEDED')
                out.write(data)
                digest.update(data)
                if time.monotonic() - report >= 30:
                    print('HOST_RECOVERY_COPY_BYTES=' + str(size), flush=True)
                    report = time.monotonic()
            need(p.wait(timeout=30) == 0, 'TAR_COPY_FAILED')
            out.flush()
            os.fsync(out.fileno())
        finally:
            p.stdout.close()
            if p.poll() is None:
                p.terminate()
                try:
                    p.wait(timeout=15)
                except subprocess.TimeoutExpired:
                    p.kill()
                    p.wait(timeout=15)
    return digest.hexdigest(), size


class DigestReader:
    def __init__(self, file):
        self.file, self.digest = file, hashlib.sha256()

    def read(self, size=-1):
        data = self.file.read(size)
        self.digest.update(data)
        return data


def verify_archive(path, expected, manifest_before):
    seen, hashes = set(), {}
    with Path(path).open('rb') as file:
        reader = DigestReader(file)
        with tarfile.open(fileobj=reader, mode='r|') as archive:
            for member in archive:
                name = safe_archive_path(member.name)
                if member.isfile():
                    f = archive.extractfile(member)
                    digest = hashlib.sha256()
                    while data := f.read(1024 * 1024):
                        digest.update(data)
                    hashes[name] = digest.hexdigest()
                elif member.islnk():
                    target = safe_archive_path(member.linkname)
                    need(target in hashes, 'ARCHIVE_HARDLINK_TARGET')
                    hashes[name] = hashes[target]
                entry = manifest_before.get(name)
                if entry is None:
                    continue
                need(name not in seen, 'DUPLICATE_PROTECTED_MEMBER')
                seen.add(name)
                need((member.mode, member.uid, member.gid) ==
                     (entry['mode'], entry['uid'], entry['gid']), 'ARCHIVE_METADATA_MISMATCH')
                if entry['kind'] == 'file':
                    need(hashes.get(name) == entry['sha256'], 'ARCHIVE_FILE_HASH_MISMATCH')
                    need(member.islnk() or member.size == entry['size'], 'ARCHIVE_FILE_SIZE')
                elif entry['kind'] == 'dir':
                    need(member.isdir(), 'ARCHIVE_DIRECTORY_TYPE')
                elif entry['kind'] == 'link':
                    need(member.issym() and member.linkname == entry['target'], 'ARCHIVE_LINK_MISMATCH')
                elif entry['kind'] == 'fifo':
                    need(member.isfifo(), 'ARCHIVE_FIFO_TYPE')
                else:
                    need((member.ischr() if entry['kind'] == 'char' else member.isblk())
                         and member.devmajor == entry['major'] and member.devminor == entry['minor'],
                         'ARCHIVE_DEVICE_TYPE')
        while reader.read(1024 * 1024):
            pass
    need(reader.digest.hexdigest() == expected, 'ARCHIVE_READBACK_SHA_MISMATCH')
    need(seen == set(manifest_before), 'ARCHIVE_PROTECTED_MEMBERS_MISSING')
    return len(seen)


def phase(marker, value):
    marker.update(phase=value, updated_at=now())
    write_json(MARKER, marker)
    print('HOST_RECOVERY_PHASE=' + value, flush=True)


def capture():
    need(not MARKER.exists() or read_json(MARKER)['phase'] in ('complete', 'failed_resumed'),
         'PREVIOUS_TRANSACTION_NEEDS_RESUME')
    private_dir(STATE)
    initial = Runner(STATE / 'preflight.private.log')
    device, budget = disk(initial)
    anchor = os.open(HDD, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    previous = os.open('.', os.O_RDONLY | os.O_DIRECTORY)
    try:
        need(os.fstat(anchor).st_dev == device, 'HDD_ANCHOR_DRIFT')
        os.fchdir(anchor)
        # Every payload write uses this held filesystem as its current directory.
        # A lazy unmount cannot redirect output into the SSD mountpoint.
        name = 'host-recovery-' + datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ-') + os.urandom(4).hex()
        os.mkdir(name, 0o700)
        run = Path(name)
        runner = Runner(run / 'commands.private.log')
        before = containers(runner)
        need(all(c['running'] for c in before.values()), 'EXPECTED_SIX_RUNNING_CONTAINERS')
        paths = protected_paths(runner, before)
        units = runner.run(['systemctl', 'list-units', '--all', '--no-legend', '--plain',
                            '--state=active', '--type=service,socket,timer']).splitlines()
        names = [line.split()[0] for line in units if line.split()]
        services = [n for n in names if SERVICE.fullmatch(n)]
        sockets = [n for n in names if SOCKET.fullmatch(n)]
        timers = [n for n in names if n.endswith('.timer')]
        need({'apache2.service', 'mariadb.service', 'dovecot.service', 'docker.service',
              'containerd.service', 'cron.service'}.issubset(services), 'REQUIRED_SERVICE_INACTIVE')
        marker = {'kind': 'selected_services_cold_SSD_root_file_archive', 'phase': 'prepared',
                  'started_at': now(), 'run_directory': str(HDD / run), 'containers': before,
                  'services': services, 'sockets': sockets, 'timers': timers,
                  'units': {n: unit(runner, n) for n in services + sockets + timers},
                  'archive': str(HDD / run / 'root.tar'), 'protected_paths': paths,
                  'package_install_or_reboot': False, 'encrypted': False,
                  'full_host_boot_restore_verified': False}
        package_args = ['dpkg-query', '-W', '-f=${binary:Package}\t${db:Status-Abbrev}\t${Version}\n']
        package_before = runner.run(package_args)
        (run / 'packages.private.tsv').write_text(package_before)
        (run / 'mounts.private.json').write_text(runner.run(['findmnt', '-J', '-o', 'TARGET,SOURCE,FSTYPE,UUID']))
        with locks():
            phase(marker, 'quiescing')
            failure = None
            try:
                stop(runner, marker)
                disk(runner)
                need(HDD.stat().st_dev == device, 'HDD_MOUNT_DRIFT')
                phase(marker, 'selected_services_stopped')
                protected = manifest(paths)
                (run / 'protected.private.json').write_text(json.dumps(protected, sort_keys=True))
                swaps = []
                for line in Path('/proc/swaps').read_text().splitlines()[1:]:
                    path = line.split()[0]
                    if path.startswith('/') and Path(path).is_file():
                        swaps.append(path.lstrip('/'))
                phase(marker, 'copying_SSD_root')
                digest, size = copy_archive(tar_command('/', list(EXCLUDED) + swaps),
                                            run / 'root.tar', runner.log, budget)
                need(manifest(paths) == protected, 'COLD_SOURCE_CHANGED_DURING_COPY')
                marker.update(archive_sha256=digest, archive_bytes=size)
                phase(marker, 'copy_complete')
            except BaseException as error:
                failure = error
                marker['failure_code'] = str(error) if isinstance(error, RecoveryError) else type(error).__name__
            finally:
                # Finish recovery even if the copying process receives SIGTERM.
                old_term = signal.getsignal(signal.SIGTERM)
                old_int = signal.getsignal(signal.SIGINT)
                signal.signal(signal.SIGTERM, signal.SIG_IGN)
                signal.signal(signal.SIGINT, signal.SIG_IGN)
                marker_failed = False
                try:
                    try:
                        phase(marker, 'resuming_original_services')
                    except BaseException:
                        # Marker I/O failure must not prevent service recovery.
                        marker_failed = True
                    resume(runner, marker)
                    marker['services_resumed'] = True
                except BaseException:
                    try:
                        phase(marker, 'resume_required')
                    except BaseException:
                        pass
                    raise RecoveryError('RESUME_REQUIRED_USE_SSD_MARKER')
                finally:
                    signal.signal(signal.SIGTERM, old_term)
                    signal.signal(signal.SIGINT, old_int)
                if marker_failed and failure is None:
                    failure = RecoveryError('SSD_RECEIPT_WRITE_FAILED_SERVICES_RESUMED')
                    marker['failure_code'] = str(failure)
            if failure:
                phase(marker, 'failed_resumed')
                raise failure
        try:
            phase(marker, 'readback_verification_services_running')
            marker['protected_members_verified'] = verify_archive(run / 'root.tar', digest, protected)
            need(HDD.stat().st_dev == device, 'HDD_MOUNT_DRIFT')
            need(runner.run(package_args) == package_before, 'EXTERNAL_PACKAGE_CHANGE_DURING_BACKUP')
            marker['package_baseline_unchanged'] = True
            marker['archive_readback_sha256_verified'] = True
            phase(marker, 'complete')
            write_json(run / 'receipt.private.json', marker)
        except BaseException as error:
            marker['failure_code'] = str(error) if isinstance(error, RecoveryError) else type(error).__name__
            phase(marker, 'failed_resumed')
            raise
        print('HOST_COLD_RECOVERY=PASS; SERVICES_RESUMED; NO_INSTALL_OR_REBOOT', flush=True)
        print(json.dumps(summary(marker), indent=2), flush=True)
    finally:
        os.fchdir(previous)
        os.close(previous)
        os.close(anchor)


def summary(marker):
    keys = ('kind', 'phase', 'started_at', 'updated_at', 'run_directory', 'archive',
            'archive_sha256', 'archive_bytes', 'archive_readback_sha256_verified',
            'protected_members_verified', 'services_resumed', 'failure_code',
            'package_install_or_reboot', 'package_baseline_unchanged', 'encrypted',
            'full_host_boot_restore_verified')
    return {k: marker[k] for k in keys if k in marker}


def recover():
    marker = read_json(MARKER)
    need(marker['phase'] not in ('complete', 'failed_resumed'), 'NO_SERVICE_RECOVERY_NEEDED')
    private_dir(STATE)
    runner = Runner(STATE / 'resume.private.log')
    with locks():
        resume(runner, marker)
        marker['services_resumed'] = True
        phase(marker, 'failed_resumed')
    print('HOST_SERVICE_RESUME=PASS; NO_FILE_OR_DATABASE_RESTORE', flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--capture', action='store_true')
    mode.add_argument('--status', action='store_true')
    mode.add_argument('--resume-services', action='store_true')
    parser.add_argument('--acknowledge-shared-outage', action='store_true')
    args = parser.parse_args()
    need(os.geteuid() == 0, 'ROOT_REQUIRED')
    os.umask(0o077)
    if args.status:
        print(json.dumps(summary(read_json(MARKER)) if MARKER.exists() else {'phase': 'not_started'}, indent=2))
    elif args.resume_services:
        recover()
    else:
        need(args.acknowledge_shared_outage, 'SHARED_OUTAGE_ACK_REQUIRED')
        for sig in (signal.SIGINT, signal.SIGTERM):
            signal.signal(sig, lambda *_: (_ for _ in ()).throw(RecoveryError('INTERRUPTED')))
        capture()


if __name__ == '__main__':
    try:
        main()
    except SystemExit:
        raise
    except BaseException as error:
        code = str(error) if isinstance(error, RecoveryError) else type(error).__name__
        print('HOST_COLD_RECOVERY_ERROR=' + code, flush=True)
        raise SystemExit(1)
