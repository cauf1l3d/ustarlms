#!/usr/bin/env python3
"""Fixed accepted Academy2800 -> qualified canvas/panels2801; no worker rollout."""
from dataclasses import dataclass
import json
from pathlib import Path
import re
import connect_con02_contract as c
import connect_telegram_update as t
import course_studio_update as s
import studio_colors_update as m
from connect_con02_state import Journal, Locks, unique

APP_COMMIT = 'a3df57831a5ed49b901e5116ee800f2217fb61e9'
BASELINE_COMMIT = m.APP_COMMIT
WORKER_COMMIT = s.WORKER_COMMIT
PREDECESSOR_TOOL = '5f57115d85fbe808069b9c72d6c0ea9fa6e13f02'
BASE = Path('/var/lib/ustar-studio-viewport-update')
MODULES = m.MODULES + ('studio_viewport_update',)
VERSIONS = {'local_ustar': '2026102801', 'theme_ustar': '2026102700'}
CRON_LINE = b"VERSIONS = {'local_ustar': '2026102800', 'theme_ustar': '2026102700'}"
CHANGED = frozenset('local/ustar/' + n for n in (
    'course_studio/app.js', 'course_studio/editor.html', 'course_studio/style.css', 'version.php'))



@dataclass(frozen=True)
class Bundle:
    reference: dict
    modules: dict
    payload: dict
    worker_archive: bytes
    legacy: dict
    predecessor: m.Bundle


def validate_reference(ref):
    c.require(ref.get('schema') == 1 and re.fullmatch('[a-f0-9]{40}', ref.get('tool_commit', ''))
        and ref.get('app_commit') == APP_COMMIT and ref.get('baseline_commit') == BASELINE_COMMIT
        and ref.get('worker_commit') == WORKER_COMMIT and ref.get('legacy_commit') == c.LEGACY_COMMIT,
        'viewport_fixed_source_required')
    before, after = ref['baseline'], ref['candidate']
    c.require(len(before['files']) == 589 and len(after['files']) == 589
        and before['versions'] == {'local_ustar': 2026102800, 'theme_ustar': 2026102700}
        and after['versions'] == {'local_ustar': 2026102801, 'theme_ustar': 2026102700}, 'viewport_release_profile')
    c.require(before['tables'] == after['tables'] and len(after['tables']) == 100
        and before['schema_fields'] == after['schema_fields']
        and before['schema_indexes'] == after['schema_indexes'], 'viewport_schema_must_be_unchanged')
    old, new = set(before['files']), set(after['files'])
    c.require(old == new
        and {n for n in old if before['files'][n] != after['files'][n]} == CHANGED,
        'viewport_unrelated_source_changed')
    c.require(all(n.startswith(('local/ustar/', 'theme/ustar/')) and '..' not in Path(n).parts
        and '\\' not in n and re.fullmatch('[a-f0-9]{64}', digest)
        for n, digest in after['files'].items()), 'viewport_payload_path')


def patch_cron(raw, installation, *, accepted_sha):
    c.require(c.sha(raw) == accepted_sha and raw.count(CRON_LINE) == 1, 'cron_source_changed')
    c.require(isinstance(installation, dict) and installation.get('schema') == 1
        and installation.get('script_sha256') == accepted_sha and installation.get('versions') == m.VERSIONS,
        'cron_installation_changed')
    result = raw.replace(CRON_LINE, b"VERSIONS = {'local_ustar': '2026102801', 'theme_ustar': '2026102700'}")
    compile(result, '<reviewed-viewport2801-cron>', 'exec')
    return result, {**installation, 'versions': VERSIONS, 'script_sha256': c.sha(result)}


def load_embedded(manifest, sources, assets):
    import base64
    c.require(isinstance(manifest, dict) and manifest.get('worker_commit') == WORKER_COMMIT,
        'viewport_worker_identity')
    modules, values = c.decode_envelope({**manifest, 'worker_commit': APP_COMMIT}, sources, assets, app_commit=APP_COMMIT)
    c.require(set(modules) == {n + '.py' for n in MODULES}, 'embedded_module_inventory')
    ref = json.loads(values['reference.json'], object_pairs_hook=unique); validate_reference(ref)
    previous_ref = json.loads(values['colors.json'], object_pairs_hook=unique)
    m.validate_reference(previous_ref)
    c.require(ref['tool_commit'] == manifest['tool_commit'] and previous_ref['tool_commit'] == PREDECESSOR_TOOL
        and ref['baseline'] == previous_ref['candidate'], 'viewport_predecessor_identity')
    payload = {n: values['payload/' + n] for n in ref['candidate']['files']}
    c.require(all(c.sha(raw) == ref['candidate']['files'][n] for n, raw in payload.items()), 'embedded_payload_digest')
    # Reconstruct the fixed2800 envelope for its unchanged validator. Only the
    # four changed baseline files need separate bytes; all other files are equal.
    previous_values = {n: raw for n, raw in values.items() if n not in ('reference.json', 'colors.json')
        and not n.startswith(('payload/', 'baseline/'))}
    previous_values['reference.json'] = values['colors.json']
    for name in ref['baseline']['files']:
        previous_values['payload/' + name] = values['baseline/' + name] if name in CHANGED else payload[name]
    c.require(set(values) == set(previous_values) | {'colors.json'} | {'baseline/' + n for n in CHANGED},
        'embedded_asset_inventory')
    previous_sources = {n + '.py': sources[n + '.py'] for n in m.MODULES}
    previous_manifest = {'schema': 1, 'tool_commit': PREDECESSOR_TOOL, 'app_commit': m.APP_COMMIT,
        'worker_commit': WORKER_COMMIT,
        'sources': {n: {'sha256': c.sha(raw.encode()), 'bytes': len(raw.encode())} for n, raw in previous_sources.items()},
        'assets': {n: {'sha256': c.sha(raw), 'bytes': len(raw)} for n, raw in previous_values.items()}}
    previous = m.load_embedded(previous_manifest, previous_sources,
        {n: base64.b64encode(raw).decode() for n, raw in previous_values.items()})
    return Bundle(ref, modules, payload, previous.worker_archive, previous.legacy, previous)


def verify_database(current, baseline, reference, *, target):
    c.require(reference['baseline']['versions'] == {
        'local_ustar': 2026102800, 'theme_ustar': 2026102700}, 'viewport_baseline_versions')
    try:
        return c.verify_database(current, baseline, reference, target=target, historical_version=2026102800)
    except c.Refusal as error:
        if str(error) != 'schema_indexes': raise
    # The accepted23Oct pair persisted through2800 with theme2700.
    # The frozen verifier's alternate profile is tied to theme0202. Retry the
    # exact pair using the same strict verifier, without changing data/versions.
    import copy
    alternate = copy.deepcopy(reference)
    for table, field in (('local_ustar_coin_accounts', 'userid'),
                         ('local_ustar_test_results', 'attemptid')):
        indexes = alternate['baseline']['schema_indexes'].get(table, [])
        omitted = [item for item in indexes if item['fields'] == [field] and item['unique'] is True]
        c.require(len(omitted) == 1, 'schema_indexes')
        indexes.remove(omitted[0])
    c.verify_database(current, baseline, alternate, target=target, historical_version=2026102800)
    return 'server1_legacy_indexes_20261023'


def verify_predecessor_database(current, baseline, reference):
    # Preserve policy changes made since the accepted2800 installation, while
    # still checking every version, schema/index and table against that release.
    return m.verify_database(current, {**baseline, 'tasks': current['tasks']}, reference, target=True)


def advance_cron(cron2700):
    c.require(cron2700.count(m.CRON_LINE) == 1, 'cron_source_changed')
    return cron2700.replace(m.CRON_LINE, CRON_LINE)


def derive_cron(raw, installation):
    return advance_cron(m.derive_cron(raw, installation))


class Host(c.Host):
    def previous_context(self):
        if '_previous' not in self.__dict__:
            self._previous = m.make_context(self.ctx.bundle.predecessor)
            self._previous.host.log = self.log
        return self._previous

    def legacy(self): return self.previous_context().host.legacy()

    def verify_runtime(self, previous):
        return m.Host.verify_runtime(self, previous.host.previous_context())

    def accepted_cron(self):
        # The frozen2800 adapter derives2700 bytes from the pinned2000 image.
        return advance_cron(self.previous_context().host.accepted_cron())

    def predecessor(self):
        """Read the accepted2800 journal under shared deployment locks, without writes."""
        from connect_con02_application import Application
        from connect_con02_state import capture, descriptor
        previous = self.previous_context(); journal = Journal(previous); saved = journal.load()
        c.require(saved and saved.get('runtime') is None and (saved.get('app') or {}).get('phase') == 'app_ready',
            'accepted_Colors2800_required')
        row = saved['app']; Application(previous, journal, Locks(previous)).bind(row)
        previous.host.source(target=True)
        verify_predecessor_database(previous.host.database(row['prefix']), row['database'], previous.bundle.reference)
        controls = previous.host.controls(saved); journal.guard('app')
        self.verify_runtime(previous)
        accepted = self.accepted_cron(); self.accepted_cron_sha = c.sha(accepted)
        c.require(row['files']['cron_script']['after']['sha256'] == self.accepted_cron_sha, 'accepted_cron_journal_changed')
        for name in ('cron_script', 'cron_installation', 'cron_schedule'):
            image = row['files'][name]['after']
            c.require(descriptor(capture(Path(image['path']))) == image, 'accepted_cron_not_effective')
        return controls

    def controls(self, journal):
        return self.predecessor() if journal is None else super().controls(journal)

    def cron_patch(self, raw, installation):
        saved = Journal(self.ctx).load()
        digest = (saved['app']['files']['cron_script']['before']['sha256']
            if saved and saved.get('app') else getattr(self, 'accepted_cron_sha', None))
        c.require(digest is not None, 'accepted_cron_required')
        return patch_cron(raw, installation, accepted_sha=digest)


def make_context(bundle, *, root=Path('/'), uid=0, host=None):
    validate_reference(bundle.reference)
    c.require(bundle.predecessor.reference['tool_commit'] == PREDECESSOR_TOOL, 'fixed_predecessor_required')
    ctx = m.make_context(bundle.predecessor, root=root, uid=uid, host=host)
    ctx.bundle = bundle; ctx.engine.reference = bundle.reference; ctx.engine.payload = bundle.payload
    ctx.engine.schema_fields = bundle.reference['candidate']['schema_fields']
    ctx.engine.base = Path(root) / str(BASE).lstrip('/'); ctx.engine.marker = ctx.engine.base / 'uninitialized-primitive.json'
    if host is None: ctx.host = Host(ctx)
    ctx.verify_database = verify_database; ctx.baseline_ack_enabled = True
    ctx.patch_cron = lambda raw, record: Host.cron_patch(ctx.host, raw, record)
    return ctx


def check(ctx):
    from connect_con02_application import Application
    with Locks(ctx).check():
        journal = Journal(ctx); saved = journal.load(); index_profile = None
        if saved is None:
            ctx.host.controls(None); ctx.host.preflight(); target = False
            database = ctx.host.database(ctx.host.prefix())
            index_profile = verify_database(database, database, ctx.bundle.reference, target=False)
            ctx.host.source(target=False)
        else:
            c.require(saved.get('runtime') is None and (saved.get('app') or {}).get('phase') in ('app_ready', 'baseline_restored'),
                'viewport_recovery_required')
            target = saved['app']['phase'] == 'app_ready'
            Application(ctx, journal, Locks(ctx)).verify(saved['app'], target=target)
        c.https_snapshot(ctx)
        return {'phase': 'application_ready' if target else 'ready_for_viewport_update',
            'local_ustar': '2026102801' if target else '2026102800', 'host_changes': False, 'provider_called': False,
            **({'baseline_index_profile': index_profile} if index_profile else {})}


def main(argv=None, *, bundle=None):
    import argparse, os, signal, sys, traceback, uuid
    from connect_con02_update import artifact_guard, status
    p = argparse.ArgumentParser(description=__doc__); g = p.add_mutually_exclusive_group()
    for name in ('check', 'upgrade', 'status', 'recover', 'restore'): g.add_argument('--' + name, action='store_true')
    p.add_argument('--acknowledge-outage', action='store_true'); p.add_argument('--discard-writes-since-snapshot', action='store_true')
    args = p.parse_args(argv)
    if args.acknowledge_outage and not (args.upgrade or args.restore): p.error('outage flag requires upgrade or restore')
    if args.discard_writes_since_snapshot and not args.restore: p.error('discard flag requires restore')
    if (args.upgrade or args.restore) and not args.acknowledge_outage: p.error('explicit outage acknowledgement required')
    if not any((args.check, args.upgrade, args.status, args.recover, args.restore)):
        print(json.dumps({'phase': 'PLAN_ONLY', 'host_access': False, 'network_used': False})); return 0
    log = None
    try:
        artifact_guard(Path(sys.argv[0])); c.require(bundle is not None, 'embedded_bundle_required'); ctx = make_context(bundle)
        if args.upgrade or args.recover or args.restore:
            from connect_con02_state import private_directory
            private_directory(ctx.engine.base, ctx.uid); log = (ctx.engine.base / ('private-' + uuid.uuid4().hex + '.log')).open('xb')
            os.fchmod(log.fileno(), 0o600); ctx.host.log = log
            def interrupted(*_): raise KeyboardInterrupt()
            for sig in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP): signal.signal(sig, interrupted)
            ctx.progress = lambda phase: print(json.dumps({'phase': phase}), flush=True)
        if args.check: result = check(ctx)
        elif args.status: result = status(ctx)
        else:
            from connect_con02_application import Application
            operation = Application(ctx, Journal(ctx), Locks(ctx))
            if args.upgrade: result = operation.upgrade()
            elif args.recover: result = operation.recover()
            else: result = operation.restore(outage=args.acknowledge_outage, discard=args.discard_writes_since_snapshot)
        print(json.dumps(result, sort_keys=True), flush=True)
        return 2 if result.get('phase') == 'recovery_required' or result.get('upgrade_succeeded') is False else 0
    except BaseException as error:
        if log is not None: log.write(traceback.format_exc().encode()); log.flush(); os.fsync(log.fileno())
        reason = str(error) if isinstance(error, c.Refusal) else 'viewport_operation_failed'
        if not re.fullmatch('[a-zA-Z0-9_]{1,100}', reason): reason = 'viewport_operation_failed'
        print(json.dumps({'phase': 'refused', 'reason': reason}), flush=True); return 2
    finally:
        if log is not None: log.close()
