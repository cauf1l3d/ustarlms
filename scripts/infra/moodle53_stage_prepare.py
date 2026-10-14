#!/usr/bin/env python3
"""Copy the stopped, accepted 5.1.8 lab into a new private major rehearsal.

No service/container/image/network operations other than Docker inspect.
No SQL, PHP, downloads, production writes, deletion, activation or upgrade.
The frozen pair, mutable pair and stock-5.3/full-addon code remain separate.
"""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile

PROFILE = Path('/usr/local/sbin/USTAR_MOODLE53_PROFILE_20261014.json')
PROFILE_SHA = '40ad9cdd7235807db3b3514e335683c14b5cb6fb7433d568b0e9cee071bb6fb1'
SOURCE = Path('/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72/patch-stage-20261005')
ASSETS = Path('/var/lib/ustar-moodle53-assets-20261014.GG8CJavy')
OUTPUT = Path('/var/lib/ustar-moodle53-20261014')
LOCK = Path('/run/ustar-restore-lab-20261004.lock')
PRODUCTION = ('ustar_moodle', 'ustar_postgres')
NAMES = {'web': 'ustar_patch_stage_20261005_web', 'pg': 'ustar_patch_stage_20261005_pg'}
TREE_PATHS = {'code': 'site/public', 'moodledata': 'site/moodledata', 'postgres16': 'postgres'}
CONTROLS = ('state.json', 'pg.env', 'apache.conf', 'lab.ini', 'report.json')
MIB, GIB = 1024 ** 2, 1024 ** 3
MAX_ENTRIES, RESERVE = 150000, 4 * GIB
DATA_UID, DATA_GID = 33, 33


class PreparationError(Exception):
    pass


def require(ok, code):
    if not ok:
        raise PreparationError(code)


def relative(value):
    require(isinstance(value, str) and value and '\\' not in value and '\0' not in value,
            'INVALID_RELATIVE_PATH')
    path = PurePosixPath(value)
    require(not path.is_absolute() and path.as_posix() == value
            and not {'.', '..'} & set(path.parts), 'INVALID_RELATIVE_PATH')
    return path


def stamp(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def trusted(path, directory=False, private=False):
    info = path.lstat()
    require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
            and info.st_uid == 0 and not info.st_mode & (0o077 if private else 0o022)
            and (directory or info.st_nlink == 1), 'UNTRUSTED_CONTROL_METADATA')
    return info


def trusted_parents(path):
    for parent in path.parents:
        trusted(parent, directory=True)


def digest_stream(stream):
    h = hashlib.sha256()
    for block in iter(lambda: stream.read(MIB), b''):
        h.update(block)
    return h.hexdigest()


def file_hash(path):
    before = path.lstat()
    require(stat.S_ISREG(before.st_mode) and before.st_nlink == 1, 'NONREGULAR_OR_LINKED_FILE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        require(stamp(os.fstat(stream.fileno())) == stamp(before), 'FILE_IDENTITY_CHANGED')
        result = digest_stream(stream)
        require(stamp(os.fstat(stream.fileno())) == stamp(before), 'FILE_CHANGED_DURING_READ')
    return result


def private_bytes(path, maximum=8 * MIB):
    trusted_parents(path)
    info = trusted(path, private=True)
    require(info.st_size <= maximum, 'PRIVATE_INPUT_TOO_LARGE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        require(stamp(os.fstat(stream.fileno())) == stamp(info), 'PRIVATE_INPUT_IDENTITY_CHANGED')
        data = stream.read(maximum + 1)
        require(len(data) == info.st_size and stamp(os.fstat(stream.fileno())) == stamp(info),
                'PRIVATE_INPUT_CHANGED')
    return data


def load_profile():
    raw = private_bytes(PROFILE)
    require(hashlib.sha256(raw).hexdigest() == PROFILE_SHA, 'PROFILE_SHA256_MISMATCH')
    p = json.loads(raw)
    require(p['schema_version'] == 1 and p['source_root'] == str(SOURCE)
            and p['assets_directory'] == str(ASSETS) and p['source_containers'] == NAMES
            and p['source_core_version'] == '2025100608.00'
            and p['target_core_version'] == '2026100500.00' and p['target_core_branch'] == '503'
            and p['platform'] == 'linux/amd64' and p['postgres17_version'] == '17.11'
            and len(p['application_files']) == 526 and len(p['additional_roots']) == 19
            and len(p['additional_components']) == 57, 'PROFILE_CONTRACT_DIFFERS')
    require(re.fullmatch(r'[0-9a-f]{64}', p['archive_sha256'])
            and re.fullmatch(r'sha256:[0-9a-f]{64}', p['postgres17_digest'])
            and p['postgres17_image'] == 'docker.io/library/postgres@' + p['postgres17_digest'],
            'PROFILE_ASSET_PIN_DIFFERS')
    for name, digest in p['application_files'].items():
        relative(name)
        require(re.fullmatch(r'[0-9a-f]{64}', digest), 'INVALID_APPLICATION_HASH')
    roots = p['additional_roots']
    require(len(set(roots)) == len(roots), 'DUPLICATE_ADDON_ROOT')
    for name in roots:
        relative(name)
        require(name.startswith('public/') and not any(
            name != other and name.startswith(other + '/') for other in roots), 'OVERLAPPING_ADDON_ROOTS')
    return p


def docker_inspect(kind, names):
    # Only these read-only Docker subcommands exist in this preparer.
    require(kind in ('container', 'image'), 'NONREADONLY_DOCKER_COMMAND_REFUSED')
    command = ['docker', 'inspect', *names] if kind == 'container' else ['docker', 'image', 'inspect', *names]
    result = subprocess.run(command, capture_output=True, text=True, timeout=30, check=False)
    require(result.returncode == 0, 'DOCKER_INSPECT_FAILED')
    data = json.loads(result.stdout)
    require(isinstance(data, list) and len(data) == len(names), 'DOCKER_INSPECT_INCOMPLETE')
    return data


def container_fingerprint(row):
    # Healthcheck timestamps/logs legitimately advance on the live production
    # containers while copying; they are not identity/configuration drift.
    result = {k: row[k] for k in ('Id', 'Name', 'Image', 'HostConfig', 'Config', 'Mounts')}
    result['State'] = {k: row['State'].get(k) for k in (
        'Running', 'Status', 'ExitCode', 'StartedAt', 'FinishedAt',
        'OOMKilled', 'Error', 'Dead', 'Paused', 'Restarting', 'Pid')}
    return result


def inspect_source(p, state):
    rows = docker_inspect('container', [*PRODUCTION, NAMES['web'], NAMES['pg']])
    observed = {r['Name'].lstrip('/'): r for r in rows}
    require(set(observed) == {*PRODUCTION, *NAMES.values()}, 'CONTAINER_NAME_SET_DIFFERS')
    require(state.get('ready') is True and set(state.get('containers', {})) == {'web', 'pg'}
            and set(state.get('images', {})) == {'web', 'pg'}, 'SOURCE_OWNERSHIP_STATE_DIFFERS')
    for kind, name in NAMES.items():
        r = observed[name]
        require(r['Id'] == state['containers'][kind] and r['Image'] == state['images'][kind],
                'SOURCE_CONTAINER_ID_OR_IMAGE_DIFFERS')
        require(r['State']['Running'] is False and r['State']['Status'] == 'exited'
                and r['State']['ExitCode'] == 0 and r['HostConfig']['RestartPolicy']['Name'] == 'no',
                'SOURCE_LAB_NOT_CLEANLY_STOPPED')
        expected = ({'/var/www/html': SOURCE / TREE_PATHS['code'],
                     '/var/www/moodledata': SOURCE / TREE_PATHS['moodledata']}
                    if kind == 'web' else {'/var/lib/postgresql/data': SOURCE / TREE_PATHS['postgres16']})
        mounts = {m['Destination']: m for m in r['Mounts']}
        for dest, src in expected.items():
            require(dest in mounts and mounts[dest]['Type'] == 'bind'
                    and mounts[dest]['Source'] == str(src), 'SOURCE_BIND_ROOT_DIFFERS')
        allowed = {str(v) for v in expected.values()} | {str(SOURCE / n) for n in ('apache.conf', 'lab.ini')}
        require(all((m['Type'] == 'bind' and m['Source'] in allowed)
                    or m['Type'] == 'tmpfs' for m in r['Mounts']),
                'UNEXPECTED_SOURCE_MOUNT')
    for name in PRODUCTION:
        r = observed[name]
        require(r['State']['Running'] is True, 'PRODUCTION_NOT_RUNNING_AT_PREPARATION')
        for m in r['Mounts']:
            src = Path(m['Source'])
            require(src != SOURCE and SOURCE not in src.parents and src not in SOURCE.parents,
                    'SOURCE_OVERLAPS_PRODUCTION_MOUNT')
    image = docker_inspect('image', [p['postgres17_image']])[0]
    require(image['Os'] + '/' + image['Architecture'] == p['platform']
            and any(x.rsplit('@', 1)[-1] == p['postgres17_digest'] for x in image.get('RepoDigests', [])),
            'PG17_CACHED_IMAGE_PIN_OR_PLATFORM_DIFFERS')
    env = image.get('Config', {}).get('Env', [])
    require('PG_MAJOR=17' in env and any(x.startswith('PG_VERSION=17.11-') or x == 'PG_VERSION=17.11'
                                        for x in env), 'PG17_CACHED_IMAGE_VERSION_METADATA_DIFFERS')
    return {name: container_fingerprint(r) for name, r in observed.items()}, image['Id']


def xattrs(path):
    return {name: hashlib.sha256(os.getxattr(path, name, follow_symlinks=False)).hexdigest()
            for name in os.listxattr(path, follow_symlinks=False)}


def metadata(path, info, digest=None):
    result = {'type': 'directory' if stat.S_ISDIR(info.st_mode) else 'file',
              'uid': info.st_uid, 'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode),
              'mtime_ns': info.st_mtime_ns, 'xattrs': xattrs(path)}
    if digest is not None:
        result.update(size=info.st_size, sha256=digest)
    return result


def inventory(root, kind):
    first = root.lstat()
    require(stat.S_ISDIR(first.st_mode), 'SOURCE_TREE_NOT_DIRECTORY')
    entries = {}

    def checked(path, directory):
        info = path.lstat()
        mode = stat.S_IMODE(info.st_mode)
        require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
                and info.st_dev == first.st_dev and (directory or info.st_nlink == 1),
                'TREE_LINK_SPECIAL_ENTRY_OR_NESTED_FILESYSTEM')
        if kind == 'moodledata':
            require(info.st_uid == DATA_UID and info.st_gid == DATA_GID
                    and (not mode & 0o7022 if directory else not mode & ~0o666),
                    'MOODLEDATA_OWNER_OR_MODE_DIFFERS')
        else:
            require((kind != 'code' or info.st_uid == 0) and not mode & 0o7022,
                    'CODE_OR_POSTGRES_OWNER_OR_MODE_DIFFERS')
        rel = path.relative_to(root).as_posix()
        entries['' if rel == '.' else rel] = metadata(path, info, None if directory else file_hash(path))
        require(len(entries) <= MAX_ENTRIES, 'TREE_ENTRY_LIMIT_EXCEEDED')

    checked(root, True)

    def failed(_):
        raise PreparationError('TREE_SCAN_INCOMPLETE')

    for base, dirs, files in os.walk(root, followlinks=False, onerror=failed):
        for name in dirs:
            checked(Path(base) / name, True)
        for name in files:
            checked(Path(base) / name, False)
    return entries


def core_metadata(data, version, branch=None):
    value = data.decode('utf-8')
    found = re.findall(r'\$version\s*=\s*([0-9]+\.[0-9]+)\s*;', value)
    require(found == [version], 'CORE_VERSION_LITERAL_DIFFERS')
    if branch is not None:
        require(re.findall(r"\$branch\s*=\s*['\"]([0-9]+)['\"]\s*;", value) == [branch]
                and re.search(r'\$maturity\s*=\s*MATURITY_STABLE\s*;', value), 'TARGET_CORE_METADATA_DIFFERS')


def validate_application(root, entries, p):
    core_metadata((root / 'public/version.php').read_bytes(), p['source_core_version'])
    expected = p['application_files']
    found = {k: v['sha256'] for k, v in entries.items() if v['type'] == 'file'
             and k.startswith(('public/local/ustar/', 'public/theme/ustar/'))}
    require(found == expected, 'USTAR_SOURCE_HASH_SET_DIFFERS')
    for name in p['additional_roots']:
        require(entries.get(name, {}).get('type') == 'directory', 'ADDON_ROOT_MISSING')
    for item in p['additional_components']:
        rel = 'public/' + item['version_file']
        relative(rel)
        require(entries.get(rel, {}).get('type') == 'file', 'ADDON_VERSION_FILE_MISSING')
        text = (root / rel).read_text()
        versions = re.findall(r'\$plugin->version\s*=\s*([0-9]+)\s*;', text)
        require(versions == [str(item['disk_version'])], 'ADDON_DISK_VERSION_DIFFERS')
    require((root.parent.parent / 'postgres/PG_VERSION').read_text().strip() == '16'
            and not (root.parent.parent / 'postgres/postmaster.pid').exists(), 'SOURCE_PG16_NOT_COLD')


@contextmanager
def archive(p):
    path = ASSETS / p['archive_file']
    trusted_parents(path)
    info = trusted(path, private=True)
    require(info.st_size <= 256 * MIB, 'ARCHIVE_SIZE_LIMIT_EXCEEDED')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        require(stamp(os.fstat(stream.fileno())) == stamp(info), 'ARCHIVE_IDENTITY_CHANGED')
        require(digest_stream(stream) == p['archive_sha256'], 'OFFICIAL_ARCHIVE_SHA256_MISMATCH')
        require(stamp(os.fstat(stream.fileno())) == stamp(info), 'ARCHIVE_CHANGED_DURING_HASH')
        stream.seek(0)
        with tarfile.open(fileobj=stream, mode='r:gz') as tar:
            catalog, expanded = {}, 0
            for member in tar:
                require((member.isfile() or member.isdir()) and not member.sparse,
                        'ARCHIVE_LINK_OR_SPECIAL_ENTRY')
                name = member.name.rstrip('/') if member.isdir() else member.name
                path = relative(name)
                require(path.parts[0] == 'moodle' and (len(path.parts) > 1 or member.isdir()),
                        'ARCHIVE_OUTSIDE_MOODLE_ROOT')
                rel = '/'.join(path.parts[1:])
                require(rel not in catalog and 0 <= member.size <= 128 * MIB,
                        'ARCHIVE_DUPLICATE_OR_SIZE_LIMIT')
                catalog[rel] = member
                expanded += member.size
                require(len(catalog) <= MAX_ENTRIES and expanded <= 2 * GIB,
                        'ARCHIVE_EXPANSION_LIMIT_EXCEEDED')
            require(catalog.get('') and catalog[''].isdir() and 'config.php' not in catalog,
                    'ARCHIVE_ROOT_OR_CONFIG_DIFFERS')
            for rel in catalog:
                for parent in PurePosixPath(rel).parents if rel else ():
                    key = '' if str(parent) == '.' else str(parent)
                    require(key in catalog and catalog[key].isdir(), 'ARCHIVE_PARENT_MISSING')
            require(catalog.get('public/version.php') and catalog['public/version.php'].isfile(),
                    'TARGET_VERSION_FILE_MISSING')
            core_metadata(tar.extractfile(catalog['public/version.php']).read(),
                          p['target_core_version'], p['target_core_branch'])
            yield tar, catalog
        require(stamp(os.fstat(stream.fileno())) == stamp(info), 'ARCHIVE_CHANGED_DURING_EXTRACTION')


def copy_attributes(source, target, entry):
    os.chown(target, entry['uid'], entry['gid'])
    os.chmod(target, entry['mode'])
    os.utime(target, ns=(source.stat().st_atime_ns, entry['mtime_ns']))
    for name in os.listxattr(source, follow_symlinks=False):
        os.setxattr(target, name, os.getxattr(source, name, follow_symlinks=False), follow_symlinks=False)


def reserve():
    require(shutil.disk_usage(OUTPUT).free >= RESERVE, 'FREE_SPACE_RESERVE_REACHED')


class CopyProgress:
    def __init__(self):
        self.bytes, self.last_print = 0, 0

    def add(self, size):
        self.bytes += size
        if self.bytes - self.last_print >= 256 * MIB:
            print('MOODLE53_COPY_BYTES=' + str(self.bytes), flush=True)
            self.last_print = self.bytes
            reserve()


def copy_file(source, target, entry, progress):
    before = source.lstat()
    require(metadata(source, before, entry['sha256']) == entry, 'COPY_SOURCE_METADATA_CHANGED')
    fd = os.open(source, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as src:
        require(stamp(os.fstat(src.fileno())) == stamp(before), 'COPY_SOURCE_IDENTITY_CHANGED')
        outfd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(outfd, 'wb') as dst:
            h = hashlib.sha256()
            for block in iter(lambda: src.read(MIB), b''):
                h.update(block)
                dst.write(block)
                progress.add(len(block))
            dst.flush()
            os.fsync(dst.fileno())
        require(stamp(os.fstat(src.fileno())) == stamp(before) and h.hexdigest() == entry['sha256'],
                'COPY_SOURCE_BYTES_CHANGED')
    copy_attributes(source, target, entry)
    require(source.stat().st_ino != target.stat().st_ino or source.stat().st_dev != target.stat().st_dev,
            'COPIED_FILE_NOT_INDEPENDENT')
    require(metadata(target, target.stat(), file_hash(target)) == entry, 'COPY_READBACK_DIFFERS')


def copy_tree(source, target, entries, progress):
    target.mkdir(mode=0o700)
    for key in sorted(entries, key=lambda value: (value.count('/'), value)):
        if not key:
            continue
        entry, dest = entries[key], target / key
        if entry['type'] == 'directory':
            dest.mkdir(mode=0o700)
        else:
            copy_file(source / key, dest, entry, progress)
    for key in sorted(entries, key=lambda value: len(PurePosixPath(value).parts), reverse=True):
        if entries[key]['type'] == 'directory':
            copy_attributes(source / key, target / key, entries[key])


def budget(trees, catalog, code, roots):
    fs = os.statvfs(OUTPUT)
    block = fs.f_frsize
    rounded = lambda size: ((size + block - 1) // block) * block
    treebytes = sum(block if e['type'] == 'directory' else rounded(e['size'])
                    for rows in trees.values() for e in rows.values())
    addon = {k: e for k, e in code.items() if any(k == r or k.startswith(r + '/') for r in roots)}
    needed = 2 * treebytes + sum(block if m.isdir() else rounded(m.size) for m in catalog.values())
    needed += sum(block if e['type'] == 'directory' else rounded(e['size']) for e in addon.values())
    needed += 256 * MIB + 2 * GIB  # controls/manifests and future new-PG17 growth allowance.
    inodes = 2 * sum(map(len, trees.values())) + len(catalog) + len(addon) + 1024
    free = shutil.disk_usage(OUTPUT).free
    require(free >= needed + RESERVE and fs.f_favail >= inodes, 'INSUFFICIENT_STAGE_SPACE_OR_INODES')
    return {'available_free_bytes': free, 'required_free_bytes': needed + RESERVE,
            'minimum_free_after_bytes': RESERVE, 'required_free_inodes': inodes,
            'future_PG17_growth_allowance_bytes': 2 * GIB, 'capacity_guarantee': False}


def extract_candidate(tar, catalog, target, source, code, p, progress):
    target.mkdir(mode=0o700)
    stock = {}
    for rel in sorted(catalog, key=lambda v: (v.count('/'), v)):
        member = catalog[rel]
        dest = target / rel
        if member.isdir():
            if rel:
                dest.mkdir(mode=0o755)
                os.chmod(dest, 0o755)
            continue
        fd = os.open(dest, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
        with tar.extractfile(member) as src, os.fdopen(fd, 'wb') as dst:
            h = hashlib.sha256()
            for block in iter(lambda: src.read(MIB), b''):
                dst.write(block)
                h.update(block)
                progress.add(len(block))
            dst.flush()
            os.fsync(dst.fileno())
        require(dest.stat().st_size == member.size and file_hash(dest) == h.hexdigest(),
                'STOCK_EXTRACTION_READBACK_DIFFERS')
        os.chmod(dest, 0o644 | (member.mode & 0o111))
        stock[rel] = h.hexdigest()
    for root in p['additional_roots']:
        require(not (target / root).exists() and not any(
            rel == root or rel.startswith(root + '/') for rel in catalog), 'ADDON_COLLIDES_WITH_STOCK53')
        sub = {'' if k == root else k[len(root) + 1:]: e for k, e in code.items()
               if k == root or k.startswith(root + '/')}
        # Stock distributions may omit an otherwise-empty standard plugin parent.
        parent = (target / root).parent
        missing = []
        while not parent.exists():
            missing.append(parent)
            parent = parent.parent
        require(parent == target or target in parent.parents, 'ADDON_PARENT_OUTSIDE_CANDIDATE')
        for path in reversed(missing):
            path.mkdir(mode=0o755)
            os.chmod(path, 0o755)
        copy_tree(source / root, target / root, sub, progress)
    for rel, digest in stock.items():
        require(file_hash(target / rel) == digest, 'STOCK53_CORE_WAS_OVERWRITTEN')
    require(not (target / 'config.php').exists(), 'CANDIDATE_CONFIG_ACTIVATION_REFUSED')
    return stock


def private_json(path, value):
    temporary = path.parent / ('.' + path.name + '.new')
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as stream:
        raw = (json.dumps(value, sort_keys=True, indent=2) + '\n').encode()
        stream.write(raw)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, path)
    directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)
    return hashlib.sha256(raw).hexdigest()


def phase(report, value):
    report['phase'] = value
    report['updated_at'] = datetime.now(timezone.utc).isoformat()
    private_json(OUTPUT / 'active.json', report)
    if 'run_directory' in report:
        private_json(Path(report['run_directory']) / 'report.json', report)
    print('MOODLE53_STAGE_PHASE=' + value, flush=True)


def prepare(p):
    trusted_parents(SOURCE)
    trusted(SOURCE, directory=True, private=True)
    trusted_parents(OUTPUT)
    if not OUTPUT.exists():
        OUTPUT.mkdir(mode=0o700)
    trusted(OUTPUT, directory=True, private=True)
    if (OUTPUT / 'active.json').exists():
        old = json.loads(private_bytes(OUTPUT / 'active.json'))
        require(old.get('phase') not in ('prepared', 'copying_pairs', 'extracting_stock53', 'verifying'),
                'EXISTING_STAGE_OR_UNFINISHED_RUN_NO_AUTOMATIC_REPEAT')
    state = json.loads(private_bytes(SOURCE / 'state.json'))
    before, image_id = inspect_source(p, state)
    controls = {name: private_bytes(SOURCE / name) for name in CONTROLS}
    trees = {name: inventory(SOURCE / path, name) for name, path in TREE_PATHS.items()}
    validate_application(SOURCE / TREE_PATHS['code'], trees['code'], p)
    with archive(p) as (tar, catalog):
        storage = budget(trees, catalog, trees['code'], p['additional_roots'])
        run = Path(tempfile.mkdtemp(prefix='stage-', dir=OUTPUT))
        report = {'kind': 'stopped_full_addon_major_stage_preparation',
                  'started_at': datetime.now(timezone.utc).isoformat(), 'run_directory': str(run),
                  'source_core_version': p['source_core_version'], 'target_core_version': p['target_core_version'],
                  'postgres17_image': p['postgres17_image'], 'postgres17_image_id': image_id,
                  'archive_sha256': p['archive_sha256'], 'storage_plan': storage,
                  'containers_started_or_stopped': False, 'SQL_or_PHP_executed': False,
                  'production_upgrade_executed': False, 'compatibility_verified': False}
        progress = CopyProgress()
        try:
            phase(report, 'copying_pairs')
            for name in ('baseline', 'working'):
                pair = run / name
                pair.mkdir(mode=0o700)
                for kind, path in TREE_PATHS.items():
                    copy_tree(SOURCE / path if name == 'baseline' else run / 'baseline' / kind,
                              pair / kind, trees[kind], progress)
                controls_dir = pair / 'controls'
                controls_dir.mkdir(mode=0o700)
                for item, data in controls.items():
                    fd = os.open(controls_dir / item, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                    with os.fdopen(fd, 'wb') as stream:
                        stream.write(data)
                        stream.flush()
                        os.fsync(stream.fileno())
            phase(report, 'extracting_stock53')
            stock = extract_candidate(tar, catalog, run / 'candidate53', run / 'baseline/code',
                                      trees['code'], p, progress)
            phase(report, 'verifying')
            for name in ('baseline', 'working'):
                for kind in TREE_PATHS:
                    require(inventory(run / name / kind, kind) == trees[kind], 'PAIRED_COPY_READBACK_DIFFERS')
                for item, data in controls.items():
                    require(private_bytes(run / name / 'controls' / item) == data, 'CONTROL_COPY_READBACK_DIFFERS')
            for kind, path in TREE_PATHS.items():
                require(inventory(SOURCE / path, kind) == trees[kind], 'SOURCE_TREE_CHANGED_DURING_PREPARATION')
            require(inspect_source(p, state)[0] == before, 'CONTAINERS_CHANGED_DURING_PREPARATION')
            require(all(private_bytes(SOURCE / n) == d for n, d in controls.items()), 'SOURCE_CONTROL_CHANGED')
            manifest = {'schema_version': 1, 'complete': True, 'source_root': str(SOURCE),
                        'trees': trees, 'controls_sha256': {n: hashlib.sha256(d).hexdigest() for n, d in controls.items()},
                        'stock53_file_hashes': stock, 'addon_roots': p['additional_roots'],
                        'candidate_config_present': False, 'source_containers': before,
                        'profile_sha256': PROFILE_SHA, 'archive_sha256': p['archive_sha256'],
                        'postgres17_image': p['postgres17_image']}
            proof = private_json(run / 'manifest.json', manifest)
            report.update(manifest_sha256=proof, paired_entries=sum(map(len, trees.values())),
                          stock53_files=len(stock), addon_components_preserved=len(p['additional_components']),
                          addon_roots_preserved=len(p['additional_roots']), ustar_matching_files=len(p['application_files']),
                          independent_pair_readback_verified=True, source_trees_and_containers_unchanged=True,
                          candidate53_config_present=False, copied_bytes=progress.bytes)
            phase(report, 'prepared')
            print('MOODLE53_STAGE_PREPARE=PASS; NO_ACTIVATION; NO_UPGRADE', flush=True)
            print(json.dumps(report, indent=2), flush=True)
            return report
        except BaseException as exc:
            report['failure_code'] = str(exc) if isinstance(exc, PreparationError) else type(exc).__name__
            phase(report, 'failed_partial_preserved')
            raise


@contextmanager
def locked():
    trusted_parents(LOCK)
    fd = os.open(LOCK, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        require(stat.S_ISREG(os.fstat(fd).st_mode) and os.fstat(fd).st_uid == 0
                and os.fstat(fd).st_nlink == 1 and not os.fstat(fd).st_mode & 0o022, 'UNTRUSTED_LAB_LOCK')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise PreparationError('LAB_LOCK_BUSY_NO_CHANGE') from None
        yield
    finally:
        os.close(fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument('--prepare', action='store_true')
    modes.add_argument('--status', action='store_true')
    args = parser.parse_args()
    require(os.geteuid() == 0, 'ROOT_REQUIRED')
    os.umask(0o077)
    if args.status:
        value = (json.loads(private_bytes(OUTPUT / 'active.json')) if (OUTPUT / 'active.json').exists()
                 else {'phase': 'not_started'})
        print(json.dumps(value, indent=2))
    else:
        with locked():
            prepare(load_profile())


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        code = str(exc) if isinstance(exc, PreparationError) else type(exc).__name__
        print('MOODLE53_STAGE_PREPARE_ERROR=' + code, file=sys.stderr, flush=True)
        raise SystemExit(1)
