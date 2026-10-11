#!/usr/bin/env python3
"""Prepare separate full-addon patch code; never activate it or execute PHP/SQL.

Default is plan only. --check validates private inputs, pinned dependencies,
owned source/stage containers (inspect only), frozen selected code, archive and
budget. --prepare-candidate extracts stock core into a fresh private directory,
copies addons/extras with verified hashes, and prepares the offline stage config.
No container start/stop/create, image pulls, network, live code/data/PG writes,
cron, Moodle bootstrap, core upgrade, deletion or overwrite of existing copies.
"""
import argparse
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import sys
import tarfile
import tempfile

SOURCE = Path('/var/lib/ustar-restore-lab/20261004T123406Z-741e3f72')
CHECKPOINT = SOURCE / 'patch-rollback-20261005'
WORK = SOURCE / 'patch-stage-20261005'
PACKAGE_DIR = SOURCE / 'moodle-518-package-20261011-nnixp4se'
ARCHIVE = PACKAGE_DIR / 'moodle-5.1.8.tgz'
LOCK = Path('/run/ustar-restore-lab-20261004.lock')
COLD = Path('/usr/local/sbin/USTAR_LAB_COLD_CHECKPOINT_20261005.py')
COLD_SHA = '9c81abb6b30bea611f99fb7a3e336e12d0a7827d4ea462a4d3d63e6f8aec9012'
MANIFEST_SHA = '77672fd0b5b7bc5a27d1bd71d536f2bf47449455623589b53e0605005584b4c0'
ARCHIVE_SHA = '0e8e2cdeecf24512b75e24158fa224ed08a20c608b4e1065cb2068832467665c'
UPSTREAM = 'cc0bf17da8b5fbd290faf95b2b14a03d64331923'
CORE_HASHES = {
    'public/version.php': '8e8ca75d913bf1f0b0a73bd029d476d827f50855ecf5d1a99a891e65c498ee02',
    'public/index.php': '3af0034674ac5b59e8926ebd63e1a0bf3360a495675d82de7b800e0514c467f7',
    'composer.json': '02d8b9bf7cf18d15459cc4672835ad74cc07440a7214cfcb2e81748d159d63a3',
    'composer.lock': '064bddc7f6b5dc1f23eef6ec40d4c96091e0e651a5cc9639c6b855dd5d3afe8f',
}
# Names from the reviewed scoped core comparison. Contents remain private.
EXTRAS = (
    'bx_grade_tokens.txt', 'config.php.backup-2026-08-10',
    'config.php.backup-before-wwwroot-fix-2026-08-10', 'config.php.backup_debug',
    'config.php.before_ip_change_20260830T203841Z', 'config.php.pre-cookie2726',
    'githash.php', 'gradehelper.php', 'gradehelper_submit.php',
    'public/bitrix_bot_handler.php', 'public/config.php.before_ip_change_20260830T203238Z',
    'public/course_dashboard.php', 'public/gradehelper.php', 'public/gradehelper_submit.php',
    'public/index.php.backup_ustar', 'public/reports/course_10_005c7d20069c.pdf',
    'public/reports/course_10_72536505b05e.pdf', 'public/reports/course_10_879d66073583.pdf',
    'public/reports/course_10_9e253332c84d.pdf', 'public/reports/course_10_b661337a5988.pdf',
    'public/reports/course_10_c959c810a6b9.pdf', 'public/reports/course_10_d332481cd5be.pdf',
    'wwwroot',
)
ROOT_UID, ROOT_GID, WEB_GID = 0, 0, 33
GIB, MIB = 1024 ** 3, 1024 ** 2
RESERVE = 4 * GIB
PREFIX = 'site/public/'


class CandidateError(Exception):
    pass


def require(ok, message):
    if not ok:
        raise CandidateError(message)


def guarded(call, message):
    try:
        return call()
    except Exception:
        raise CandidateError(message) from None


def trusted(path, directory=False, private=False):
    info = path.lstat()
    require((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode))
            and info.st_uid == ROOT_UID and not info.st_mode & (0o077 if private else 0o022)
            and (directory or info.st_nlink == 1), 'Untrusted candidate input metadata')
    return info


def trusted_parents(path):
    for parent in path.parents:
        trusted(parent, directory=True)


def signature(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns)


def digest_stream(stream):
    digest = hashlib.sha256()
    for block in iter(lambda: stream.read(MIB), b''):
        digest.update(block)
    return digest.hexdigest()


def read_checked(path, expected_sha=None, maximum=32 * MIB):
    info = trusted(path)
    require(info.st_size <= maximum, 'Candidate input exceeds size limit')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        require(signature(os.fstat(stream.fileno())) == signature(info), 'Input identity changed')
        data = stream.read(maximum + 1)
        require(len(data) == info.st_size
                and signature(os.fstat(stream.fileno())) == signature(info), 'Input changed while reading')
    if expected_sha is not None:
        require(hashlib.sha256(data).hexdigest() == expected_sha, 'Candidate input checksum differs')
    return data


def relative(value):
    require(isinstance(value, str) and value and '\0' not in value and '\\' not in value,
            'Unsafe candidate relative path')
    path = PurePosixPath(value)
    require(path.parts and not path.is_absolute() and path.as_posix() == value
            and not {'.', '..'} & set(path.parts), 'Unsafe candidate relative path')
    return path


def metadata(info, directory=False, digest=None):
    result = {'type': 'directory' if directory else 'file', 'uid': info.st_uid,
              'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode)}
    if not directory:
        result.update(size=info.st_size, sha256=digest)
    return result


def load_dependencies():
    trusted_parents(COLD)
    data = read_checked(COLD, COLD_SHA, MIB)
    spec = importlib.util.spec_from_file_location('ustar_candidate_pinned_cold', COLD)
    cold = importlib.util.module_from_spec(spec)
    exec(compile(data, str(COLD), 'exec'), cold.__dict__)
    require((cold.SOURCE, cold.CHECKPOINT, cold.WORK) == (SOURCE, CHECKPOINT, WORK),
            'Pinned checkpoint dependency namespace differs')
    p = guarded(cold.load_preflight, 'Pinned planning dependency failed')
    resume, source, template = guarded(p.load_resume, 'Pinned engine/checker dependency failed')
    profile = guarded(p.load_profile, 'Pinned planning profile failed')
    stage = guarded(lambda: cold.stage_engine(resume), 'Pinned stage adapter failed')
    require(p.ROOT == SOURCE and source.ROOT == SOURCE and stage.ROOT == WORK
            and stage.PORT == 18085 and set(stage.NAMES.values()).isdisjoint(source.PRODUCTION),
            'Unexpected retained stage namespace')
    return cold, p, resume, source, stage, template, profile


def load_manifest():
    trusted_parents(CHECKPOINT / 'manifest.json')
    trusted(CHECKPOINT, directory=True, private=True)
    trusted(CHECKPOINT / 'manifest.json', private=True)
    value = json.loads(read_checked(CHECKPOINT / 'manifest.json', MANIFEST_SHA))
    require(value.get('schema_version') == 1 and value.get('complete') is True
            and value.get('source') == str(SOURCE)
            and value.get('source_core_db_version') == '2025100601.03'
            and isinstance(value.get('entries'), dict) and 0 < len(value['entries']) <= 100000,
            'Unsupported accepted checkpoint manifest')
    for key, entry in value['entries'].items():
        relative(key)
        require(isinstance(entry, dict) and entry.get('type') in ('file', 'directory'),
                'Invalid checkpoint entry')
        if entry['type'] == 'file':
            require(isinstance(entry.get('size'), int) and 0 <= entry['size'] <= 8 * GIB
                    and isinstance(entry.get('sha256'), str)
                    and re.fullmatch(r'[0-9a-f]{64}', entry['sha256']), 'Invalid checkpoint file reference')
    return value['entries']


def frozen_info(rel, entries, directory=False):
    relative(rel)
    key = PREFIX + rel
    expected = entries.get(key)
    require(expected is not None and expected['type'] == ('directory' if directory else 'file'),
            'Required frozen code entry missing')
    path = CHECKPOINT / 'site/public' / rel
    trusted_parents(path)
    info = trusted(path, directory=directory)
    require(metadata(info, directory, expected.get('sha256')) == expected
            and info.st_dev == CHECKPOINT.stat().st_dev, 'Frozen code metadata changed')
    return path, info, expected


def frozen_bytes(rel, entries, maximum=MIB):
    path, _, expected = frozen_info(rel, entries)
    return read_checked(path, expected['sha256'], maximum)


def selected_entries(entries, roots):
    require(len(roots) == len(set(roots)) and len(EXTRAS) == len(set(EXTRAS)),
            'Duplicate overlay reference')
    for root in roots:
        relative(root)
        require(root.startswith('public/') and not any(
            root != other and root.startswith(other + '/') for other in roots), 'Overlapping addon roots')
        frozen_info(root, entries, directory=True)
    selected = {}
    for key, entry in entries.items():
        if not key.startswith(PREFIX):
            continue
        rel = key[len(PREFIX):]
        if any(rel == root or rel.startswith(root + '/') for root in roots):
            selected[rel] = entry
    for rel in EXTRAS:
        frozen_info(rel, entries)
        require(rel not in selected, 'Extra file overlaps addon root')
        selected[rel] = entries[PREFIX + rel]
    for rel in list(selected):
        for parent in relative(rel).parents:
            if str(parent) != '.':
                name = parent.as_posix()
                frozen_info(name, entries, directory=True)
                selected[name] = entries[PREFIX + name]
    return selected


def archive_catalog(tar):
    catalog = {}
    total = 0
    for member in tar:
        require(len(catalog) < 100000 and (member.isdir() or member.isfile())
                and not member.sparse, 'Unsupported archive entry type or count')
        name = member.name.rstrip('/') if member.isdir() else member.name
        path = relative(name)
        require(path.parts[0] == 'moodle' and (len(path.parts) > 1 or member.isdir()),
                'Archive outside Moodle root')
        rel = PurePosixPath(*path.parts[1:]).as_posix() if len(path.parts) > 1 else '.'
        require(rel not in catalog and isinstance(member.size, int)
                and 0 <= member.size <= 100 * MIB, 'Duplicate archive path or invalid size')
        catalog[rel] = member
        total += member.size if member.isfile() else 0
        require(total <= GIB, 'Archive expansion exceeds limit')
    require('.' in catalog and catalog['.'].isdir(), 'Archive root is missing')
    for rel in catalog:
        for parent in PurePosixPath(rel).parents:
            require(parent.as_posix() in catalog and catalog[parent.as_posix()].isdir(),
                    'Archive parent directory is missing or not a directory')
    for path, digest in CORE_HASHES.items():
        require(path in catalog and catalog[path].isfile()
                and catalog[path].size <= MIB, 'Pinned core metadata absent')
        require(hashlib.sha256(tar.extractfile(catalog[path]).read()).hexdigest() == digest,
                'Package core metadata differs from pinned upstream')
    require('config.php' not in catalog and 'vendor' not in catalog,
            'Unexpected root config/vendor in pinned distribution')
    return catalog


def archive_open():
    require(PACKAGE_DIR.parent == SOURCE and ARCHIVE.parent == PACKAGE_DIR,
            'Unexpected package namespace')
    trusted_parents(ARCHIVE)
    trusted(PACKAGE_DIR, directory=True, private=True)
    info = trusted(ARCHIVE, private=True)
    require(info.st_size <= 100 * MIB, 'Package size exceeds limit')
    fd = os.open(ARCHIVE, os.O_RDONLY | os.O_NOFOLLOW)
    stream = os.fdopen(fd, 'rb')
    try:
        require(signature(os.fstat(fd)) == signature(info), 'Package identity changed')
        require(digest_stream(stream) == ARCHIVE_SHA, 'Official package checksum differs')
        require(signature(os.fstat(fd)) == signature(info), 'Package changed during hashing')
        stream.seek(0)
        tar = tarfile.open(fileobj=stream, mode='r:gz')
        return stream, tar, info
    except BaseException:
        stream.close()
        raise


def reserve():
    require(shutil.disk_usage(SOURCE).free >= RESERVE, 'Free space reached the 4 GiB reserve')


def budget(catalog, selected):
    fs = os.statvfs(SOURCE)
    block = fs.f_frsize
    needed = sum(block if m.isdir() else ((m.size + block - 1) // block) * block
                 for m in catalog.values())
    needed += sum(block if e['type'] == 'directory' else ((e['size'] + block - 1) // block) * block
                  for e in selected.values())
    # Code copy only; reserve manifest/config overhead, not an activation/DB-growth guarantee.
    needed += 128 * MIB
    free = shutil.disk_usage(SOURCE).free
    inodes = len(catalog) + len(selected) + 1000
    require(free >= needed + RESERVE and fs.f_favail >= inodes,
            'Insufficient space or inodes for separate candidate and reserve')
    return {'candidate_copy_allowance_bytes': needed, 'required_free_bytes': needed + RESERVE,
            'available_free_bytes': free, 'minimum_free_after_bytes': RESERVE,
            'required_free_inodes': inodes, 'available_free_inodes': fs.f_favail,
            'activation_database_growth_budget_included': False}


def inspect_labs(source, stage):
    before = guarded(source.production_state, 'Production inspect failed')
    snapshots = []
    for lab in (source, stage):
        state = guarded(lab.load_state, 'Lab state read failed')
        require(state.get('ready') is True and state.get('stopped') is False
                and set(state.get('containers', {})) == {'web', 'pg'}
                and set(state.get('images', {})) == {'web', 'pg'}, 'Expected running accepted labs')
        require(set(lab.NAMES.values()).isdisjoint(lab.PRODUCTION), 'Lab namespace overlaps production')
        identities = {}
        for kind in ('pg', 'web'):
            item = guarded(lambda: lab.owned(kind, state), 'Lab ownership/image/mount guard failed')
            require(item['State']['Running'], 'A required lab container is stopped')
            identities[kind] = (item['Id'], item['Image'], item['State']['StartedAt'])
        guarded(lambda: lab.assert_offline(state), 'Offline lab namespace differs')
        snapshots.append(identities)
    return before, snapshots


def prepare_config(resume, source, template, entries):
    guarded(lambda: resume.check_config(source, template), 'Reviewed offline source config differs')
    original = frozen_bytes('config.php', entries, maximum=128 * 1024)
    require(read_checked(SOURCE / 'site/public/config.php', maximum=128 * 1024) == original,
            'Frozen/source offline configuration differs')
    frozen_bytes('public/config.php', entries, maximum=128 * 1024)
    text = original.decode('utf-8')
    replacements = {
        "$CFG->wwwroot = 'http://127.0.0.1:18084';": "$CFG->wwwroot = 'http://127.0.0.1:18085';",
        "$CFG->sessioncookie = 'USTAR_RECOVERY_LAB_20261004';":
        "$CFG->sessioncookie = 'USTAR_PATCH_STAGE_20261005';",
    }
    for old, new in replacements.items():
        require(text.count(old) == 1, 'Unsupported offline config substitution')
        text = text.replace(old, new)
    require(read_checked(WORK / 'site/public/config.php', maximum=128 * 1024) == text.encode(),
            'Current workspace config changed since preparation')
    ending = "require_once(__DIR__ . '/lib/setup.php');\n"
    require(text.endswith(ending) and text.count(ending) == 1
            and '$CFG->defaulthomepage' not in text, 'Unsupported homepage config insertion')
    return (text[:-len(ending)] + "$CFG->defaulthomepage = '/local/ustar/home.php';\n" + ending).encode()


def validate_overlay(entries, selected, profile, source):
    for rel, entry in selected.items():
        path, _, _ = frozen_info(rel, entries, entry['type'] == 'directory')
        if entry['type'] == 'file':
            fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
            with os.fdopen(fd, 'rb') as stream:
                info = os.fstat(stream.fileno())
                require(metadata(info, False, entry['sha256']) == entry
                        and digest_stream(stream) == entry['sha256']
                        and signature(os.fstat(stream.fileno())) == signature(info),
                        'Selected frozen file changed or hash differs')
    for path, digest in profile['application_files'].items():
        require(path in selected and selected[path]['type'] == 'file'
                and selected[path]['sha256'] == digest, 'USTAR manifest differs from pinned profile')
    app_files = {rel for rel, e in selected.items() if e['type'] == 'file'
                 and rel.startswith(('public/local/ustar/', 'public/theme/ustar/'))}
    require(app_files == set(profile['application_files']), 'USTAR overlay inventory differs')
    for component in profile['additional_components']:
        rel = 'public/' + component['version_file']
        require(rel in selected, 'Required addon metadata missing from full overlay')
        text = source.strip_php_comments(frozen_bytes(rel, entries).decode('utf-8'))
        versions = re.findall(r'\$plugin\s*->\s*version\s*=\s*(\d+)(?:\.0+)?\s*;', text)
        require(versions == [str(component['disk_version'])], 'Addon literal version differs')
        if component['requires_literal'] is not None:
            needs = re.findall(r'\$plugin\s*->\s*requires\s*=\s*(\d+)(?:\.0+)?\s*;', text)
            require(needs == [str(component['requires_literal'])]
                    and component['requires_literal'] <= 2025100608, 'Addon declared minimum core differs')


def precheck(dependencies, tar):
    _, _, resume, source, stage, template, profile = dependencies
    for root in (SOURCE, WORK):
        trusted(root, directory=True, private=True)
        trusted_parents(root)
        for name in ('state.json', 'pg.env', 'lab.ini', 'apache.conf', 'report.json'):
            trusted(root / name, private=True)
    before = inspect_labs(source, stage)
    report = json.loads(read_checked(WORK / 'report.json'))
    require(report.get('kind') == 'lab_cold_checkpoint_and_patch_workspace'
            and report.get('checkpoint_manifest_sha256') == MANIFEST_SHA
            and report.get('core_upgrade_executed') is False and report.get('http_login') == 200
            and report.get('cold_copy_file_hashes') == report.get('cold_copy_restore_bootstrap') == 'PASS',
            'Expected accepted original-core workspace report')
    entries = load_manifest()
    selected = selected_entries(entries, profile['additional_roots'])
    validate_overlay(entries, selected, profile, source)
    config = prepare_config(resume, source, template, entries)
    catalog = archive_catalog(tar)
    for root in profile['additional_roots']:
        require(not any(rel == root or rel.startswith(root + '/') for rel in catalog),
                'Addon root collides with stock candidate core')
    for rel, entry in selected.items():
        require(rel not in catalog or (entry['type'] == 'directory' and catalog[rel].isdir()),
                'Selected overlay file collides with stock candidate core')
    return entries, selected, config, catalog, before, budget(catalog, selected)


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write_file(path, incoming, expected_size, uid, gid, mode, expected_sha=None):
    reserve()
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    digest, size = hashlib.sha256(), 0
    with os.fdopen(fd, 'wb') as out:
        for block in iter(lambda: incoming.read(MIB), b''):
            size += len(block)
            require(size <= expected_size, 'Candidate copy source grew')
            out.write(block)
            digest.update(block)
            reserve()
        require(size == expected_size, 'Candidate copy source ended early')
        require(expected_sha is None or digest.hexdigest() == expected_sha, 'Candidate copy hash differs')
        os.fchown(out.fileno(), uid, gid)
        os.fchmod(out.fileno(), mode)
        out.flush()
        os.fsync(out.fileno())
        info = os.fstat(out.fileno())
    return metadata(info, False, digest.hexdigest())


def extract_stock(tar, catalog, code):
    code.mkdir(mode=0o755)
    os.chown(code, ROOT_UID, ROOT_GID)
    os.chmod(code, 0o755)
    inventory = {}
    # Create all parents first, then read regular files in their archive order.
    # Sorting compressed members by path causes repeated gzip backtracking.
    directories = sorted((r for r, m in catalog.items() if m.isdir()),
                         key=lambda r: (len(PurePosixPath(r).parts), r))
    files = [r for r, m in catalog.items() if m.isfile()]
    for rel in directories + files:
        if rel == '.':
            continue
        member = catalog[rel]
        target = code / rel
        if member.isdir():
            reserve()
            target.mkdir(mode=0o755)
            os.chown(target, ROOT_UID, ROOT_GID)
            os.chmod(target, 0o755)
            inventory[rel] = metadata(target.lstat(), True)
        else:
            with tar.extractfile(member) as incoming:
                inventory[rel] = write_file(target, incoming, member.size, ROOT_UID, ROOT_GID,
                                             0o755 if member.mode & 0o111 else 0o644)
    return inventory


def copy_overlay(code, entries, selected, inventory):
    for rel in sorted(selected, key=lambda r: (len(PurePosixPath(r).parts), r)):
        expected = selected[rel]
        src, info, _ = frozen_info(rel, entries, expected['type'] == 'directory')
        target = code / rel
        if expected['type'] == 'directory':
            if rel not in inventory:
                reserve()
                target.mkdir(mode=0o700)
                os.chown(target, info.st_uid, info.st_gid)
                os.chmod(target, stat.S_IMODE(info.st_mode))
                inventory[rel] = metadata(target.lstat(), True)
        else:
            fd = os.open(src, os.O_RDONLY | os.O_NOFOLLOW)
            with os.fdopen(fd, 'rb') as incoming:
                require(signature(os.fstat(incoming.fileno())) == signature(info),
                        'Frozen file identity changed before copy')
                actual = write_file(target, incoming, expected['size'], expected['uid'], expected['gid'],
                                    expected['mode'], expected['sha256'])
                require(signature(os.fstat(incoming.fileno())) == signature(info),
                        'Frozen file changed during candidate copy')
            require(actual == expected and (target.stat().st_dev, target.stat().st_ino)
                    != (info.st_dev, info.st_ino), 'Candidate overlay metadata or independent inode differs')
            inventory[rel] = actual


def verify_candidate(code, inventory):
    actual = set()
    def failed(_):
        raise CandidateError('Candidate verification scan incomplete')
    for base, dirs, files in os.walk(code, followlinks=False, onerror=failed):
        for name in dirs + files:
            path = Path(base) / name
            rel = path.relative_to(code).as_posix()
            actual.add(rel)
            require(rel in inventory, 'Unexpected entry in candidate code')
            expected = inventory[rel]
            info = trusted(path, directory=expected['type'] == 'directory')
            require(metadata(info, expected['type'] == 'directory', expected.get('sha256')) == expected
                    and info.st_dev == code.stat().st_dev, 'Candidate metadata or filesystem differs')
            if expected['type'] == 'file':
                fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
                with os.fdopen(fd, 'rb') as stream:
                    require(signature(os.fstat(stream.fileno())) == signature(info)
                            and digest_stream(stream) == expected['sha256']
                            and signature(os.fstat(stream.fileno())) == signature(info),
                            'Candidate readback hash differs')
    require(actual == set(inventory), 'Candidate entries missing')
    for path, expected in CORE_HASHES.items():
        require(inventory[path]['sha256'] == expected, 'Stock core metadata changed during overlay')
    for path in sorted(inventory, key=lambda r: len(PurePosixPath(r).parts), reverse=True):
        if inventory[path]['type'] == 'directory':
            sync_directory(code / path)
    sync_directory(code)


def private_json(path, value):
    import io
    data = (json.dumps(value, sort_keys=True, indent=2) + '\n').encode()
    return write_file(path, io.BytesIO(data), len(data), ROOT_UID, ROOT_GID, 0o600)


def prepare(dependencies, tar, checked, archive_identity):
    import io
    entries, selected, config, catalog, before, planned = checked
    _, _, _, source, stage, _, profile = dependencies
    reserve()
    root = Path(tempfile.mkdtemp(prefix='patch-candidate-518-20261011-', dir=SOURCE))
    print('CANDIDATE_COPY_START; DIRECTORY=' + str(root), flush=True)
    try:
        code = root / 'code'
        inventory = extract_stock(tar, catalog, code)
        copy_overlay(code, entries, selected, inventory)
        inventory['config.php'] = write_file(code / 'config.php', io.BytesIO(config), len(config),
                                             ROOT_UID, WEB_GID, 0o640)
        verify_candidate(code, inventory)
        require(inspect_labs(source, stage) == before, 'Source/stage/production identity or start time changed')
        require(hashlib.sha256(read_checked(CHECKPOINT / 'manifest.json')).hexdigest() == MANIFEST_SHA,
                'Accepted checkpoint manifest changed')
        require(prepare_config(dependencies[2], source, dependencies[5], entries) == config,
                'Baseline offline config changed during preparation')
        validate_overlay(entries, selected, profile, source)
        stream, archive_info = archive_identity
        require(signature(os.fstat(stream.fileno())) == signature(archive_info),
                'Package changed during candidate preparation')
        reserve()
        manifest = {'schema_version': 1, 'kind': 'separate_patch_candidate_code', 'complete': True,
                    'upstream_commit': UPSTREAM, 'archive_sha256': ARCHIVE_SHA,
                    'checkpoint_manifest_sha256': MANIFEST_SHA, 'entries': inventory}
        proof = private_json(root / 'manifest.json', manifest)
        report = {'kind': 'full_addon_patch_candidate_code_only', 'candidate_directory': str(root),
                  'core_version': '2025100608.00', 'upstream_commit': UPSTREAM,
                  'archive_sha256': ARCHIVE_SHA, 'stock_core_files_unchanged':
                  sum(m.isfile() for m in catalog.values()), 'candidate_file_readback_hashes': 'PASS',
                  'addon_roots_preserved': len(profile['additional_roots']),
                  'addon_metadata_matching': len(profile['additional_components']),
                  'ustar_matching_files': len(profile['application_files']),
                  'extra_files_preserved': len(EXTRAS), 'independent_overlay_inodes': 'PASS',
                  'candidate_manifest_sha256': proof['sha256'], 'candidate_entries': len(inventory),
                  'custom_home_config_prepared': '/local/ustar/home.php',
                  'custom_home_runtime_verified': False, 'root_vendor_copied': False,
                  'original_root_vendor_present_in_checkpoint':
                  any(key == PREFIX + 'vendor' or key.startswith(PREFIX + 'vendor/') for key in entries),
                  'runtime_dependency_compatibility_verified': False,
                  'full_addon_compatibility_verified': False, 'core_upgrade_executed': False,
                  'candidate_activated': False, 'PHP_SQL_executed': False,
                  'containers_mutated': False, 'production_containers_unchanged': True,
                  'existing_lab_container_identities_and_config_unchanged': True,
                  'existing_lab_paths_written_by_helper': False,
                  'patch_upgrade_rollback_verified': False, 'storage_plan': planned}
        private_json(root / 'report.json', report)
        sync_directory(root)
        sync_directory(SOURCE)
        reserve()
        return report
    except BaseException:
        # Keep copied data; remove only our own final acceptance names by renaming.
        # A failed/late-interrupted operation is never accepted by a future switch.
        for name in ('manifest.json', 'report.json'):
            target = root / ('unaccepted-' + name)
            if (root / name).exists() and not target.exists():
                try:
                    os.rename(root / name, target)
                    sync_directory(root)
                except OSError:
                    print('CANDIDATE_ACCEPTANCE_QUARANTINE=INCOMPLETE; no activation allowed', flush=True)
        print('CANDIDATE_COPY=FAIL; partial candidate preserved; existing labs/checkpoint untouched', flush=True)
        raise


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--prepare-candidate', action='store_true')
    args = parser.parse_args()
    if not args.check and not args.prepare_candidate:
        print('PLAN_ONLY; --check reads; --prepare-candidate creates separate code only; no PHP/SQL/activation')
        return 0
    try:
        require(os.geteuid() == ROOT_UID, 'Run explicit candidate modes as root')
        trusted_parents(LOCK)
        info = trusted(LOCK)
        fd = os.open(LOCK, os.O_RDONLY | os.O_NOFOLLOW)
        with os.fdopen(fd, 'rb'):
            require(signature(os.fstat(fd)) == signature(info), 'Existing lock identity changed')
            fcntl.flock(fd, (fcntl.LOCK_SH if args.check else fcntl.LOCK_EX) | fcntl.LOCK_NB)
            dependencies = load_dependencies()
            stream, tar, archive_info = archive_open()
            try:
                checked = precheck(dependencies, tar)
                if args.check:
                    require(inspect_labs(dependencies[3], dependencies[4]) == checked[4],
                            'Lab identity changed during read-only check')
                    require(signature(os.fstat(stream.fileno())) == signature(archive_info),
                            'Package changed during read-only check')
                    report = {'kind': 'candidate_readonly_preflight', 'result': 'PASS',
                              'core_upgrade_executed': False, 'storage_plan': checked[5]}
                else:
                    report = prepare(dependencies, tar, checked, (stream, archive_info))
                print('LAB_PATCH_CANDIDATE_PREFLIGHT=PASS; read-only; no container/file changes'
                      if args.check else 'LAB_PATCH_CANDIDATE_CODE=PASS; NOT_ACTIVATED; NO_CORE_UPGRADE')
                print(json.dumps(report, sort_keys=True, indent=2), flush=True)
            finally:
                tar.close()
                stream.close()
        return 0
    except Exception as error:
        message = str(error) if isinstance(error, CandidateError) else type(error).__name__
        print('LAB_PATCH_CANDIDATE_ERROR=' + message, file=sys.stderr, flush=True)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
