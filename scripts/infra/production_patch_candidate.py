#!/usr/bin/env python3
"""Prepare a separate production patch candidate; never activate or upgrade it.

Uses pinned installed readiness/builder primitives, official archive and accepted
old code inventory. Reads live code and bounded READ ONLY version SQL. Writes
only a new private candidate directory and its own coordination lock, preserving
source bytes and metadata. No production/stage stop, service change, PHP/Moodle
bootstrap, Composer, data/DB copy or write, image pull or archive restore.
"""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import io
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import time
from types import ModuleType

READINESS = Path('/usr/local/sbin/USTAR_PRODUCTION_PATCH_READINESS_20261012.py')
READINESS_SHA = 'f72031dcb6df1bbaa89f8aa34d33bf30de67c62395e4398a1ad391823ef1f886'
BUILDER = Path('/usr/local/sbin/USTAR_LAB_PATCH_CANDIDATE_20261012.py')
BUILDER_SHA = 'bb71ddd01566341d506a560628453bdcec045de89af7f4a80774a205f2f059c2'
BASE = Path('/var/lib/ustar-production-patch-20261012')
RESERVE = 4 * 1024 ** 3
SETUP = "require_once(__DIR__ . '/lib/setup.php');"
HOME = "$CFG->defaulthomepage = '/local/ustar/home.php';\n"
# Official original-core loader, official 5.1.8 loader, and reviewed lab shim.
# The frozen lab loader was deliberately rewritten; compare these bytes directly.
PUBLIC_CONFIG_HASHES = {
    '7b6509492ac5bc749f94bfb155fc84e94787fa85ee1ef21f8daeaf972592b020',
    'dfaf7dc96a95a5438da758e38eee84b91e403ec72e4f8888f1e208118f8936d1',
    '37a41158e70d7adab27321c087d123f71fb97d61327c188fad221d8b07f8484b',
}


class CandidateRefusal(Exception):
    pass


def require(ok, message):
    if not ok:
        raise CandidateRefusal(message)


def load_module(path, digest, name):
    for parent in path.parents:
        info = parent.lstat()
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
                'Untrusted installed helper parent')
    before = path.lstat()
    require(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and before.st_nlink == 1
            and not before.st_mode & 0o022 and before.st_size <= 1024 ** 2,
            'Untrusted installed helper')
    with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
        require(os.fstat(stream.fileno()) == before, 'Helper replaced before read')
        data = stream.read(1024 ** 2 + 1)
    # stat_result does not include nanoseconds in tuple equality; compare explicitly.
    after = path.lstat()
    require((before.st_dev, before.st_ino, before.st_mode, before.st_uid, before.st_gid,
             before.st_nlink, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
            == (after.st_dev, after.st_ino, after.st_mode, after.st_uid, after.st_gid,
                after.st_nlink, after.st_size, after.st_mtime_ns, after.st_ctime_ns)
            and hashlib.sha256(data).hexdigest() == digest, 'Installed helper checksum or identity differs')
    module = ModuleType(name)
    exec(compile(data, str(path), 'exec'), module.__dict__)
    return module


def dependencies():
    p = load_module(READINESS, READINESS_SHA, 'production_candidate_pinned_readiness')
    c = load_module(BUILDER, BUILDER_SHA, 'production_candidate_pinned_builder')
    require(p.CODE == Path('/opt/ustar/data/moodle/public') and p.PACKAGE == c.ARCHIVE,
            'Pinned input namespaces differ')
    require(not BASE.is_relative_to(p.CODE) and not p.CODE.is_relative_to(BASE)
            and not BASE.is_relative_to(c.SOURCE), 'Candidate namespace overlaps live or lab code')
    return p, c


def metadata(info, digest=None):
    item = {'type': 'directory' if stat.S_ISDIR(info.st_mode) else 'file',
            'uid': info.st_uid, 'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode)}
    if item['type'] == 'file':
        item.update(size=info.st_size, sha256=digest)
    return item


def scan_code(p, root):
    p.no_links(root)
    device = root.stat().st_dev
    entries, started = {}, time.monotonic()
    def failed(_error):
        raise CandidateRefusal('Code inventory incomplete')
    for base, dirs, files in os.walk(root, followlinks=False, onerror=failed):
        for name in dirs + files:
            path = Path(base) / name
            info = path.lstat()
            require(info.st_dev == device and not stat.S_ISLNK(info.st_mode)
                    and (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode))
                    and (stat.S_ISDIR(info.st_mode) or info.st_nlink == 1),
                    'Code input device, type or link differs')
            rel = path.relative_to(root).as_posix()
            require(len(entries) < 100000 and time.monotonic() - started < 300,
                    'Code inventory exceeded bound')
            digest = p.read_file(path, 100 * 1024 ** 2, True) if stat.S_ISREG(info.st_mode) else None
            require(p.signature(info) == p.signature(path.lstat()), 'Code changed during inventory')
            entries[rel] = metadata(info, digest)
    return entries


def overlay_selected(c, entries, roots):
    require(len(roots) == 19 and len(set(roots)) == 19, 'Unexpected addon root set')
    selected = {}
    for root in roots:
        c.relative(root)
        require(root.startswith('public/') and entries.get(root, {}).get('type') == 'directory'
                and not any(other != root and root.startswith(other + '/') for other in roots),
                'Missing or overlapping addon root')
    for rel, item in entries.items():
        if any(rel == root or rel.startswith(root + '/') for root in roots):
            selected[rel] = item
    for rel in c.EXTRAS:
        require(entries.get(rel, {}).get('type') == 'file' and rel not in selected,
                'Reviewed extra file missing or overlaps addon')
        selected[rel] = entries[rel]
    for rel in list(selected):
        for parent in c.relative(rel).parents:
            if str(parent) != '.':
                key = parent.as_posix()
                require(entries.get(key, {}).get('type') == 'directory', 'Overlay parent missing')
                selected[key] = entries[key]
    return selected


def baseline_guard(c, live, frozen, selected):
    expected = {key[len(c.PREFIX):]: value for key, value in frozen.items()
                if key.startswith(c.PREFIX)}
    require(set(live) == set(expected), 'Live code inventory differs from accepted original; review additions/removals')
    for rel, item in live.items():
        require(item['type'] == expected[rel]['type'], 'Live code entry type differs')
        if rel == 'public/config.php':
            require(item['sha256'] in PUBLIC_CONFIG_HASHES, 'Production public config loader needs separate review')
        elif item['type'] == 'file' and rel not in c.EXTRAS and rel != 'config.php':
            require(item['sha256'] == expected[rel]['sha256'], 'Original code file changed since accepted checkpoint')


def configuration(original):
    text = original.decode('utf-8')
    require('$CFG->defaulthomepage' not in text and text.count(SETUP) == 1
            and text.rstrip().endswith(SETUP), 'Production config needs separate homepage insertion review')
    index = text.index(SETUP)
    return (text[:index] + HOME + text[index:]).encode()


def config_tail_kind(tail):
    """Classify only the raw suffix; never return config text or approve insertion."""
    comment = False
    while tail.strip():
        tail = tail.lstrip()
        if tail.startswith(('//', '#')):
            tail = tail.partition('\n')[2]
        elif tail.startswith('/*'):
            end = tail.find('*/', 2)
            if end == -1:
                return 'unterminated_block_comment'
            tail = tail[end + 2:]
        elif tail.startswith('?>') and not tail[2:].strip():
            return 'closing_tag_with_optional_comments_before_it'
        else:
            return 'other_text'
        comment = True
    return 'comments_and_whitespace' if comment else 'whitespace_only'


def config_diagnostic(original):
    """Report fixed labels/counts for the existing raw-byte guard, not a PHP parse."""
    report = {'kind': 'production_candidate_config_guard_diagnostic',
              'read_only': True, 'config_text_emitted': False,
              'PHP_or_Moodle_bootstrap': False, 'SQL_executed': False,
              'candidate_created_or_activated': False,
              'production_config_data_database_services_tasks_changed': False,
              'observations_scope': 'raw_text_only_not_PHP_semantics_or_insertion_approval'}
    try:
        text = original.decode('utf-8')
    except UnicodeDecodeError:
        report.update(utf8_decodable=False, existing_insertion_guard_accepts=False,
                      failed_guard_conditions=['utf8_decoding'])
        return report
    count = text.count(SETUP)
    conditions = {'homepage_raw_token_absent': '$CFG->defaulthomepage' not in text,
                  'exact_setup_occurs_once': count == 1,
                  'exact_setup_is_final_nonwhitespace_text': text.rstrip().endswith(SETUP)}
    report.update(utf8_decodable=True, guard_conditions=conditions,
                  existing_insertion_guard_accepts=all(conditions.values()),
                  failed_guard_conditions=[key for key, ok in conditions.items() if not ok],
                  exact_setup_raw_occurrences=count,
                  suffix_after_unique_exact_setup=(config_tail_kind(text.split(SETUP, 1)[1])
                                                   if count == 1 else 'not_applicable'))
    # Literal spelling observations can also occur inside comments or values. Do
    # not use these counts to transform config or print surrounding source text.
    spellings = {'lib_spaced_single_quote': SETUP,
                 'lib_compact_single_quote': "require_once(__DIR__.'/lib/setup.php');",
                 'lib_spaced_double_quote': 'require_once(__DIR__ . "/lib/setup.php");',
                 'public_lib_spaced_single_quote': "require_once(__DIR__ . '/public/lib/setup.php');",
                 'cfg_dirroot_single_quote': "require_once($CFG->dirroot . '/lib/setup.php');"}
    report['known_raw_setup_spelling_counts'] = {key: text.count(value) for key, value in spellings.items()}
    return report


def profile_guard(profile, live, db):
    for rel, digest in profile['application_files'].items():
        require(live.get(rel, {}).get('sha256') == digest, 'USTAR file hash differs from tested profile')
    require(len(profile['application_files']) == 526 and len(profile['additional_components']) == 57,
            'Pinned profile shape differs')
    for rel, digest in profile['core_metadata_sha256'].items():
        require(live.get(rel, {}).get('sha256') == digest, 'Original core metadata differs')
    require(live.get('public/index.php', {}).get('sha256') == profile['index_sha256'],
            'Original homepage index differs')
    require(len(db['addons']) == 57, 'Preserved addon DB version set differs')


def reserve(p):
    fs = os.statvfs(BASE)
    require(fs.f_bavail * fs.f_frsize >= RESERVE and fs.f_favail >= 100,
            'Candidate reached storage reserve')


@contextmanager
def own_lock(p, c, create):
    p.no_links(BASE)
    for parent in BASE.parents:
        info = parent.lstat()
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
                'Untrusted candidate namespace parent')
    created = not BASE.exists()
    if created:
        require(create, 'Candidate namespace not prepared; check can run via prepare mode')
        BASE.mkdir(mode=0o700)
    info = BASE.lstat()
    require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o077,
            'Candidate namespace not private/root-owned')
    namespace = {'kind': 'ustar_production_patch_518_preparation',
                 'production_code': str(p.CODE), 'official_archive_sha256': c.ARCHIVE_SHA}
    if created:
        c.private_json(BASE / 'namespace.json', namespace)
        c.sync_directory(BASE)
    else:
        require(p.read_json(BASE / 'namespace.json') == namespace, 'Candidate namespace ownership marker differs')
    flags = os.O_RDONLY | os.O_NOFOLLOW
    if create:
        flags = os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW
    fd = os.open(BASE / 'lock', flags, 0o600)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
                and not info.st_mode & 0o077, 'Candidate coordination lock differs')
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    finally:
        os.close(fd)


def copy_live(p, c, source, target, rel, item):
    src, dest = source / rel, target / rel
    p.no_links(src)
    before = src.lstat()
    require(metadata(before, item.get('sha256')) == item, 'Overlay metadata changed')
    if item['type'] == 'directory':
        if not dest.exists():
            reserve(p)
            dest.mkdir(mode=0o700)
            os.chown(dest, item['uid'], item['gid'])
            os.chmod(dest, item['mode'])
        return metadata(dest.lstat())
    require(not dest.exists() and not dest.is_symlink(), 'Overlay target already occupied')
    with os.fdopen(os.open(src, os.O_RDONLY | os.O_NOFOLLOW), 'rb') as stream:
        require(p.signature(os.fstat(stream.fileno())) == p.signature(before), 'Source replaced before copy')
        actual = c.write_file(dest, stream, item['size'], item['uid'], item['gid'], item['mode'], item['sha256'])
        require(p.signature(os.fstat(stream.fileno())) == p.signature(before)
                and p.signature(src.lstat()) == p.signature(before), 'Source changed during copy')
    require((dest.stat().st_dev, dest.stat().st_ino) != (before.st_dev, before.st_ino), 'Overlay is not independent')
    return actual


def verify(p, c, root, inventory):
    require(scan_code(p, root) == inventory, 'Candidate readback inventory or hashes differ')
    for rel, digest in c.CORE_HASHES.items():
        require(inventory[rel]['sha256'] == digest, 'Official core modified in candidate')
    for rel in sorted(inventory, key=lambda x: x.count('/'), reverse=True):
        if inventory[rel]['type'] == 'directory':
            c.sync_directory(root / rel)
    c.sync_directory(root)


def precheck(p, c, tar):
    before = p.containers()
    p.root_input(p.PROFILE)
    require(p.read_file(p.PROFILE, digest_only=True) == p.PROFILE_SHA, 'Pinned profile differs')
    profile = p.read_json(p.PROFILE)
    original = p.read_file(p.CODE / 'config.php', 128 * 1024)
    config = configuration(original)
    db = p.versions(p.parse_prefix(original.decode('utf-8')), profile)
    live = scan_code(p, p.CODE)
    selected = overlay_selected(c, live, profile['additional_roots'])
    baseline_guard(c, live, c.load_manifest(), selected)
    profile_guard(profile, live, db)
    catalog = c.archive_catalog(tar)
    for root in profile['additional_roots']:
        require(not any(rel == root or rel.startswith(root + '/') for rel in catalog), 'Addon collides with stock core')
    c.route_overlay(selected, catalog)
    require(p.CODE.stat().st_dev == c.SOURCE.stat().st_dev, 'Candidate budget filesystem differs')
    budget = c.budget(catalog, selected)
    return before, profile, original, config, db, live, selected, catalog, budget


def prepare(p, c, tar, checked, archive_stream, archive_info):
    before, profile, original, config, db, live, selected, catalog, budget = checked
    require(BASE.stat().st_dev == p.CODE.stat().st_dev, 'Candidate and budget filesystems differ')
    require(p.signature(os.fstat(archive_stream.fileno())) == p.signature(archive_info)
            and p.signature(c.ARCHIVE.lstat()) == p.signature(archive_info), 'Official archive identity changed')
    root = Path(tempfile.mkdtemp(prefix='candidate-518-', dir=BASE))
    print('PRODUCTION_CANDIDATE_START; DIRECTORY=' + str(root), flush=True)
    try:
        c.reserve = lambda: reserve(p)
        code = root / 'code'
        inventory = c.extract_stock(tar, catalog, code)
        require(p.signature(os.fstat(archive_stream.fileno())) == p.signature(archive_info)
                and p.signature(c.ARCHIVE.lstat()) == p.signature(archive_info), 'Official archive changed during extraction')
        print('PRODUCTION_CANDIDATE_STOCK_EXTRACTED; NO_ACTIVATION', flush=True)
        mounted, preserved = c.route_overlay(selected, catalog)
        for rel in sorted(mounted, key=lambda x: (x.count('/'), x)):
            inventory[rel] = copy_live(p, c, p.CODE, code, rel, mounted[rel])
        private = root / 'preserved-original'
        private.mkdir(mode=0o700)
        retained = {}
        for rel, target in [('config.php', 'config.php'), ('public/config.php', 'public-config.php'),
                            ('public/index.php', 'public-index.php'), *[(x, x) for x in preserved]]:
            retained[target] = copy_live(p, c, p.CODE, private, rel, live[rel]) if target == rel else None
            if target != rel:
                item = live[rel]
                with io.BytesIO(p.read_file(p.CODE / rel, 16 * 1024 ** 2)) as stream:
                    retained[target] = c.write_file(private / target, stream, item['size'],
                                                    item['uid'], item['gid'], item['mode'], item['sha256'])
        item = live['config.php']
        inventory['config.php'] = c.write_file(code / 'config.php', io.BytesIO(config), len(config),
                                              item['uid'], item['gid'], item['mode'])
        verify(p, c, code, inventory)
        require(scan_code(p, private) == retained, 'Preserved original readback differs')
        require(scan_code(p, p.CODE) == live and p.read_file(p.CODE / 'config.php', 128 * 1024) == original,
                'Live production code/config changed during preparation')
        after_db = p.versions(p.parse_prefix(original.decode('utf-8')), profile)
        require(after_db['core'] == db['core'] and after_db['addons'] == db['addons']
                and after_db['enabled_tasks'] == db['enabled_tasks'] and p.containers() == before,
                'Production version/tasks/container identity changed during preparation')
        source_manifest = {'code_entries': live, 'original_config_sha256': hashlib.sha256(original).hexdigest()}
        source_sha = c.private_json(root / 'source-manifest.json', source_manifest)['sha256']
        value = {'kind': 'separate_unactivated_production_patch_candidate',
                 'created_at': datetime.now(timezone.utc).isoformat(), 'entries': inventory,
                 'preserved_original': retained, 'source_manifest_sha256': source_sha,
                 'source_core_db_version': db['core'], 'source_addon_versions': db['addons'],
                 'production_enabled_tasks': db['enabled_tasks'], 'official_archive_sha256': c.ARCHIVE_SHA,
                 'homepage_relative_url': '/local/ustar/home.php', 'production_activated': False}
        manifest_sha = c.private_json(root / 'candidate-manifest.json', value)['sha256']
        c.sync_directory(private)
        c.sync_directory(root)
        report = {'kind': value['kind'], 'result': 'PASS', 'candidate_directory': str(root),
                  'candidate_manifest_sha256': manifest_sha, 'source_manifest_sha256': source_sha,
                  'candidate_entries': len(inventory), 'preserved_original_files': len(retained),
                  'addon_roots_preserved': len(profile['additional_roots']), 'addon_versions_matching': 57,
                  'ustar_matching_files': 526, 'stock_core_and_candidate_readback_hashes': 'PASS',
                  'production_source_readback_and_identities': 'UNCHANGED', 'candidate_homepage_config_added': True,
                  'SQL_scope': 'bounded_BEGIN_READ_ONLY_versions_only', 'PHP_or_Moodle_bootstrap': False,
                  'candidate_activated': False, 'production_upgrade_executed': False,
                  'production_data_database_services_tasks_changed': False, 'storage_plan': budget,
                  'production_deployment_backup_and_rollback_ready': False,
                  'full_addon_runtime_compatibility_verified': False}
        c.private_json(root / 'report.json', report)
        c.sync_directory(root)
        return report
    except BaseException:
        print('PRODUCTION_CANDIDATE_INCOMPLETE; RETAINED_DIRECTORY=' + str(root), flush=True)
        raise


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--prepare-candidate', action='store_true')
    mode.add_argument('--diagnose-config', action='store_true')
    args = parser.parse_args()
    if not (args.check or args.prepare_candidate or args.diagnose_config):
        print('PLAN_ONLY; --prepare-candidate writes a separate private copy; no activation or upgrade')
        return 0
    try:
        require(os.geteuid() == 0, 'Run production candidate preparation as root')
        os.umask(0o077)
        sys.dont_write_bytecode = True
        if args.diagnose_config:
            p = load_module(READINESS, READINESS_SHA, 'production_candidate_pinned_readiness')
            require(p.CODE == Path('/opt/ustar/data/moodle/public'), 'Pinned production code namespace differs')
            report = config_diagnostic(p.read_file(p.CODE / 'config.php', 128 * 1024))
            print('PRODUCTION_CONFIG_DIAGNOSTIC=COMPLETE; READ_ONLY; NO_CONFIG_TEXT')
            print(json.dumps(report, indent=2, sort_keys=True))
            return 0
        p, c = dependencies()
        stream, tar, archive_info = c.archive_open()
        try:
            checked = precheck(p, c, tar)
            if args.check:
                print('PRODUCTION_CANDIDATE_PREFLIGHT=PASS; READ_ONLY; NO_COPY')
                print(json.dumps(checked[-1], indent=2))
            else:
                with own_lock(p, c, True):
                    report = prepare(p, c, tar, checked, stream, archive_info)
                print('PRODUCTION_CANDIDATE=PASS; NOT_ACTIVATED; NO_UPGRADE')
                print(json.dumps(report, indent=2, sort_keys=True))
        finally:
            tar.close()
            stream.close()
        return 0
    except Exception as error:
        print('PRODUCTION_CANDIDATE_ERROR=' + (str(error) if isinstance(error, CandidateRefusal)
              else type(error).__name__), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
