"""Bounded2801→2802 delivery; real files/journals, external Docker/SQL modeled."""
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
import test_studio_viewport_update as prior


def adapter():
    assert importlib.util.find_spec('perf_query_update'), 'Separate2801-to2802 operator required'
    import perf_query_update
    return perf_query_update


def builder():
    assert importlib.util.find_spec('build_perf_query_update'), 'Exact-Git2802 packager required'
    import build_perf_query_update
    return build_perf_query_update


class Contract(unittest.TestCase):
    def test_cron_advances_only_an_exact_accepted2801_script_and_record(self):
        raw = b"# preserved policy\nVERSIONS = {'local_ustar': '2026102801', 'theme_ustar': '2026102700'}\n"
        record = {'schema': 1, 'script_sha256': c.sha(raw),
                  'versions': {'local_ustar': '2026102801', 'theme_ustar': '2026102700'}, 'owner': 'preserved'}
        after, info = adapter().patch_cron(raw, record, accepted_sha=c.sha(raw))
        self.assertEqual(b"# preserved policy\nVERSIONS = {'local_ustar': '2026102802', 'theme_ustar': '2026102700'}\n", after)
        self.assertEqual({'local_ustar': '2026102802', 'theme_ustar': '2026102700'}, info['versions'])
        self.assertEqual('preserved', info['owner']); self.assertEqual(c.sha(after), info['script_sha256'])
        for bad in (raw + b'#foreign', raw + raw, raw.replace(b'2801', b'2800')):
            with self.subTest(bad=bad), self.assertRaises(c.Refusal):
                adapter().patch_cron(bad, record, accepted_sha=c.sha(raw))
        with self.assertRaises(c.Refusal):
            adapter().patch_cron(raw, {**record, 'versions': {'local_ustar': '2026102800', 'theme_ustar': '2026102700'}}, accepted_sha=c.sha(raw))

    def test_cron_chain_derives2801_from_pinned2000_without_adopting_live_code(self):
        from unittest.mock import patch
        import connect_con02_application as app
        raw = b"# fixed\nVERSIONS = {'local_ustar': '2026102000', 'theme_ustar': '2026100202'}\n"
        record = {'schema': 1, 'script_sha256': c.sha(raw),
                  'versions': {'local_ustar': '2026102000', 'theme_ustar': '2026100202'}}
        with patch.object(app, 'CRON_BEFORE_SHA', c.sha(raw)):
            self.assertEqual(b"# fixed\nVERSIONS = {'local_ustar': '2026102801', 'theme_ustar': '2026102700'}\n", adapter().derive_cron(raw, record))
            with self.assertRaises(c.Refusal): adapter().derive_cron(raw + b'#foreign', record)

    def test_accepted_cron_comes_from_frozen_predecessor_not_live_receipt(self):
        from types import SimpleNamespace
        from unittest.mock import patch
        raw = b"VERSIONS = {'local_ustar': '2026102800', 'theme_ustar': '2026102700'}\n"
        host = adapter().Host(SimpleNamespace())
        previous = SimpleNamespace(host=SimpleNamespace(accepted_cron=lambda: raw))
        with patch.object(host, 'previous_context', return_value=previous):
            self.assertEqual(b"VERSIONS = {'local_ustar': '2026102801', 'theme_ustar': '2026102700'}\n", host.accepted_cron())
        previous.host.accepted_cron = lambda: raw + raw
        with patch.object(host, 'previous_context', return_value=previous), self.assertRaises(c.Refusal): host.accepted_cron()

    def test_default_is_offline_and_destructive_modes_require_flags(self):
        from unittest.mock import patch
        module = adapter()
        with patch.object(module, 'make_context', create=True, side_effect=AssertionError('host touched')):
            out = io.StringIO()
            with contextlib.redirect_stdout(out): self.assertEqual(0, module.main([]))
            self.assertEqual({'phase': 'PLAN_ONLY', 'host_access': False, 'network_used': False}, json.loads(out.getvalue()))
            for args in (['--upgrade'], ['--restore'], ['--recover', '--acknowledge-outage'], ['--check', '--status']):
                with self.subTest(args=args), contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit): module.main(args)


class Reference(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.bundle = builder().reference_from_git(ROOT, adapter().APP_COMMIT)

    def test_only_four_changes_and_one_added_native_test_with_unchanged_schema_worker(self):
        ref = self.bundle.reference
        self.assertEqual('a3df57831a5ed49b901e5116ee800f2217fb61e9', ref['baseline_commit'])
        self.assertEqual('81118e39f54c9ac34475f640bf2b9d4d8a047f24', self.bundle.predecessor.reference['tool_commit'])
        self.assertEqual('6c56a5b6ab830b97b706a5d7028b49cfae31e7a4', ref['worker_commit'])
        before, after = ref['baseline'], ref['candidate']
        self.assertEqual(589, len(before['files'])); self.assertEqual(590, len(self.bundle.payload))
        self.assertEqual({'local_ustar': 2026102801, 'theme_ustar': 2026102700}, before['versions'])
        self.assertEqual({'local_ustar': 2026102802, 'theme_ustar': 2026102700}, after['versions'])
        self.assertEqual({'local/ustar/tests/query_batching_test.php'}, set(after['files']) - set(before['files']))
        self.assertEqual({'local/ustar/classes/competition.php', 'local/ustar/classes/feed_article_resolver.php',
                         'local/ustar/feed_admin.php', 'local/ustar/version.php'},
                         {n for n in before['files'] if before['files'][n] != after['files'][n]})
        for key in ('tables', 'schema_fields', 'schema_indexes'): self.assertEqual(before[key], after[key])
        self.assertEqual(100, len(after['tables']))

    def test_foreign_source_missing_added_file_schema_version_or_worker_is_refused(self):
        for mutate in (lambda r: r['candidate']['files'].__setitem__('local/ustar/connect.php', 'f'*64),
                       lambda r: r['candidate']['files'].pop('local/ustar/tests/query_batching_test.php'),
                       lambda r: r['candidate']['tables'].append('unknown_table'),
                       lambda r: r['candidate']['versions'].__setitem__('theme_ustar', 2026100202),
                       lambda r: r.__setitem__('worker_commit', r['app_commit'])):
            bad = copy.deepcopy(self.bundle.reference); mutate(bad)
            with self.subTest(mutate=mutate), self.assertRaises(c.Refusal): adapter().validate_reference(bad)

    def test_context_uses_new_journal_without_host_access(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td); host = object(); ctx = adapter().make_context(self.bundle, root=root, uid=os.getuid(), host=host)
            self.assertEqual(root/'var/lib/ustar-perf-query-update', ctx.engine.base)
            self.assertIs(host, ctx.host); self.assertEqual([], list(root.iterdir()))


class Package(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.b = builder(); cls.tmp = tempfile.TemporaryDirectory(); cls.addClassCleanup(cls.tmp.cleanup)
        cls.root = Path(cls.tmp.name)
        env = {**os.environ, 'GIT_INDEX_FILE': str(cls.root/'index'), 'GIT_AUTHOR_NAME': 'Perf fixture',
               'GIT_AUTHOR_EMAIL': 'fixture@example.invalid', 'GIT_COMMITTER_NAME': 'Perf fixture', 'GIT_COMMITTER_EMAIL': 'fixture@example.invalid'}
        def git(*args): return subprocess.check_output(['git','-C',str(ROOT),*args],env=env,stderr=subprocess.DEVNULL).decode().strip()
        frozen = adapter().APP_COMMIT
        git('read-tree', frozen)
        git('add', 'scripts/infra/perf_query_update.py', 'scripts/infra/build_perf_query_update.py')
        cls.commit = git('commit-tree', git('write-tree'), '-p', frozen, '-m', 'Perf2802 package fixture')
        with contextlib.redirect_stdout(io.StringIO()): cls.artifact = cls.b.build(cls.commit, cls.root/'one')

    def envelope(self):
        constants = {n.targets[0].id: ast.literal_eval(n.value) for n in ast.parse(self.artifact.read_text()).body
            if isinstance(n,ast.Assign) and isinstance(n.targets[0],ast.Name)
            and n.targets[0].id in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON')}
        return [json.loads(constants[n]) for n in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON')]

    def test_nested_version_baselines_round_trip_without_shadowing(self):
        manifest, sources, assets = self.envelope()
        bundle = adapter().load_embedded(manifest,sources,assets)
        self.assertEqual(self.commit,bundle.reference['tool_commit'])
        self.assertIn(b'2026102800',base64.b64decode(assets['baseline/local/ustar/version.php']))
        self.assertIn(b'2026102801',base64.b64decode(assets['baseline2801/local/ustar/version.php']))
        self.assertIn(b'2026102802',base64.b64decode(assets['payload/local/ustar/version.php']))

    def test_tampered_baseline_payload_predecessor_and_extra_asset_are_refused(self):
        manifest, sources, assets = self.envelope()
        for name in ('baseline2801/local/ustar/version.php','payload/local/ustar/tests/query_batching_test.php','viewport.json'):
            bad = dict(assets); bad[name] = base64.b64encode(b'unknown').decode()
            with self.subTest(name=name),self.assertRaises(c.Refusal): adapter().load_embedded(manifest,sources,bad)
        bad = copy.deepcopy(manifest); bad['worker_commit'] = bad['app_commit']
        with self.assertRaises(c.Refusal): adapter().load_embedded(bad,sources,assets)
        raw=b'foreign'; bad=dict(assets); bad['extra.json']=base64.b64encode(raw).decode()
        changed=copy.deepcopy(manifest); changed['assets']['extra.json']={'bytes':len(raw),'sha256':c.sha(raw)}
        with self.assertRaises(c.Refusal): adapter().load_embedded(changed,sources,bad)

    def test_isolated_default_ignores_poisoned_siblings(self):
        (self.artifact.parent/'perf_query_update.py').write_text('raise RuntimeError("SIBLING_IMPORTED")')
        r=subprocess.run([sys.executable,'-I','-B',str(self.artifact)],capture_output=True,text=True,timeout=30)
        self.assertEqual(0,r.returncode,r.stderr)
        self.assertEqual({'phase':'PLAN_ONLY','host_access':False,'network_used':False},json.loads(r.stdout))

    def test_archive_is_deterministic_private_and_hash_complete(self):
        with contextlib.redirect_stdout(io.StringIO()): other=self.b.build(self.commit,self.root/'two')
        one=next(self.artifact.parent.glob('*.tar.gz')); two=next(other.parent.glob('*.tar.gz'))
        self.assertEqual(one.read_bytes(),two.read_bytes())
        with tarfile.open(one) as archive:
            self.assertEqual({'update.py','README.txt','INSTALL.json','SHA256SUMS'},set(archive.getnames()))
            self.assertTrue(all(m.isfile() and m.mode==0o600 and m.uid==m.gid==0 for m in archive))
            files={m.name:archive.extractfile(m).read() for m in archive}
        for row in files['SHA256SUMS'].decode().splitlines():
            digest,name=row.split('  ',1); self.assertEqual(digest,c.sha(files[name]))
        self.assertEqual(590,json.loads(files['INSTALL.json'])['payload_files'])
        self.assertFalse(json.loads(files['INSTALL.json'])['worker_rollout'])

    def test_old_application_tree_cannot_build_the_new_operator(self):
        with self.assertRaisesRegex(c.Refusal,'tool_qualified_component_mismatch'):
            self.b.build('81118e39f54c9ac34475f640bf2b9d4d8a047f24',self.root/'bad')


class Application(unittest.TestCase):
    def setUp(self):
        import connect_con02_application as a
        import connect_con02_state as state
        from types import MethodType,SimpleNamespace
        self.module=adapter(); self.old=prior.Application(methodName='runTest'); self.old.setUp(); self.addCleanup(self.old.doCleanups)
        self.assertEqual('app_ready',self.old.op.upgrade()['phase'])
        oldctx=self.old.ctx; self.f=self.old.old.old.old.f; d=self.f.d; self.engine=d
        self.old_journal_bytes=self.old.journal.path.read_bytes()
        oldengine=copy.copy(d); oldengine.reference=copy.deepcopy(d.reference)
        previous_host=type(oldctx.host)(oldengine,oldengine.identities,io.BytesIO())
        self.previous=c.Context(oldctx.bundle,oldctx.root,oldctx.uid,previous_host,oldengine)
        self.baseline=copy.deepcopy(oldctx.host.database('mdl_')); self.baseline['tasks']['prune']=[0,1]
        (d.p.PGDATA/'db.json').write_text(json.dumps(self.baseline))
        d.reference={'baseline':copy.deepcopy(d.reference['candidate']),'candidate':copy.deepcopy(d.reference['candidate'])}
        d.reference['candidate']['versions']={'local_ustar':2026102802,'theme_ustar':2026102700}
        self.new='local/ustar/tests/query_batching_test.php'; self.raw=b'<?php // qualified query batching\n'
        d.payload={**d.payload,self.new:self.raw}; d.reference['candidate']['files']={n:c.sha(v) for n,v in d.payload.items()}
        d.base=self.f.root/'perf2802'; d.marker=d.base/'uninitialized.json'; d.attempt=0
        ref={**d.reference,'tool_commit':'e'*40,'app_commit':self.module.APP_COMMIT}
        self.ctx=c.Context(SimpleNamespace(reference=ref),oldctx.root,oldctx.uid,oldctx.host,d)
        self.ctx.verify_database=self.module.verify_database; self.ctx.baseline_ack_enabled=True
        self.raw_cron=self.f.cron.read_bytes()
        self.ctx.patch_cron=lambda raw,record:self.module.patch_cron(raw,record,accepted_sha=c.sha(self.raw_cron))
        def upgrade(host):
            d.attempt+=1; db=host.database('mdl_'); db['addons'].update(local_ustar='2026102802',theme_ustar='2026102700')
            (d.p.PGDATA/'db.json').write_text(json.dumps(db))
            if d.attempt==d.fail_at: raise c.Refusal('synthetic_SQL_failure')
        self.ctx.host.cli_upgrade=MethodType(upgrade,self.ctx.host)
        self.journal=state.Journal(self.ctx); self.locks=state.Locks(self.ctx); self.op=a.Application(self.ctx,self.journal,self.locks)
        self.controls=self.ctx.host.controls(None); self.services=copy.deepcopy(d.services)

    def test_upgrade_repeat_adds_file_preserves_policy_receipt_and_services(self):
        self.assertEqual('2026102802',self.op.upgrade()['local_ustar'])
        self.assertEqual(self.raw,(self.engine.web/self.new).read_bytes())
        db=self.ctx.host.database('mdl_')
        for key in ('schema','indexes','tasks'): self.assertEqual(self.baseline[key],db[key])
        self.assertEqual('2026102700',db['addons']['theme_ustar'])
        self.assertTrue(self.op.upgrade()['verify_only']); self.assertEqual(2,self.engine.attempt)
        self.assertEqual(self.old_journal_bytes,self.old.journal.path.read_bytes())
        self.assertEqual(self.services,self.engine.services)

    def test_failure_before_release_restores_exact_pair_cron_and_removes_added_file(self):
        self.engine.fail_at=2; result=self.op.upgrade()
        self.assertEqual('baseline_restored',result['phase']); self.assertFalse(result['upgrade_succeeded'])
        self.assertEqual(self.baseline,self.ctx.host.database('mdl_')); self.assertEqual(self.raw_cron,self.f.cron.read_bytes())
        self.assertFalse((self.engine.web/self.new).exists()); self.assertEqual(self.old_journal_bytes,self.old.journal.path.read_bytes())

    def test_process_loss_after_publication_recovers_without_discarding_new_writes(self):
        pid=os.fork()
        if pid==0:
            original=self.journal.checkpoint
            def crash(scope,phase,**fields):
                row=original(scope,phase,**fields)
                if phase=='public_release_committed': os._exit(73)
                return row
            self.journal.checkpoint=crash; self.op.upgrade(); os._exit(74)
        _,status=os.waitpid(pid,0); self.assertEqual(73,os.waitstatus_to_exitcode(status))
        path=self.engine.p.DATA/'new-public-write'; path.write_bytes(b'preserve new business writes')
        self.assertEqual('app_ready',self.op.recover()['phase']); self.assertEqual(b'preserve new business writes',path.read_bytes())
        self.assertEqual(self.raw,(self.engine.web/self.new).read_bytes())

    def test_late_restore_requires_both_flags_and_removes_added_file(self):
        self.op.upgrade()
        for flags in ({},{'outage':True},{'discard':True}):
            with self.subTest(flags=flags),self.assertRaises(c.Refusal): self.op.restore(**flags)
        self.assertEqual('baseline_restored',self.op.restore(outage=True,discard=True)['phase'])
        self.assertFalse((self.engine.web/self.new).exists()); self.assertEqual(self.baseline,self.ctx.host.database('mdl_'))
        self.assertEqual(self.raw_cron,self.f.cron.read_bytes()); self.assertEqual(self.old_journal_bytes,self.old.journal.path.read_bytes())


class Predecessor(unittest.TestCase):
    setUp=Application.setUp
    def guard(self):
        from unittest.mock import patch
        host=self.module.Host(self.ctx)
        for name,value in [('previous_context',self.previous),('verify_runtime',None),('accepted_cron',self.raw_cron)]:
            p=patch.object(host,name,return_value=value); p.start(); self.addCleanup(p.stop)
        return host

    def test_missing2801_receipt_refuses_without_creating_state(self):
        host=self.guard(); self.old.journal.path.unlink()
        before={str(p):p.read_bytes() for p in self.f.root.rglob('*') if p.is_file()}
        with self.assertRaisesRegex(c.Refusal,'accepted_Viewport2801_required'): host.predecessor()
        self.assertEqual(before,{str(p):p.read_bytes() for p in self.f.root.rglob('*') if p.is_file()})
        self.assertFalse(self.ctx.engine.base.exists())

    def test_accepted2801_preserves_new_owner_task_policy(self):
        host=self.guard(); self.assertEqual(self.controls,host.predecessor())
        self.assertEqual(c.sha(self.raw_cron),host.accepted_cron_sha)
        self.assertEqual(self.old_journal_bytes,self.old.journal.path.read_bytes())

    def test_rewritten_live_cron_and_receipt_cannot_bless_foreign_code(self):
        from connect_con02_state import capture,descriptor
        host=self.guard(); self.f.cron.write_bytes(self.raw_cron+b'#foreign executable code\n')
        saved=self.old.journal.load(); saved['app']['files']['cron_script']['after']=descriptor(capture(self.f.cron))
        (self.old.journal.folder(saved['app'])/'cron_script.after').write_bytes(self.f.cron.read_bytes()); self.old.journal.save(saved)
        with self.assertRaisesRegex(c.Refusal,'accepted_cron_journal_changed'): host.predecessor()

    def test_source_and_schema_drift_are_refused_without_changes(self):
        host=self.guard(); path=self.engine.web/self.old.new; original=path.read_bytes(); path.write_bytes(b'foreign source')
        with self.assertRaisesRegex(ValueError,'source_drift'): host.predecessor()
        self.assertEqual(b'foreign source',path.read_bytes()); path.write_bytes(original)
        db=copy.deepcopy(self.baseline); db['schema'][0][2]='text'; (self.engine.p.PGDATA/'db.json').write_text(json.dumps(db))
        with self.assertRaises(c.Refusal): host.predecessor()

    def test_check_is_readonly_before_upgrade_and_accepts_target_after(self):
        before={str(p):(p.stat().st_ino,p.read_bytes()) for p in self.f.root.rglob('*') if p.is_file()}
        result=self.module.check(self.ctx)
        self.assertEqual('ready_for_perf_update',result['phase']); self.assertEqual('2026102801',result['local_ustar'])
        self.assertEqual(before,{str(p):(p.stat().st_ino,p.read_bytes()) for p in self.f.root.rglob('*') if p.is_file()})
        self.assertFalse(self.ctx.engine.base.exists()); self.op.upgrade()
        result=self.module.check(self.ctx); self.assertEqual('application_ready',result['phase']); self.assertEqual('2026102802',result['local_ustar'])


class ActualCodePermissions(unittest.TestCase):
    setUp=Application.setUp
    def test_query_source_is_readable_but_not_writable_by_actual_uid33(self):
        import shutil
        mandatory=os.environ.get('USTAR_REQUIRE_PERF_UID33')=='1'
        if os.geteuid()!=0 or not shutil.which('setpriv'):
            if mandatory:self.fail('actual UID33 requires root and setpriv')
            self.skipTest('actual UID33 requires root and setpriv')
        root=self.engine.web/'local/ustar'; self.f.root.chmod(0o755)
        for p in root.parents:
            if p==self.f.root:break
            p.chmod(0o755)
        try:os.chown(root,0,33)
        except OSError:
            if mandatory:raise
            self.skipTest('namespace cannot map GID33')
        root.chmod(0o755); self.assertEqual('app_ready',self.op.upgrade()['phase'])
        code='import pathlib,sys;p=pathlib.Path(sys.argv[1]);assert p.read_bytes()==b"<?php // qualified query batching\\n";\ntry:p.write_bytes(b"bad")\nexcept PermissionError:pass\nelse:raise AssertionError("web user wrote code")'
        result=subprocess.run(['setpriv','--reuid=33','--regid=33','--clear-groups',sys.executable,'-I','-c',code,str(self.engine.web/self.new)],capture_output=True,text=True,timeout=10)
        self.assertEqual(0,result.returncode,result.stderr)


class RuntimeAdmission(prior.RuntimeAdmission):
    def setUp(self):
        from types import SimpleNamespace
        super().setUp(); previous=self.previous
        self.previous=SimpleNamespace(host=SimpleNamespace(previous_context=lambda:previous))
        self.host=adapter().Host(SimpleNamespace())


if __name__=='__main__':unittest.main()
