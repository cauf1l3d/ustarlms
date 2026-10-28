"""Fixed Studio deployment adapter; production is never accessed by these tests."""
import contextlib
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'scripts/infra'),str(ROOT/'tests/r16')]
import connect_con02_contract as c

class Contract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if importlib.util.find_spec('course_studio_update') is None:
            raise AssertionError('Fixed Studio deployment adapter is required')
        import course_studio_update as s
        cls.s=s

    def test_only_2300_to_2600_preserves_100_table_schema_and_worker(self):
        s=self.s
        self.assertEqual('6c56a5b6ab830b97b706a5d7028b49cfae31e7a4',s.BASELINE_COMMIT)
        self.assertEqual('2d95137ac85a09ad4c513edae8a792152153c840',s.APP_COMMIT)
        self.assertEqual(s.BASELINE_COMMIT,s.WORKER_COMMIT)
        self.assertEqual({'local_ustar':'2026102600','theme_ustar':'2026100202'},s.VERSIONS)

    def test_cron_changes_only_local_version_and_retains_installation_metadata(self):
        s=self.s
        raw=b"import sys\nVERSIONS = {'local_ustar': '2026102300', 'theme_ustar': '2026100202'}\nprint('ordinary role unchanged')\n"
        record={'schema':1,'script_sha256':c.sha(raw),'versions':{'local_ustar':'2026102300','theme_ustar':'2026100202'},'opaque_owner':'preserved'}
        after,info=s.patch_cron(raw,record,accepted_sha=c.sha(raw))
        self.assertEqual(raw.replace(b'2026102300',b'2026102600'),after)
        self.assertEqual('preserved',info['opaque_owner']);self.assertEqual(c.sha(after),info['script_sha256'])
        self.assertEqual(s.VERSIONS,info['versions'])
        for changed in (raw+b'#drift',raw.replace(b'2300',b'2200')):
            with self.assertRaises(c.Refusal):s.patch_cron(changed,record,accepted_sha=c.sha(raw))

    def test_default_cli_is_offline_and_mutation_requires_explicit_outage(self):
        from unittest.mock import patch
        s=self.s
        with patch.object(s,'make_context',side_effect=AssertionError('host touched')):
            out=io.StringIO()
            with contextlib.redirect_stdout(out):self.assertEqual(0,s.main([]))
            self.assertEqual({'phase':'PLAN_ONLY','host_access':False,'network_used':False},json.loads(out.getvalue()))
            for args in (['--upgrade'],['--restore'],['--recover','--acknowledge-outage'],['--check','--discard-writes-since-snapshot']):
                with self.subTest(args=args),contextlib.redirect_stderr(io.StringIO()),self.assertRaises(SystemExit):s.main(args)

class CurrentTaskPolicy(unittest.TestCase):
    def test_current_disabled_task_policy_is_preserved_but_schema_drift_is_refused(self):
        import test_connect_telegram_update as f
        import course_studio_update as studio
        fixture=f.Application(methodName='runTest');fixture.setUp();self.addCleanup(fixture.doCleanups)
        baseline=fixture.f.ctx.host.database('mdl_');current=copy.deepcopy(baseline)
        current['addons']['local_ustar']='2026102300';current['tasks']['prune']=[0,1]
        studio.verify_predecessor_database(current,baseline,fixture.f.ctx.bundle.reference)
        self.assertNotEqual(current['tasks'],baseline['tasks'])
        changed=copy.deepcopy(current);changed['schema'][0][2]='text'
        with self.assertRaises(c.Refusal):studio.verify_predecessor_database(changed,baseline,fixture.f.ctx.bundle.reference)


class Reference(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if importlib.util.find_spec('build_course_studio_update') is None:
            raise AssertionError('Exact-Git Studio packager is required')
        import build_course_studio_update as b
        import course_studio_update as s
        cls.b,cls.s=b,s
        cls.bundle=b.reference_from_git(ROOT,'2d95137ac85a09ad4c513edae8a792152153c840')

    def test_exact_qualified_payload_and_no_worker_rollout(self):
        b,s=self.bundle,self.s
        self.assertEqual(557,len(b.reference['baseline']['files']));self.assertEqual(582,len(b.payload))
        self.assertEqual(b.reference['baseline']['tables'],b.reference['candidate']['tables'])
        self.assertEqual(100,len(b.reference['candidate']['tables']))
        self.assertEqual(b.reference['baseline']['schema_fields'],b.reference['candidate']['schema_fields'])
        self.assertEqual(b.reference['baseline']['schema_indexes'],b.reference['candidate']['schema_indexes'])
        self.assertEqual(s.BASELINE_COMMIT,b.predecessor.reference['app_commit'])
        self.assertEqual(b.predecessor.worker_archive,b.worker_archive)
        self.assertIn('local/ustar/course_studio/vendor/pdfjs/pdf.worker.min.mjs',b.payload)

    def test_unrelated_source_and_schema_drift_are_refused(self):
        s=self.s
        s.validate_reference(self.bundle.reference)
        changed=copy.deepcopy(self.bundle.reference);changed['candidate']['files']['local/ustar/connect.php']='f'*64
        with self.assertRaises(c.Refusal):s.validate_reference(changed)
        changed=copy.deepcopy(self.bundle.reference);changed['candidate']['tables'].append('invented_table')
        with self.assertRaises(c.Refusal):s.validate_reference(changed)

class Application(unittest.TestCase):
    """Actual cold file copies/cron/public boundary; Docker and SQL are modeled."""
    def setUp(self):
        import test_connect_con02_application as f
        import connect_con02_application as app
        import connect_con02_state as state
        import course_studio_update as studio
        from types import MethodType
        self.f=f.Application(methodName='runTest');self.f.setUp();self.addCleanup(self.f.doCleanups)
        ctx=self.f.ctx;d=self.f.d
        d.reference['baseline']['versions']['local_ustar']=2026102300
        d.reference['candidate']=copy.deepcopy(d.reference['baseline'])
        d.reference['candidate']['versions']['local_ustar']=2026102600
        self.new='local/ustar/course_studio/pdf.js';d.payload[self.new]=b'qualified PDF adapter'
        d.reference['candidate']['files']={n:c.sha(raw) for n,raw in d.payload.items()}
        ctx.bundle.reference={**d.reference,'tool_commit':'d'*40,'app_commit':studio.APP_COMMIT}
        before=ctx.host.database('mdl_');before['addons']['local_ustar']='2026102300'
        (d.p.PGDATA/'db.json').write_text(json.dumps(before));self.database=copy.deepcopy(before)
        self.f.raw_cron=self.f.raw_cron.replace(b'2026102000',b'2026102300')
        self.f.cron.write_bytes(self.f.raw_cron)
        record=json.loads(self.f.install.read_bytes());record.update(
            versions={'local_ustar':'2026102300','theme_ustar':'2026100202'},script_sha256=c.sha(self.f.raw_cron))
        self.f.install.write_text(json.dumps(record));ctx.verify_database=studio.verify_database
        ctx.baseline_ack_enabled=True
        ctx.patch_cron=lambda raw,info:studio.patch_cron(raw,info,accepted_sha=c.sha(self.f.raw_cron))
        def upgrade(host):
            d.attempt+=1;db=host.database('mdl_');db['addons']['local_ustar']='2026102600'
            (d.p.PGDATA/'db.json').write_text(json.dumps(db))
            if d.attempt==d.fail_at:raise c.Refusal('synthetic_SQL_failure')
        ctx.host.cli_upgrade=MethodType(upgrade,ctx.host)
        self.journal=state.Journal(ctx);self.op=app.Application(ctx,self.journal,d.locks)
        self.include=d.control.read_bytes();self.services=copy.deepcopy(d.services)
        self.old=self.f.root/'accepted-telegram-state';self.old.write_bytes(b'accepted2300 journal')

    def test_2600_repeat_retains_workers_schema_tasks_and_paired_restore(self):
        result=self.op.upgrade();self.assertEqual('app_ready',result['phase'])
        self.assertEqual('2026102600',result['local_ustar'])
        self.assertEqual(b'qualified PDF adapter',(self.f.d.web/self.new).read_bytes())
        for key in ('schema','indexes','tasks'):self.assertEqual(self.database[key],self.f.ctx.host.database('mdl_')[key])
        self.assertEqual(self.include,self.f.d.control.read_bytes());self.assertEqual(self.services,self.f.d.services)
        self.assertTrue(self.op.upgrade()['verify_only']);self.assertEqual(2,self.f.d.attempt)
        with self.assertRaises(c.Refusal):self.op.restore(outage=True)
        self.assertEqual('baseline_restored',self.op.restore(outage=True,discard=True)['phase'])
        self.assertEqual(self.database,self.f.ctx.host.database('mdl_'))
        self.assertFalse((self.f.d.web/self.new).exists());self.assertEqual(self.f.raw_cron,self.f.cron.read_bytes())
        self.assertEqual(b'accepted2300 journal',self.old.read_bytes())

    def test_upgrade_failure_before_release_restores_2300_pair_and_controls(self):
        self.f.d.fail_at=2
        result=self.op.upgrade();self.assertEqual('baseline_restored',result['phase'])
        self.assertFalse(result['upgrade_succeeded']);self.assertEqual(self.database,self.f.ctx.host.database('mdl_'))
        self.assertEqual(self.include,self.f.d.control.read_bytes());self.assertEqual(self.services,self.f.d.services)
        self.assertEqual(b'accepted2300 journal',self.old.read_bytes())

    def test_process_loss_after_public_release_preserves_new_business_writes(self):
        pid=os.fork()
        if pid==0:
            original=self.journal.checkpoint
            def crash(scope,phase,**fields):
                row=original(scope,phase,**fields)
                if phase=='public_release_committed':os._exit(73)
                return row
            self.journal.checkpoint=crash;self.op.upgrade();os._exit(74)
        _,status=os.waitpid(pid,0);self.assertEqual(73,os.waitstatus_to_exitcode(status))
        new=self.f.d.p.DATA/'new-after-studio-release';new.write_bytes(b'keep new writes')
        self.assertEqual('app_ready',self.op.recover()['phase']);self.assertEqual(b'keep new writes',new.read_bytes())
        self.assertEqual(self.include,self.f.d.control.read_bytes())

    def test_existing_metadata_and_new_readonly_source_are_preserved(self):
        root=self.f.d.web/'local/ustar';existing=root/'version.php'
        root.chmod(0o755);existing.chmod(0o444)
        expected=os.stat(existing)
        self.assertEqual('app_ready',self.op.upgrade()['phase'])
        actual=os.stat(existing);new=os.stat(self.f.d.web/self.new)
        self.assertEqual((expected.st_uid,expected.st_gid,expected.st_mode),(actual.st_uid,actual.st_gid,actual.st_mode))
        self.assertEqual(os.stat(root).st_uid,new.st_uid);self.assertFalse(new.st_mode&0o022)

class Package(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import build_course_studio_update as b
        cls.b=b;cls.tmp=tempfile.TemporaryDirectory();cls.addClassCleanup(cls.tmp.cleanup);cls.root=Path(cls.tmp.name)
        env={**os.environ,'GIT_INDEX_FILE':str(cls.root/'index'),
            'GIT_AUTHOR_NAME':'Studio package fixture','GIT_AUTHOR_EMAIL':'fixture@example.invalid',
            'GIT_COMMITTER_NAME':'Studio package fixture','GIT_COMMITTER_EMAIL':'fixture@example.invalid'}
        def git(*args):return subprocess.check_output(['git','-C',str(ROOT),*args],env=env,stderr=subprocess.DEVNULL).decode().strip()
        # This operator intentionally installs frozen Studio2600. A later app
        # candidate must not silently change the fixture's accepted source tree.
        frozen='62b0da2020bc27569c0bd4f8ac96b676f29b7490'
        git('read-tree',frozen);git('add','scripts/infra/course_studio_update.py','scripts/infra/build_course_studio_update.py')
        cls.commit=git('commit-tree',git('write-tree'),'-p',frozen,'-m','Studio2600 package private verification snapshot')
        with contextlib.redirect_stdout(io.StringIO()):cls.artifact=b.build(cls.commit,cls.root/'one')

    def test_offline_file_ignores_adjacent_poison_module(self):
        (self.artifact.parent/'course_studio_update.py').write_text('raise RuntimeError("SIBLING_IMPORTED")')
        r=subprocess.run([sys.executable,'-I','-B',str(self.artifact)],capture_output=True,text=True,timeout=45)
        self.assertEqual(0,r.returncode,r.stderr);self.assertEqual(
            {'phase':'PLAN_ONLY','host_access':False,'network_used':False},json.loads(r.stdout))

    def test_reproducible_archive_contains_only_checked_regular_members(self):
        import tarfile,hashlib
        with contextlib.redirect_stdout(io.StringIO()):other=self.b.build(self.commit,self.root/'two')
        self.assertEqual(self.artifact.read_bytes(),other.read_bytes())
        archives=sorted(self.artifact.parent.glob('*.tar.gz'));self.assertEqual(1,len(archives))
        with tarfile.open(archives[0]) as t:
            self.assertEqual({'update.py','README.txt','INSTALL.json','SHA256SUMS'},set(t.getnames()))
            self.assertTrue(all(m.isfile() and m.mode==0o600 for m in t))
            files={m.name:t.extractfile(m).read() for m in t}
        self.assertEqual(self.artifact.read_bytes(),files['update.py'])
        for row in files['SHA256SUMS'].decode().splitlines():
            digest,name=row.split('  ',1);self.assertEqual(digest,hashlib.sha256(files[name]).hexdigest())
        meta=json.loads(files['INSTALL.json']);self.assertFalse(meta['worker_rollout']);self.assertEqual(582,meta['payload_files'])

    def test_payload_or_worker_identity_tampering_is_refused(self):
        import ast,base64
        import course_studio_update as studio
        constants={n.targets[0].id:ast.literal_eval(n.value) for n in ast.parse(self.artifact.read_text()).body
            if isinstance(n,ast.Assign) and isinstance(n.targets[0],ast.Name)
            and n.targets[0].id in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON')}
        manifest,sources,assets=[json.loads(constants[n]) for n in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON')]
        bad=dict(assets);bad['payload/local/ustar/version.php']=base64.b64encode(b'unqualified').decode()
        with self.assertRaises(c.Refusal):studio.load_embedded(manifest,sources,bad)
        bad=copy.deepcopy(manifest);bad['worker_commit']=studio.APP_COMMIT
        with self.assertRaises(c.Refusal):studio.load_embedded(bad,sources,assets)

class ActualCodePermissions(unittest.TestCase):
    setUp=Application.setUp
    def test_uid33_can_read_but_cannot_modify_new_source(self):
        import shutil
        mandatory=os.environ.get('USTAR_REQUIRE_STUDIO_UID33')=='1'
        if os.geteuid()!=0 or not shutil.which('setpriv'):
            if mandatory:self.fail('actual UID33 fixture requires root and setpriv')
            self.skipTest('actual UID33 fixture requires root and setpriv')
        root=self.f.d.web/'local/ustar';self.f.root.chmod(0o755)
        for p in root.parents:
            if p==self.f.root:break
            p.chmod(0o755)
        try:os.chown(root,0,33)
        except OSError:
            if mandatory:raise
            self.skipTest('local namespace cannot map GID33')
        root.chmod(0o755)
        self.assertEqual('app_ready',self.op.upgrade()['phase'])
        p=self.f.d.web/self.new
        r=subprocess.run(['setpriv','--reuid=33','--regid=33','--clear-groups',sys.executable,'-I','-c',
            'import pathlib,sys;p=pathlib.Path(sys.argv[1]);assert p.read_bytes()==b"qualified PDF adapter";'
            '\ntry:p.write_bytes(b"bad")\nexcept PermissionError:pass\nelse:raise AssertionError("web user wrote code")',str(p)],
            capture_output=True,text=True,timeout=10)
        self.assertEqual(0,r.returncode,r.stderr)

if __name__=='__main__':unittest.main()
