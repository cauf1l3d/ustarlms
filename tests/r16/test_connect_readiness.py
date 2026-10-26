"""Real source files and metadata parsing; Docker/SQL are modeled, not native acceptance."""
import contextlib,hashlib,importlib.util,io,json,os,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]

class ConnectReadinessTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        path=ROOT/'scripts/infra/connect_readiness.py'
        if not path.is_file():raise AssertionError('Connect Academy readonly preflight missing')
        sys.path[:0]=[str(ROOT/'scripts'),str(ROOT/'scripts/infra')]
        cls.p=__import__('connect_readiness')
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name);self.code=self.root/'code';self.web=self.code/'public'
        self.baseline={}
        for scope,version in [('local/ustar',2026100204),('theme/ustar',2026100202)]:
            path=self.web/scope/'version.php';path.parent.mkdir(parents=True)
            data=f'<?php\n$plugin->version = {version};\n'.encode();path.write_bytes(data)
            self.baseline[scope+'/version.php']=hashlib.sha256(data).hexdigest()
        (self.code/'config.php').write_text("<?php\n$CFG->prefix = 'mdl_';\n$CFG->dbpass = 'PRIVATE_CONFIG_CANARY';\n")
        candidate=dict(self.baseline);candidate['local/ustar/connect_bridge.php']='1'*64
        self.ref={'schema':1,'source_commit':'a'*40,'baseline_commit':'b'*40,
          'baseline':{'files':self.baseline,'versions':{'local_ustar':2026100204,'theme_ustar':2026100202},'tables':['local_ustar_test']},
          'candidate':{'files':candidate,'versions':{'local_ustar':2026101905,'theme_ustar':2026100202},'tables':['local_ustar_test','local_ustar_conn_links']}}
        self.calls=[];self.db={'transaction_read_only':'on','core':'2025100608.00','local_ustar':'2026100204','theme_ustar':'2026100202','tables':['local_ustar_test']}
        self.state=self.root/'backup';self.state.mkdir()
        (self.state/'last-hdd-success.json').write_text(json.dumps({'success':True,'finished_utc':'2026-10-19T00:31:00+00:00','private':'PRIVATE_BACKUP_CANARY'}))
    def runner(self,args,input_text=None,timeout=30):
        self.calls.append((args,input_text))
        if args[:2]==['docker','inspect']:
            pg=args[-1]=='ustar_postgres';source='/opt/ustar/data/postgres' if pg else str(self.code)
            target='/var/lib/postgresql/data' if pg else '/var/www/html'
            return json.dumps({'running':True,'mounts':[{'Type':'bind','Source':source,'Destination':target}]})
        if args[:2]==['docker','exec']:return json.dumps(self.db)
        raise AssertionError('Unexpected host command')
    def check(self):return self.p.check(self.ref,code=self.code,runner=self.runner,backup_state=self.state)
    def test_nested_public_baseline_and_candidate_delta_are_separate(self):
        before={p:p.read_bytes() for p in self.code.rglob('*') if p.is_file()}
        result=self.check()
        self.assertEqual('baseline_measured',result['status'])
        self.assertTrue(result['source']['baseline_matches'])
        self.assertEqual(['local/ustar/connect_bridge.php'],result['source']['candidate_delta']['git_only'])
        self.assertFalse(result['deployment_approved'])
        self.assertEqual(before,{p:p.read_bytes() for p in before})
        self.assertNotIn('PRIVATE_',json.dumps(result))
        self.assertFalse(result['backup']['archive_or_restore_verified'])
    def test_changed_source_and_disk_db_version_mismatch_stay_blocked(self):
        (self.web/'local/ustar/version.php').write_text('<?php\n$plugin->version = 2026101905;\n')
        result=self.check();self.assertEqual('review_required',result['status'])
        self.assertFalse(result['source']['baseline_matches'])
        self.assertFalse(result['database']['disk_versions_match'])
    def test_ambiguous_layout_and_symlink_refuse_before_host_commands(self):
        for scope in ('local/ustar','theme/ustar'):
            path=self.code/scope/'version.php';path.parent.mkdir(parents=True);path.write_text('<?php')
        with self.assertRaisesRegex(self.p.Refusal,'layout'):self.check()
        self.assertEqual([],self.calls)
    def test_prefix_injection_and_private_stderr_are_not_exported(self):
        (self.code/'config.php').write_text("<?php\n$CFG->prefix = 'mdl_; DROP';\n")
        with self.assertRaises(self.p.Refusal):self.check()
        self.assertFalse(any(c[0][:2]==['docker','exec'] for c in self.calls))
    def test_database_query_is_bounded_and_readonly(self):
        self.check();sql=[sql for args,sql in self.calls if args[:2]==['docker','exec']][0]
        self.assertTrue(sql.startswith('BEGIN READ ONLY;'));self.assertTrue(sql.endswith('ROLLBACK;'))
        self.assertIn('statement_timeout',sql);self.assertIn('lock_timeout',sql)
        self.assertNotRegex(sql.upper(),r'\b(UPDATE|DELETE|INSERT|CREATE|ALTER|DROP)\b')
    def test_missing_backup_remains_unknown(self):
        (self.state/'last-hdd-success.json').unlink()
        result=self.check();self.assertEqual('unavailable',result['backup']['status'])
        self.assertFalse(result['deployment_approved'])
    def test_database_extra_fields_and_partial_scan_are_refused(self):
        self.db['password']='PRIVATE_DB_CANARY'
        result=self.check();self.assertEqual('unavailable',result['database']['status'])
        self.assertNotIn('PRIVATE_',json.dumps(result));self.assertEqual('review_required',result['status'])
    def test_plan_mode_does_not_read_host(self):
        with patch.object(self.p,'check',side_effect=AssertionError('host read')),patch.object(self.p,'load_reference',return_value=self.ref),contextlib.redirect_stdout(io.StringIO()) as out:
            self.assertEqual(0,self.p.main([]))
        self.assertEqual('plan_only',json.loads(out.getvalue())['status'])
    def test_symlink_webroot_refused_before_host_commands(self):
        real=self.code/'real-public';self.web.rename(real);self.web.symlink_to(real,target_is_directory=True)
        with self.assertRaisesRegex(self.p.Refusal,'layout_symlink'):self.check()
        self.assertEqual([],self.calls)
    def test_partial_source_scan_stays_review_required(self):
        (self.web/'local/ustar/alias.php').symlink_to(self.code/'config.php')
        result=self.check();self.assertFalse(result['source']['complete'])
        self.assertEqual('review_required',result['status']);self.assertNotIn('PRIVATE_',json.dumps(result))
    def test_failed_command_does_not_return_stderr(self):
        with patch('production_patch_readiness.subprocess.run',return_value=unittest.mock.Mock(returncode=1,stdout='',stderr='PRIVATE_COMMAND_CANARY')):
            with self.assertRaisesRegex(self.p.Refusal,'^Bounded host command failed$'):
                self.p.command(['docker','inspect','ustar_moodle'])
    def test_bundle_is_exact_git_deterministic_and_plan_only(self):
        import subprocess,zipfile
        path=ROOT/'scripts/infra/build_connect_readiness.py'
        self.assertTrue(path.is_file(),'Connect preflight bundle builder missing')
        spec=importlib.util.spec_from_file_location('connect_readiness_builder',path)
        builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
        repo=self.root/'repo';repo.mkdir()
        def git(*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL).decode().strip()
        git('init')
        for name,version in [('local/ustar',2026100204),('theme/ustar',2026100202)]:
            target=repo/'moodle'/name/'version.php';target.parent.mkdir(parents=True)
            target.write_text(f'<?php\n$plugin->version = {version};\n')
        schema=repo/'moodle/local/ustar/db/install.xml';schema.parent.mkdir();schema.write_text('<XMLDB><TABLES><TABLE NAME="local_ustar_test"/></TABLES></XMLDB>')
        git('add','.');git('-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','-m','baseline');base=git('rev-parse','HEAD')
        for name in ('scripts/infra/connect_readiness.py','scripts/production_manifest.py','scripts/infra/production_patch_readiness.py'):
            target=repo/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes((ROOT/name).read_bytes())
        marker=repo/'context/code_map/generated/source_manifest.json';marker.parent.mkdir(parents=True);marker.write_text(json.dumps({'source_commit':base}))
        (repo/'moodle/local/ustar/version.php').write_text('<?php\n$plugin->version = 2026101905;\n')
        git('add','.');git('-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','-m','candidate');sha=git('rev-parse','HEAD')
        target=builder.build(sha,self.root/'out',repo=repo);before=target.read_bytes()
        (repo/'scripts/infra/connect_readiness.py').write_text('PRIVATE_DIRTY_CANARY')
        self.assertEqual(before,builder.build(sha,self.root/'out',repo=repo).read_bytes())
        with zipfile.ZipFile(target) as archive:
            self.assertEqual(sha+'\n',archive.read('SOURCE_COMMIT').decode())
            self.assertNotIn(b'PRIVATE_DIRTY_CANARY',archive.read('connect_readiness.py'))
        proc=subprocess.run([sys.executable,'-I',str(target)],capture_output=True,text=True,timeout=10)
        self.assertEqual(0,proc.returncode,proc.stderr);self.assertEqual('plan_only',json.loads(proc.stdout)['status'])
    def test_refusal_reports_fixed_reason_without_private_exception_details(self):
        with patch.object(self.p,'load_reference',return_value=self.ref),patch.object(self.p.os,'geteuid',return_value=0),patch.object(self.p,'check',side_effect=self.p.Refusal('code_layout_ambiguous_or_missing')),contextlib.redirect_stdout(io.StringIO()) as out:
            self.assertEqual(2,self.p.main(['--check']))
        self.assertEqual('code_layout_ambiguous_or_missing',json.loads(out.getvalue())['reason'])
