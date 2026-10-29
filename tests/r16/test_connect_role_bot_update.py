"""Coordinated role bot successor: fixed2803 baseline, current receipts and no provider traffic."""
import contextlib
import copy
import importlib
import io
import json
import os
from pathlib import Path
import sys
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch
from types import SimpleNamespace,MethodType

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'scripts/infra'),str(ROOT/'tests/r16')]
import connect_con02_contract as c
import test_connect_telegram_update as old


class Profiles(unittest.TestCase):
    @classmethod
    def setUpClass(cls):old.Successor.setUpClass()
    def setUp(self):
        f=old.Successor();f.setUp();self.ctx=f.ctx
        import connect_telegram_update as telegram
        values=telegram.profiles(self.ctx,f.before,generation='b'*32,cutoff=300,
            notify_secret=b'55'*32,ack_secret=b'66'*32)
        self.before=copy.deepcopy(f.before)
        self.before['files'].update({n:{'raw':raw} for n,raw in values.items()})
        for n in ('bot_key','receiver_bot_dropin','probe_bot_dropin'):
            self.before['files'][n]={'raw':None,'kind':'absent'}
        self.original=copy.deepcopy(self.before)
    def profile(self,secret=b'77'*32):
        m=importlib.import_module('connect_role_bot_update')
        return m.profiles(self.ctx,self.before,generation='c'*32,cutoff=999,
            notify_secret=secret,ack_secret=b'88'*32)
    def test_new_bot_key_is_separate_old_ack_and_sender_are_preserved(self):
        after=self.profile();self.assertEqual(self.original,self.before)
        self.assertNotIn('ack_key',after);self.assertNotIn('notify_key',after)
        self.assertEqual(b'77'*32+b'\n',after['bot_key'])
        self.assertTrue(after['include'].startswith(self.before['files']['include']['raw']))
        self.assertIn(b"'operations'=>['bot.update','bot.upload']",after['include'])
        self.assertIn(b'ustar_connect_ack_since=200;',after['include'])
        self.assertNotIn(b'ustar_connect_ack_since=999;',after['include'])
    def test_new_recipient_profile_is_explicit_opt_in_and_future_only(self):
        after=self.profile();sender=json.loads(after['notifications'])
        self.assertEqual(3,sender['notification_contract']);self.assertEqual('tg_notify_v3',sender['bridge_key_id'])
        self.assertIs(True,sender['role_bot_delivery'])
        self.assertEqual('academy_linked_opt_in',sender['notification_subjects'])
        self.assertIn(b'unset($CFG->ustar_connect_notify_userids);',after['include'])
        self.assertIn(b'$CFG->ustar_connect_notify_since=999;',after['include'])
        self.assertNotIn(b'UPDATE',after['include'])
        for name in ('receiver','probe'):
            cfg=json.loads(after[name]);self.assertIs(False,cfg['onboarding_enabled'])
            self.assertIs(True,cfg['role_bot_enabled']);self.assertEqual('https://ustar.local',cfg['bot_academy_origin'])
            self.assertEqual('tg_ack_v1',cfg['ack_bridge_key_id'])
            self.assertIn(b'LoadCredential=bot-bridge.key:/etc/ustar-connect/bot-bridge.key',after[name+'_bot_dropin'])
    def test_reused_secret_unknown_config_or_nonempty_bot_file_is_refused(self):
        for secret in (b'11'*32,b'22'*32,b'33'*32,b'44'*32,b'55'*32,b'ZZ'*32):
            with self.subTest(secret=secret[:2]),self.assertRaises(c.Refusal):self.profile(secret)
        cfg=json.loads(self.before['files']['receiver']['raw']);cfg['unknown']=True
        self.before['files']['receiver']['raw']=json.dumps(cfg).encode()
        with self.assertRaises(c.Refusal):self.profile()
        self.before=copy.deepcopy(self.original);self.before['files']['bot_key']['raw']=b'foreign'
        with self.assertRaises(c.Refusal):self.profile()


class Contract(unittest.TestCase):
    def test_default_mode_has_no_host_access_and_upgrade_requires_outage(self):
        m=importlib.import_module('connect_role_bot_update')
        with patch.object(m,'make_context',side_effect=AssertionError('host touched')):
            out=io.StringIO()
            with contextlib.redirect_stdout(out):self.assertEqual(0,m.main([]))
            self.assertEqual({'phase':'PLAN_ONLY','host_access':False,'network_used':False},json.loads(out.getvalue()))
            for args in (['--upgrade'],['--restore'],['--check','--enable-bot'],['--recover','--acknowledge-outage']):
                with self.subTest(args=args),contextlib.redirect_stderr(io.StringIO()),self.assertRaises(SystemExit):m.main(args)
    def test_cron_requires_exact_accepted2803_bytes_and_record(self):
        m=importlib.import_module('connect_role_bot_update')
        raw=b"# accepted\nVERSIONS = {'local_ustar': '2026102803', 'theme_ustar': '2026102700'}\n"
        record={'schema':1,'versions':{'local_ustar':'2026102803','theme_ustar':'2026102700'},'script_sha256':c.sha(raw),'preserve':'yes'}
        new,info=m.patch_cron(raw,record,accepted_sha=c.sha(raw))
        self.assertIn(b'2026102900',new);self.assertEqual('yes',info['preserve'])
        for bad in (raw+b'#foreign',raw+raw,raw.replace(b'2803',b'2802')):
            with self.assertRaises(c.Refusal):m.patch_cron(bad,record,accepted_sha=c.sha(raw))


class Reference(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import connect_role_bot_update as m
        import build_connect_role_bot_update as b
        cls.bundle=b.reference_from_git(ROOT,m.APP_COMMIT)
    def test_exact2803_baseline_and_three_metadata_tables_without_old_schema_changes(self):
        import connect_role_bot_update as m
        ref=self.bundle.reference;old,new=ref['baseline'],ref['candidate']
        self.assertEqual(606,len(self.bundle.payload));self.assertEqual(103,len(new['tables']))
        self.assertEqual({'local_ustar_conn_dialogs','local_ustar_conn_actions','local_ustar_conn_updates'},set(new['tables'])-set(old['tables']))
        self.assertEqual(2026102803,old['versions']['local_ustar']);self.assertEqual(2026102900,new['versions']['local_ustar'])
        self.assertEqual(m.PREDECESSOR_TOOL,self.bundle.predecessor.reference['tool_commit'])
        self.assertNotEqual(self.bundle.worker_archive,self.bundle.predecessor.worker_archive)
        for mutate in (lambda r:r['candidate']['tables'].append('unknown'),lambda r:r['candidate']['files'].pop('local/ustar/connect_bot_confirm.php'),
            lambda r:r['candidate']['schema_fields']['local_ustar_notifications']['ackat'].__setitem__('default','1')):
            value=copy.deepcopy(ref);mutate(value)
            with self.assertRaises(c.Refusal):m.validate_reference(value)

    def test_embedded_full_predecessor_and_new_worker_round_trip_without_weakening_old_operator(self):
        import base64
        import connect_role_bot_update as m
        import build_connect_role_bot_update as b
        values=b.assets_for(self.bundle)
        sources={n+'.py':(ROOT/('scripts/' if n=='production_manifest' else 'scripts/infra/')/(n+'.py')).read_text() for n in m.MODULES}
        ref=self.bundle.reference
        manifest={'schema':1,'tool_commit':ref['tool_commit'],'app_commit':m.APP_COMMIT,'worker_commit':m.WORKER_COMMIT,
            'sources':{n:{'bytes':len(raw.encode()),'sha256':c.sha(raw.encode())} for n,raw in sources.items()},
            'assets':{n:{'bytes':len(raw),'sha256':c.sha(raw)} for n,raw in values.items()}}
        assets={n:base64.b64encode(raw).decode() for n,raw in values.items()}
        loaded=m.load_embedded(manifest,sources,assets)
        self.assertEqual(ref,loaded.reference);self.assertEqual(self.bundle.predecessor.reference,loaded.predecessor.reference)
        for name in ('baseline2803/local/ustar/version.php','worker.tar.gz','feed.json'):
            bad=dict(assets);bad[name]=base64.b64encode(b'foreign').decode()
            with self.subTest(name=name),self.assertRaises(c.Refusal):m.load_embedded(manifest,sources,bad)


class Predecessor(unittest.TestCase):
    """Real2803 journal/cron/source; Docker, SQL and older runtime use the existing fixture."""
    def setUp(self):
        import test_feed_media_update as feed_fixture
        import connect_con02_state as state
        import connect_role_bot_update as m
        f=feed_fixture.Application(methodName='runTest');f.setUp();self.addCleanup(f.doCleanups)
        self.assertEqual('app_ready',f.op.upgrade()['phase'])
        self.f=f;self.cron=f.f.cron;self.raw=self.cron.read_bytes()
        self.assertEqual(b"VERSIONS = {'local_ustar': '2026102803', 'theme_ustar': '2026102700'}\n",self.raw)
        self.ctx=c.Context(SimpleNamespace(reference={'tool_commit':'f'*40,'app_commit':m.APP_COMMIT}),
            f.ctx.root,f.ctx.uid,None,SimpleNamespace(base=f.ctx.root/'role-preflight'))
        self.host=m.Host(self.ctx);self.ctx.host=self.host;self.host._previous=f.ctx
        # feed.Host.accepted_cron() reconstructs its BASELINE2802, not its target2803.
        # Keep that predecessor contract; exercise the real role Host consumer.
        f.ctx.host.accepted_cron=lambda:b"VERSIONS = {'local_ustar': '2026102802', 'theme_ustar': '2026102700'}\n"
        f.ctx.host.previous_context=lambda:SimpleNamespace()
        f.ctx.host.verify_runtime=lambda previous:None
        self.state=state

    def snapshot(self):
        return {str(p):(p.stat().st_ino,p.read_bytes()) for p in self.ctx.root.rglob('*') if p.is_file()}

    def rewrite_receipt(self,raw):
        saved=self.f.journal.load();row=saved['app']
        row['files']['cron_script']['after']=self.state.descriptor(self.state.capture(self.cron))
        (self.f.journal.folder(row)/'cron_script.after').write_bytes(raw)
        self.f.journal.save(saved)

    def test_real2803_preflight_accepts_current_cron_without_writing_any_files(self):
        before=self.snapshot();controls=self.host.predecessor()
        self.assertEqual(c.sha(self.raw),self.host.accepted_cron_sha)
        self.assertEqual('absent',controls['files']['bot_key']['kind'])
        self.assertEqual(before,self.snapshot());self.assertFalse(self.ctx.engine.base.exists())
        after,record=self.host.cron_patch(self.raw,json.loads(self.f.f.install.read_bytes()))
        self.assertEqual(b"VERSIONS = {'local_ustar': '2026102900', 'theme_ustar': '2026102700'}\n",after)
        self.assertEqual(c.sha(after),record['script_sha256'])
        self.assertEqual(before,self.snapshot())

    def test_forged_live2803_cron_and_matching_receipt_cannot_bless_foreign_code(self):
        raw=self.raw+b'# foreign executable code\n';self.cron.write_bytes(raw);self.rewrite_receipt(raw)
        before=self.snapshot()
        with self.assertRaisesRegex(c.Refusal,'accepted_cron_journal_changed'):self.host.predecessor()
        self.assertEqual(before,self.snapshot());self.assertFalse(self.ctx.engine.base.exists())

    def test_stale2802_cron_and_matching_receipt_are_still_refused(self):
        raw=self.raw.replace(b'2026102803',b'2026102802');self.cron.write_bytes(raw);self.rewrite_receipt(raw)
        with self.assertRaisesRegex(c.Refusal,'accepted_cron_journal_changed'):self.host.predecessor()
        self.assertFalse(self.ctx.engine.base.exists())

    def test_live_cron_drift_without_rewriting_receipt_is_refused(self):
        self.cron.write_bytes(self.raw+b'# live drift\n');before=self.snapshot()
        with self.assertRaisesRegex(c.Refusal,'unknown_control_drift'):self.host.predecessor()
        self.assertEqual(before,self.snapshot())

    def test_predecessor_reconstruction_rejects_missing_or_duplicate_version_line(self):
        for raw in (b'# missing version\n',self.raw,self.raw.replace(b'2803',b'2802')*2):
            self.f.ctx.host.accepted_cron=lambda:raw
            with self.subTest(raw=raw),self.assertRaisesRegex(c.Refusal,'cron_source_changed'):self.host.predecessor()


class Runtime(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        old.Runtime.setUpClass();Reference.setUpClass();cls.role_bundle=Reference.bundle
    def setUp(self):
        import connect_con02_state as state
        import connect_con02_runtime as runtime
        import connect_role_bot_update as m
        self.bundle=old.Runtime.bundle;old.Runtime.setUp(self)
        self.assertEqual('runtime_active',self.op.enable()['phase'])
        self.v3_journal=self.journal.path;self.v3_bytes=self.v3_journal.read_bytes()
        for name in ('bot_key','receiver_bot_dropin','probe_bot_dropin'):self.paths[name]=self.root/'controls'/name
        self.before_role={n:state.capture(p) for n,p in self.paths.items()}
        self.ctx=c.Context(self.role_bundle,self.root,os.getuid(),self.host,SimpleNamespace(base=self.root/'role-state'))
        self.ctx.profiles=m.profiles;self.ctx.baseline_ack_enabled=True
        self.journal=state.Journal(self.ctx);self.locks=state.Locks(self.ctx)
        self.journal.begin('app',{'files':{}},{'files':{}},generation='d'*32);self.journal.checkpoint('app','app_ready')
        self.op=runtime.Runtime(self.ctx,self.journal,self.locks)
    def test_role_enable_disable_preserves_old_keys_receipt_and_current_sqlite_cursor(self):
        import sqlite3
        import connect_con02_state as state
        self.assertEqual('runtime_active',self.op.enable()['phase'])
        self.assertEqual('academy_linked_opt_in',json.loads(self.paths['notifications'].read_bytes())['notification_subjects'])
        self.assertEqual(0o600,self.paths['bot_key'].stat().st_mode&0o777)
        for name in ('ack_key','notify_key','link_key'):self.assertEqual(self.before_role[name],state.capture(self.paths[name]))
        with sqlite3.connect(self.db) as db:db.execute('UPDATE journal SET cursor=120')
        self.assertEqual('runtime_disabled',self.op.disable()['phase'])
        self.assertEqual(self.before_role,{n:state.capture(p) for n,p in self.paths.items()})
        self.assertEqual(self.v3_bytes,self.v3_journal.read_bytes())
        with sqlite3.connect(self.db) as db:self.assertEqual([(120,'preserved')],db.execute('SELECT * FROM journal').fetchall())
    def test_failed_read_only_probe_restores_entire_v3_profile_without_business_restore(self):
        import connect_con02_state as state
        self.host.fail_v2=True;result=self.op.enable()
        self.assertEqual('runtime_disabled',result['phase']);self.assertFalse(result['enable_succeeded'])
        self.assertEqual(self.before_role,{n:state.capture(p) for n,p in self.paths.items()})
        self.assertEqual(b'app2200_business_rows',self.business.read_bytes())
    def test_process_loss_resumes_same_bot_key_and_cutoff_at_each_handoff(self):
        for boundary in ('prepared','private_files','include_handoff','pointer_switched','probed','runtime_active'):
            with self.subTest(boundary=boundary):
                pid=os.fork()
                if pid==0:
                    original=self.journal.begin if boundary=='prepared' else self.journal.checkpoint
                    def crash(scope,*args,**fields):
                        result=original(scope,*args,**fields)
                        if scope=='runtime' and (boundary=='prepared' or args[0]==boundary):os._exit(73)
                        return result
                    if boundary=='prepared':self.journal.begin=crash
                    else:self.journal.checkpoint=crash
                    self.op.enable();os._exit(74)
                _,status=os.waitpid(pid,0);self.assertEqual(73,os.waitstatus_to_exitcode(status))
                before=self.journal.load()['runtime'];self.assertEqual('runtime_active',self.op.enable()['phase'])
                after=self.journal.load()['runtime']
                self.assertEqual(before['generation'],after['generation']);self.assertEqual(before['cutoff'],after['cutoff'])
                self.assertEqual(before['files']['bot_key'],after['files']['bot_key']);self.op.disable()


class Application(unittest.TestCase):
    """Real physical pair copies. Docker/SQL are modeled; actual103-table PG is a separate native gate."""
    def setUp(self):
        import test_connect_con02_application as fixture
        import connect_con02_state as state
        import connect_con02_application as app
        import connect_role_bot_update as m
        f=fixture.Application(methodName='runTest');f.setUp();self.addCleanup(f.doCleanups)
        self.f=f;self.ctx=f.ctx;d=f.d;self.d=d;db=f.ctx.host.database('mdl_')
        db['addons'].update(local_ustar='2026102803',theme_ustar='2026102700')
        oldprofile=copy.deepcopy(d.reference['baseline']);oldprofile['versions']={'local_ustar':2026102803,'theme_ustar':2026102700}
        newprofile=copy.deepcopy(oldprofile);newprofile['versions']={'local_ustar':2026102900,'theme_ustar':2026102700}
        schema=list(db['schema']);indexes=list(db['indexes'])
        for table in sorted(m.NEW_TABLES):
            newprofile['schema_fields'][table]={'id':{'type':'int','length':'10','notnull':True}}
            newprofile['schema_indexes'][table]=[{'fields':['id'],'unique':True}]
            schema.append([table,'id','bigint','int8',None,64,0,'NO',None])
            indexes.append([table,'CREATE UNIQUE INDEX '+table+'_pk ON mdl_'+table+' USING btree (id)'])
        newprofile['tables']=sorted(set(oldprofile['tables'])|m.NEW_TABLES)
        self.new='local/ustar/role-bot.php';d.payload[self.new]=b'<?php // synthetic role bot source\n'
        newprofile['files']={n:c.sha(raw) for n,raw in d.payload.items()}
        d.reference={'baseline':oldprofile,'candidate':newprofile};self.ctx.bundle.reference={**d.reference,'tool_commit':'f'*40,'app_commit':m.APP_COMMIT}
        d.base=f.root/'role-app';d.marker=d.base/'uninitialized.json';self.ctx.verify_database=m.verify_database
        self.ctx.baseline_ack_enabled=True
        f.raw_cron=b"VERSIONS = {'local_ustar': '2026102803', 'theme_ustar': '2026102700'}\n";f.cron.write_bytes(f.raw_cron)
        record=json.loads(f.install.read_bytes());record.update(versions={'local_ustar':'2026102803','theme_ustar':'2026102700'},script_sha256=c.sha(f.raw_cron));f.install.write_text(json.dumps(record))
        self.ctx.patch_cron=lambda raw,record:m.patch_cron(raw,record,accepted_sha=c.sha(f.raw_cron))
        (d.p.PGDATA/'db.json').write_text(json.dumps(db));self.baseline=copy.deepcopy(db)
        def upgrade(host):
            d.attempt+=1;current=host.database('mdl_');current['addons'].update(local_ustar='2026102900',theme_ustar='2026102700')
            current.update(tables=newprofile['tables'],schema=sorted(schema),indexes=sorted(indexes))
            (d.p.PGDATA/'db.json').write_text(json.dumps(current))
            if d.attempt==d.fail_at:raise c.Refusal('synthetic_upgrade_failure')
        self.ctx.host.cli_upgrade=MethodType(upgrade,self.ctx.host)
        self.journal=state.Journal(self.ctx);self.op=app.Application(self.ctx,self.journal,state.Locks(self.ctx))
    def test_upgrade_and_repeat_preserve_policy_and_publish_new_readable_source(self):
        self.assertEqual('app_ready',self.op.upgrade()['phase']);self.assertTrue(self.op.upgrade()['verify_only'])
        self.assertEqual(2,self.d.attempt);self.assertEqual(self.baseline['tasks'],self.ctx.host.database('mdl_')['tasks'])
        self.assertEqual(b'<?php // synthetic role bot source\n',(self.d.web/self.new).read_bytes())
    def test_failure_before_publication_restores2803_pair_and_removes_added_source(self):
        self.d.fail_at=2;result=self.op.upgrade()
        self.assertEqual('baseline_restored',result['phase']);self.assertFalse(result['upgrade_succeeded'])
        self.assertEqual(self.baseline,self.ctx.host.database('mdl_'));self.assertFalse((self.d.web/self.new).exists())
        self.assertEqual(self.f.raw_cron,self.f.cron.read_bytes())
    def test_publication_recovery_preserves_new_business_writes(self):
        pid=os.fork()
        if pid==0:
            original=self.journal.checkpoint
            def crash(scope,phase,**fields):
                row=original(scope,phase,**fields)
                if phase=='public_release_committed':os._exit(73)
                return row
            self.journal.checkpoint=crash;self.op.upgrade();os._exit(74)
        _,status=os.waitpid(pid,0);self.assertEqual(73,os.waitstatus_to_exitcode(status))
        marker=self.d.p.DATA/'new-write';marker.write_bytes(b'preserve')
        self.assertEqual('app_ready',self.op.recover()['phase']);self.assertEqual(b'preserve',marker.read_bytes())
        with self.assertRaises(c.Refusal):self.op.restore(outage=True)
        self.assertEqual('baseline_restored',self.op.restore(outage=True,discard=True)['phase'])
        self.assertEqual(self.baseline,self.ctx.host.database('mdl_'))


class ActualCodePermissions(unittest.TestCase):
    setUp=Application.setUp
    def test_new_component_file_is_readable_but_not_writable_by_actual_uid33(self):
        import shutil
        mandatory=os.environ.get('USTAR_REQUIRE_ROLE_BOT_UID33')=='1'
        if os.geteuid()!=0 or not shutil.which('setpriv'):
            if mandatory:self.fail('Actual UID33 requires root and setpriv')
            self.skipTest('Actual UID33 requires root and setpriv')
        root=self.d.web/'local/ustar';self.f.root.chmod(0o755)
        for parent in root.parents:
            if parent==self.f.root:break
            parent.chmod(0o755)
        try:os.chown(root,0,33)
        except OSError:
            if mandatory:raise
            self.skipTest('Namespace cannot map GID33')
        root.chmod(0o755);self.assertEqual('app_ready',self.op.upgrade()['phase'])
        source=self.d.web/self.new
        program='import pathlib,sys;p=pathlib.Path(sys.argv[1]);assert p.read_bytes().startswith(b"<?php");\ntry:p.write_bytes(b"bad")\nexcept PermissionError:pass\nelse:raise AssertionError("web user wrote code")'
        result=subprocess.run(['setpriv','--reuid=33','--regid=33','--clear-groups',sys.executable,'-I','-c',program,str(source)],capture_output=True,text=True,timeout=10)
        self.assertEqual(0,result.returncode,result.stderr)


class Package(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import build_connect_role_bot_update as b
        cls.temp=tempfile.TemporaryDirectory();cls.addClassCleanup(cls.temp.cleanup);cls.root=Path(cls.temp.name)
        cls.commit=subprocess.check_output(['git','-C',str(ROOT),'rev-parse','HEAD'],text=True).strip()
        with contextlib.redirect_stdout(io.StringIO()):cls.artifact=b.build(cls.commit,cls.root/'one')
    def test_archive_is_deterministic_private_crc_complete_and_default_offline_isolated(self):
        import build_connect_role_bot_update as b
        import zipfile
        (self.artifact.parent/'connect_role_bot_update.py').write_text('raise RuntimeError("POISONED_SIBLING")')
        result=subprocess.run([sys.executable,'-I','-B',str(self.artifact)],capture_output=True,text=True,timeout=60)
        self.assertEqual(0,result.returncode,result.stderr)
        self.assertEqual({'phase':'PLAN_ONLY','host_access':False,'network_used':False},json.loads(result.stdout))
        with contextlib.redirect_stdout(io.StringIO()):other=b.build(self.commit,self.root/'two')
        first=next(self.artifact.parent.glob('*.tar.gz'));second=next(other.parent.glob('*.tar.gz'))
        self.assertEqual(first.read_bytes(),second.read_bytes())
        with tarfile.open(first) as archive:
            self.assertEqual({'update.py','observe.py','INSTALL.json','README.txt','SHA256SUMS'},set(archive.getnames()))
            self.assertTrue(all(m.isfile() and m.mode==0o600 and m.uid==m.gid==0 for m in archive))
            files={m.name:archive.extractfile(m).read() for m in archive}
        for line in files['SHA256SUMS'].decode().splitlines():
            digest,name=line.split('  ',1);self.assertEqual(digest,c.sha(files[name]))
        info=json.loads(files['INSTALL.json']);self.assertTrue(info['worker_rollout']);self.assertEqual(606,info['payload_files']);self.assertEqual(103,info['tables'])
        observer=self.artifact.parent/'observe.py';observer.write_bytes(files['observe.py'])
        result=subprocess.run([sys.executable,'-I','-B',str(observer)],capture_output=True,text=True,timeout=15)
        self.assertEqual(0,result.returncode,result.stderr);self.assertEqual('PLAN_ONLY',json.loads(result.stdout)['phase'])
        bundle=b.reference_from_git(ROOT,self.commit)
        with tarfile.open(fileobj=io.BytesIO(bundle.worker_archive)) as archive:
            for name in ('receiver.pyz','notifications.pyz','admin.pyz'):
                with zipfile.ZipFile(io.BytesIO(archive.extractfile(name).read())) as pyz:self.assertIsNone(pyz.testzip())


if __name__=='__main__':unittest.main()
