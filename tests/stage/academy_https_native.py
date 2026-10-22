#!/usr/bin/env python3
"""Native Apache/OpenSSL/PHP fixture; systemd and Docker boundaries are modeled."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
import http.client
import socket
import ssl

ROOT=Path(__file__).resolve().parents[2]
sys.path[:0]=[str(ROOT/'scripts/infra'),str(ROOT/'scripts')]
sys.path.insert(0,str(ROOT/'connect-worker'))
from ustar_connect.bridge import BridgeClient,PATH as BRIDGE_PATH,sign_request
from connect_chat_link_update import LoginProbe
import hdd_backup
def module(name,path):
    s=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
lib=module('tls_library',ROOT/'scripts/infra/academy_tls_probe.py')
x=module('https_manager',ROOT/'scripts/infra/academy_https.py')
def run(args,raw=None):
    p=subprocess.run(args,input=raw,capture_output=True,timeout=120)
    if p.returncode:raise RuntimeError('NATIVE_COMMAND_FAILED: '+p.stderr.decode(errors='replace')[-2000:])
    return p.stdout+p.stderr

def main(verification=None):
    if os.geteuid()!=0 or os.environ.get('USTAR_ACADEMY_TLS_FIXTURE')!='1' or Path('/opt/ustar').exists():raise RuntimeError('ISOLATED_ROOT_FIXTURE_REQUIRED')
    apache=shutil.which('apache2');php=shutil.which('php')
    if not apache or not php:raise RuntimeError('APACHE_AND_PHP_REQUIRED')
    with tempfile.TemporaryDirectory(prefix='ustar-https-native-') as temp:
        root=Path(temp);root.chmod(0o755);p=x.Paths(lib,root)
        for folder in (p.old.site.parent,p.old.enabled.parent,p.old.config.parent,p.old.ca.parent,p.service.parent,p.tool.parent):folder.mkdir(parents=True,exist_ok=True)
        original=b"<?php\n$CFG = new stdClass();\n$origin='http://ustar.local';\n$CFG->wwwroot=$origin;\nrequire_once(__DIR__ . '/lib/setup.php');\n"
        p.old.config.write_bytes(original);p.old.config.chmod(0o640)
        setup=p.old.config.parent/'lib';setup.mkdir();(setup/'setup.php').write_text('<?php // Synthetic backend; real Moodle bootstrap is a separate CI gate.\n')
        cli=p.old.config.parent/'admin/cli';cli.mkdir(parents=True);(cli/'purge_caches.php').write_text('<?php echo "fixture_cache_purge";')
        login=p.old.config.parent/'login';login.mkdir()
        (login/'index.php').write_text('''<?php
require dirname(__DIR__) . '/config.php';
header('Set-Cookie: MoodleSessionFixture=synthetic; Path=/; HttpOnly' . (!empty($CFG->cookiesecure) ? '; Secure' : ''));
echo '<html><form>fixture login</form></html>';
''')
        bridge=p.old.config.parent/'local/ustar/connect_bridge.php';bridge.parent.mkdir(parents=True)
        # Actual installed facade/verifier/dispatcher. Only Moodle bootstrap is
        # modeled here; the separate Moodle/PG gate proves sslproxy normalization.
        bridge.write_bytes((ROOT/'moodle/local/ustar/connect_bridge.php').read_bytes())
        (setup/'setup.php').write_text('''<?php
define('MOODLE_INTERNAL',true);
$_SERVER['HTTPS']=!empty($CFG->sslproxy) ? 'on' : '';
$CFG->ustar_connect_bridge_enabled=true;
$CFG->ustar_connect_bridge_peers=['127.0.0.1'];
$CFG->ustar_connect_bridge_keys=['tg_link'=>['secret'=>str_repeat('12',32),'provider'=>'telegram','tenant'=>'123456','operations'=>['link.claim']]];
spl_autoload_register(function($name){$prefix='local_ustar\\\\connect\\\\';if(str_starts_with($name,$prefix)){require '''+json.dumps(str(ROOT/'moodle/local/ustar/classes/connect'))+''' . '/' . substr($name,strlen($prefix)) . '.php';}});
''')
        p.tool.write_text('fixture installed tool; systemd boundary modeled');p.tool.chmod(0o600)
        p.include.write_text('Header always set X-USTAR-Observed fixture\nCustomLog /dev/null common\n');p.include.chmod(0o644)
        run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','90','-subj','/CN=Native HTTPS fixture CA','-addext','basicConstraints=critical,CA:TRUE','-addext','keyUsage=critical,keyCertSign,cRLSign','-keyout',str(p.old.ca_key),'-out',str(p.old.ca)])
        for path in (p.old.ca,p.old.ca_key):path.chmod(0o600)
        http_site=f'''<VirtualHost *:18081>
ServerName ustar.local
ProxyPreserveHost On
ProxyPass / http://127.0.0.1:18082/
ProxyPassReverse / http://127.0.0.1:18082/
Include {p.include}
</VirtualHost>
'''
        p.http.write_text(http_site);p.http.chmod(0o644);p.http_enabled.symlink_to(p.http)
        default=p.old.enabled.parent/'000-default.conf';default.write_text('<VirtualHost *:18081>\nServerName other.invalid\nDocumentRoot "'+str(login)+'"\n</VirtualHost>\n')
        conf=root/'apache.conf';pid=root/'apache.pid'
        mods=('mpm_event','authz_core','authz_host','alias','headers','dir','ssl','socache_shmcb','proxy','proxy_http')
        conf.write_text('\n'.join([f'ServerRoot "{root}"',f'PidFile "{pid}"','ServerName other.invalid','Listen 127.0.0.1:18081','Listen 127.0.0.1:18444',*[f'LoadModule {m}_module /usr/lib/apache2/modules/mod_{m}.so' for m in mods],'User www-data','Group www-data','DirectoryIndex index.php',f'DocumentRoot "{login}"',f'<Directory "{login}">','Require all granted','</Directory>',f'ErrorLog "{root}/error.log"',f'IncludeOptional "{p.old.enabled.parent}/*.conf"'])+'\n')
        log=(root/'process.log').open('wb')
        # The fixture replaces config.php repeatedly in one long-lived CLI
        # process. Disable its CLI opcode cache explicitly; this is not the
        # production Apache/PHP runtime or a relaxation of Secure-cookie checks.
        backend=subprocess.Popen([php,'-d','opcache.enable_cli=0','-d','opcache.enable=0',
            '-S','127.0.0.1:18082','-t',str(p.old.config.parent)],stdout=log,stderr=log)
        server=subprocess.Popen([apache,'-f',str(conf),'-DFOREGROUND'],stdout=log,stderr=log)
        try:
            class NativeHost(x.Host):
                HTTP_PORT,HTTPS_PORT,BACKEND_PORT,BRIDGE_PORT=18081,18444,18082,18445
                def __init__(self):
                    super().__init__(lib);self.tls.HTTP_PORT=self.HTTP_PORT;self.tls.HTTPS_PORT=self.HTTPS_PORT
                    self.timer=False;self.force_due=False;self.lose_ack=False
                def units(self):return {'apache2':int(pid.read_text()),'caddy':1234567,'ustar-console':1234568}
                def vhosts(self):return lib.parse_vhosts(run([apache,'-f',str(conf),'-t','-D','DUMP_VHOSTS']).decode())
                def test_config(self):run([apache,'-f',str(conf),'-t']);return self.vhosts()
                def reload(self):
                    run([apache,'-f',str(conf),'-k','graceful'])
                    if self.lose_ack:self.lose_ack=False;raise x.Refusal('lost_reload_ack')
                def runtime(self,path):return {'id':'native_PHP_fixture','config':str(path)}
                def metadata(self,rt):
                    code='define("CLI_SCRIPT",true); require '+json.dumps(rt['config'])+'; echo json_encode(["url"=>$CFG->wwwroot,"sslproxy"=>!empty($CFG->sslproxy),"cookiesecure"=>!empty($CFG->cookiesecure)]);'
                    return json.loads(run([php,'-r',code]))
                def lint(self,rt,raw):run([php,'-l'],raw)
                def purge(self,rt):run([php,str(cli/'purge_caches.php')])
                def unit_available(self):return not self.timer
                def timer_start(self):self.timer=True
                def timer_probe(self):assert self.timer and manager.load()['phase']=='active'
                def timer_stop(self):self.timer=False
                def daemon_reload(self):pass
                def due(self,cert):return self.force_due
            host=NativeHost()
            for _ in range(60):
                if backend.poll() is not None or server.poll() is not None:raise RuntimeError('NATIVE_SERVICE_EXITED')
                try:
                    if pid.exists() and host.http_status()==200:break
                except Exception:pass
                time.sleep(.1)
            else:raise RuntimeError('NATIVE_START_TIMEOUT')
            # Existing owner-accepted probe on first TLS binding, independent of product cutover.
            lib.directory(p.old.base,0o700,0,root);probe_folder=p.old.base/'probe';probe_folder.mkdir(mode=0o700)
            leaf=host.certificate(p.old.ca,p.old.ca_key,probe_folder)
            lib.directory(p.old.web,0o755,0,root);lib.directory(p.old.health.parent,0o755,0,root)
            body=b'original accepted probe';lib.atomic(p.old.health,body,0o644)
            text=lib.render_vhost(p.old,probe_folder/'server.crt',probe_folder/'server.key',18444).encode();lib.atomic(p.old.site,text,0o644);p.old.enabled.symlink_to(p.old.site)
            host.test_config();host.reload()
            before_first={'*:18081':host.vhosts()['*:18081']}
            probe_state={'schema':1,'phase':'tls_probe_ready','first_tls':True,'before':before_first,'units':host.units(),'http_status':200,'site_sha256':lib.digest(text),'health_sha256':lib.digest(body),'leaf':leaf}
            lib.atomic(p.old.state,(json.dumps(probe_state)+'\n').encode())
            manager=x.Manager(lib,p,host,baseline_config=x.sha(original),ca_sha=host.authority(p.old.ca,p.old.ca_key))
            before={name:path.read_bytes() for name,path in manager.files().items()};ca_before={q.name:q.read_bytes() for q in (p.old.ca,p.old.ca_key,p.include)}
            assert manager.check()
            assert manager.cutover()['phase']=='active'
            update_probe=LoginProbe();update_probe.HTTPS_PORT=host.HTTPS_PORT
            update_probe.app_check(p.old.ca,manager.load()['leaf'])
            # Actual TLS/SNI/leaf/cookie readiness used by the HDD adapter.
            hdd_backup.HTTPS_STATE=p.state;hdd_backup.HTTPS_CA=p.old.ca;hdd_backup.HTTPS_PORT=host.HTTPS_PORT
            hdd_backup.parents_trusted=lambda path:None  # Disposable /tmp ancestor only.
            assert hdd_backup.https_ready()['secure_session_cookie'] is True
            status,headers,body,peer=host.request(p.old.ca,'/login/index.php')
            assert status==200 and ('X-USTAR-Observed','fixture') in headers
            assert manager.load()['leaf']['fingerprint']==peer
            assert manager.cutover()['phase']=='active'
            assert manager.renew()['phase']=='renewal_not_due'
            host.force_due=True;old_leaf=manager.load()['leaf']['fingerprint']
            assert manager.renew()['phase']=='renewed' and manager.load()['leaf']['fingerprint']!=old_leaf
            current=manager.load()['leaf'];host.lose_ack=True
            try:manager.renew()
            except x.Refusal:pass
            else:raise AssertionError('LOST_RENEW_ACK_ACCEPTED')
            assert manager.status()['phase']=='active' and manager.load()['leaf']==current
            bridge_url='https://127.0.0.1:'+str(host.BRIDGE_PORT)+BRIDGE_PATH
            client=BridgeClient(bridge_url,'tg_link','12'*32,ca_file=str(p.old.ca))
            def private_acceptance():
                client.probe_link_scope()
                with socket.create_connection(('127.0.0.1',host.BRIDGE_PORT),timeout=5) as connection:
                    with ssl.create_default_context(cafile=str(p.old.ca)).wrap_socket(connection,server_hostname='127.0.0.1') as stream:
                        assert x.sha(stream.getpeercert(binary_form=True))==manager.load()['leaf']['fingerprint']
                for private_path in ('/','/login/index.php',BRIDGE_PATH):
                    conn=http.client.HTTPSConnection('127.0.0.1',host.BRIDGE_PORT,context=ssl.create_default_context(cafile=str(p.old.ca)),timeout=5)
                    try:
                        conn.request('GET',private_path);response=conn.getresponse();assert response.status==403;response.read()
                    finally:conn.close()
                signed=sign_request('registration.options',{'probe':'link-scope-v1'},key_id='tg_link',secret_hex='12'*32,now=int(time.time()))
                for path in (BRIDGE_PATH,BRIDGE_PATH+'/extra','//local//ustar//connect_bridge.php','/local/ustar/%63onnect_bridge.php'):
                    for tls in (False,True):
                        conn=(http.client.HTTPSConnection('127.0.0.1',host.HTTPS_PORT,context=ssl.create_default_context(cafile=str(p.old.ca)),timeout=5)
                              if tls else http.client.HTTPConnection('127.0.0.1',host.HTTP_PORT,timeout=5))
                        try:
                            conn.request('POST',path,signed.body,{**signed.headers,'Host':'ustar.local'})
                            response=conn.getresponse();raw=response.read(65536)
                            assert response.status in (403,404),(tls,path,response.status)
                            assert b'forbidden_operation' not in raw,'PUBLIC_BRIDGE_REACHED_PHP'
                        finally:conn.close()
                bad=BridgeClient(bridge_url,'tg_link','34'*32,ca_file=str(p.old.ca))
                try:bad.probe_link_scope()
                except ValueError as error:assert str(error)=='bridge_scope_unverified'
                else:raise AssertionError('WRONG_HMAC_ACCEPTED')
            # Durable profile before-images, no Moodle config write or rollback-image loss.
            prior=manager.load();host.lose_ack=True
            try:manager.set_bridge_enabled(True)
            except x.Refusal:pass
            else:raise AssertionError('LOST_PROFILE_ACK_ACCEPTED')
            assert manager.load()==prior
            assert manager.set_bridge_enabled(True)['bridge_transport_enabled'] is True
            private_acceptance()
            try:manager.rollback()
            except x.Refusal as error:assert str(error)=='deactivate_bridge_before_HTTP_rollback'
            else:raise AssertionError('HTTP_ROLLBACK_WITH_LIVE_BRIDGE_ACCEPTED')
            assert manager.renew()['phase']=='renewed';private_acceptance()
            current=manager.load();host.lose_ack=True
            try:manager.renew()
            except x.Refusal:pass
            else:raise AssertionError('LOST_BRIDGE_RENEW_ACK_ACCEPTED')
            assert manager.load()['leaf']==current['leaf'];private_acceptance()
            if verification is not None:verification(manager,host,root)
            manager.set_bridge_enabled(False)
            assert manager.rollback()['phase']=='rolled_back'
            for name,path in manager.files().items():assert path.read_bytes()==before[name]
            for path in (p.old.ca,p.old.ca_key,p.include):assert path.read_bytes()==ca_before[path.name]
            assert host.http_status()==200
            assert host.tls_check(p.old.ca,b'original accepted probe')==leaf['fingerprint']
            host.lose_ack=True
            try:manager.cutover()
            except x.Refusal:pass
            else:raise AssertionError('LOST_CUTOVER_ACK_ACCEPTED')
            assert manager.load()['phase']=='rolled_back' and host.http_status()==200
            evidence={'result':'PASS','real_Apache_OpenSSL_PHP':True,'real_Moodle_bootstrap':'separate_connect_db_gate','HTTP302_path_query_preserved':True,'verified_HTTPS_proxy_secure_cookie':True,'observability_header_and_include_unchanged':True,'renewal_and_failed_renewal_recovery':True,'cutover_repeat_rollback_lost_ack_recovery':True,'systemd_and_Docker_boundaries':'modeled;production_executes_real_checks','production_access':False}
            target=ROOT/'artifacts/academy-https-native.json';target.parent.mkdir(exist_ok=True);target.write_text(json.dumps(evidence,indent=2)+'\n')
            print('ACADEMY_CANONICAL_HTTPS_NATIVE=PASS; Apache/OpenSSL/PHP/proxy/redirect/renew/recovery; isolated_fixture')
        except BaseException:
            # Disposable synthetic fixture only; retain actual PHP/Apache errors
            # rather than losing all diagnostics with TemporaryDirectory cleanup.
            target=ROOT/'artifacts';target.mkdir(exist_ok=True)
            for name in ('process.log','error.log'):
                if (root/name).exists():shutil.copyfile(root/name,target/('academy-https-native-'+name))
            raise
        finally:
            for proc in (server,backend):
                proc.terminate()
                try:proc.wait(timeout=10)
                except subprocess.TimeoutExpired:proc.kill();proc.wait(timeout=10)
            log.close()

if __name__=='__main__':main()
