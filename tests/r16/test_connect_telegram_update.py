"""Successor uses the accepted v2 runtime without losing outstanding personal ACKs."""
import copy
import contextlib
import io
import importlib.util
import json
import os
from pathlib import Path
import sys
import subprocess
import tempfile
from types import SimpleNamespace
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path[:0] = [str(ROOT/'scripts/infra'), str(ROOT/'tests/r16')]
import connect_con02_contract as c
import connect_con02_profiles as v2
import connect_con02_state as state
import test_connect_con02_profiles as fixture


class Successor(unittest.TestCase):
    @classmethod
    def setUpClass(cls): fixture.Profiles.setUpClass()

    def setUp(self):
        f = fixture.Profiles(); f.setUp(); self.ctx = f.ctx
        values = v2.profiles(f.ctx, f.before, generation='a'*32, cutoff=200,
                             notify_secret=b'33'*32, ack_secret=b'44'*32)
        self.before = copy.deepcopy(f.before)
        self.before['files'].update({n: {'raw': raw} for n, raw in values.items()})
        self.original = copy.deepcopy(self.before)

    def module(self):
        self.assertIsNotNone(importlib.util.find_spec('connect_telegram_update'),
                             'separate successor, not a changed 4394 artifact')
        import connect_telegram_update as t
        return t

    def test_v3_preserves_existing_ack_key_cutoff_and_fence(self):
        after = self.module().profiles(self.ctx, self.before, generation='b'*32,
            cutoff=999, notify_secret=b'55'*32, ack_secret=b'66'*32)
        self.assertEqual({'include', 'notifications', 'notify_key'}, set(after))
        self.assertTrue(after['include'].startswith(self.original['files']['include']['raw']))
        self.assertIn(b'ustar_connect_ack_since=200;', after['include'])
        self.assertNotIn(b'ustar_connect_ack_since=999;', after['include'])
        self.assertIn(b'ustar_connect_notification_presentation_enabled=true;', after['include'])
        self.assertIn(b"['notification.lease','notification.begin_v3','notification.finish']", after['include'])
        cfg = json.loads(after['notifications'])
        self.assertEqual(3, cfg['notification_contract'])
        self.assertEqual('tg_notify_v3', cfg['bridge_key_id'])
        self.assertEqual(['12345'], cfg['notification_subjects'])
        self.assertEqual(self.original, self.before)

    def test_refuses_downgraded_or_disabled_ack_baseline(self):
        t = self.module()
        for key, value in [('notification_contract', 1), ('bridge_key_id', 'unknown')]:
            changed = copy.deepcopy(self.before)
            cfg = json.loads(changed['files']['notifications']['raw']); cfg[key] = value
            changed['files']['notifications']['raw'] = json.dumps(cfg).encode()
            with self.subTest(key=key), self.assertRaises(c.Refusal):
                t.profiles(self.ctx, changed, generation='b'*32, cutoff=999,
                           notify_secret=b'55'*32, ack_secret=b'66'*32)
        self.before['files']['include']['raw'] = self.before['files']['include']['raw'].replace(
            b'ustar_connect_ack_enabled=true', b'ustar_connect_ack_enabled=false')
        with self.assertRaises(c.Refusal):
            t.profiles(self.ctx, self.before, generation='b'*32, cutoff=999,
                       notify_secret=b'55'*32, ack_secret=b'66'*32)

    def test_new_sender_secret_cannot_reuse_ack_or_link_secret(self):
        t = self.module()
        for secret in (b'11'*32, b'44'*32, b'ZZ'*32):
            with self.subTest(secret=secret[:2]), self.assertRaises(c.Refusal):
                t.profiles(self.ctx, self.before, generation='b'*32, cutoff=999,
                           notify_secret=secret, ack_secret=b'66'*32)

    def test_cron_changes_only_version_and_recorded_digest(self):
        t = self.module()
        raw = b"# accepted custom cron flags\nVERSIONS = {'local_ustar': '2026102200', 'theme_ustar': '2026100202'}\n"
        install = {'schema': 1, 'versions': {'local_ustar':'2026102200','theme_ustar':'2026100202'},
                   'script_sha256': c.sha(raw), 'instance': 'preserved'}
        new, record = t.patch_cron(raw, install, accepted_sha=c.sha(raw))
        self.assertEqual(raw.replace(b'2026102200', b'2026102300'), new)
        self.assertEqual('preserved', record['instance'])
        self.assertEqual({'local_ustar':'2026102300','theme_ustar':'2026100202'}, record['versions'])
        self.assertEqual(c.sha(new), record['script_sha256'])
        with self.assertRaises(c.Refusal): t.patch_cron(raw+b'#drift', install, accepted_sha=c.sha(raw))

    def test_successor_journal_roundtrip_and_wrong_release_denial(self):
        t = self.module()
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            ctx = c.Context(SimpleNamespace(reference={'tool_commit':'a'*40, 'app_commit':t.APP_COMMIT}),
                            root, os.getuid(), None, SimpleNamespace(base=root/'state'))
            journal = state.Journal(ctx)
            journal.begin('app', {'files':{}}, {'files':{}}, generation='c'*32)
            self.assertEqual(t.APP_COMMIT, journal.load()['app_commit'])
            ctx.bundle.reference['app_commit'] = c.APP_COMMIT
            with self.assertRaises(c.Refusal): journal.load()

    def test_handback_worker_cannot_adopt_a_rewritten_manifest(self):
        t=self.module()
        self.assertTrue(hasattr(t.Host,'verify_predecessor_worker'),'read-only exact handback worker verification')
        from connect_con02_runtime import stage_worker
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);(root/'opt/ustar-connect/releases').mkdir(parents=True)
            ctx=c.Context(self.ctx.bundle,root,os.getuid(),SimpleNamespace(validate_units=lambda v:None),None)
            release=stage_worker(ctx);(release.parent.parent/'current').symlink_to(release)
            installation=fixture.Profiles.legacy['connect_install'].Installation(root=root)
            previous=SimpleNamespace(bundle=ctx.bundle,host=SimpleNamespace(legacy=lambda:SimpleNamespace(installation=installation)))
            host=t.Host(SimpleNamespace());host.verify_predecessor_worker(previous)
            changed=release/'receiver.pyz';changed.write_bytes(b'unknown executable')
            path=release/'manifest.json';manifest=json.loads(path.read_bytes())
            manifest['files']['receiver.pyz']=c.sha(changed.read_bytes());path.write_text(json.dumps(manifest))
            with self.assertRaisesRegex(c.Refusal,'accepted_worker_changed'):host.verify_predecessor_worker(previous)


class Package(unittest.TestCase):
    def builder(self):
        self.assertIsNotNone(importlib.util.find_spec('build_connect_telegram_update'), 'exact Git successor builder')
        import build_connect_telegram_update as b
        return b

    def test_embedded_release_is_2200_to_2300_with_unchanged_100_tables(self):
        import connect_telegram_update as t
        bundle = self.builder().reference_from_git(ROOT, '6c56a5b6ab830b97b706a5d7028b49cfae31e7a4')
        self.assertEqual(2026102200, bundle.reference['baseline']['versions']['local_ustar'])
        self.assertEqual(2026102300, bundle.reference['candidate']['versions']['local_ustar'])
        self.assertEqual(t.PREDECESSOR_TOOL, bundle.predecessor.reference['tool_commit'])
        self.assertEqual(100, len(bundle.reference['candidate']['tables']))
        self.assertEqual(bundle.reference['baseline']['schema_fields'], bundle.reference['candidate']['schema_fields'])
        self.assertEqual(bundle.reference['baseline']['schema_indexes'], bundle.reference['candidate']['schema_indexes'])
        from connect_con02_runtime import worker_files, stage_worker
        self.assertEqual((t.APP_COMMIT+'\n').encode(), worker_files(bundle)['SOURCE_COMMIT'])
        with tempfile.TemporaryDirectory() as td:
            root = Path(td); (root/'opt/ustar-connect/releases').mkdir(parents=True)
            ctx = c.Context(bundle,root,os.getuid(),SimpleNamespace(validate_units=lambda v:None),None)
            self.assertEqual(t.APP_COMMIT, stage_worker(ctx).name)
        bundle.reference['worker_commit'] = c.APP_COMMIT
        with self.assertRaises(c.Refusal): worker_files(bundle)

    def package(self):
        cls=self.__class__
        if not hasattr(cls,'artifact'):
            import connect_telegram_update as t
            cls.tmp=tempfile.TemporaryDirectory();cls.addClassCleanup(cls.tmp.cleanup);cls.output=Path(cls.tmp.name)
            env={**os.environ,'GIT_INDEX_FILE':str(cls.output/'index'),
                 'GIT_AUTHOR_NAME':'Telegram fixture','GIT_AUTHOR_EMAIL':'fixture@example.invalid',
                 'GIT_COMMITTER_NAME':'Telegram fixture','GIT_COMMITTER_EMAIL':'fixture@example.invalid'}
            def git(*args):return subprocess.check_output(['git','-C',str(ROOT),*args],env=env,stderr=subprocess.DEVNULL).decode().strip()
            # This historical operator package must keep its accepted release trees.
            # Later Academy candidates (e.g. Studio) are qualified by their own gate.
            git('read-tree',t.APP_COMMIT)
            git('add','scripts/infra/build_connect_telegram_update.py','scripts/infra/build_connect_con02_update.py',
                *['scripts/infra/'+n+'.py' for n in t.MODULES if n!='production_manifest'])
            cls.commit=git('commit-tree',git('write-tree'),'-p','HEAD','-m','Telegram package private verification snapshot')
            with contextlib.redirect_stdout(io.StringIO()):cls.artifact=self.builder().build(cls.commit,cls.output/'one')
        return cls.artifact

    def test_ordinary_file_offline_default_ignores_adjacent_modules(self):
        artifact=self.package()
        poison=artifact.parent/'connect_telegram_update.py';poison.write_text('raise RuntimeError("SIBLING_IMPORTED")\n')
        result=subprocess.run([sys.executable,'-B',str(artifact)],cwd=artifact.parent,capture_output=True,text=True,timeout=45)
        self.assertEqual(0,result.returncode,result.stderr)
        self.assertEqual({'phase':'PLAN_ONLY','host_access':False,'network_used':False},json.loads(result.stdout))

    def test_changed_application_tree_is_still_denied(self):
        self.package()
        with tempfile.TemporaryDirectory() as td:
            env={**os.environ,'GIT_INDEX_FILE':str(Path(td)/'index'),
                 'GIT_AUTHOR_NAME':'Telegram fixture','GIT_AUTHOR_EMAIL':'fixture@example.invalid',
                 'GIT_COMMITTER_NAME':'Telegram fixture','GIT_COMMITTER_EMAIL':'fixture@example.invalid'}
            def git(*args,raw=None):
                return subprocess.check_output(['git','-C',str(ROOT),*args],input=raw,env=env,
                    stderr=subprocess.DEVNULL).decode().strip()
            git('read-tree',self.commit)
            path='moodle/local/ustar/version.php'
            original=subprocess.check_output(['git','-C',str(ROOT),'show',self.commit+':'+path])
            blob=git('hash-object','-w','--stdin',raw=original+b'\n// package fixture drift\n')
            git('update-index','--cacheinfo','100644',blob,path)
            changed=git('commit-tree',git('write-tree'),'-p',self.commit,'-m','Private changed-app negative fixture')
            with self.assertRaisesRegex(c.Refusal,'tool_release_component_mismatch'):
                self.builder().build(changed,Path(td)/'refused')

    def test_ordinary_file_reproducible_and_changed_payload_denied(self):
        import ast
        import connect_telegram_update as t
        first=self.package()
        with contextlib.redirect_stdout(io.StringIO()):second=self.builder().build(self.commit,self.output/'two')
        self.assertEqual(first.read_bytes(),second.read_bytes())
        constants={n.targets[0].id:ast.literal_eval(n.value) for n in ast.parse(first.read_text()).body
            if isinstance(n,ast.Assign) and isinstance(n.targets[0],ast.Name)
            and n.targets[0].id in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON')}
        m,s,a=[json.loads(constants[n]) for n in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON')]
        self.assertEqual(self.commit,t.load_embedded(m,s,a).reference['tool_commit'])
        bad=dict(a);bad['worker.tar.gz']=bad['worker.tar.gz']+'\n'
        with self.assertRaises(c.Refusal):t.load_embedded(m,s,bad)
        bad=dict(a);bad['../escape']='YQ=='
        with self.assertRaises(c.Refusal):t.load_embedded(m,s,bad)

    def test_invalid_modes_refuse_before_host_access(self):
        import connect_telegram_update as t
        from unittest.mock import patch
        with patch.object(t,'make_context',side_effect=AssertionError('host touched')):
            for argv in (['--upgrade'],['--check','--enable-presentation'],['--check','--acknowledge-outage'],
                         ['--restore'],['--recover','--discard-writes-since-snapshot'],['--root','/tmp/test']):
                with self.subTest(argv=argv),contextlib.redirect_stderr(io.StringIO()),self.assertRaises(SystemExit) as e:
                    t.main(argv)
                self.assertEqual(2,e.exception.code)


class Runtime(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        fixture.Profiles.setUpClass()
        from build_connect_telegram_update import reference_from_git
        cls.bundle=reference_from_git(ROOT,'6c56a5b6ab830b97b706a5d7028b49cfae31e7a4')

    def setUp(self):
        import test_connect_con02_runtime as f
        from connect_con02_runtime import Runtime as Operation
        import connect_telegram_update as t
        f.Runtime.setUp(self)
        self.assertEqual('runtime_active',Operation(self.ctx,self.journal,self.locks).enable()['phase'])
        self.old_journal=self.journal.path
        self.old_bytes=self.old_journal.read_bytes()
        self.v2_images={n:state.capture(p) for n,p in self.paths.items()}
        self.ack_key=self.paths['ack_key'].read_bytes()
        self.ctx=c.Context(self.bundle,self.root,os.getuid(),self.host,SimpleNamespace(base=self.root/'successor-state'))
        self.ctx.profiles=t.profiles;self.ctx.baseline_ack_enabled=True
        self.journal=state.Journal(self.ctx);self.locks=state.Locks(self.ctx)
        self.journal.begin('app',{'files':{}},{'files':{}},generation='c'*32)
        self.journal.checkpoint('app','app_ready')
        self.op=Operation(self.ctx,self.journal,self.locks)

    def test_enable_disable_retains_old_journal_cursor_and_ack(self):
        import sqlite3
        self.assertEqual('runtime_active',self.op.enable()['phase'])
        self.assertEqual(3,json.loads(self.paths['notifications'].read_bytes())['notification_contract'])
        self.assertEqual(self.ack_key,self.paths['ack_key'].read_bytes())
        self.assertTrue(self.paths['include'].read_bytes().startswith(self.v2_images['include']['raw']))
        with sqlite3.connect(self.db) as db:db.execute('UPDATE journal SET cursor=84')
        result=self.op.disable()
        self.assertTrue(result['ack_enabled'])
        self.assertEqual(self.v2_images,{n:state.capture(p) for n,p in self.paths.items()})
        self.assertEqual(self.old_bytes,self.old_journal.read_bytes())

        with sqlite3.connect(self.db) as db:self.assertEqual([(84,'preserved')],db.execute('SELECT * FROM journal').fetchall())

    def test_failed_probe_restores_v2_with_ack_still_enabled(self):
        self.host.fail_v2=True
        result=self.op.enable()
        self.assertEqual('runtime_disabled',result['phase'])
        self.assertFalse(result['enable_succeeded'])
        self.assertTrue(result['ack_enabled'])
        self.assertEqual(self.v2_images,{n:state.capture(p) for n,p in self.paths.items()})
        self.assertEqual(self.old_bytes,self.old_journal.read_bytes())

    def test_process_loss_resumes_same_secret_and_can_return_to_v2(self):
        for boundary in ('private_files','include_handoff','pointer_switched','runtime_active'):
            with self.subTest(boundary=boundary):
                pid=os.fork()
                if pid==0:
                    original=self.journal.checkpoint
                    def crash(scope,phase,**fields):
                        row=original(scope,phase,**fields)
                        if phase==boundary:os._exit(73)
                        return row
                    self.journal.checkpoint=crash
                    self.op.enable();os._exit(74)
                _,status=os.waitpid(pid,0);self.assertEqual(73,os.waitstatus_to_exitcode(status))
                before=self.journal.load()['runtime']
                self.assertEqual('runtime_active',self.op.enable()['phase'])
                after=self.journal.load()['runtime']
                self.assertEqual(before['generation'],after['generation'])
                self.assertEqual(before['files']['notify_key'],after['files']['notify_key'])
                self.assertEqual(self.ack_key,self.paths['ack_key'].read_bytes())
                self.op.disable()
        self.assertEqual(self.old_bytes,self.old_journal.read_bytes())


class Application(unittest.TestCase):
    """Real pair copies/public boundary; Docker, backup producer and SQL are modeled."""
    def setUp(self):
        import test_connect_con02_application as f
        import connect_telegram_update as t
        import connect_con02_application as app
        from types import MethodType
        self.f=f.Application(methodName='runTest');self.f.setUp();self.addCleanup(self.f.doCleanups)
        ctx=self.f.ctx;d=self.f.d
        d.reference['baseline']['versions']['local_ustar']=2026102200
        d.reference['candidate']=copy.deepcopy(d.reference['baseline'])
        d.reference['candidate']['versions']['local_ustar']=2026102300
        d.reference['candidate']['files']={n:c.sha(raw) for n,raw in d.payload.items()}
        ctx.bundle.reference={**d.reference,'tool_commit':'d'*40,'app_commit':t.APP_COMMIT}
        before=ctx.host.database('mdl_');before['addons']['local_ustar']='2026102200'
        (d.p.PGDATA/'db.json').write_text(json.dumps(before));self.database=copy.deepcopy(before)
        self.f.raw_cron=self.f.raw_cron.replace(b'2026102000',b'2026102200')
        self.f.cron.write_bytes(self.f.raw_cron)
        record=json.loads(self.f.install.read_bytes());record.update(
            versions={'local_ustar':'2026102200','theme_ustar':'2026100202'},script_sha256=c.sha(self.f.raw_cron))
        self.f.install.write_text(json.dumps(record))
        ctx.verify_database=t.verify_database;ctx.baseline_ack_enabled=True
        ctx.patch_cron=lambda raw,installation:t.patch_cron(raw,installation,accepted_sha=c.sha(self.f.raw_cron))
        def upgrade(host):
            d.attempt+=1
            db=host.database('mdl_');db['addons']['local_ustar']='2026102300'
            (d.p.PGDATA/'db.json').write_text(json.dumps(db))
            if d.attempt==d.fail_at:raise c.Refusal('synthetic_SQL_failure')
        ctx.host.cli_upgrade=MethodType(upgrade,ctx.host)
        self.journal=state.Journal(ctx);self.op=app.Application(ctx,self.journal,self.f.d.locks)
        self.old=self.f.root/'old-con02-state';self.old.write_bytes(b'accepted predecessor journal')

    def test_2300_repeat_retains_schema_tasks_ack_and_2200_pair(self):
        result=self.op.upgrade()
        self.assertEqual('app_ready',result['phase']);self.assertEqual('2026102300',result['local_ustar'])
        self.assertTrue(result['ack_enabled'])
        db=self.f.ctx.host.database('mdl_')
        for key in ('schema','indexes','tasks'):self.assertEqual(self.database[key],db[key])
        self.assertEqual('2026102300',json.loads(self.f.install.read_bytes())['versions']['local_ustar'])
        self.assertTrue(self.op.upgrade()['verify_only']);self.assertEqual(2,self.f.d.attempt)
        self.assertEqual('baseline_restored',self.op.restore(outage=True,discard=True)['phase'])
        self.assertEqual(self.database,self.f.ctx.host.database('mdl_'))
        self.assertEqual(self.f.raw_cron,self.f.cron.read_bytes())
        self.assertEqual(b'accepted predecessor journal',self.old.read_bytes())

    def test_before_public_failure_restores_2200_without_changing_predecessor(self):
        self.f.d.fail_at=2
        self.assertEqual('baseline_restored',self.op.upgrade()['phase'])
        self.assertEqual(self.database,self.f.ctx.host.database('mdl_'))
        self.assertEqual(self.f.raw_cron,self.f.cron.read_bytes())
        self.assertEqual(b'accepted predecessor journal',self.old.read_bytes())

    def test_process_loss_after_public_retains_new_writes(self):
        pid=os.fork()
        if pid==0:
            original=self.journal.checkpoint
            def crash(scope,phase,**fields):
                row=original(scope,phase,**fields)
                if phase=='public_release_committed':os._exit(73)
                return row
            self.journal.checkpoint=crash
            self.op.upgrade();os._exit(74)
        _,status=os.waitpid(pid,0);self.assertEqual(73,os.waitstatus_to_exitcode(status))
        new=self.f.d.p.DATA/'new_after_release';new.write_bytes(b'keep this')
        self.assertEqual('app_ready',self.op.recover()['phase'])
        self.assertEqual(b'keep this',new.read_bytes())
        self.assertEqual('2026102300',self.f.ctx.host.database('mdl_')['addons']['local_ustar'])
        with self.assertRaises(c.Refusal):self.op.restore(outage=True)


class Predecessor(unittest.TestCase):
    """Read-only admission against a real private journal and saved cron images."""
    def setUp(self):
        import test_connect_con02_application as f
        import connect_telegram_update as t
        self.f=f.Application(methodName='runTest');self.f.setUp();self.addCleanup(self.f.doCleanups)
        self.f.ctx.bundle.reference['tool_commit']=t.PREDECESSOR_TOOL
        self.op=self.f.operation()
        self.assertEqual('app_ready',self.op.upgrade()['phase'])
        self.journal=state.Journal(self.f.ctx)
        self.journal.begin('runtime',{'files':{}},{'files':{}},generation='f'*32)
        self.journal.checkpoint('runtime','runtime_active')
        previous=self.f.ctx
        class Host(t.Host):
            def predecessor_context(self):return previous
            # Physical immutable worker admission is exercised separately above.
            def verify_predecessor_worker(self,ctx):pass
        self.host=Host(SimpleNamespace())

    def test_reads_predecessor_without_rewriting_old_journal_or_cron(self):
        before=state.capture(self.journal.path);cron=state.capture(self.f.cron)
        result=self.host.predecessor()
        self.assertEqual([130],result['fence']['notification_pilot_userids'])
        self.assertEqual(before,state.capture(self.journal.path))
        self.assertEqual(cron,state.capture(self.f.cron))
        self.assertEqual(c.sha(self.f.cron.read_bytes()),self.host.accepted_cron_sha)

    def test_requires_completed_app_and_active_ack_generation(self):
        for scope,phase in [('app','recovery_required'),('runtime','runtime_disabled')]:
            saved=self.journal.load();changed=copy.deepcopy(saved);changed[scope]['phase']=phase;self.journal.save(changed)
            with self.subTest(scope=scope),self.assertRaisesRegex(c.Refusal,'accepted_CON02_active_required'):
                self.host.predecessor()
            self.journal.save(saved)

    def test_changed_recorded_cron_is_refused(self):
        row=self.journal.load()['app'];path=self.journal.folder(row)/'cron_script.before'
        path.write_bytes(path.read_bytes()+b'# drift')
        with self.assertRaisesRegex(c.Refusal,'saved_image_changed'):self.host.predecessor()

    def test_completed_journal_cannot_admit_rolled_back_cron(self):
        row=self.journal.load()['app'];image=row['files']['cron_script']['before']
        raw=self.journal.read_private(self.journal.folder(row)/'cron_script.before')
        state.replace(self.f.cron,raw,image['metadata'])
        with self.assertRaisesRegex(c.Refusal,'accepted_cron_not_effective'):self.host.predecessor()



if __name__ == '__main__': unittest.main()
