"""Private delivery inputs: no real server secrets, provider calls or application upgrade."""
import contextlib,hashlib,importlib.util,io,json,os,subprocess,sys,tempfile,unittest,zipfile
from pathlib import Path
from types import SimpleNamespace
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/infra'))
FILE=ROOT/'scripts/infra/connect_private_bridge.py'
if FILE.exists():
    spec=importlib.util.spec_from_file_location('private_bridge',FILE)
    x=importlib.util.module_from_spec(spec);spec.loader.exec_module(x)
else:x=SimpleNamespace()


class Configuration(unittest.TestCase):
    def test_include_handoff_is_before_bootstrap_without_rewriting_existing_settings(self):
        before=b"<?php\n$CFG->wwwroot='https://ustar.local';\n$CFG->sslproxy=true;\nrequire_once(__DIR__ . '/lib/setup.php');\n"
        after=x.patch_config(before)
        self.assertIn(before.split(b'require_once')[0],after)
        self.assertLess(after.index(b"/.ustar-connect/bridge.php"),after.index(b'/lib/setup.php'))
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
            git('-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','-qm','private check fixture')
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
