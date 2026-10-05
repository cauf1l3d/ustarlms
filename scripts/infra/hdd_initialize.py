#!/usr/bin/env python3
"""One-time initialization of the owner's already erased, exact 500 GB HDD.

No SSD, mount, fstab, application, cleanup or scheduler changes. Refuses every
existing partition/signature, including its own completed/partial prior run.
"""
import argparse
import errno
import fcntl
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import uuid

DEVICE = Path('/dev/disk/by-id/wwn-0x50014ee200c1ee59')
SERIAL = 'WD-WCAS82914317'
WWN = '0x50014ee200c1ee59'
CAPACITY = 500106780160
LINUX = '0FC63DAF-8483-4772-8E79-3D69D8477DE4'
LABEL = 'ustar-hdd'
ENV = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL': 'C', 'LANG': 'C'}
TOOLS = ('lsblk', 'blockdev', 'wipefs', 'sfdisk', 'mkfs.ext4', 'udevadm', 'fuser', 'blkid')


class Refusal(RuntimeError):
    pass


def require(ok, reason):
    if not ok:
        raise Refusal(reason)


def run(argv, *, input=None, allowed=(0,), timeout=60):
    p = subprocess.run(argv, input=input, text=True, capture_output=True,
                       env=ENV, timeout=timeout, check=False)
    require(p.returncode in allowed,
            f'{argv[0]} exit={p.returncode}: {p.stderr.strip()[:500]}')
    return p


def block_number(path):
    s = path.stat()
    require(stat.S_ISBLK(s.st_mode), f'Not a block device: {path}')
    return f'{os.major(s.st_rdev)}:{os.minor(s.st_rdev)}'


def tree(disk):
    data = json.loads(run(['lsblk', '--json', '--bytes', '--paths', '--output',
                          'NAME,TYPE,SIZE,RO,FSTYPE,MOUNTPOINTS,SERIAL,WWN,PKNAME,MAJ:MIN,LOG-SEC',
                          str(disk)]).stdout)['blockdevices']
    require(len(data) == 1, 'Ambiguous lsblk device')
    return data[0]


def validate_identity(node, disk):
    require(node['name'] == str(disk) and re.fullmatch(r'/dev/sd[a-z]+', str(disk)),
            'Unexpected whole-disk path')
    require(node['type'] == 'disk' and not node['ro'], 'Not a writable whole disk')
    require(node.get('serial', '').strip() == SERIAL, 'Serial mismatch')
    require(node.get('wwn', '').lower() == WWN, 'WWN mismatch')
    require(int(node['size']) == CAPACITY and int(node['log-sec']) == 512,
            'Capacity/sector size mismatch')


def nodes(node):
    yield node
    for child in node.get('children', []):
        yield from nodes(child)


def unused(node):
    """Check the host, other process mount namespaces, swaps, holders and opens."""
    numbers = {n['maj:min'] for n in nodes(node)}
    root = os.stat('/').st_dev
    require(f'{os.major(root)}:{os.minor(root)}' not in numbers, 'System disk refused')
    for n in nodes(node):
        require(not any(n.get('mountpoints') or []), 'Device is mounted/in use')
        path = Path(n['name'])
        require(block_number(path) == n['maj:min'], 'Block identity changed')
        holder = Path('/sys/class/block') / path.name / 'holders'
        require(holder.is_dir() and not list(holder.iterdir()), 'Device has holders')
        p = run(['fuser', str(path)], allowed=(0, 1))
        require(p.returncode == 1 and not p.stdout.strip() and not p.stderr.strip(),
                'Device is open or fuser cannot verify it')
    for p in Path('/proc').glob('[0-9]*/mountinfo'):
        try:
            text = p.read_text()
        except OSError as e:
            if e.errno in (errno.ENOENT, errno.ESRCH):
                continue
            raise
        require(not any(len(r.split()) > 2 and r.split()[2] in numbers
                        for r in text.splitlines()), 'Mounted in a process namespace')
    for line in Path('/proc/swaps').read_text().splitlines()[1:]:
        s = Path(line.split()[0]).stat()
        number = s.st_rdev if stat.S_ISBLK(s.st_mode) else s.st_dev
        require(f'{os.major(number)}:{os.minor(number)}' not in numbers, 'Active swap')


def no_signatures(path):
    signatures = json.loads(run(['wipefs', '--json', '--no-act', str(path)]).stdout)
    require(signatures.get('signatures') == [], 'Existing signature; never overwrite')


def blank_edges(path):
    fd = os.open(path, os.O_RDONLY | os.O_CLOEXEC | os.O_NOFOLLOW)
    try:
        for offset in (0, CAPACITY - 1048576):
            data = os.pread(fd, 1048576, offset)
            require(len(data) == 1048576 and not any(data), 'Disk edges are not zeroed')
    finally:
        os.close(fd)


def preflight():
    require(os.geteuid() == 0, 'Root required')
    for name in TOOLS:
        require(shutil.which(name, path=ENV['PATH']), f'Missing dependency: {name}')
    disk = DEVICE.resolve(strict=True)
    node = tree(disk)
    validate_identity(node, disk)
    require(block_number(disk) == node['maj:min'], 'Block identity mismatch')
    require(not node.get('children'), 'Existing partitions; initialization is one-time')
    require(not node.get('fstype'), 'Existing filesystem')
    unused(node)
    require(int(run(['blockdev', '--getsize64', str(disk)]).stdout) == CAPACITY,
            'Block capacity changed')
    no_signatures(disk)
    blank_edges(disk)
    return disk, node['maj:min']


def verify_partition(disk, table):
    require(table.get('label') == 'gpt' and table.get('device') == str(disk)
            and table.get('unit') == 'sectors' and table.get('sectorsize') == 512,
            'Unexpected GPT header')
    parts = table.get('partitions', [])
    require(len(parts) == 1, 'Expected exactly one new partition')
    p = parts[0]
    require(p['node'] == str(disk) + '1' and p['type'].upper() == LINUX
            and p.get('name') == LABEL and p['start'] == 2048, 'Unexpected partition')
    end = p['start'] + p['size'] - 1
    require(CAPACITY // 512 - 8192 <= end <= CAPACITY // 512 - 34,
            'Unexpected partition extent')
    return Path(p['node'])


def initialize():
    disk, number = preflight()
    # No force/no-reread or signature wiping; sfdisk also checks kernel usage.
    run(['sfdisk', '--lock=nonblock', '--wipe=never', '--wipe-partitions=never', str(disk)],
        input=f'label: gpt\nunit: sectors\n\nstart=2048, type={LINUX}, name="{LABEL}"\n')
    run(['udevadm', 'settle', '--timeout=30'])
    table = json.loads(run(['sfdisk', '--json', str(disk)]).stdout)['partitiontable']
    part = verify_partition(disk, table)
    node = tree(disk)
    validate_identity(node, disk)
    require(DEVICE.resolve(strict=True) == disk and block_number(disk) == number,
            'Whole-disk identity changed')
    children = node.get('children', [])
    require(len(children) == 1 and children[0]['name'] == str(part)
            and children[0]['type'] == 'part' and not children[0].get('children')
            and not children[0].get('fstype')
            and int(children[0]['size']) == table['partitions'][0]['size'] * 512,
            'Kernel partition does not match GPT')
    unused(node)
    no_signatures(part)
    fsuuid = str(uuid.uuid4())
    print('HDD_INITIALIZE=FORMATTING; exact authorized HDD only', flush=True)
    run(['mkfs.ext4', '-L', LABEL, '-U', fsuuid, '-m', '1', '-E',
         'nodiscard,lazy_itable_init=0,lazy_journal_init=0', str(part)], timeout=1800)
    run(['udevadm', 'settle', '--timeout=30'])
    info = dict(r.split('=', 1) for r in run(['blkid', '-p', '-o', 'export', str(part)]).stdout.splitlines()
                if '=' in r)
    require(info.get('TYPE') == 'ext4' and info.get('LABEL') == LABEL
            and info.get('UUID') == fsuuid, 'New filesystem verification failed')
    return {'status': 'FILESYSTEM_READY', 'device': str(DEVICE), 'resolved_device': str(disk),
            'serial': SERIAL, 'partition': str(part), 'filesystem': 'ext4', 'uuid': fsuuid,
            'label': LABEL, 'reserved_percent': 1, 'mounted': False,
            'fstab_changed': False, 'ssd_changed': False, 'backups_scheduled': False}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true', help='Read-only disk preflight')
    mode.add_argument('--initialize', action='store_true', help='Create GPT and ext4 on exact empty HDD')
    args = parser.parse_args(argv)
    os.umask(0o077)
    if args.check:
        disk, number = preflight()
        print(json.dumps({'status': 'EMPTY_HDD_CHECK_PASS', 'resolved_device': str(disk),
                          'serial': SERIAL, 'size_bytes': CAPACITY, 'major_minor': number,
                          'writes': False}, indent=2))
        return
    require(os.geteuid() == 0, 'Root required')
    # Separate process lock; --check does not create it. Keep the file in place.
    fd = os.open('/run/lock/ustar-hdd-initialize.lock',
                 os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    try:
        s = os.fstat(fd)
        require(stat.S_ISREG(s.st_mode) and s.st_uid == 0 and s.st_nlink == 1
                and stat.S_IMODE(s.st_mode) == 0o600, 'Untrusted lock file')
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        print(json.dumps(initialize(), indent=2))
    finally:
        os.close(fd)


if __name__ == '__main__':
    try:
        main()
    except (Refusal, OSError, ValueError, KeyError, subprocess.SubprocessError) as exc:
        print(f'HDD_INITIALIZE_ERROR: {exc}; preserve partial state, do not force/reformat', file=sys.stderr)
        sys.exit(1)
