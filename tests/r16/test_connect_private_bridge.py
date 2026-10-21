"""Private delivery inputs: no real server secrets, provider calls or application upgrade."""
import contextlib,hashlib,importlib.util,io,json,os,subprocess,sys,tempfile,unittest,zipfile
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/infra'))
FILE=ROOT/'scripts/infra/connect_private_bridge.py'
if FILE.exists():
    spec=importlib.util.spec_from_file_location('private_bridge',FILE)
    x=importlib.util.module_from_spec(spec);spec.loader.exec_module(x)
else:x=SimpleNamespace()


class Configuration(unittest.TestCase):
    def test_plugin_flags_are_loaded_after_bootstrap_without_rewriting_core_settings(self):
        before=b"<?php\n$CFG->wwwroot='https://ustar.local';\n$CFG->sslproxy=true;\nrequire_once(__DIR__ . '/lib/setup.php');\n"
        after=x.patch_config(before)
        self.assertTrue(after.startswith(before))
        self.assertGreater(after.index(b"/.ustar-connect/bridge.php"),after.index(b'/lib/setup.php'))
        self.assertNotIn(b'ustar_connect_bridge_keys',after)
        for raw in (after,before.replace(b'require_once',b'// require_once'),before+b'echo "unexpected";',
                    before.replace(b'$CFG->sslproxy',b'$CFG->ustar_connect_bridge_enabled=false;\n$CFG->sslproxy')):
            with self.assertRaises(x.Refusal):x.patch_config(raw)

    def test_settings_have_exact_link_only_scope_and_current_bot_channel(self):
        value=x.php_settings('12'*32,'123456').decode()
        self.assertIn("'operations'=>['link.claim']",value)
        self.assertIn("'tenant'=>'123456'",value)
        self.assertIn("'username'=>'ustarcorp_bot'",value)
        self.assertIn("$CFG->ustar_connect_link_transport='chat';",value)
        self.assertIn("['172.18.0.1']",value)
        for forbidden in ('notification.lease','actor.open','registration.submit','entry_enabled'):
            self.assertNotIn(forbidden,value)
        for secret,bot in [('INVALID_SECRET','123456'),('12'*32,"123';echo 'bad"),('12'*32,'0')]:
            with self.assertRaises(x.Refusal):x.php_settings(secret,bot)

    def test_receiver_config_uses_only_own_systemd_credentials_and_preserves_proxy_state(self):
        def config(unit):
            return {'bot_token_file':'/run/credentials/ustar-connect-'+unit+'.service/telegram.token',
                'bot_username':'ustarcorp_bot','state_directory':'/var/lib/ustar-connect-receiver',
                'telegram_proxy':'http://127.0.0.1:18081','onboarding_enabled':False,
                'bridge_endpoint':None,'bridge_key_id':None,'bridge_key_file':None,'bridge_ca_file':None}
        for unit in ('probe','receiver'):
            before=config(unit);after=json.loads(x.receiver_settings(json.dumps(before).encode(),unit))
            for name in ('bot_token_file','bot_username','state_directory','telegram_proxy','onboarding_enabled'):
                self.assertEqual(before[name],after[name])
            self.assertTrue(after['linking_enabled'])
            self.assertEqual('/run/credentials/ustar-connect-'+unit+'.service/bridge.key',after['bridge_key_file'])
            self.assertEqual('https://127.0.0.1:18443/local/ustar/connect_bridge.php',after['bridge_endpoint'])
            for name,bad in [('telegram_proxy','http://unknown.invalid'),('onboarding_enabled',True),('bot_username','other_bot'),('state_directory','/other')]:
                with self.assertRaises(x.Refusal):x.receiver_settings(json.dumps({**before,name:bad}).encode(),unit)

    def test_default_plan_does_not_access_host(self):
        with contextlib.redirect_stdout(io.StringIO()) as out:
            self.assertEqual(0,x.main([]))
        self.assertEqual({'phase':'plan_only','host_access':False},json.loads(out.getvalue()))


class ReadonlyDelivery(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name)
        code=self.root/'opt/ustar/data/moodle/public';code.mkdir(parents=True)
        (code/'public').mkdir();(code/'config.php').write_bytes(b'PRIVATE_DATABASE_CANARY')
        self.ref={'schema':1,'tool_commit':'1'*40,'payload_commit':'1e8a8819f50785ebb4fb2640eeb9386c969d59d1','files':{}}
        for name in x.SECURITY_FILES:
            path=code/'public'/name;path.parent.mkdir(parents=True,exist_ok=True)
            path.write_bytes(b'fixed reviewed source');self.ref['files'][name]=hashlib.sha256(path.read_bytes()).hexdigest()
        config=self.root/'etc/ustar-connect';config.mkdir(parents=True,mode=0o700)
        for unit in ('probe','receiver'):
            value={'bot_token_file':'/run/credentials/ustar-connect-'+unit+'.service/telegram.token',
                'bot_username':'ustarcorp_bot','state_directory':'/var/lib/ustar-connect-receiver',
                'telegram_proxy':'http://127.0.0.1:18081','onboarding_enabled':False,
                'bridge_endpoint':None,'bridge_key_id':None,'bridge_key_file':None,'bridge_ca_file':None}
            p=config/(unit+'.json');p.write_text(json.dumps(value));p.chmod(0o600)
        token=config/'telegram.token';token.write_text('123456:'+'TOKEN_CANARY_'+'x'*30);token.chmod(0o600)
        self.manager=SimpleNamespace(p=SimpleNamespace(old=SimpleNamespace(config=code/'config.php')),
            load=lambda:{'phase':'active','tool_sha':x.LEGACY_HTTPS_SHA},guards=lambda s:None,
            verify=lambda s:None,host=SimpleNamespace(runtime=lambda p:{'id':'a'*64,'config':'/var/www/html/config.php'}))
        self.inst=SimpleNamespace(config=config,units=self.root/'etc/systemd/system',
            current=lambda:Path('/verified/release')/('2'*40),release_files=lambda p:{},
            check_units=lambda v:{'ustar-connect-'+n+'.service':b'fixed unit' for n in ('receiver','probe','notify')},
            host=SimpleNamespace(active=lambda n:n=='ustar-connect-receiver.service'))
        self.host=SimpleNamespace(gateway=lambda rt:'172.18.0.1',port_free=lambda:True)
        self.check=x.Readiness(self.ref,self.manager,self.inst,self.host,self.root,uid=os.geteuid(),
            lib=SimpleNamespace(read_file=lambda path,*args:path.read_bytes()))

    def test_check_is_narrow_readonly_and_does_not_print_credentials(self):
        before={str(p):p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
        value=self.check.check()
        self.assertEqual('ready_for_private_bridge_preparation',value['phase'])
        self.assertEqual(len(x.SECURITY_FILES),value['reviewed_transport_files'])
        self.assertFalse(value['configuration_changed']);self.assertFalse(value['telegram_API_called'])
        self.assertNotIn('CANARY',json.dumps(value))
        self.assertEqual(before,{str(p):p.read_bytes() for p in self.root.rglob('*') if p.is_file()})

    def test_writable_or_foreign_parent_unknown_paths_and_source_drift_refuse(self):
        parent=self.manager.p.old.config.parent;parent.chmod(0o777)
        with self.assertRaisesRegex(x.Refusal,'unsafe_include_parent'):self.check.check()
        parent.chmod(0o755)
        new=parent/'.ustar-connect';new.mkdir()
        with self.assertRaisesRegex(x.Refusal,'existing_private_bridge_path'):self.check.check()
        new.rmdir()
        source=parent/'public'/x.SECURITY_FILES[0];source.write_text('ADMIN_CHANGE')
        with self.assertRaisesRegex(x.Refusal,'transport_source_drift'):self.check.check()
        self.assertEqual('ADMIN_CHANGE',source.read_text())

    def test_wrong_gateway_or_missing_security_proof_cannot_report_ready(self):
        self.host.gateway=lambda rt:'172.19.0.1'
        with self.assertRaisesRegex(x.Refusal,'backend_peer_changed'):self.check.check()
        self.host.gateway=lambda rt:'172.18.0.1'
        self.ref['files'].pop(x.SECURITY_FILES[0])
        with self.assertRaisesRegex(x.Refusal,'invalid_transport_reference'):self.check.check()

    def test_refusal_reports_entire_unsafe_chain_before_any_mutation(self):
        code=self.manager.p.old.config.parent
        paths=(code,code.parent,code.parent.parent)
        for path in paths:path.chmod(0o777)
        with self.assertRaises(x.Refusal) as caught:self.check.check()
        details=getattr(caught.exception,'details',{})
        self.assertIn('unsafe_parents',details)
        self.assertEqual([str(p) for p in paths],[p['path'] for p in details['unsafe_parents']])
        self.assertTrue(all((p.stat().st_mode&0o777)==0o777 for p in paths))


@unittest.skipUnless(os.geteuid()==0,'actual UID33 ownership fixture requires root')
class ParentPreparation(ReadonlyDelivery):
    def setUp(self):
        super().setUp()
        self.assertTrue(hasattr(x,'ParentPreparation'),'guarded parent preparation is missing')
        self.root.chmod(0o755)
        self.code=self.manager.p.old.config.parent
        try:os.chown(self.code,33,33)
        except OSError:
            if os.environ.get('USTAR_REQUIRE_PRIVATE_PARENT_FIXTURE')=='1':raise
            self.skipTest('runtime does not map actual UID/GID33; strict CI host fixture required')
        config=self.code/'config.php';os.chown(config,33,0);config.chmod(0o640)
        os.setxattr(config,'user.fixture',b'config metadata canary')
        os.setxattr(self.code,'user.fixture',b'directory metadata canary')
        spec=importlib.util.spec_from_file_location('parent_library',ROOT/'scripts/infra/academy_tls_probe.py')
        lib=importlib.util.module_from_spec(spec);spec.loader.exec_module(lib)
        self.check.lib=lib

    def test_writable_or_foreign_parent_unknown_paths_and_source_drift_refuse(self):
        # The inherited readonly cases assume a root-owned parent.
        os.chown(self.code,0,33)
        super().test_writable_or_foreign_parent_unknown_paths_and_source_drift_refuse()

    def test_check_is_narrow_readonly_and_does_not_print_credentials(self):
        before=self.code.stat()
        with self.assertRaisesRegex(x.Refusal,'unsafe_include_parent'):self.check.check()
        self.assertEqual(33,self.code.stat().st_uid)
        self.assertEqual(before.st_ctime_ns,self.code.stat().st_ctime_ns)

    def test_wrong_gateway_or_missing_security_proof_cannot_report_ready(self):
        os.chown(self.code,0,33)
        super().test_wrong_gateway_or_missing_security_proof_cannot_report_ready()

    def test_prepare_changes_only_parent_uid_and_preserves_children_mode_and_xattrs(self):
        before={str(p):(p.read_bytes(),p.stat().st_uid,p.stat().st_gid,p.stat().st_mode)
                for p in self.code.rglob('*') if p.is_file()}
        self.assertTrue(hasattr(x,'ParentPreparation'),'guarded parent preparation is missing')
        operation=x.ParentPreparation(self.check)
        report=operation.prepare()
        self.assertEqual('include_parent_prepared',report['phase'])
        self.assertEqual((0,33,0o755),(self.code.stat().st_uid,self.code.stat().st_gid,self.code.stat().st_mode&0o7777))
        self.assertEqual(b'directory metadata canary',os.getxattr(self.code,'user.fixture'))
        self.assertEqual(b'config metadata canary',os.getxattr(self.code/'config.php','user.fixture'))
        self.assertEqual(before,{str(p):(p.read_bytes(),p.stat().st_uid,p.stat().st_gid,p.stat().st_mode)
                                for p in self.code.rglob('*') if p.is_file()})
        self.assertFalse(report['configuration_changed']);self.assertFalse(report['key_generated'])
        self.assertEqual('ready_for_private_bridge_preparation',self.check.check()['phase'])
        repeated=operation.prepare()
        self.assertEqual('include_parent_prepared',repeated['phase'])
        self.assertFalse(repeated['directory_ownership_changed'])
        # A real UID33 process may read the future include but cannot replace it.
        private=self.code/'.ustar-connect';private.mkdir(mode=0o750);os.chown(private,0,33)
        include=private/'bridge.php';include.write_bytes(b'PRIVATE_SYNTHETIC_KEY');include.chmod(0o640);os.chown(include,0,33)
        def uid33():os.setgroups([]);os.setgid(33);os.setuid(33)
        code='from pathlib import Path; import os; p=Path(os.environ["FIXTURE_INCLUDE"]); assert p.read_bytes()==b"PRIVATE_SYNTHETIC_KEY"; os.rename(p.parent,p.parent.with_name("replaced"))'
        result=subprocess.run(['/usr/bin/python3','-c',code],env={**os.environ,'FIXTURE_INCLUDE':str(include)},
                              preexec_fn=uid33,capture_output=True,text=True)
        self.assertNotEqual(0,result.returncode);self.assertIn('PermissionError',result.stderr)

    def test_other_refusals_leave_original_owner_and_create_no_transaction(self):
        self.assertTrue(hasattr(x,'ParentPreparation'),'guarded parent preparation is missing')
        self.host.gateway=lambda rt:'172.19.0.1'
        with self.assertRaisesRegex(x.Refusal,'backend_peer_changed'):x.ParentPreparation(self.check).prepare()
        self.assertEqual(33,self.code.stat().st_uid)
        self.assertFalse((self.root/'var/lib/ustar-connect-private-parent').exists())
        self.host.gateway=lambda rt:'172.18.0.1'
        self.code.chmod(0o775)
        with self.assertRaisesRegex(x.Refusal,'unsafe_include_parent'):x.ParentPreparation(self.check).prepare()

    def test_interrupted_chown_resumes_without_replacing_before_image(self):
        self.assertTrue(hasattr(x,'ParentPreparation'),'guarded parent preparation is missing')
        operation=x.ParentPreparation(self.check)
        original=self.check.lib.atomic
        def interrupted(path,raw,*args):
            if json.loads(raw).get('phase')=='prepared':raise OSError('simulated acknowledgement loss')
            return original(path,raw,*args)
        with patch.object(self.check.lib,'atomic',side_effect=interrupted):
            with self.assertRaises(OSError):operation.prepare()
        self.assertEqual(0,self.code.stat().st_uid)
        saved=operation.state.read_bytes()
        self.assertEqual('preparing',json.loads(saved)['phase'])
        self.assertEqual('include_parent_prepared',operation.prepare()['phase'])
        self.assertEqual(33,json.loads(operation.state.read_bytes())['before']['uid'])
        self.assertEqual('include_parent_restored',operation.restore()['phase'])
        self.assertEqual((33,33,0o755),(self.code.stat().st_uid,self.code.stat().st_gid,self.code.stat().st_mode&0o7777))

    def test_restore_refuses_active_include_or_administrator_drift(self):
        self.assertTrue(hasattr(x,'ParentPreparation'),'guarded parent preparation is missing')
        operation=x.ParentPreparation(self.check);operation.prepare()
        marker=self.code/'.ustar-connect';marker.mkdir()
        with self.assertRaisesRegex(x.Refusal,'existing_private_bridge_path'):operation.restore()
        self.assertEqual(0,self.code.stat().st_uid);marker.rmdir()
        os.setxattr(self.code,'user.fixture',b'ADMIN_CHANGE')
        with self.assertRaisesRegex(x.Refusal,'include_parent_metadata_drift'):operation.restore()
        self.assertEqual(0,self.code.stat().st_uid)

    def test_unexpected_ancestor_and_different_uid_refuse_before_chown(self):
        operation=x.ParentPreparation(self.check)
        os.chown(self.code.parent,33,33)
        with self.assertRaisesRegex(x.Refusal,'unsafe_include_parent'):operation.prepare()
        self.assertEqual(33,self.code.stat().st_uid)
        os.chown(self.code.parent,0,0);os.chown(self.code,34,33)
        with self.assertRaisesRegex(x.Refusal,'unsafe_include_parent'):operation.prepare()
        self.assertEqual(34,self.code.stat().st_uid)

    def test_lost_restore_ack_resumes_and_unknown_owner_drift_is_preserved(self):
        operation=x.ParentPreparation(self.check);operation.prepare()
        original=self.check.lib.atomic
        def interrupted(path,raw,*args):
            if json.loads(raw).get('phase')=='restored':raise OSError('restore acknowledgement lost')
            return original(path,raw,*args)
        with patch.object(self.check.lib,'atomic',side_effect=interrupted):
            with self.assertRaises(OSError):operation.restore()
        self.assertEqual(33,self.code.stat().st_uid)
        self.assertEqual('restoring',json.loads(operation.state.read_bytes())['phase'])
        self.assertEqual('include_parent_restored',operation.restore()['phase'])
        operation.prepare();os.chown(self.code,33,33)
        with self.assertRaisesRegex(x.Refusal,'include_parent_unexpected_owner'):operation.prepare()
        self.assertEqual(33,self.code.stat().st_uid)

    def test_measured_ancestor_and_code_are_prepared_and_restored_together(self):
        ancestor=self.code.parent;os.chown(ancestor,33,33);ancestor.chmod(0o775)
        os.setxattr(ancestor,'user.fixture',b'ancestor metadata')
        before={str(p):(p.read_bytes(),p.stat().st_uid,p.stat().st_gid,p.stat().st_mode)
                for p in ancestor.rglob('*') if p.is_file()}
        operation=x.ParentPreparation(self.check);report=operation.prepare()
        self.assertEqual('include_parent_prepared',report['phase'])
        for path in (ancestor,self.code):
            self.assertEqual((0,33,0o755),(path.stat().st_uid,path.stat().st_gid,path.stat().st_mode&0o7777))
        self.assertEqual(2,len(report['directories']))
        self.assertEqual(b'ancestor metadata',os.getxattr(ancestor,'user.fixture'))
        self.assertEqual(before,{str(p):(p.read_bytes(),p.stat().st_uid,p.stat().st_gid,p.stat().st_mode)
                                for p in ancestor.rglob('*') if p.is_file()})
        self.assertFalse(operation.prepare()['directory_ownership_changed'])
        self.assertEqual('include_parent_restored',operation.restore()['phase'])
        self.assertEqual((33,33,0o775),(ancestor.stat().st_uid,ancestor.stat().st_gid,ancestor.stat().st_mode&0o7777))
        self.assertEqual(33,self.code.stat().st_uid)

    def test_chain_resumes_after_each_ownership_and_mode_boundary(self):
        ancestor=self.code.parent
        for target_call in range(1,4):
            with self.subTest(boundary=target_call):
                os.chown(ancestor,33,33);ancestor.chmod(0o775)
                os.chown(self.code,33,33);self.code.chmod(0o755)
                operation=x.ParentPreparation(self.check)
                if operation.state.exists():operation.state.unlink()
                real_chown=os.fchown;real_chmod=os.fchmod;count=0
                def wrapped(function):
                    def apply(*args):
                        nonlocal count
                        result=function(*args);count+=1
                        if count==target_call:raise OSError('interrupted after filesystem mutation')
                        return result
                    return apply
                with patch.object(os,'fchown',side_effect=wrapped(real_chown)),patch.object(os,'fchmod',side_effect=wrapped(real_chmod)):
                    with self.assertRaises(OSError):operation.prepare()
                self.assertEqual('include_parent_prepared',operation.prepare()['phase'])
                state=json.loads(operation.state.read_bytes())
                self.assertEqual(33,state['ancestor_before']['uid'])
                self.assertEqual(0o775,state['ancestor_before']['mode'])
                self.assertEqual('include_parent_restored',operation.restore()['phase'])

    def test_other_unsafe_ancestors_refuse_all_changes_and_report_all(self):
        ancestor=self.code.parent;os.chown(ancestor,33,33);ancestor.chmod(0o775)
        ancestor.parent.chmod(0o777)
        operation=x.ParentPreparation(self.check)
        with self.assertRaisesRegex(x.Refusal,'unsafe_include_parent') as caught:operation.prepare()
        self.assertEqual(3,len(caught.exception.details['unsafe_parents']))
        self.assertFalse(operation.state.exists())
        self.assertEqual((33,33),(self.code.stat().st_uid,ancestor.stat().st_uid))

    def test_chain_restore_interruption_and_ancestor_drift_are_guarded(self):
        ancestor=self.code.parent;os.chown(ancestor,33,33);ancestor.chmod(0o775)
        operation=x.ParentPreparation(self.check);operation.prepare()
        real_chmod=os.fchmod
        def interrupted(fd,mode):
            real_chmod(fd,mode)
            if os.path.isdir('/proc/self/fd/'+str(fd)):
                raise OSError('restore chmod acknowledgement lost')
        with patch.object(os,'fchmod',side_effect=interrupted):
            with self.assertRaises(OSError):operation.restore()
        self.assertEqual('restoring',json.loads(operation.state.read_bytes())['phase'])
        self.assertEqual('include_parent_restored',operation.restore()['phase'])
        operation.prepare();os.setxattr(ancestor,'user.fixture',b'ADMIN_CHANGE')
        with self.assertRaisesRegex(x.Refusal,'include_parent_metadata_drift'):operation.restore()
        self.assertEqual(0,ancestor.stat().st_uid)

    def test_ancestor_acl_mask_is_narrowed_and_restored_without_changing_named_entries(self):
        import struct
        ancestor=self.code.parent;os.chown(ancestor,33,33);ancestor.chmod(0o775)
        # An extended ACL with group-write in its mask and a named group entry.
        raw=struct.pack('<I',2)+b''.join(struct.pack('<HHI',tag,perm,ident)
            for tag,perm,ident in [(1,7,0xffffffff),(4,7,0xffffffff),(8,7,34),(16,7,0xffffffff),(32,5,0xffffffff)])
        os.setxattr(ancestor,'system.posix_acl_access',raw)
        before=os.getxattr(ancestor,'system.posix_acl_access')
        operation=x.ParentPreparation(self.check);operation.prepare()
        after=os.getxattr(ancestor,'system.posix_acl_access')
        expected=bytearray(before);struct.pack_into('<H',expected,4+3*8+2,5)
        self.assertEqual(bytes(expected),after)
        operation.restore()
        self.assertEqual(before,os.getxattr(ancestor,'system.posix_acl_access'))

    def test_chain_inode_swap_after_intent_refuses_before_any_ownership_write(self):
        ancestor=self.code.parent;os.chown(ancestor,33,33);ancestor.chmod(0o775)
        operation=x.ParentPreparation(self.check);original=self.check.lib.atomic
        displaced=ancestor.with_name('displaced-moodle')
        def swapped(path,raw,*args):
            result=original(path,raw,*args)
            if not displaced.exists():
                ancestor.rename(displaced);ancestor.mkdir(mode=0o775);os.chown(ancestor,33,33);ancestor.chmod(0o775)
            return result
        with patch.object(self.check.lib,'atomic',side_effect=swapped):
            with self.assertRaisesRegex(x.Refusal,'include_parent_replaced'):operation.prepare()
        self.assertEqual(33,ancestor.stat().st_uid)
        self.assertEqual(33,displaced.stat().st_uid)
        self.assertEqual(33,(displaced/'public').stat().st_uid)


class BuildPackage(unittest.TestCase):
    def test_exact_git_package_is_deterministic_plan_only_and_detects_corruption(self):
        module=ROOT/'scripts/infra/build_connect_private_bridge.py'
        self.assertTrue(module.exists(),'private bridge check builder missing')
        spec=importlib.util.spec_from_file_location('build_private_bridge',module)
        builder=importlib.util.module_from_spec(spec);spec.loader.exec_module(builder)
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);repo=root/'repo'
            def git(*args):return subprocess.check_output(['git','-C',str(repo),*args],stderr=subprocess.DEVNULL)
            subprocess.check_call(['git','clone','-q','--shared',str(ROOT),str(repo)])
            for name in ('connect_private_bridge','build_connect_private_bridge'):
                (repo/('scripts/infra/'+name+'.py')).write_bytes((ROOT/('scripts/infra/'+name+'.py')).read_bytes())
            git('add','scripts/infra/connect_private_bridge.py','scripts/infra/build_connect_private_bridge.py')
            git('-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','--allow-empty','-qm','private check fixture')
            commit=git('rev-parse','HEAD').decode().strip()
            a=builder.build(commit,root/'a',repo=repo);b=builder.build(commit,root/'b',repo=repo)
            self.assertEqual(a.read_bytes(),b.read_bytes())
            value=x.bundle(a);self.assertEqual(commit,value['tool_commit'])
            result=subprocess.run([sys.executable,'-I',str(a)],capture_output=True,text=True,check=True)
            self.assertEqual({'phase':'plan_only','host_access':False},json.loads(result.stdout))
            with zipfile.ZipFile(a) as z:files={n:z.read(n) for n in z.namelist()}
            files['connect_private_bridge.py']+=b'\n# tampered\n'
            broken=root/'broken.pyz'
            with zipfile.ZipFile(broken,'w') as z:
                for name,raw in files.items():z.writestr(name,raw)
            with self.assertRaisesRegex(x.Refusal,'bundle_digest_changed'):x.bundle(broken)
            path=repo/'scripts/infra/connect_private_bridge.py';path.write_bytes(path.read_bytes()+b'\n# local drift\n')
            with patch.object(builder,'ROOT',repo),self.assertRaisesRegex(ValueError,'dirty_builder_dependency'):
                builder.build(commit,root/'dirty',repo=repo)
