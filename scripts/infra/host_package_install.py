#!/usr/bin/env python3
"""Install the approved cached host packages, then resume original services.

No archive creation, file/database restoration, kernel removal or reboot.
Service control is reused from the hash-pinned, server-accepted cold helper.
"""
import argparse
from contextlib import contextmanager, ExitStack
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import time

DEPENDENCY = Path('/usr/local/sbin/USTAR_HOST_COLD_RECOVERY_20261013.py')
DEPENDENCY_SHA = '3f8f0c0eae0d9702216d276231f2c7151fc40635b962843a0e9fe85efae6f0c4'
STATE = Path('/var/lib/ustar-host-packages-20261014')
MARKER = STATE / 'active.json'
RECOVERY_MARKER = Path('/var/lib/ustar-host-recovery-20261013/active.json')
ARCHIVE = Path('/srv/ustar-storage/host-recovery-20261013T234210Z-874c1983/root.tar')
ARCHIVE_SHA = '8de2742f3ffa303b8f4df3e53fef77fb9047768fdc50eebe696b61be96477730'
ARCHIVE_BYTES = 43704842240
KERNEL = '6.8.0-147-generic'
BOOT = Path('/boot')
FALLBACKS = ('6.8.0-139-generic', '6.8.0-146-generic')
PROFILE = Path('/etc/apparmor.d/usr.lib.snapd.snap-confine.real')
PROFILE_SHA = '93e1813e77614f3d1f60455ebf40733820fe1038eacabe14ff682a280438df9e'
EXPECTED = {'apparmor': '4.0.1really4.0.1-0ubuntu0.24.04.8', 'base-files': '13ubuntu10.5', 'containerd.io': '2.4.1-2~ubuntu.24.04~noble', 'dmidecode': '3.5-3ubuntu0.2', 'docker-buildx-plugin': '0.38.0-1~ubuntu.24.04~noble', 'docker-ce': '5:29.9.0-1~ubuntu.24.04~noble', 'docker-ce-cli': '5:29.9.0-1~ubuntu.24.04~noble', 'docker-ce-rootless-extras': '5:29.9.0-1~ubuntu.24.04~noble', 'docker-compose-plugin': '5.6.0-1~ubuntu.24.04~noble', 'krb5-locales': '1.20.1-6ubuntu2.10', 'libapparmor1': '4.0.1really4.0.1-0ubuntu0.24.04.8', 'libaudit-common': '1:3.1.2-2.1ubuntu0.1', 'libaudit1': '1:3.1.2-2.1ubuntu0.1', 'libgssapi-krb5-2': '1.20.1-6ubuntu2.10', 'libk5crypto3': '1.20.1-6ubuntu2.10', 'libkrb5-3': '1.20.1-6ubuntu2.10', 'libkrb5support0': '1.20.1-6ubuntu2.10', 'libnetplan1': '1.1.2-8ubuntu1~24.04.3', 'libopeniscsiusr': '2.1.9-3ubuntu5.5', 'libx11-6': '2:1.8.7-1ubuntu0.1', 'libx11-data': '2:1.8.7-1ubuntu0.1', 'linux-firmware': '20240318.git3b128b60.0ubuntu3.1', 'linux-firmware-amd-graphics': '20240318.git3b128b60-0ubuntu3.3', 'linux-firmware-amd-misc': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-broadcom-wireless': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-intel-graphics': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-intel-misc': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-intel-wireless': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-marvell-prestera': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-marvell-wireless': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-mediatek': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-mellanox-spectrum': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-misc': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-netronome': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-nvidia-graphics': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-qlogic': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-qualcomm-graphics': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-qualcomm-misc': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-qualcomm-wireless': '20240318.git3b128b60-0ubuntu3.1', 'linux-firmware-realtek': '20240318.git3b128b60-0ubuntu3.1', 'linux-generic': '6.8.0-147.147', 'linux-headers-6.8.0-147': '6.8.0-147.147', 'linux-headers-6.8.0-147-generic': '6.8.0-147.147', 'linux-headers-generic': '6.8.0-147.147', 'linux-image-6.8.0-147-generic': '6.8.0-147.147', 'linux-image-generic': '6.8.0-147.147', 'linux-libc-dev': '6.8.0-147.147', 'linux-modules-6.8.0-147-generic': '6.8.0-147.147', 'linux-modules-extra-6.8.0-147-generic': '6.8.0-147.147', 'linux-tools-6.8.0-147': '6.8.0-147.147', 'linux-tools-6.8.0-147-generic': '6.8.0-147.147', 'linux-tools-common': '6.8.0-147.147', 'motd-news-config': '13ubuntu10.5', 'netplan-generator': '1.1.2-8ubuntu1~24.04.3', 'netplan.io': '1.1.2-8ubuntu1~24.04.3', 'open-iscsi': '2.1.9-3ubuntu5.5', 'python-apt-common': '2.7.7ubuntu5.3', 'python3-apt': '2.7.7ubuntu5.3', 'python3-distupgrade': '1:24.04.29', 'python3-netplan': '1.1.2-8ubuntu1~24.04.3', 'sosreport': '4.11.2-0ubuntu0~24.04.1', 'thermald': '2.5.6-2ubuntu0.24.04.6', 'ubuntu-release-upgrader-core': '1:24.04.29'}
PACKAGE_QUERY = ['dpkg-query', '-W', '-f=${binary:Package}\t${db:Status-Abbrev}\t${Version}\n']
APT = ['apt-get', '--no-download', '--no-remove', '-y',
       '-o', 'Dpkg::Options::=--force-confold', 'dist-upgrade']
INTERRUPTED = False


class InstallError(Exception):
    pass


def need(condition, code):
    if not condition:
        raise InstallError(code)


def signal_requested(signum, frame):
    # KillMode=mixed sends the first stop signal only to this supervisor.
    # Keep waiting for native APT/dpkg to finish before restoring services.
    global INTERRUPTED
    INTERRUPTED = True


def cancelled():
    need(not INTERRUPTED, 'STOP_REQUESTED')


def dependency():
    s = DEPENDENCY.lstat()
    need(stat.S_ISREG(s.st_mode) and s.st_uid == 0 and not s.st_mode & 0o077
         and s.st_nlink == 1, 'UNTRUSTED_SERVICE_HELPER')
    for parent in DEPENDENCY.parents:
        meta = parent.lstat()
        need(stat.S_ISDIR(meta.st_mode) and meta.st_uid == 0 and not meta.st_mode & 0o022,
             'UNTRUSTED_HELPER_PARENT')
    need(hashlib.sha256(DEPENDENCY.read_bytes()).hexdigest() == DEPENDENCY_SHA,
         'SERVICE_HELPER_HASH')
    spec = importlib.util.spec_from_file_location('ustar_accepted_host_control', DEPENDENCY)
    h = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(h)
    return h


def phase(h, marker, value):
    marker.update(phase=value, updated_at=h.now())
    h.write_json(MARKER, marker)
    print('HOST_PACKAGES_PHASE=' + value, flush=True)


@contextmanager
def maintenance(h, wait_seconds=1200):
    h.private_dir(h.BACKUP_LOCK.parent)
    fd = os.open(h.BACKUP_LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        s = os.fstat(fd)
        need(stat.S_ISREG(s.st_mode) and s.st_uid == 0 and s.st_nlink == 1
             and not s.st_mode & 0o022, 'UNTRUSTED_MAINTENANCE_LOCK')
        deadline = time.monotonic() + wait_seconds
        printed = False
        while True:
            cancelled()
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if not printed:
                    print('HOST_PACKAGES_PHASE=waiting_for_existing_maintenance', flush=True)
                    printed = True
                need(time.monotonic() < deadline, 'MAINTENANCE_BUSY_NO_STOP')
                time.sleep(1)
        yield
    finally:
        os.close(fd)


def package_manager_idle(h):
    # APT must own its native POSIX locks during installation. These probes
    # release them before invoking APT; the shared flock stays held throughout.
    with ExitStack() as stack:
        for path in h.APT_LOCKS:
            fd = os.open(path, os.O_RDWR | os.O_NOFOLLOW)
            stack.callback(os.close, fd)
            s = os.fstat(fd)
            need(stat.S_ISREG(s.st_mode) and s.st_uid == 0 and s.st_nlink == 1
                 and not s.st_mode & 0o022, 'UNTRUSTED_PACKAGE_LOCK')
            try:
                fcntl.lockf(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as e:
                raise InstallError('PACKAGE_MANAGER_BUSY') from e


def approved_plan(text):
    need(not any(line.startswith('Remv ') for line in text.splitlines()), 'REMOVAL_PLAN')
    found = {}
    for line in text.splitlines():
        if not line.startswith('Inst '):
            continue
        m = re.match(r'^Inst (\S+) (?:\[[^\]]*\] )?\((\S+) ', line)
        need(m is not None, 'UNPARSEABLE_APT_PLAN')
        name = m[1].split(':')[0]
        need(name not in found, 'DUPLICATE_APT_PLAN')
        found[name] = m[2]
    need(found == EXPECTED, 'EXACT_PACKAGE_PLAN_CHANGED_NO_STOP')


def config_hashes(h):
    paths = {Path('/etc/fstab'), Path('/etc/ssh/sshd_config'), PROFILE}
    paths.update(Path('/etc/netplan').glob('*.yaml'))
    paths.update(Path('/etc/ssh/sshd_config.d').glob('*.conf'))
    paths.add(Path('/etc/docker/daemon.json'))
    result = {}
    for path in sorted(paths):
        if not path.exists() and not path.is_symlink():
            result[str(path)] = None
            continue
        h.trusted(path)
        result[str(path)] = hashlib.sha256(path.read_bytes()).hexdigest()
    need(result.get(str(PROFILE)) == PROFILE_SHA, 'ACCEPTED_APPARMOR_CONFIG_DRIFT')
    return result


def preflight(h, runner):
    recovery = h.read_json(RECOVERY_MARKER)
    need(recovery.get('phase') == 'complete' and recovery.get('services_resumed') is True
         and recovery.get('archive_readback_sha256_verified') is True
         and recovery.get('package_baseline_unchanged') is True
         and recovery.get('archive') == str(ARCHIVE)
         and recovery.get('archive_sha256') == ARCHIVE_SHA, 'ACCEPTED_COLD_ARCHIVE_REQUIRED')
    mount = json.loads(runner.run(['findmnt', '-J', '--mountpoint', str(h.HDD),
                                  '-o', 'TARGET,FSTYPE,UUID']))['filesystems']
    need(len(mount) == 1 and mount[0]['uuid'] == h.HDD_UUID and mount[0]['fstype'] == 'ext4',
         'RECOVERY_HDD_IDENTITY')
    s = h.trusted(ARCHIVE, private=True)
    need(s.st_size == ARCHIVE_BYTES and s.st_dev == h.HDD.stat().st_dev, 'ARCHIVE_STAT_DRIFT')
    baseline = ARCHIVE.parent / 'packages.private.tsv'
    h.trusted(baseline, private=True)
    need(runner.run(PACKAGE_QUERY) == baseline.read_text(), 'PACKAGE_BASELINE_DRIFT_NO_STOP')
    need(not runner.run(['dpkg', '--audit']).strip(), 'DPKG_AUDIT_NO_STOP')
    need(runner.run(['uname', '-r']).strip() == FALLBACKS[0], 'RUNNING_KERNEL_DRIFT_NO_STOP')
    for kernel in FALLBACKS:
        need((BOOT / ('vmlinuz-' + kernel)).is_file()
             and (BOOT / ('initrd.img-' + kernel)).is_file(), 'KERNEL_FALLBACK_MISSING')
    package_manager_idle(h)
    approved_plan(runner.run(['apt-get', '--simulate', '--no-remove', 'dist-upgrade'], timeout=120))
    # Native APT validates availability in its existing cache before any stop.
    runner.run(['apt-get', '--download-only', *APT[1:]], timeout=120)
    return config_hashes(h)


def snapshot(h, runner):
    before = h.containers(runner)
    need(all(c['running'] for c in before.values()), 'EXPECTED_SIX_RUNNING_CONTAINERS')
    rows = runner.run(['systemctl', 'list-units', '--all', '--no-legend', '--plain',
                       '--state=active', '--type=service,socket,timer']).splitlines()
    names = [line.split()[0] for line in rows if line.split()]
    services = [n for n in names if h.SERVICE.fullmatch(n)]
    sockets = [n for n in names if h.SOCKET.fullmatch(n)]
    timers = [n for n in names if n.endswith('.timer')]
    need({'apache2.service', 'mariadb.service', 'dovecot.service', 'docker.service',
          'containerd.service', 'cron.service'}.issubset(services), 'REQUIRED_SERVICE_INACTIVE')
    return {'containers': before, 'services': services, 'sockets': sockets, 'timers': timers,
            'units': {n: h.unit(runner, n) for n in services + sockets + timers}}


def apt_install(h, marker, runner):
    env = {**os.environ, 'LC_ALL': 'C', 'DEBIAN_FRONTEND': 'noninteractive',
           'NEEDRESTART_MODE': 'l', 'NEEDRESTART_SUSPEND': '1'}
    phase(h, marker, 'installing_cached_packages')
    with runner.log.open('ab') as log:
        with subprocess.Popen(APT, stdin=subprocess.DEVNULL, stdout=log, stderr=log, env=env) as process:
            marker['apt_pid'] = process.pid
            h.write_json(MARKER, marker)
            rc = process.wait()  # No Python timeout kills a live dpkg transaction.
    marker['apt_exit_status'] = rc
    h.write_json(MARKER, marker)
    need(rc == 0, 'APT_INSTALL_FAILED')


def verify_packages(runner):
    rows = runner.run(PACKAGE_QUERY + sorted(EXPECTED)).splitlines()
    found = {}
    for row in rows:
        name, status, version = row.split('\t')
        need(status == 'ii ', 'PACKAGE_NOT_CONFIGURED')
        found[name.split(':')[0]] = version
    need(found == EXPECTED, 'INSTALLED_VERSIONS_MISMATCH')
    need(not runner.run(['dpkg', '--audit']).strip(), 'DPKG_POST_AUDIT')
    runner.run(['apt-get', '--no-download', 'check'], timeout=120)


def boot_check(runner):
    for kernel in (*FALLBACKS, KERNEL):
        for stem in ('vmlinuz-', 'initrd.img-'):
            path = BOOT / (stem + kernel)
            need(path.is_file() and path.stat().st_size > 0, 'BOOT_FILE_MISSING')
    image = runner.run(['lsinitramfs', str(BOOT / ('initrd.img-' + KERNEL))], timeout=120)
    need('lib/modules/' + KERNEL + '/' in image, 'INITRAMFS_KERNEL_MISSING')
    grub = BOOT / 'grub/grub.cfg'
    runner.run(['grub-script-check', str(grub)])
    need('/vmlinuz-' + KERNEL in grub.read_text(), 'GRUB_KERNEL_MISSING')


def resumed_check(h, marker, runner):
    need(config_hashes(h) == marker['config_hashes'], 'CRITICAL_CONFIG_BYTES_CHANGED')
    need(runner.run(['docker', 'info', '--format', '{{.ServerVersion}}']).strip() == '29.9.0',
         'DOCKER_RUNTIME_VERSION')
    need(re.search(r'(?:^|\s)v?2\.4\.1(?:\s|$)', runner.run(['containerd', '--version'])),
         'CONTAINERD_RUNTIME_VERSION')
    apparmor = h.unit(runner, 'apparmor.service')
    need(apparmor.get('ActiveState') == 'active' and apparmor.get('Result') == 'success',
         'APPARMOR_NOT_ACTIVE')
    need('docker-default (enforce)' in Path('/sys/kernel/security/apparmor/profiles').read_text(),
         'DOCKER_APPARMOR_NOT_ENFORCED')


def transact(h, runner, marker):
    failure = None
    try:
        cancelled()
        marker['stop_started'] = True
        phase(h, marker, 'quiescing_original_services')
        h.stop(runner, marker)
        cancelled()
        # Native APT remains the owner of its locks. Recheck the frozen plan
        # after stopping timers and services, just before the install command.
        package_manager_idle(h)
        approved_plan(runner.run(['apt-get', '--simulate', '--no-remove', 'dist-upgrade'], timeout=120))
        marker['package_install_started'] = True
        apt_install(h, marker, runner)
        phase(h, marker, 'checking_installed_packages_and_boot')
        verify_packages(runner)
        marker['installed_versions_verified'] = len(EXPECTED)
        boot_check(runner)
        marker['new_kernel_boot_files_verified'] = True
        cancelled()
    except BaseException as e:
        failure = e
        marker['failure_code'] = str(e) if isinstance(e, (InstallError, h.RecoveryError)) else type(e).__name__
    finally:
        try:
            package_manager_idle(h)
            phase(h, marker, 'resuming_original_services')
            h.resume(runner, marker)
            marker['services_resumed'] = True
        except BaseException as e:
            marker['resume_error'] = str(e) if isinstance(e, (InstallError, h.RecoveryError)) else type(e).__name__
            phase(h, marker, 'resume_required')
            if failure is None:
                failure = e
    if failure is not None:
        if marker.get('services_resumed'):
            phase(h, marker, 'failed_resumed')
        raise failure
    try:
        resumed_check(h, marker, runner)
    except BaseException as e:
        marker['failure_code'] = str(e) if isinstance(e, (InstallError, h.RecoveryError)) else type(e).__name__
        phase(h, marker, 'failed_resumed')
        raise
    marker.update(container_identities_and_policies_preserved=True, critical_config_bytes_preserved=True,
                  reboot_executed=False, reboot_acceptance='PENDING')
    phase(h, marker, 'complete_reboot_pending')


def install(h):
    h.private_dir(STATE)
    runner = h.Runner(STATE / 'commands.private.log')
    with maintenance(h):
        if MARKER.exists():
            old = h.read_json(MARKER)
            need(old.get('phase') not in ('complete_reboot_pending',), 'ALREADY_INSTALLED_NO_REPEAT')
            need(not old.get('package_install_started'), 'PREVIOUS_INSTALL_INSPECTION_REQUIRED')
            need(not old.get('stop_started') or old.get('services_resumed'), 'RESUME_REQUIRED_NO_INSTALL')
        marker = {'kind': 'approved_cached_host_package_install', 'started_at': h.now(),
                  'package_install_started': False, 'reboot_executed': False}
        phase(h, marker, 'preflight_no_stop')
        try:
            marker['config_hashes'] = preflight(h, runner)
            marker.update(snapshot(h, runner))
        except BaseException as e:
            marker['failure_code'] = str(e) if isinstance(e, (InstallError, h.RecoveryError)) else type(e).__name__
            phase(h, marker, 'preflight_failed_no_stop')
            raise
        transact(h, runner, marker)


def recover(h):
    with maintenance(h):
        marker = h.read_json(MARKER)
        need(marker.get('stop_started') and not marker.get('services_resumed'), 'NO_SERVICE_RECOVERY_NEEDED')
        package_manager_idle(h)
        runner = h.Runner(STATE / 'resume.private.log')
        try:
            phase(h, marker, 'resuming_original_services')
            h.resume(runner, marker)
            marker['services_resumed'] = True
            phase(h, marker, 'failed_resumed')
        except BaseException:
            phase(h, marker, 'resume_required')
            raise


def summary(marker):
    keys = ('kind', 'phase', 'started_at', 'updated_at', 'package_install_started', 'apt_pid',
            'apt_exit_status', 'installed_versions_verified', 'new_kernel_boot_files_verified',
            'services_resumed', 'failure_code', 'resume_error', 'failed_service',
            'container_identities_and_policies_preserved', 'critical_config_bytes_preserved',
            'reboot_executed', 'reboot_acceptance')
    return {k: marker[k] for k in keys if k in marker}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--install', action='store_true')
    mode.add_argument('--status', action='store_true')
    mode.add_argument('--resume-services', action='store_true')
    parser.add_argument('--acknowledge-shared-outage', action='store_true')
    args = parser.parse_args()
    need(os.geteuid() == 0, 'ROOT_REQUIRED')
    os.umask(0o077)
    os.environ['LC_ALL'] = 'C'
    h = dependency()
    if args.status:
        print(json.dumps(summary(h.read_json(MARKER)) if MARKER.exists() else {'phase': 'not_started'}, indent=2))
        return
    signal.signal(signal.SIGTERM, signal_requested)
    signal.signal(signal.SIGINT, signal_requested)
    if args.install:
        need(args.acknowledge_shared_outage, 'SHARED_OUTAGE_ACK_REQUIRED')
        install(h)
        print('HOST_PACKAGES_INSTALL=PASS; SERVICES_RESUMED; REBOOT_PENDING', flush=True)
    else:
        recover(h)
        print('HOST_PACKAGES_RESUME=PASS; NO_INSTALL_OR_REBOOT', flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('HOST_PACKAGES_ERROR=' + (str(error) if isinstance(error, InstallError)
                                      else type(error).__name__), flush=True)
        raise SystemExit(1)
