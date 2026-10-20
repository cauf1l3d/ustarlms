#!/usr/bin/env python3
"""Guarded canonical Academy HTTPS cutover and local certificate renewal."""
import argparse
import base64
from contextlib import contextmanager
import fcntl
import hashlib
import http.client
import importlib.util
import json
import os
from pathlib import Path
import re
import signal
import socket
import ssl
import stat
import subprocess
import sys
import tempfile
import time
import uuid
from urllib.parse import urljoin, urlsplit

HELPER = Path('/usr/local/sbin/USTAR_ACADEMY_TLS_PROBE_20261020.py')
HELPER_SHA = '7b5fd3a62c3b9d9c72bcaf513d96777bf0a00926bcba91ec3ac1740896c78bd7'
TOOL = Path('/usr/local/sbin/USTAR_ACADEMY_HTTPS_20261020.py')
CA_SHA = 'cc397ba406aa58445e4f57a5e2644df20bd746273403e555f3df736694d5935f'
ORIGIN = 'https://ustar.local'
MARKER = b'// USTAR managed canonical HTTPS; rollback uses private before-image.'
HEALTH = b'<!doctype html><meta charset="utf-8"><h1>USTAR HTTPS</h1><p>Academy HTTPS is active.</p>'


class Refusal(Exception): pass

def require(value, reason):
    if not value: raise Refusal(reason)

def sha(raw): return hashlib.sha256(raw).hexdigest()


def patch_config(raw):
    require(MARKER not in raw, 'already_managed_config')
    setup = list(re.finditer(rb'(?m)^[ \t]*require_once\s*\(\s*__DIR__\s*\.\s*([\'"])/lib/setup\.php\1\s*\)\s*;', raw))
    require(len(setup) == 1, 'unsupported_config_bootstrap')
    at = setup[0]
    suffix = raw[at.end():]
    suffix = re.sub(rb'/\*.*?\*/|//[^\n]*|\#[^\n]*', b'', suffix, flags=re.S)
    require(not suffix.strip(), 'executable_config_suffix')
    overrides = (b'\n' + MARKER + b"\n$CFG->wwwroot = 'https://ustar.local';\n"
                 b'$CFG->sslproxy = true;\n$CFG->cookiesecure = true;\n')
    return raw[:at.start()] + overrides + raw[at.start():]


def proxy_and_redirect(raw, paths, cert, key, http_port=80, https_port=443, backend_port=8082):
    text = raw.decode('utf-8')
    groups = list(re.finditer(r'<VirtualHost\s+\*:' + str(http_port) + r'\s*>(.*?)</VirtualHost\s*>', text, re.S | re.I))
    require(len(groups) == 1, 'unsupported_HTTP_vhost')
    match = groups[0]
    outside = text[:match.start()] + text[match.end():]
    require(not re.sub(r'(?m)^\s*#.*$', '', outside).strip(), 'HTTP_outside_directives')
    body = match[1]
    require(re.findall(r'(?mi)^\s*ServerName\s+([^\s#]+)', body) == ['ustar.local'], 'unexpected_HTTP_name')
    require(not re.search(r'(?mi)^\s*(?:ServerAlias|SSLEngine|SSLCertificate|Listen)\b', body), 'unsupported_HTTP_directive')
    root_proxy = r'(?mi)^\s*ProxyPass\s+/\s+http://127\.0\.0\.1:' + str(backend_port) + r'/[ \t]*(?:#.*)?$'
    root_reverse = root_proxy.replace('ProxyPass\\s', 'ProxyPassReverse\\s')
    require(len(re.findall(root_proxy, body)) == len(re.findall(root_reverse, body)) == 1, 'unexpected_HTTP_backend')
    for path in (paths.health, cert, key):
        require(re.fullmatch(r'/[A-Za-z0-9_./-]+', str(path)), 'unsupported_path')
    health_path = '/__ustar_tls_probe__/ready.html'
    proxy = f'''<VirtualHost *:{https_port}>
    SSLEngine on
    SSLCertificateFile "{cert}"
    SSLCertificateKeyFile "{key}"
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLUseStapling off
    ProxyPassInherit Off
    ProxyPass {health_path} !
    Alias {health_path} "{paths.health}"
    <Directory "{paths.health.parent}">
        Options None
        AllowOverride None
        Require all denied
    </Directory>
    <LocationMatch "^/__ustar_tls_probe__/ready\\.html$">
        SetHandler default-handler
        Require all granted
    </LocationMatch>
{body}
</VirtualHost>
'''
    redirect_body = re.sub(root_reverse, '', re.sub(root_proxy, '', body))
    redirect = f'<VirtualHost *:{http_port}>\n    Redirect 302 / https://ustar.local/\n{redirect_body}\n</VirtualHost>\n'
    return proxy.encode(), redirect.encode()


def same_topology(before, after, owned_sources):
    require(set(before) == set(after), 'apache_bindings_changed')
    for binding, rows in before.items():
        require(len(rows) == len(after[binding]), 'apache_vhost_count_changed')
        for old, new in zip(rows, after[binding]):
            if old['source'] in owned_sources:
                require({k:v for k,v in old.items() if k != 'line'} == {k:v for k,v in new.items() if k != 'line'}, 'managed_vhost_topology_changed')
            else: require(old == new, 'unrelated_vhost_changed')


def load_helper():
    require(not any(p.is_symlink() for p in (HELPER, *HELPER.parents)), 'unsafe_helper_path')
    info = HELPER.stat()
    require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1
            and not info.st_mode & 0o077 and info.st_size <= 131072, 'unsafe_helper')
    raw = HELPER.read_bytes()
    require(sha(raw) == HELPER_SHA, 'helper_changed')
    spec = importlib.util.spec_from_loader('ustar_pinned_tls', loader=None)
    module = importlib.util.module_from_spec(spec)
    exec(compile(raw, str(HELPER), 'exec'), module.__dict__)
    return module


class Paths:
    def __init__(self, lib, root=Path('/')):
        self.old = lib.Paths(root); self.root = Path(root)
        self.base = self.root / 'var/lib/ustar-academy-https'
        self.state = self.base / 'state.json'
        self.http = self.root / 'etc/apache2/sites-available/ustar.conf'
        self.http_enabled = self.root / 'etc/apache2/sites-enabled/ustar.conf'
        self.include = self.root / 'etc/apache2/ustar-console-observability.conf'
        self.tool = self.root / str(TOOL).lstrip('/')
        self.service = self.root / 'etc/systemd/system/ustar-academy-cert-renew.service'
        self.timer = self.service.with_suffix('.timer')


def unit_texts():
    service = f'''[Unit]
Description=Renew USTAR Academy local TLS certificate
After=apache2.service
[Service]
Type=oneshot
User=root
UMask=0077
ExecStart=/usr/bin/python3 -I -B {TOOL} --renew
TimeoutStartSec=180
'''
    timer = '''[Unit]
Description=Check USTAR Academy certificate daily
[Timer]
OnCalendar=*-*-* 03:20:00
RandomizedDelaySec=30m
Persistent=true
[Install]
WantedBy=timers.target
'''
    return service.encode(), timer.encode()


class Host:
    HTTP_PORT, HTTPS_PORT, BACKEND_PORT = 80, 443, 8082
    def __init__(self, lib): self.lib, self.tls = lib, lib.Host()
    def __getattr__(self, key): return getattr(self.tls, key)
    def runtime(self, path):
        # Use one JSON object and no Config/Env fields (those may contain credentials).
        data = json.loads(self.lib.command(['docker', 'inspect', '--format',
            '{"id":{{json .Id}},"running":{{json .State.Running}},"mounts":{{json .Mounts}},"ports":{{json .NetworkSettings.Ports}}}', 'ustar_moodle']))
        require(data['running'] and re.fullmatch(r'[a-f0-9]{64}', data['id']), 'Moodle_container_unavailable')
        mappings = []
        for m in data['mounts']:
            source = Path(m['Source'])
            if m['Type'] == 'bind' and path.is_relative_to(source) and path != source:
                target = Path(m['Destination']) / path.relative_to(source)
                if str(target) in ('/var/www/html/config.php', '/var/www/html/public/config.php'):
                    mappings.append(str(target))
        require(len(mappings) == 1, 'Moodle_config_needs_directory_bind')
        bindings = data['ports'].get('80/tcp') or []
        require(bindings and all(b['HostIp'] == '127.0.0.1' and b['HostPort'] == str(self.BACKEND_PORT) for b in bindings), 'Moodle_backend_binding_changed')
        rt = {'id': data['id'], 'config': mappings[0]}
        actual = self.lib.command(['docker', 'exec', rt['id'], 'php', '-r', 'echo hash_file("sha256",' + json.dumps(rt['config']) + ');']).decode()
        require(actual == sha(self.lib.read_file(path)), 'Moodle_config_mount_mismatch')
        return rt
    def metadata(self, rt):
        code = 'define("CLI_SCRIPT",true); require ' + json.dumps(rt['config']) + '; echo json_encode(["url"=>$CFG->wwwroot,"sslproxy"=>!empty($CFG->sslproxy),"cookiesecure"=>!empty($CFG->cookiesecure)]);'
        return json.loads(self.lib.command(['docker','exec','-u','33:33',rt['id'],'php','-r',code]))
    def lint(self, rt, raw):
        result = subprocess.run(['docker','exec','-i',rt['id'],'php','-l'], input=raw, capture_output=True, timeout=30, env={"PATH":"/usr/sbin:/usr/bin:/sbin:/bin","LANG":"C","LC_ALL":"C"})
        require(result.returncode == 0, 'Moodle_config_lint_failed')
    def purge(self, rt):
        self.lib.command(['docker','exec','-u','33:33',rt['id'],'php',str(Path(rt['config']).parent/'admin/cli/purge_caches.php')], timeout=120)
    def unit_available(self):
        return all(self.lib.command(['systemctl','show',p,'--value','-p','LoadState']).strip() == b'not-found'
                   for p in ('ustar-academy-cert-renew.service','ustar-academy-cert-renew.timer'))
    def timer_start(self):
        self.lib.command(['systemctl','daemon-reload'])
        self.lib.command(['systemctl','enable','--now','ustar-academy-cert-renew.timer'])
        require(self.lib.command(['systemctl','is-active','ustar-academy-cert-renew.timer']).strip() == b'active', 'renewal_timer_not_active')
    def timer_probe(self):
        self.lib.command(['systemctl','start','ustar-academy-cert-renew.service'], timeout=180)
        require(self.lib.command(['systemctl','show','ustar-academy-cert-renew.service','--value','-p','Result']).strip()==b'success','renewal_service_probe_failed')
    def timer_stop(self):
        self.lib.command(['systemctl','disable','--now','ustar-academy-cert-renew.timer'])
        self.lib.command(['systemctl','stop','ustar-academy-cert-renew.service'])
    def daemon_reload(self): self.lib.command(['systemctl','daemon-reload'])
    def due(self, cert):
        p = subprocess.run(['openssl','x509','-in',str(cert),'-noout','-checkend',str(10*86400)], capture_output=True, timeout=10, env={"PATH":"/usr/sbin:/usr/bin:/sbin:/bin","LANG":"C","LC_ALL":"C"})
        require(p.returncode in (0,1), 'leaf_expiry_unavailable')
        return p.returncode == 1
    def request(self, ca, path):
        ctx = ssl.create_default_context(cafile=str(ca))
        with socket.create_connection(('127.0.0.1',self.HTTPS_PORT),timeout=10) as sock:
            with ctx.wrap_socket(sock,server_hostname='ustar.local') as stream:
                peer = sha(stream.getpeercert(binary_form=True))
                stream.sendall(('GET '+path+' HTTP/1.1\r\nHost: ustar.local\r\nConnection: close\r\n\r\n').encode())
                response=http.client.HTTPResponse(stream);response.begin()
                raw=response.read(2*1024*1024+1)
                require(len(raw)<=2*1024*1024,'response_too_large')
                return response.status,response.getheaders(),raw,peer
    def redirect_check(self):
        conn=http.client.HTTPConnection('127.0.0.1',self.HTTP_PORT,timeout=10)
        try:
            conn.request('HEAD','/login/index.php?ustar_https_check=1',headers={'Host':'ustar.local','Connection':'close'})
            response=conn.getresponse()
            require(response.status==302 and response.getheader('Location')==ORIGIN+'/login/index.php?ustar_https_check=1','HTTP_redirect_failed')
        finally:conn.close()
    def app_check(self, ca, leaf):
        path='/login/index.php'
        for _ in range(4):
            status,headers,body,peer=self.request(ca,path)
            require(peer==leaf['fingerprint'],'unexpected_live_leaf')
            if status==200:
                cookies=[v for k,v in headers if k.lower()=='set-cookie' and v.lower().startswith('moodlesession')]
                require(cookies and all(re.search(r';\s*secure(?:;|$)',v,re.I) for v in cookies),'session_cookie_not_secure')
                return
            require(status in (301,302,303),'HTTPS_login_failed')
            dest=urlsplit(urljoin(ORIGIN+path,dict((k.lower(),v) for k,v in headers).get('location','')))
            require(dest.scheme=='https' and dest.netloc=='ustar.local','unexpected_login_redirect')
            path=dest.path+('?' + dest.query if dest.query else '')
        raise Refusal('HTTPS_redirect_loop')


class Manager:
    def __init__(self, lib=None, paths=None, host=None, uid=0, baseline_config=None, ca_sha=CA_SHA):
        self.lib=lib or load_helper();self.p=paths or Paths(self.lib);self.host=host or Host(self.lib)
        self.uid=uid;self.baseline_config=baseline_config or self.lib.EXPECTED_CONFIG;self.ca_sha=ca_sha
    def read(self,path,private=False):return self.lib.read_file(path,self.uid,private)
    def save(self,s):self.lib.atomic(self.p.state,(json.dumps(s,sort_keys=True)+'\n').encode())
    def load(self):
        s=json.loads(self.read(self.p.state,True));require(s.get('schema')==1,'invalid_state');return s
    @contextmanager
    def locked(self):
        self.lib.directory(self.p.base,0o700,self.uid,self.p.root)
        fd=os.open(self.p.base/'lock',os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
        try:
            st=os.fstat(fd);require(stat.S_ISREG(st.st_mode) and st.st_uid==self.uid and st.st_nlink==1 and stat.S_IMODE(st.st_mode)==0o600,'unsafe_lock')
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB);yield
        finally:os.close(fd)
    def files(self):return {'config':self.p.old.config,'http':self.p.http,'tls':self.p.old.site,'health':self.p.old.health}
    def authority(self):
        self.read(self.p.old.ca);self.read(self.p.old.ca_key,True)
        value=self.host.authority(self.p.old.ca,self.p.old.ca_key)
        require(value==self.ca_sha,'accepted_CA_changed');return value
    def check(self):
        old=self.lib.Manager(self.p.old,self.host,uid=self.uid,expected_config=self.baseline_config)
        state=old.load();require(state['phase']=='tls_probe_ready','accepted_probe_required');old.verify(state)
        require(self.p.http_enabled.is_symlink() and self.p.http_enabled.resolve()==self.p.http,'unexpected_HTTP_site_link')
        raw=self.read(self.p.http);self.read(self.p.include)
        require([v.strip() for v in re.findall(rb'(?mi)^\s*Include\s+([^\r\n#]+)',raw)]==[str(self.p.include).encode()],'unexpected_HTTP_include')
        require(self.host.unit_available() and not any(os.path.lexists(p) for p in (self.p.service,self.p.timer)),'existing_renewal_units')
        for p in (self.p.service.parent,self.p.tool.parent):
            st=p.stat();require(not any(a.is_symlink() for a in (p,*p.parents)) and stat.S_ISDIR(st.st_mode) and st.st_uid==self.uid and not st.st_mode&0o022,'unsafe_system_directory')
        self.read(self.p.tool,True);self.authority()
        rt=self.host.runtime(self.p.old.config)
        require(self.host.metadata(rt)['url']=='http://ustar.local','Academy_origin_changed')
        cfg=patch_config(self.lib.read_file(self.p.old.config));self.host.lint(rt,cfg)
        proxy_and_redirect(raw,self.p.old,Path('/private/test.crt'),Path('/private/test.key'),self.host.HTTP_PORT,self.host.HTTPS_PORT,self.host.BACKEND_PORT)
        return {'runtime':rt,'units':self.host.units(),'topology':self.host.test_config(),'include_sha':sha(self.read(self.p.include))}
    def replace(self,path,raw,meta):
        fd,name=tempfile.mkstemp(prefix='.ustar-https-',dir=path.parent)
        try:
            with os.fdopen(fd,'wb') as out:
                os.fchown(out.fileno(),meta['uid'],meta['gid']);os.fchmod(out.fileno(),meta['mode'])
                for key,value in meta['xattrs'].items():os.setxattr(out.fileno(),key,base64.b64decode(value))
                out.write(raw);out.flush();os.fsync(out.fileno())
            os.replace(name,path);self.lib.sync_directory(path.parent)
        finally:
            if os.path.exists(name):os.unlink(name)
    def capture(self,name,path,folder):
        raw=self.lib.read_file(path);st=path.stat();backup=folder/(name+'.before')
        self.lib.atomic(backup,raw)
        return {'before':sha(raw),'after':sha(raw),'backup':str(backup),'uid':st.st_uid,'gid':st.st_gid,'mode':stat.S_IMODE(st.st_mode),
                'xattrs':{k:base64.b64encode(os.getxattr(path,k)).decode() for k in os.listxattr(path)}}
    def guards(self,s,rollback=False):
        require(sha(self.read(self.p.tool,True))==s['tool_sha'],'installed_tool_changed')
        require(self.p.http_enabled.is_symlink() and self.p.http_enabled.resolve()==self.p.http,'HTTP_site_link_changed')
        require(self.p.old.enabled.is_symlink() and self.p.old.enabled.resolve()==self.p.old.site,'TLS_site_link_changed')
        for name,path in self.files().items():
            allowed={s['files'][name]['after']}
            if rollback:allowed.add(s['files'][name]['before'])
            require(sha(self.lib.read_file(path)) in allowed,'owned_'+name+'_changed')
        for path in (self.p.service,self.p.timer):
            if os.path.lexists(path):require(sha(self.read(path))==s['unit_hashes'][path.name],'owned_unit_changed')
        if not rollback:require(sha(self.read(self.p.include))==s['include_sha'],'observability_include_changed')
    def leaf_ready(self,s):
        require(self.host.tls_check(self.p.old.ca,HEALTH)==s['leaf']['fingerprint'],'unexpected_live_leaf')
    def wait_leaf(self,s):
        until=time.monotonic()+8
        while True:
            try:self.leaf_ready(s);return
            except (Exception,):
                if time.monotonic()>=until:raise Refusal('HTTPS_leaf_health_failed') from None
                time.sleep(.2)
    def verify(self,s,transaction=False):
        self.guards(s);self.authority()
        same_topology(s['topology'],self.host.vhosts(),{str(self.p.http_enabled),str(self.p.old.enabled)})
        if transaction:require(self.host.units()==s['units'],'shared_service_PID_changed')
        rt=self.host.runtime(self.p.old.config)
        if transaction:require(rt==s['runtime'],'Moodle_container_changed')
        require(self.host.metadata(rt)=={'url':ORIGIN,'sslproxy':True,'cookiesecure':True},'Moodle_HTTPS_config_not_effective')
        self.wait_leaf(s);self.host.redirect_check();self.host.app_check(self.p.old.ca,s['leaf'])
    def public(self,s):return {'phase':s['phase'],'academy_origin':ORIGIN if s['phase']=='active' else 'pending_or_restored','leaf':s.get('leaf'),'business_data_restored':False}
    def cutover(self):
        result=self._cutover_transaction()
        try:self.host.timer_probe()
        except BaseException:
            self.rollback();raise
        return result
    def _cutover_transaction(self):
        with self.locked():
            if self.p.state.exists():
                previous=self.load()
                if previous['phase']=='active':self.verify(previous);return self.public(previous)
                require(previous['phase']=='rolled_back','unfinished_change_requires_rollback')
            base=self.check();folder=self.p.base/('run-'+uuid.uuid4().hex);folder.mkdir(mode=0o700)
            records={name:self.capture(name,path,folder) for name,path in self.files().items()}
            units=unit_texts()
            s={'schema':1,'phase':'preparing','reload_attempted':False,'timer_attempted':False,'folder':str(folder),'files':records,
               'unit_hashes':{p.name:sha(raw) for p,raw in zip((self.p.service,self.p.timer),units)},'tool_sha':sha(self.read(self.p.tool,True)),**base}
            self.save(s)
            try:
                leaf=self.host.certificate(self.p.old.ca,self.p.old.ca_key,folder);self.authority()
                proxy,redirect=proxy_and_redirect(self.read(self.p.http),self.p.old,folder/'server.crt',folder/'server.key',self.host.HTTP_PORT,self.host.HTTPS_PORT,self.host.BACKEND_PORT)
                desired={'config':patch_config(self.lib.read_file(self.p.old.config)),'http':redirect,'tls':proxy,'health':HEALTH}
                s['leaf']=leaf
                for name,raw in desired.items():s['files'][name]['after']=sha(raw)
                self.save(s)
                require(sha(self.read(self.p.include))==s['include_sha'],'observability_include_changed')
                for name,path in self.files().items():
                    require(sha(self.lib.read_file(path))==records[name]['before'],'baseline_file_changed')
                    self.replace(path,desired[name],records[name])
                same_topology(s['topology'],self.host.test_config(),{str(self.p.http_enabled),str(self.p.old.enabled)})
                self.host.purge(s['runtime'])
                s['reload_attempted']=True;self.save(s);self.host.reload();self.verify(s,True)
                for path,raw in zip((self.p.service,self.p.timer),units):
                    require(not os.path.lexists(path),'existing_renewal_units');self.lib.atomic(path,raw,0o644)
                s['timer_attempted']=True;self.save(s);self.host.timer_start()
                self.guards(s);s['phase']='active';self.save(s);return self.public(s)
            except BaseException:
                self._rollback(self.load());raise
    def _rollback(self,s):
        require(s['phase']!='renewing','recover_renewal_before_rollback')
        self.guards(s,True)
        if s['timer_attempted']:self.host.timer_stop()
        for path in (self.p.service,self.p.timer):
            if path.exists():path.unlink();self.lib.sync_directory(path.parent)
        self.host.daemon_reload()
        for name,path in self.files().items():self.replace(path,self.read(Path(s['files'][name]['backup']),True),s['files'][name])
        self.host.test_config()
        if s['reload_attempted']:self.host.reload()
        current_runtime=self.host.runtime(self.p.old.config)
        self.host.purge(current_runtime)
        require(self.host.metadata(current_runtime)['url']=='http://ustar.local','HTTP_config_recovery_failed')
        s['phase']='rolled_back';self.save(s);return self.public(s)
    def rollback(self):
        with self.locked():return self._rollback(self.load())
    def recover_renewal(self,s):
        pending=s['pending'];current=sha(self.read(self.p.old.site))
        require(current in (s['files']['tls']['after'],pending['site_sha']),'owned_tls_changed')
        self.replace(self.p.old.site,self.read(Path(pending['before']),True),s['files']['tls'])
        self.host.test_config();self.host.reload();self.wait_leaf(s)
        s.pop('pending');s['phase']='active';self.save(s)
    def renew(self):
        with self.locked():
            s=self.load()
            if s['phase']=='renewing':self.recover_renewal(s)
            require(s['phase']=='active','HTTPS_not_active');self.guards(s);self.authority()
            cert=Path(s['folder'])/'server.crt'
            if not self.host.due(cert):return {'phase':'renewal_not_due','leaf':s['leaf']}
            folder=self.p.base/('renew-'+uuid.uuid4().hex);folder.mkdir(mode=0o700)
            leaf=self.host.certificate(self.p.old.ca,self.p.old.ca_key,folder);self.authority()
            original=self.read(Path(s['files']['http']['backup']),True)
            proxy,_=proxy_and_redirect(original,self.p.old,folder/'server.crt',folder/'server.key',self.host.HTTP_PORT,self.host.HTTPS_PORT,self.host.BACKEND_PORT)
            backup=folder/'tls.before';self.lib.atomic(backup,self.read(self.p.old.site))
            s['pending']={'before':str(backup),'site_sha':sha(proxy)};s['phase']='renewing';self.save(s)
            try:
                self.replace(self.p.old.site,proxy,s['files']['tls']);self.host.test_config();self.host.reload()
                candidate={**s,'leaf':leaf};self.wait_leaf(candidate)
                self.guards({**s,'files':{**s['files'],'tls':{**s['files']['tls'],'after':sha(proxy)}}})
                s.update(folder=str(folder),leaf=leaf,phase='active');s['files']['tls']['after']=sha(proxy);s.pop('pending');self.save(s)
                return {'phase':'renewed','leaf':leaf}
            except BaseException:
                self.recover_renewal(s);raise
    def status(self):
        if not self.p.state.exists():return {'phase':'not_prepared'}
        s=self.load()
        if s['phase']=='active':self.verify(s)
        return self.public(s)


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    modes=parser.add_mutually_exclusive_group()
    for name in ('check','cutover','renew','status','rollback'):modes.add_argument('--'+name,action='store_true')
    parser.add_argument('--acknowledge-outage',action='store_true')
    args=parser.parse_args(argv)
    if not any(getattr(args,n) for n in ('check','cutover','renew','status','rollback')):
        print('{"phase":"plan_only","host_access":false}');return 0
    try:
        require(os.geteuid()==0 and sys.version_info>=(3,12),'root_python312_required')
        require(not args.cutover or args.acknowledge_outage,'cutover_needs_outage_acknowledgement')
        os.umask(0o077)
        def stop(*_):raise KeyboardInterrupt()
        for sig in (signal.SIGTERM,signal.SIGINT,signal.SIGHUP):signal.signal(sig,stop)
        manager=Manager()
        if args.check:manager.check();result={'phase':'ready_for_HTTPS_cutover','host_configuration_changed':False}
        else:result=getattr(manager,next(n for n in ('cutover','renew','status','rollback') if getattr(args,n)))()
        print(json.dumps(result,ensure_ascii=False,indent=2));return 0
    except BaseException as error:
        known=isinstance(error,Refusal) or error.__class__.__name__=='Refusal'
        reason=str(error) if known and re.fullmatch('[A-Za-z_]{1,100}',str(error)) else 'HTTPS_operation_refused'
        print(json.dumps({'phase':'refused','reason':reason}));return 2


if __name__=='__main__':raise SystemExit(main())
