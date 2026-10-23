#!/usr/bin/env python3
"""Fixed CON-02 release contracts. No bootstrap, nonce or provider call in check."""
from dataclasses import dataclass
from decimal import Decimal
import hashlib
import importlib
import io
from pathlib import Path
import re
from types import SimpleNamespace

APP_COMMIT = '534764821c4f65437ab50b3c7f25578d538e9504'
BASELINE_COMMIT = '1e8a8819f50785ebb4fb2640eeb9386c969d59d1'
LEGACY_COMMIT = 'a86e208029b6104150e85d0e90384f84da214c22'
OVERLAY_SHA = '2f6c0c8b25941ac435237fc9df4c4de738b885f467ccbab80b9988c0bb42e498'
CORE = '2025100608.00'
BASE = Path('/var/lib/ustar-connect-con02-update')
SCOPES = ('local/ustar', 'theme/ustar')
SERVICES = ('ustar-connect-notify.service', 'ustar-connect-receiver.service')
MODULES = ('connect_con02_contract', 'connect_con02_state', 'connect_con02_application',
           'connect_con02_profiles', 'connect_con02_runtime', 'connect_con02_update',
           'connect_academy_installer', 'connect_readiness', 'production_patch_readiness',
           'production_patch_upgrade', 'production_patch_switch_preflight', 'lab_cold_checkpoint',
           'lab_patch_upgrade', 'lab_patch_candidate', 'cron_release_update', 'production_manifest')


class Refusal(ValueError):
    """Public error codes only; never include host values in exception text."""


def require(value, reason):
    if not value: raise Refusal(reason)


def sha(raw): return hashlib.sha256(raw).hexdigest()


def decode_envelope(manifest, sources, assets, *, app_commit='534764821c4f65437ab50b3c7f25578d538e9504'):
    """Also copied verbatim into the standalone bootstrap, before embedded imports."""
    import base64
    import hashlib
    from pathlib import PurePosixPath
    import re
    require(isinstance(manifest, dict) and set(manifest) == {'schema', 'tool_commit', 'app_commit',
        'worker_commit', 'sources', 'assets'} and manifest['schema'] == 1
        and re.fullmatch('[a-f0-9]{40}', manifest.get('tool_commit', ''))
        and manifest['app_commit'] == app_commit
        and manifest['worker_commit'] == manifest['app_commit'], 'embedded_manifest')
    require(isinstance(sources, dict) and isinstance(assets, dict) and 1 <= len(sources) <= 64
            and len(sources) + len(assets) <= 1024 and not set(sources) & set(assets)
            and set(manifest['sources']) == set(sources) and set(manifest['assets']) == set(assets), 'embedded_inventory')
    total = 0
    for group in ('sources', 'assets'):
        for name, spec in manifest[group].items():
            require(isinstance(name, str) and name and '\\' not in name
                    and not name.startswith('/') and '..' not in PurePosixPath(name).parts
                    and str(PurePosixPath(name)) == name and not name.endswith('/'), 'embedded_path')
            if group == 'sources': require(re.fullmatch('[a-z][a-z0-9_]*[.]py', name), 'embedded_module_name')
            require(isinstance(spec, dict) and set(spec) == {'sha256', 'bytes'}
                    and type(spec['bytes']) is int and 0 <= spec['bytes'] <= 128 * 1024**2
                    and re.fullmatch('[a-f0-9]{64}', spec['sha256']), 'embedded_size_or_hash')
            total += spec['bytes']
    require(total <= 256 * 1024**2, 'embedded_total_size')
    result = []
    for group, contents in [('sources', sources), ('assets', assets)]:
        decoded = {}
        for name, value in contents.items():
            spec = manifest[group][name]
            require(isinstance(value, str), 'embedded_encoding')
            if group == 'sources':
                require(len(value) <= spec['bytes'], 'embedded_size')
                raw = value.encode('utf-8')
            else:
                require(len(value) == 4 * ((spec['bytes'] + 2) // 3), 'embedded_base64_size')
                try: raw = base64.b64decode(value, validate=True)
                except (ValueError, UnicodeError): raise Refusal('embedded_base64') from None
                require(base64.b64encode(raw).decode() == value, 'embedded_noncanonical_base64')
            require(len(raw) == spec['bytes'] and hashlib.sha256(raw).hexdigest() == spec['sha256'], 'embedded_digest')
            decoded[name] = raw
        result.append(decoded)
    return tuple(result)


@dataclass(frozen=True)
class EmbeddedBundle:
    reference: dict
    modules: dict[str, bytes]
    payload: dict[str, bytes]
    worker_archive: bytes
    legacy: dict[str, bytes]


@dataclass
class Context:
    bundle: EmbeddedBundle
    root: Path
    uid: int
    host: object
    engine: SimpleNamespace


def validate_prefix(value):
    require(isinstance(value, str) and re.fullmatch('[A-Za-z][A-Za-z0-9_]{0,31}', value), 'database_prefix')
    return value


def validate_fence(value):
    require(isinstance(value, dict), 'notification_fence_shape')
    ids = value.get('notification_pilot_userids')
    subjects = value.get('notification_subjects')
    require(isinstance(ids, list) and len(ids) == 1 and type(ids[0]) is int and 0 < ids[0] < 2**31,
            'notification_fence_changed')
    require(isinstance(subjects, list) and len(subjects) == 1 and isinstance(subjects[0], str)
            and re.fullmatch('[1-9][0-9]{0,15}', subjects[0]) and int(subjects[0]) < 2**52,
            'notification_subject_fence_changed')
    require(type(value.get('notify_since')) is int and value['notify_since'] > 0
            and type(value.get('notifications_enabled')) is bool
            and value.get('linking_enabled') is True, 'notification_cutoff_or_flags_changed')
    # Current employee link state intentionally is not an activation prerequisite.
    return value


def read_fence(include, sender, receiver=None):
    require(isinstance(include, bytes) and isinstance(sender, dict), 'notification_fence_shape')
    ids = re.findall(rb'(?m)^\$CFG->ustar_connect_notify_userids=\[([0-9]+)\];$', include)
    cutoff = re.findall(rb'(?m)^\$CFG->ustar_connect_notify_since=([0-9]+);$', include)
    flags = re.findall(rb'(?m)^\$CFG->ustar_connect_notifications_enabled=(true|false);$', include)
    require(len(ids) == len(cutoff) == len(flags) == 1, 'notification_fence_changed')
    require(receiver is None or receiver.get('linking_enabled') is True, 'linking_flag_changed')
    return validate_fence({'notification_pilot_userids': [int(ids[0])],
        'notification_subjects': sender.get('notification_subjects'), 'notify_since': int(cutoff[0]),
        'notifications_enabled': flags[0] == b'true', 'linking_enabled': True})


def select_target(journal, addons):
    require(isinstance(addons, dict), 'database_versions')
    if journal is None:
        require(addons.get('local_ustar') == '2026102000', 'unowned_target_release')
        return False
    app = journal.get('app')
    require(isinstance(app, dict), 'application_transaction_required')
    if app['phase'] == 'baseline_restored': return False
    require(app['phase'] == 'app_ready', 'application_recovery_required')
    require(addons.get('local_ustar') == '2026102200', 'database_versions')
    return True


def private_failure(ctx):
    """Keep command exceptions in the existing private log while recovery proceeds."""
    import os
    import traceback
    try:
        log = ctx.host.log
        log.write(traceback.format_exc().encode()); log.flush()
        try: os.fsync(log.fileno())
        except (AttributeError, OSError, ValueError): pass
    except (AttributeError, OSError, ValueError): pass


def verify_schema_profile(snapshot, indexes, profile, tables, prefix, *, allow_historical=False,
                          historical_version=2026102000):
    fields = profile['schema_fields']
    rows = [r for r in snapshot['schema'] if r[0] in tables]
    require(all(len(r) == 9 for r in rows), 'schema_column_shape')
    actual = {(r[0], r[1]): r for r in rows}
    expected = {(t, f) for t in tables for f in fields[t]}
    require(len(actual) == len(rows) and set(actual) == expected, 'schema_column_inventory')
    for (table, field), row in actual.items():
        spec = fields[table][field]
        if spec['type'] == 'int':
            length = int(spec['length'])
            kind = 'int2' if length <= 4 else 'int4' if length <= 9 else 'int8'
        else: kind = {'char':'varchar', 'text':'text'}[spec['type']]
        require(row[3] == kind and (row[7] == 'NO') == spec['notnull'], 'schema_column_type')
        if spec['type'] == 'char': require(row[4] == int(spec['length']), 'schema_column_length')
        default = spec.get('default')
        if spec.get('sequence'):
            default = "nextval('" + prefix + table + '_' + field + "_seq'::regclass)"
        elif spec['type'] == 'char' and (default is not None or spec['notnull']):
            default = "'" + (default or '').replace("'", "''") + "'::character varying"
        require(row[8] == default, 'schema_column_default')
    actual_indexes, expected_indexes = {}, {}
    for table in tables:
        found = []
        for name, definition in indexes:
            if name != table: continue
            match = re.fullmatch(r'CREATE (UNIQUE )?INDEX \S+ ON (?:public\.)?\S+ USING btree \(([a-z0-9_, ]+)\)', definition)
            require(match is not None, 'schema_index_shape')
            found.append((tuple(f.strip() for f in match[2].split(',')), bool(match[1])))
        actual_indexes[table] = sorted(found)
        expected_indexes[table] = sorted((tuple(s['fields']), s['unique'])
                                         for s in profile['schema_indexes'][table])
    if actual_indexes == expected_indexes:
        return 'xml_install_indexes'
    # Owner23Oct verified exactly this paired omission on the accepted2000
    # database. Both tables are retained legacy history, not active Connect
    # writers. This is a fixed alternate baseline, never arbitrary adoption or
    # an index repair. Every remaining index/column/default still must match.
    require(allow_historical and profile.get('versions') == {
        'local_ustar': historical_version, 'theme_ustar': 2026100202}, 'schema_indexes')
    historical = {t: list(v) for t, v in expected_indexes.items()}
    for table, field in (('local_ustar_coin_accounts', 'userid'),
                         ('local_ustar_test_results', 'attemptid')):
        missing = ((field,), True)
        require(table in historical and historical[table].count(missing) == 1, 'schema_indexes')
        historical[table].remove(missing)
    require(actual_indexes == historical, 'schema_indexes')
    return 'server1_legacy_indexes_20261023'


def verify_database(current, baseline, reference, *, target, historical_version=2026102000):
    require(isinstance(current, dict) and isinstance(baseline, dict), 'database_shape')
    wanted = reference['candidate' if target else 'baseline']
    expected = dict(baseline['addons'])
    if target: expected.update({k: str(v) for k, v in wanted['versions'].items()})
    require(Decimal(current['core']) == Decimal(CORE) and current['core'] == baseline['core']
            and current['addons'] == expected, 'database_versions')
    require({k: baseline['addons'].get(k) for k in reference['baseline']['versions']}
            == {k: str(v) for k, v in reference['baseline']['versions'].items()}, 'baseline_versions')
    require(current['tables'] == wanted['tables'], 'database_table_inventory')
    require(current['tasks'] == baseline['tasks'], 'scheduled_task_flags')
    old = set(reference['baseline']['tables'])
    # information_schema query strips the Moodle prefix; pg_indexes.tablename
    # retains it. Normalize table identity, retaining full index definitions.
    def index_rows(rows):
        result=[];prefixes=set()
        for table, definition in rows:
            matches=[t for t in reference['candidate']['tables'] if table.endswith(t)]
            require(matches, 'database_index_table')
            name=max(matches,key=len);prefix=table[:-len(name)]
            if prefix: validate_prefix(prefix)
            prefixes.add(prefix);result.append([name,definition])
        require(len(prefixes)<=1,'database_index_prefix')
        return result, prefixes
    old_indexes, old_prefix=index_rows(baseline['indexes'])
    current_indexes, current_prefix=index_rows(current['indexes'])
    require(current_prefix == old_prefix, 'database_index_prefix')
    index_profile = verify_schema_profile(baseline, old_indexes, reference['baseline'], old,
                                         next(iter(old_prefix), ''), allow_historical=True,
                                         historical_version=historical_version)
    require([r for r in current['schema'] if r[0] in old] == baseline['schema'], 'old_schema_changed')
    require([r for r in current_indexes if r[0] in old] == old_indexes, 'old_indexes_changed')
    if not target:
        require(current['schema'] == baseline['schema'] and current['indexes'] == baseline['indexes'], 'restored_schema')
        return index_profile
    new = set(wanted['tables']) - old
    verify_schema_profile(current, current_indexes, wanted, new, next(iter(current_prefix), ''))
    return index_profile


def https_snapshot(ctx):
    require(ctx.host is not None, 'host_context_required')
    value = ctx.host.https_snapshot()
    require(isinstance(value, dict) and value.get('verified') is True, 'HTTPS_owner_not_verified')
    return value


def require_https_unchanged(ctx, before):
    require(https_snapshot(ctx) == before, 'HTTPS_owner_changed_during_operation')


def make_context(bundle, *, root=Path('/'), uid=0, host=None):
    require(bundle.reference['app_commit'] == APP_COMMIT and bundle.reference['worker_commit'] == APP_COMMIT
            and bundle.reference['baseline_commit'] == BASELINE_COMMIT, 'fixed_release_required')
    root = Path(root)
    names = {'p': 'production_patch_readiness', 'cold': 'lab_cold_checkpoint', 'u': 'lab_patch_upgrade',
             'c': 'lab_patch_candidate', 'tx': 'production_patch_upgrade', 'w': 'production_patch_switch_preflight',
             'probe': 'connect_readiness', 'addon': 'connect_academy_installer'}
    d = SimpleNamespace(**{k: importlib.import_module(v) for k, v in names.items()})
    # Each operation has its own accepted helper profile. Never mutate imported constants.
    d.w = SimpleNamespace(**vars(d.w))
    d.w.HELPERS = dict(d.w.HELPERS)
    for name, digest in [('hdd_backup', 'a604fbcd6a0733d29142bb3130dc4bea2e7da1797c6995699d9d5516f21b05c3')]:
        if name in d.w.HELPERS: d.w.HELPERS[name] = (d.w.HELPERS[name][0], digest)
    d.w.HELPERS['hdd_scheduler'] = (Path('/usr/local/sbin/USTAR_HDD_SCHEDULE_20261006.py'),
        'a97039a8111dfa4d68fa0a851e17dec9a82d8f1096b7b61b8a71609859134e8c')
    d.reference, d.payload = bundle.reference, bundle.payload
    d.schema_fields = bundle.reference['candidate']['schema_fields']
    d.base = root / str(BASE).lstrip('/')
    d.marker = d.base / 'uninitialized-primitive.json'
    d.web = root / 'opt/ustar/data/moodle/public/public'
    d.trees = {k: root / v for k, v in {'code': 'opt/ustar/data/moodle/public',
                'moodledata': 'opt/ustar/data/moodle/moodledata', 'postgres': 'opt/ustar/data/postgres'}.items()}
    d.identities = None
    configure_primitives(d)
    ctx = Context(bundle, root, uid, host, d)
    if host is None:
        require(root == Path('/'), 'production_root_required')
        ctx.host = Host(ctx)
    return ctx


def configure_primitives(d):
    """Private function globals and xattr-aware copy adapters; original helpers stay frozen."""
    import base64
    import os
    from types import FunctionType, ModuleType
    def clone(module):
        result = ModuleType('_con02_' + module.__name__)
        result.__dict__.update(vars(module))
        for name, value in vars(module).items():
            if isinstance(value, FunctionType) and value.__globals__ is vars(module):
                function = FunctionType(value.__code__, result.__dict__, value.__name__, value.__defaults__, value.__closure__)
                function.__kwdefaults__ = value.__kwdefaults__
                result.__dict__[name] = function
        return result
    for name in ('cold', 'c', 'tx', 'u'): setattr(d, name, clone(getattr(d, name)))
    d.cold.reserve = lambda: d.tx.reserve(d.p)
    d.c.reserve = lambda: d.tx.reserve(d.p)
    original_inventory = d.tx.inventory
    original_copy = d.u.copy_contents
    def attrs(path):
        return {k: base64.b64encode(os.getxattr(path, k)).decode() for k in sorted(os.listxattr(path))}
    def inventory(engine, root):
        result = original_inventory(engine, root)
        for key, item in result.items(): item['xattrs'] = attrs(root / key)
        return result
    def copy_contents(cold, source, dest, entries):
        for key, item in entries.items(): require(attrs(source / key) == item['xattrs'], 'copy_source_xattrs_changed')
        plain = {k: {n: v for n, v in item.items() if n != 'xattrs'} for k, item in entries.items()}
        original_copy(cold, source, dest, plain)
        for key, item in entries.items():
            path = dest / key
            for attr in os.listxattr(path):
                if attr not in item['xattrs']: os.removexattr(path, attr)
            for attr, value in item['xattrs'].items(): os.setxattr(path, attr, base64.b64decode(value, validate=True))
            require(attrs(path) == item['xattrs'] and attrs(source / key) == item['xattrs'], 'copy_xattrs_readback')
            if item['type'] == 'directory': cold.sync_directory(path)
    d.tx.inventory = inventory
    d.u.copy_contents = copy_contents


def check(ctx):
    require(ctx.host is not None and ctx.engine is not None, 'host_context_required')
    from connect_con02_state import Journal, Locks
    journal = Journal(ctx)
    with Locks(ctx).check():
        s = journal.load()
        if s and s.get('app') and hasattr(ctx.host,'bind_identities'):
            ctx.host.bind_identities(s['app']['primitive']['identities'])
        database = ctx.host.database(ctx.host.prefix())
        target = select_target(s, database['addons'])
        baseline = s['app']['database'] if s and s.get('app') else database
        index_profile = verify_database(database, baseline, ctx.bundle.reference, target=target)
        ctx.host.source(target=target)
        ctx.host.controls(s)
        https_snapshot(ctx)
        return {'phase': 'app_ready' if target else 'baseline_ready', 'configuration_changed': False,
                'host_changes': False, 'provider_called': False, 'source_files': len(ctx.bundle.reference[
                    'candidate' if target else 'baseline']['files']), 'local_ustar': database['addons']['local_ustar'],
                'baseline_index_profile': index_profile}


class Host:
    """Production primitives are bound lazily; construction does not access the host."""
    def __init__(self, ctx):
        self.ctx = ctx
        self.log = io.BytesIO()
        self._control = None

    def delegate(self):
        if self._control is None:
            d = self.ctx.engine
            d.identities = d.p.containers()
            owner = self
            class Control(d.tx.Host):
                def action(self, argv, timeout=90): return owner.action(argv, timeout)
            self._control = Control(d, d.identities, self.log)
        return self._control

    def bind_identities(self, identities):
        d=self.ctx.engine
        require(isinstance(identities,dict) and set(identities)==set(d.p.IMAGES), 'recorded_container_inventory')
        for name,image in d.p.IMAGES.items():
            row=identities[name]
            require(isinstance(row,dict) and row.get('Image')==image
                    and re.fullmatch('[a-f0-9]{64}',row.get('Id','')), 'recorded_container_identity')
        if self._control is None:
            owner=self
            class Control(d.tx.Host):
                def action(self,argv,timeout=90):return owner.action(argv,timeout)
            d.identities=identities
            self._control=Control(d,identities,self.log)
        else:
            require({n:(r['Id'],r['Image']) for n,r in self._control.identities.items()}
                    == {n:(r['Id'],r['Image']) for n,r in identities.items()}, 'recorded_container_identity')
        self._control.inspect()  # Same pinned IDs/images/binds, including stopped containers.

    def __getattr__(self, name): return getattr(self.delegate(), name)

    def database(self, prefix, addons=None):
        validate_prefix(prefix)
        return self.delegate().sql(self.ctx.engine.addon.db_query(prefix))

    def action(self, argv, timeout=90):
        import subprocess
        result = subprocess.run(argv, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=timeout)
        self.log.write(result.stdout); self.log.flush()
        require(result.returncode == 0, 'host_command_failed')

    def legacy(self):
        if '_legacy' not in self.__dict__:
            modules = frozen_legacy(self.ctx.bundle)
            https, install = modules['academy_https'], modules['connect_install']
            m = https.Manager()
            i = install.Installation()
            bridge = modules['connect_private_bridge']
            ref = {'schema': 1, 'tool_commit': LEGACY_COMMIT, 'payload_commit': BASELINE_COMMIT,
                   'files': {n: self.ctx.bundle.reference['baseline']['files'][n] for n in bridge.SECURITY_FILES}}
            reader = bridge.Readiness(ref, m, i)
            link = modules['connect_bridge_activation'].Deployment(reader,
                self.ctx.bundle.legacy['scripts/infra/academy_https.py'], Path('/not-used-by-readonly'), '')
            self._legacy = SimpleNamespace(modules=modules, manager=m, installation=i, reader=reader, link=link)
        return self._legacy

    def https_snapshot(self):
        from connect_con02_state import capture, descriptor
        m = self.legacy().manager; s = m.load()
        require(s.get('phase') == 'active' and s.get('bridge_enabled') is True, 'active_private_HTTPS_required')
        # These owner checks use files, OpenSSL, Apache topology and the static TLS
        # health endpoint. Deliberately exclude metadata()/app_check() PHP bootstrap.
        m.guards(s); m.authority(); m.topology(s, m.host.vhosts()); m.leaf_ready(s)
        files = {**m.files(), 'tool': m.p.tool, 'state': m.p.state, 'observability': m.p.include,
                 'service': m.p.service, 'timer': m.p.timer, 'ca': m.p.old.ca, 'ca_key': m.p.old.ca_key,
                 'cert': Path(s['folder']) / 'server.crt', 'leaf_key': Path(s['folder']) / 'server.key'}
        return {'verified': True, 'owner': 'ustar-academy-https',
                'files': {k: descriptor(capture(v)) for k, v in files.items()}, 'leaf': s['leaf']}

    def control_paths(self):
        r = self.ctx.root
        files = {n: r / ('etc/ustar-connect/' + f) for n, f in {
            'receiver': 'receiver.json', 'probe': 'probe.json', 'notifications': 'notifications.json',
            'link_key': 'bridge.key', 'link_ca': 'bridge.ca', 'token': 'telegram.token',
            'notify_key': 'notifications-bridge.key', 'notify_ca': 'notifications-bridge-ca.crt',
            'ack_key': 'ack-bridge.key'}.items()}
        files['include'] = r / 'opt/ustar/data/moodle/public/.ustar-connect/bridge.php'
        files['pointer'] = r / 'opt/ustar-connect/current'
        for n in ('receiver', 'probe', 'notify'):
            stem = r / ('etc/systemd/system/ustar-connect-' + n + '.service')
            files[n + '_unit'] = stem
            if n != 'notify':
                files[n + '_link_dropin'] = stem.with_name(stem.name + '.d') / '40-ustar-link.conf'
                files[n + '_ack_dropin'] = stem.with_name(stem.name + '.d') / '60-ustar-con02-ack.conf'
            else: files['notify_dropin'] = stem.with_name(stem.name + '.d') / '50-ustar-notify-pilot.conf'
        return files

    def validate_journal(self, journal):
        for scope in ('app','runtime'):
            row=journal.get(scope)
            if row is None:continue
            paths=self.control_paths()
            if scope=='app':
                paths.update(cron_script=self.ctx.root/'usr/local/sbin/USTAR_CRON_SETUP_20261005.py',
                    cron_installation=self.ctx.root/'var/lib/ustar-cron/installation.json',
                    cron_schedule=self.ctx.root/'etc/cron.d/ustar_moodle')
            require(set(row['files'])==set(paths), 'journal_control_inventory')
            require(all(pair[side]['path']==str(paths[name]) for name,pair in row['files'].items()
                        for side in ('before','after')), 'journal_control_path')

    def controls(self, journal):
        import json
        from connect_con02_state import capture, descriptor
        paths = self.control_paths()
        if journal is None:
            old = self.legacy(); modules = old.modules
            pilot = modules['connect_notification_pilot'].Pilot(old.link, {'tool_commit': LEGACY_COMMIT},
                Path('/not-used-by-readonly'), '', b'')
            s = pilot.load(); require(s['phase'] == 'active' and s['new_release'] == LEGACY_COMMIT, 'accepted_pilot_required')
            pilot.guard(s, active=True)
            require(self.legacy().installation.current().name == LEGACY_COMMIT, 'accepted_worker_required')
        else:
            app = journal.get('app'); require(app is not None, 'application_transaction_required')
            initial = app['controls']
            runtime = journal.get('runtime')
            for name, path in paths.items():
                allowed = [initial[name]]
                if runtime and name in runtime['files']:
                    allowed += list(runtime['files'][name].values())
                require(descriptor(capture(path)) in allowed, 'runtime_control_drift')
        # Detect additional overrides, including vendor/runtime directories.
        allowed = {'receiver': {'40-ustar-link.conf'}, 'probe': {'40-ustar-link.conf'},
                   'notify': {'50-ustar-notify-pilot.conf'}}
        if journal and (journal.get('runtime') or getattr(self.ctx, 'baseline_ack_enabled', False)):
            for n in ('receiver', 'probe'): allowed[n].add('60-ustar-con02-ack.conf')
        import os
        for n, names in allowed.items():
            unit = 'ustar-connect-' + n + '.service'
            for base in ('run/systemd/system', 'usr/lib/systemd/system', 'lib/systemd/system'):
                p = self.ctx.root / base / unit
                require(not os.path.lexists(p) and not os.path.lexists(p.with_name(unit + '.d')), 'unknown_runtime_override')
            folder = self.ctx.root / 'etc/systemd/system' / (unit + '.d')
            require(folder.is_dir() and not folder.is_symlink()
                    and {p.name for p in folder.iterdir()} <= names, 'unknown_runtime_override')
        images = {k: capture(v) for k, v in paths.items()}
        fence = read_fence(images['include']['raw'], json.loads(images['notifications']['raw']),
                           json.loads(images['receiver']['raw']))
        validate_fence(fence)
        return {'files': images, 'fence': fence}

    def preflight(self):
        d = self.ctx.engine
        d.p.mount(); self.delegate()
        require(not (d.p.DATA / 'climaintenance.html').exists()
                and not (d.p.STATE / 'moodle-was-running.json').exists(), 'pending_host_recovery')
        for name in ('hdd_backup', 'hdd_scheduler', 'backup_engine'):
            path, digest = d.w.HELPERS[name]
            d.p.root_input(path)
            require(d.p.read_file(path, 1048576, True) == digest, 'accepted_backup_helper_changed')
        return {'restart_policies': d.w.docker_conditions(d.p, d.identities)['restart_policies'],
                'capacity': d.addon.capacity(d), 'roots': d.tx.roots(d), 'identities': d.identities}

    def fresh_backup(self, marker):
        return self.ctx.engine.addon.fresh_hdd(self.ctx.engine, marker, self)

    def recover_backup(self):
        d = self.ctx.engine
        if (d.p.STATE / 'moodle-was-running.json').exists():
            path, digest = d.w.HELPERS['hdd_backup']
            d.p.root_input(path)
            require(d.p.read_file(path, 1048576, True) == digest, 'accepted_backup_helper_changed')
            self.action(['python3', '-I', '-B', str(path), '--recover'], timeout=120)
        require(not (d.p.STATE / 'moodle-was-running.json').exists(), 'backup_recovery_pending')

    def capacity(self, cold=False): return self.ctx.engine.addon.capacity(self.ctx.engine, cold)

    def restore_capacity(self, pair):
        # The baseline pair and the current live trees are already allocated.
        # Recovery needs one more complete copy, not the original three-copy budget.
        import os
        d=self.ctx.engine
        require(set(pair['trees'])==set(d.trees), 'restore_tree_inventory')
        estimates=[d.w.tree_estimate(d.p,d.marker.parent/'baseline'/name) for name in d.trees]
        require(all(e['vanished_entries']==0 for e in estimates), 'restore_capacity_changed')
        total=sum(e['allocation_allowance_bytes'] for e in estimates)
        entries=sum(e['files']+e['directories'] for e in estimates)
        fs=os.statvfs(d.p.CODE)
        require(fs.f_bavail*fs.f_frsize>=6*1024**3+total and fs.f_favail>=entries+10000,
                'restore_copy_capacity')

    def references(self, prefix): return self.ctx.engine.tx.references(self.ctx.engine, self, prefix)

    def validate_units(self, values):
        i = self.legacy().installation
        current = i.current(); require(current is not None, 'managed_worker_required')
        old = i.release_files(current)
        for name in ('receiver', 'probe', 'notify'):
            n = 'ustar-connect-' + name + '.service'
            require(values['units/' + n] == old['units/' + n]
                    == self.ctx.engine.p.read_file(i.units / n, 65536), 'base_unit_change_requires_review')

    def application_ready(self, state):
        require(state.get('app') is not None and state['app']['phase'] == 'app_ready', 'app_ready2200_required')
        from connect_con02_application import Application
        from connect_con02_state import Journal, Locks
        Application(self.ctx, Journal(self.ctx), Locks(self.ctx)).verify(state['app'], target=True)

    def validate_profiles(self, values):
        m = self.legacy().manager
        m.host.lint(m.host.runtime(m.p.old.config), values['include'])

    def login(self):
        m = self.legacy().manager
        s = m.load(); m.host.app_check(m.p.old.ca, s['leaf'])
        return 200

    def prefix(self):
        d = self.ctx.engine
        return validate_prefix(d.p.parse_prefix(d.p.read_file(d.p.CODE / 'config.php', 131072).decode()))

    def source(self, *, target): self.ctx.engine.addon.source(self.ctx.engine, target)

    def service_active(self, unit):
        import subprocess
        r = subprocess.run(['systemctl', 'is-active', unit], capture_output=True, timeout=15)
        require(r.returncode in (0, 3), 'service_state_unavailable')
        return r.stdout.strip() == b'active'

    def service_enabled(self, unit):
        import subprocess
        r = subprocess.run(['systemctl', 'is-enabled', unit], capture_output=True, timeout=15)
        require(r.stdout.strip() in (b'enabled', b'disabled'), 'service_enabled_state_unavailable')
        return r.stdout.strip() == b'enabled'

    def service(self, action, unit):
        require(action in ('start', 'stop', 'enable', 'disable', 'daemon-reload'), 'service_action')
        self.action(['systemctl', action] + ([unit] if unit else []), timeout=60)

    def probe(self, profile):
        require(profile in ('v1', 'v2'), 'probe_profile')
        self.service('start', 'ustar-connect-probe.service')


def frozen_legacy(bundle):
    """Keep frozen guard imports private; do not replace process-global modules."""
    import builtins
    from types import ModuleType
    sources = {Path(k).stem: v for k, v in bundle.legacy.items()}
    sources['connect_install'] = sources.pop('install')
    result = {}
    def load(name):
        if name in result: return result[name]
        module = ModuleType('_con02_frozen_' + name)
        result[name] = module
        module.__file__ = '<frozen-a86/' + name + '.py>'
        def scoped_import(target, globals=None, locals=None, fromlist=(), level=0):
            if level == 0 and target in sources: return load(target)
            return builtins.__import__(target, globals, locals, fromlist, level)
        module.__dict__['__builtins__'] = {**vars(builtins), '__import__': scoped_import}
        exec(compile(sources[name], module.__file__, 'exec'), module.__dict__)
        return module
    for name in sources: load(name)
    return result
