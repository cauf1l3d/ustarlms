"""Separate2800→2801 delivery. Never accesses production or a provider."""
import ast
import base64
import contextlib
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT / 'scripts/infra'), str(ROOT / 'scripts'), str(ROOT / 'tests/r16')]
import connect_con02_contract as c


def adapter():
    assert importlib.util.find_spec('studio_viewport_update'), 'Separate2800-to2801 operator required'
    import studio_viewport_update
    return studio_viewport_update


def builder():
    assert importlib.util.find_spec('build_studio_viewport_update'), 'Exact-Git2801 packager required'
    import build_studio_viewport_update
    return build_studio_viewport_update


class Contract(unittest.TestCase):
    def test_host_derives2800_from_frozen_predecessor_not_live_cron(self):
        from types import SimpleNamespace
        from unittest.mock import patch
        raw = b"VERSIONS = {'local_ustar': '2026102700', 'theme_ustar': '2026102700'}\n"
        module = adapter(); host = module.Host(SimpleNamespace())
        previous = SimpleNamespace(host=SimpleNamespace(accepted_cron=lambda: raw))
        with patch.object(host, 'previous_context', return_value=previous):
            self.assertEqual(raw.replace(b"'local_ustar': '2026102700'", b"'local_ustar': '2026102800'"), host.accepted_cron())
        previous.host.accepted_cron = lambda: raw + raw
        with patch.object(host, 'previous_context', return_value=previous), self.assertRaises(c.Refusal):
            host.accepted_cron()

    def test_cron_chain_derives2800_from_pinned2000_without_adopting_live_hash(self):
        from unittest.mock import patch
        import connect_con02_application as app
        raw = b"# fixed fixture\nVERSIONS = {'local_ustar': '2026102000', 'theme_ustar': '2026100202'}\n"
        record = {'schema': 1, 'script_sha256': c.sha(raw),
                  'versions': {'local_ustar': '2026102000', 'theme_ustar': '2026100202'}, 'owner': 'unchanged'}
        self.assertTrue(hasattr(adapter(), 'derive_cron'), 'Pinned predecessor cron derivation required')
        with patch.object(app, 'CRON_BEFORE_SHA', c.sha(raw)):
            after = adapter().derive_cron(raw, record)
            self.assertEqual(raw.replace(b'2026102000', b'2026102800').replace(b'2026100202', b'2026102700'), after)
            with self.assertRaises(c.Refusal): adapter().derive_cron(raw + b'#foreign', record)

    def test_cron_updates_local_version_preserving_other_bytes_and_record(self):
        raw = b"# accepted flags\nVERSIONS = {'local_ustar': '2026102800', 'theme_ustar': '2026102700'}\n"
        record = {'schema': 1, 'script_sha256': c.sha(raw),
                  'versions': {'local_ustar': '2026102800', 'theme_ustar': '2026102700'}, 'owner': 'preserved'}
        after, info = adapter().patch_cron(raw, record, accepted_sha=c.sha(raw))
        self.assertEqual(b"# accepted flags\nVERSIONS = {'local_ustar': '2026102801', 'theme_ustar': '2026102700'}\n", after)
        self.assertEqual({'local_ustar': '2026102801', 'theme_ustar': '2026102700'}, info['versions'])
        self.assertEqual('preserved', info['owner']); self.assertEqual(c.sha(after), info['script_sha256'])
        for bad in (raw + b'#drift', raw.replace(b'2700', b'2300'), raw + raw):
            with self.subTest(bad=bad), self.assertRaises(c.Refusal):
                adapter().patch_cron(bad, record, accepted_sha=c.sha(raw))
        bad = {**record, 'versions': {'local_ustar': '2026102800', 'theme_ustar': '2026100202'}}
        with self.assertRaises(c.Refusal): adapter().patch_cron(raw, bad, accepted_sha=c.sha(raw))

    def test_cli_offline_and_destructive_modes_require_explicit_flags(self):
        from unittest.mock import patch
        module = adapter()
        with patch.object(module, 'make_context', create=True, side_effect=AssertionError('host touched')):
            out = io.StringIO()
            with contextlib.redirect_stdout(out): self.assertEqual(0, module.main([]))
            self.assertEqual({'phase': 'PLAN_ONLY', 'host_access': False, 'network_used': False}, json.loads(out.getvalue()))
            for args in (['--upgrade'], ['--restore'], ['--recover', '--acknowledge-outage'],
                         ['--check', '--discard-writes-since-snapshot'], ['--check', '--status']):
                with self.subTest(args=args), contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
                    module.main(args)


class Reference(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.bundle = builder().reference_from_git(ROOT, 'a3df57831a5ed49b901e5116ee800f2217fb61e9')

    def test_only_qualified_589_files_with_unchanged_schema_and_worker(self):
        ref = self.bundle.reference
        self.assertEqual('a3df57831a5ed49b901e5116ee800f2217fb61e9', ref['app_commit'])
        self.assertEqual('4c48dce7dadd4da8f054f2acf0d6974bdaeecebc', ref['baseline_commit'])
        self.assertEqual('6c56a5b6ab830b97b706a5d7028b49cfae31e7a4', ref['worker_commit'])
        self.assertEqual('5f57115d85fbe808069b9c72d6c0ea9fa6e13f02', self.bundle.predecessor.reference['tool_commit'])
        self.assertEqual(589, len(ref['baseline']['files'])); self.assertEqual(589, len(self.bundle.payload))
        self.assertEqual({'local_ustar': 2026102800, 'theme_ustar': 2026102700}, ref['baseline']['versions'])
        self.assertEqual({'local_ustar': 2026102801, 'theme_ustar': 2026102700}, ref['candidate']['versions'])
        for name in ('tables', 'schema_fields', 'schema_indexes'):
            self.assertEqual(ref['baseline'][name], ref['candidate'][name])
        self.assertEqual(100, len(ref['candidate']['tables']))
        raw = subprocess.check_output(['git', '-C', str(ROOT), 'show',
            'a3df57831a5ed49b901e5116ee800f2217fb61e9:moodle/local/ustar/course_studio/word.js'])
        self.assertEqual(raw, self.bundle.payload['local/ustar/course_studio/word.js'])

    def test_rejects_unrelated_source_schema_version_and_worker_changes(self):
        module = adapter(); module.validate_reference(self.bundle.reference)
        changes = [lambda r: r['candidate']['files'].__setitem__('local/ustar/connect.php', 'f' * 64),
                   lambda r: r['candidate']['tables'].append('invented_table'),
                   lambda r: r['candidate']['versions'].__setitem__('theme_ustar', 2026100202),
                   lambda r: r.__setitem__('worker_commit', r['app_commit']),
                   lambda r: r['candidate']['files'].__setitem__('local/ustar/../escape', 'f' * 64)]
        for change in changes:
            bad = copy.deepcopy(self.bundle.reference); change(bad)
            with self.subTest(change=change), self.assertRaises(c.Refusal): module.validate_reference(bad)

    def test_context_uses_separate_journal_and_does_not_access_host(self):
        self.assertTrue(hasattr(adapter(), 'make_context'), 'Successor isolated context required')
        with tempfile.TemporaryDirectory() as td:
            root = Path(td); host = object()
            ctx = adapter().make_context(self.bundle, root=root, uid=os.getuid(), host=host)
            self.assertEqual(root / 'var/lib/ustar-studio-viewport-update', ctx.engine.base)
            self.assertEqual([], list(root.iterdir())); self.assertIs(host, ctx.host)
            self.assertEqual(self.bundle.reference, ctx.engine.reference)


class Package(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.b = builder(); cls.tmp = tempfile.TemporaryDirectory(); cls.addClassCleanup(cls.tmp.cleanup)
        cls.root = Path(cls.tmp.name)
        env = {**os.environ, 'GIT_INDEX_FILE': str(cls.root / 'index'),
               'GIT_AUTHOR_NAME': 'Materials fixture', 'GIT_AUTHOR_EMAIL': 'fixture@example.invalid',
               'GIT_COMMITTER_NAME': 'Materials fixture', 'GIT_COMMITTER_EMAIL': 'fixture@example.invalid'}
        def git(*args):
            return subprocess.check_output(['git', '-C', str(ROOT), *args], env=env, stderr=subprocess.DEVNULL).decode().strip()
        # Future application candidates must not silently repoint this operator.
        frozen = 'a3df57831a5ed49b901e5116ee800f2217fb61e9'
        git('read-tree', frozen)
        git('add', 'scripts/infra/studio_viewport_update.py', 'scripts/infra/build_studio_viewport_update.py')
        cls.commit = git('commit-tree', git('write-tree'), '-p', frozen, '-m', 'Viewport2800 package verification snapshot')
        with contextlib.redirect_stdout(io.StringIO()): cls.artifact = cls.b.build(cls.commit, cls.root / 'one')

    def test_isolated_offline_file_ignores_poisoned_siblings(self):
        (self.artifact.parent / 'studio_viewport_update.py').write_text('raise RuntimeError("SIBLING_IMPORTED")')
        r = subprocess.run([sys.executable, '-I', '-B', str(self.artifact)], capture_output=True, text=True, timeout=45)
        self.assertEqual(0, r.returncode, r.stderr)
        self.assertEqual({'phase': 'PLAN_ONLY', 'host_access': False, 'network_used': False}, json.loads(r.stdout))

    def test_archive_reproducible_regular_members_and_all_digests(self):
        with contextlib.redirect_stdout(io.StringIO()): other = self.b.build(self.commit, self.root / 'two')
        self.assertEqual(self.artifact.read_bytes(), other.read_bytes())
        archive, = self.artifact.parent.glob('*.tar.gz')
        with tarfile.open(archive) as t:
            self.assertEqual({'update.py', 'README.txt', 'INSTALL.json', 'SHA256SUMS'}, set(t.getnames()))
            self.assertTrue(all(m.isfile() and m.mode == 0o600 and m.uid == m.gid == 0 for m in t))
            files = {m.name: t.extractfile(m).read() for m in t}
        self.assertEqual(self.artifact.read_bytes(), files['update.py'])
        for row in files['SHA256SUMS'].decode().splitlines():
            digest, name = row.split('  ', 1); self.assertEqual(digest, c.sha(files[name]))
        meta = json.loads(files['INSTALL.json'])
        self.assertEqual(589, meta['payload_files']); self.assertFalse(meta['worker_rollout'])
        self.assertEqual('4c48dce7dadd4da8f054f2acf0d6974bdaeecebc', meta['baseline_commit'])

    def envelope(self):
        constants = {n.targets[0].id: ast.literal_eval(n.value) for n in ast.parse(self.artifact.read_text()).body
            if isinstance(n, ast.Assign) and isinstance(n.targets[0], ast.Name)
            and n.targets[0].id in ('MANIFEST_JSON', 'SOURCES_JSON', 'ASSETS_JSON')}
        return [json.loads(constants[n]) for n in ('MANIFEST_JSON', 'SOURCES_JSON', 'ASSETS_JSON')]

    def test_unqualified_payload_or_predecessor_or_worker_is_refused(self):
        manifest, sources, assets = self.envelope()
        self.assertEqual(self.commit, adapter().load_embedded(manifest, sources, assets).reference['tool_commit'])
        bad = dict(assets); bad['payload/local/ustar/version.php'] = base64.b64encode(b'unknown').decode()
        with self.assertRaises(c.Refusal): adapter().load_embedded(manifest, sources, bad)
        bad = copy.deepcopy(manifest); bad['worker_commit'] = bad['app_commit']
        with self.assertRaises(c.Refusal): adapter().load_embedded(bad, sources, assets)
        bad = dict(assets); bad['studio.json'] = base64.b64encode(b'{}').decode()
        with self.assertRaises(c.Refusal): adapter().load_embedded(manifest, sources, bad)

    def test_builder_refuses_an_old_application_tree(self):
        with self.assertRaisesRegex(c.Refusal, 'tool_qualified_component_mismatch'):
            self.b.build('5f57115d85fbe808069b9c72d6c0ea9fa6e13f02', self.root / 'bad')


class Application(unittest.TestCase):
    """Actual2800pair and successor copies; external Docker/SQL are modeled."""
    def setUp(self):
        import test_studio_colors_update as f
        import connect_con02_application as a
        import connect_con02_state as state
        from types import MethodType, SimpleNamespace
        self.module = adapter()
        self.assertTrue(hasattr(self.module, 'verify_database'), 'Successor DB contract required')
        self.old = f.Application(methodName='runTest'); self.old.setUp(); self.addCleanup(self.old.doCleanups)
        self.assertEqual('app_ready', self.old.op.upgrade()['phase'])
        oldctx = self.old.ctx; d = self.old.old.old.f.d
        self.accepted = copy.deepcopy(self.old.journal.load()); self.old_journal_bytes = self.old.journal.path.read_bytes()
        # Freeze the previous engine view before the successor selects its profile.
        oldengine = copy.copy(d); oldengine.reference = copy.deepcopy(d.reference)
        previous_host = type(oldctx.host)(oldengine, oldengine.identities, io.BytesIO())
        self.previous = c.Context(oldctx.bundle, oldctx.root, oldctx.uid, previous_host, oldengine)
        self.baseline = copy.deepcopy(oldctx.host.database('mdl_'))
        self.baseline['tasks']['prune'] = [0, 1]  # Owner may legitimately change policy after2800.
        (d.p.PGDATA / 'db.json').write_text(json.dumps(self.baseline))
        d.reference = {'baseline': copy.deepcopy(d.reference['candidate']), 'candidate': copy.deepcopy(d.reference['candidate'])}
        d.reference['candidate']['versions'] = {'local_ustar': 2026102801, 'theme_ustar': 2026102700}
        self.new = 'local/ustar/course_studio/word.js'; d.payload = {**d.payload, self.new: b'qualified canvas zoom and independent panels'}
        d.reference['candidate']['files'] = {n: c.sha(v) for n, v in d.payload.items()}
        d.base = self.old.old.old.f.root / 'successor2801'; d.marker = d.base / 'uninitialized.json'; d.attempt = 0
        ref = {**d.reference, 'tool_commit': 'e' * 40, 'app_commit': self.module.APP_COMMIT}
        self.ctx = c.Context(SimpleNamespace(reference=ref), oldctx.root, oldctx.uid, oldctx.host, d)
        self.ctx.verify_database = self.module.verify_database; self.ctx.baseline_ack_enabled = True
        self.raw_cron = self.old.old.old.f.cron.read_bytes()
        self.ctx.patch_cron = lambda raw, info: self.module.patch_cron(raw, info, accepted_sha=c.sha(self.raw_cron))
        def upgrade(host):
            d.attempt += 1; db = host.database('mdl_')
            db['addons'].update(local_ustar='2026102801', theme_ustar='2026102700')
            (d.p.PGDATA / 'db.json').write_text(json.dumps(db))
            if d.attempt == d.fail_at: raise c.Refusal('synthetic_SQL_failure')
        self.ctx.host.cli_upgrade = MethodType(upgrade, self.ctx.host)
        self.journal = state.Journal(self.ctx); self.locks = state.Locks(self.ctx)
        self.op = a.Application(self.ctx, self.journal, self.locks)
        self.controls = self.ctx.host.controls(None); self.services = copy.deepcopy(d.services)

    def test_upgrade_repeat_changes_local_version_preserves_policy_and_old_journal(self):
        self.assertEqual('2026102801', self.op.upgrade()['local_ustar'])
        db = self.ctx.host.database('mdl_')
        self.assertEqual('2026102700', db['addons']['theme_ustar'])
        for key in ('schema', 'indexes', 'tasks'): self.assertEqual(self.baseline[key], db[key])
        self.assertEqual(b'qualified canvas zoom and independent panels', (self.old.old.old.f.d.web / self.new).read_bytes())
        info = json.loads(self.old.old.old.f.install.read_bytes())
        self.assertEqual({'local_ustar': '2026102801', 'theme_ustar': '2026102700'}, info['versions'])
        self.assertTrue(self.op.upgrade()['verify_only']); self.assertEqual(2, self.old.old.old.f.d.attempt)
        self.assertEqual(self.old_journal_bytes, self.old.journal.path.read_bytes())
        self.assertEqual(self.services, self.old.old.old.f.d.services)
        self.assertEqual(self.controls, self.ctx.host.controls(self.journal.load()))

    def test_failure_before_release_restores_exact2800_pair_and_cron(self):
        self.old.old.old.f.d.fail_at = 2
        result = self.op.upgrade(); self.assertEqual('baseline_restored', result['phase'])
        self.assertFalse(result['upgrade_succeeded'])
        self.assertEqual(self.baseline, self.ctx.host.database('mdl_'))
        self.assertEqual(self.raw_cron, self.old.old.old.f.cron.read_bytes())
        self.assertEqual(b'qualified palette and compact chrome', (self.old.old.old.f.d.web / self.new).read_bytes())
        self.assertEqual(self.old_journal_bytes, self.old.journal.path.read_bytes())

    def test_actual_process_loss_after_public_boundary_keeps_new_writes(self):
        pid = os.fork()
        if pid == 0:
            original = self.journal.checkpoint
            def crash(scope, phase, **fields):
                row = original(scope, phase, **fields)
                if phase == 'public_release_committed': os._exit(73)
                return row
            self.journal.checkpoint = crash; self.op.upgrade(); os._exit(74)
        _, status = os.waitpid(pid, 0); self.assertEqual(73, os.waitstatus_to_exitcode(status))
        p = self.old.old.old.f.d.p.DATA / 'new-public-write'; p.write_bytes(b'keep newer business writes')
        self.assertEqual('app_ready', self.op.recover()['phase'])
        self.assertEqual(b'keep newer business writes', p.read_bytes())
        self.assertEqual('2026102700', self.ctx.host.database('mdl_')['addons']['theme_ustar'])

    def test_late_restore_requires_both_flags_and_restores2800(self):
        self.op.upgrade()
        p = self.old.old.old.f.d.p.DATA / 'new-public-write'; p.write_bytes(b'explicit discard only')
        for flags in ({}, {'outage': True}, {'discard': True}):
            with self.subTest(flags=flags), self.assertRaises(c.Refusal): self.op.restore(**flags)
        self.assertTrue(p.exists())
        self.assertEqual('baseline_restored', self.op.restore(outage=True, discard=True)['phase'])
        self.assertFalse(p.exists()); self.assertEqual(self.baseline, self.ctx.host.database('mdl_'))
        self.assertEqual(self.raw_cron, self.old.old.old.f.cron.read_bytes())
        self.assertEqual(self.old_journal_bytes, self.old.journal.path.read_bytes())


class Predecessor(unittest.TestCase):
    setUp = Application.setUp
    # Each test exercises the new guard with real2800journal/source/cron images.
    def guard(self):
        from unittest.mock import patch
        host = self.module.Host(self.ctx)
        patcher = patch.object(host, 'previous_context', return_value=self.previous); patcher.start(); self.addCleanup(patcher.stop)
        # The native worker/runtime and pinned2000 source are separate fixtures.
        patcher = patch.object(host, 'verify_runtime', return_value=None); patcher.start(); self.addCleanup(patcher.stop)
        patcher = patch.object(host, 'accepted_cron', return_value=self.raw_cron); patcher.start(); self.addCleanup(patcher.stop)
        return host

    def test_predecessor_uses2800_target_preserves_current_task_policy(self):
        host = self.guard(); controls = host.predecessor()
        self.assertEqual(self.controls, controls); self.assertEqual(c.sha(self.raw_cron), host.accepted_cron_sha)
        self.assertEqual(self.old_journal_bytes, self.old.journal.path.read_bytes())

    def test_missing2800_journal_refuses_without_creating_successor_state(self):
        host = self.guard(); self.old.journal.path.unlink()
        before = {str(p): p.read_bytes() for p in self.old.old.old.f.root.rglob('*') if p.is_file()}
        with self.assertRaisesRegex(c.Refusal, 'accepted_Colors2800_required'): host.predecessor()
        self.assertEqual(before, {str(p): p.read_bytes() for p in self.old.old.old.f.root.rglob('*') if p.is_file()})
        self.assertFalse(self.ctx.engine.base.exists())

    def test_rewritten_live_cron_and_predecessor_receipt_cannot_bless_new_code(self):
        from connect_con02_state import capture, descriptor
        host = self.guard(); self.old.old.old.f.cron.write_bytes(self.raw_cron + b'# unreviewed executable code\n')
        saved = self.old.journal.load()
        saved['app']['files']['cron_script']['after'] = descriptor(capture(self.old.old.old.f.cron))
        (self.old.journal.folder(saved['app']) / 'cron_script.after').write_bytes(self.old.old.old.f.cron.read_bytes())
        self.old.journal.save(saved)
        with self.assertRaisesRegex(c.Refusal, 'accepted_cron_journal_changed'): host.predecessor()

    def test_stale_source_and_cron_are_refused_without_changes(self):
        host = self.guard(); path = self.old.old.old.f.d.web / self.old.new
        raw = path.read_bytes(); path.write_bytes(b'unreviewed studio source')
        with self.assertRaisesRegex(ValueError, 'source_drift'): host.predecessor()
        self.assertEqual(b'unreviewed studio source', path.read_bytes()); path.write_bytes(raw)
        self.old.old.old.f.cron.write_bytes(self.raw_cron + b'#unreviewed')
        with self.assertRaises(c.Refusal): host.predecessor()
        self.assertEqual(self.raw_cron + b'#unreviewed', self.old.old.old.f.cron.read_bytes())
        self.assertEqual(self.old_journal_bytes, self.old.journal.path.read_bytes())

    def test_database_schema_and_theme_drift_are_refused(self):
        host = self.guard(); path = self.old.old.old.f.d.p.PGDATA / 'db.json'
        for kind in ('schema', 'theme'):
            db = copy.deepcopy(self.baseline)
            if kind == 'schema': db['schema'][0][2] = 'text'
            else: db['addons']['theme_ustar'] = '2026100202'
            path.write_text(json.dumps(db))
            with self.subTest(kind=kind), self.assertRaises(c.Refusal): host.predecessor()

    def test_readonly_check_creates_no_state_and_target_check_accepts2801(self):
        before = {str(p): (p.stat().st_ino, p.read_bytes()) for p in self.old.old.old.f.root.rglob('*') if p.is_file()}
        result = self.module.check(self.ctx)
        self.assertEqual('ready_for_viewport_update', result['phase']); self.assertEqual('2026102800', result['local_ustar'])
        after = {str(p): (p.stat().st_ino, p.read_bytes()) for p in self.old.old.old.f.root.rglob('*') if p.is_file()}
        self.assertEqual(before, after); self.assertFalse(self.ctx.engine.base.exists())
        self.op.upgrade(); result = self.module.check(self.ctx)
        self.assertEqual('application_ready', result['phase']); self.assertEqual('2026102801', result['local_ustar'])


class ActualCodePermissions(unittest.TestCase):
    setUp = Application.setUp
    def test_canvas_source_is_readable_but_not_writable_by_uid33(self):
        import shutil
        mandatory = os.environ.get('USTAR_REQUIRE_VIEWPORT_UID33') == '1'
        if os.geteuid() != 0 or not shutil.which('setpriv'):
            if mandatory: self.fail('actual UID33 requires root and setpriv')
            self.skipTest('actual UID33 requires root and setpriv')
        root = self.old.old.old.f.d.web / 'local/ustar'; self.old.old.old.f.root.chmod(0o755)
        for p in root.parents:
            if p == self.old.old.old.f.root: break
            p.chmod(0o755)
        try: os.chown(root, 0, 33)
        except OSError:
            if mandatory: raise
            self.skipTest('namespace cannot map GID33')
        root.chmod(0o755); self.assertEqual('app_ready', self.op.upgrade()['phase'])
        p = self.old.old.old.f.d.web / self.new
        result = subprocess.run(['setpriv', '--reuid=33', '--regid=33', '--clear-groups', sys.executable, '-I', '-c',
            'import pathlib,sys;p=pathlib.Path(sys.argv[1]);assert p.read_bytes()==b"qualified canvas zoom and independent panels";'
            '\ntry:p.write_bytes(b"bad")\nexcept PermissionError:pass\nelse:raise AssertionError("web user wrote code")', str(p)],
            capture_output=True, text=True, timeout=10)
        self.assertEqual(0, result.returncode, result.stderr)


class RuntimeAdmission(unittest.TestCase):
    """Read-only new adapter admission of actual v3 files, worker ZIP and SQLite."""
    @classmethod
    def setUpClass(cls):
        import test_connect_telegram_update as f
        f.Runtime.setUpClass(); cls.fixture = f.Runtime

    def setUp(self):
        from types import SimpleNamespace
        from connect_con02_runtime import Runtime
        import test_connect_con02_profiles as profiles
        self.f = self.fixture(methodName='runTest'); self.f.setUp(); self.addCleanup(self.f.doCleanups)
        self.assertEqual('runtime_active', Runtime(self.f.ctx, self.f.journal, self.f.locks).enable()['phase'])
        installation = profiles.Profiles.legacy['connect_install'].Installation(root=self.f.root)
        self.f.host.legacy = lambda: SimpleNamespace(installation=installation)
        accepted2800_parent = SimpleNamespace(host=SimpleNamespace(previous_context=lambda: self.f.ctx))
        self.previous = SimpleNamespace(host=SimpleNamespace(previous_context=lambda: accepted2800_parent))
        parent = self.previous
        self.previous = SimpleNamespace(host=SimpleNamespace(previous_context=lambda: parent))
        self.host = adapter().Host(SimpleNamespace())

    def test_runtime_admission_retains_worker_keys_sqlite_and_journals(self):
        def files():
            return {str(p): (p.stat().st_ino, p.read_bytes()) for p in self.f.root.rglob('*') if p.is_file()}
        before = files(); self.host.verify_runtime(self.previous); self.assertEqual(before, files())

    def test_disabled_runtime_and_rewritten_worker_are_refused(self):
        saved = self.f.journal.load(); bad = copy.deepcopy(saved); bad['runtime']['phase'] = 'runtime_disabled'
        self.f.journal.save(bad)
        with self.assertRaisesRegex(c.Refusal, 'accepted_Telegram2300_required'): self.host.verify_runtime(self.previous)
        self.f.journal.save(saved)
        path = self.f.paths['pointer'].resolve() / 'receiver.pyz'; path.write_bytes(b'unreviewed worker')
        manifest_path = path.parent / 'manifest.json'; manifest = json.loads(manifest_path.read_bytes())
        manifest['files']['receiver.pyz'] = c.sha(path.read_bytes()); manifest_path.write_text(json.dumps(manifest))
        with self.assertRaisesRegex(c.Refusal, 'accepted_worker_changed'): self.host.verify_runtime(self.previous)


if __name__ == '__main__': unittest.main()
