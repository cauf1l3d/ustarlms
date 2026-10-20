#!/usr/bin/env python3
"""Private bridge readiness and fixed configuration rendering. No activation."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import stat
import sys
import zipfile
from contextlib import contextmanager,ExitStack


class Refusal(Exception): pass


def require(value,reason):
    if not value:raise Refusal(reason)


MARKER=b'// USTAR managed private chat-link include; no employee authentication.'
KEY_ID='tg_link_v1'
PEER='172.18.0.1'
DROPIN=(b'# USTAR managed private chat-link credentials\n[Service]\n'
        b'LoadCredential=bridge.key:/etc/ustar-connect/bridge.key\n'
        b'LoadCredential=bridge.ca:/etc/ustar-connect/bridge.ca\n')
LEGACY_HTTPS_SHA='42550fb1ab769dbcad7b604fe5e0619f6c7ac69fa5cbac46c624924f6895aa6e'
PAYLOAD='1e8a8819f50785ebb4fb2640eeb9386c969d59d1'
SECURITY_FILES=('local/ustar/connect_bridge.php','local/ustar/classes/connect/bridge_auth.php',
    'local/ustar/classes/connect/bridge_http.php','local/ustar/classes/connect/bridge_service.php',
    'local/ustar/classes/connect/identity_service.php','local/ustar/classes/connect/link_management.php',
    'local/ustar/version.php','theme/ustar/version.php')


def unique(pairs):
    result={}
    for key,value in pairs:
        require(key not in result,'duplicate_control_field');result[key]=value
    return result


def patch_config(raw):
    require(MARKER not in raw and not re.search(rb'\$CFG\s*->\s*ustar_connect_',raw),'existing_connect_configuration')
    matches=list(re.finditer(rb'(?m)^[ \t]*require_once\s*\(\s*__DIR__\s*\.\s*([\'"])/lib/setup\.php\1\s*\)\s*;',raw))
    require(len(matches)==1,'unsupported_config_bootstrap')
    at=matches[0]
    suffix=re.sub(rb'/\*.*?\*/|//[^\n]*|\#[^\n]*',b'',raw[at.end():],flags=re.S)
    require(not suffix.strip(),'executable_config_suffix')
    include=b"\n"+MARKER+b"\nrequire(__DIR__ . '/.ustar-connect/bridge.php');\n"
    return raw[:at.start()]+include+raw[at.start():]


def php_settings(secret,bot_id):
    require(isinstance(secret,str) and re.fullmatch('[a-f0-9]{64}',secret),'invalid_generated_key')
    require(isinstance(bot_id,str) and re.fullmatch('[1-9][0-9]{0,19}',bot_id),'invalid_bot_identity')
    return ("<?php\n// USTAR root-managed link-only configuration; outside document root.\n"
        "$CFG->ustar_connect_bridge_enabled=true;\n"
        "$CFG->ustar_connect_bridge_peers=['"+PEER+"'];\n"
        "$CFG->ustar_connect_bridge_keys=['"+KEY_ID+"'=>['secret'=>'"+secret+
        "','provider'=>'telegram','tenant'=>'"+bot_id+"','operations'=>['link.claim']]];\n"
        "$CFG->ustar_connect_channels=['telegram'=>['tenant'=>'"+bot_id+"','username'=>'ustarcorp_bot']];\n"
        "$CFG->ustar_connect_link_transport='chat';\n").encode()


def receiver_settings(raw,unit):
    require(unit in ('probe','receiver'),'invalid_receiver_unit')
    try:value=json.loads(raw,object_pairs_hook=unique)
    except (ValueError,TypeError):raise Refusal('invalid_receiver_configuration') from None
    base={'bot_token_file','bot_username','state_directory','telegram_proxy','onboarding_enabled',
        'bridge_endpoint','bridge_key_id','bridge_key_file','bridge_ca_file'}
    require(isinstance(value,dict) and set(value) in (base,base|{'linking_enabled'}),'unknown_receiver_configuration')
    directory='/run/credentials/ustar-connect-'+unit+'.service/'
    require(value['bot_token_file']==directory+'telegram.token'
        and value['bot_username']=='ustarcorp_bot' and value['state_directory']=='/var/lib/ustar-connect-receiver'
        and value['telegram_proxy']=='http://127.0.0.1:18081' and value['onboarding_enabled'] is False
        and value.get('linking_enabled',False) is False
        and all(value[key] is None for key in ('bridge_endpoint','bridge_key_id','bridge_key_file','bridge_ca_file')),
        'receiver_configuration_drift')
    value.update(linking_enabled=True,bridge_endpoint='https://127.0.0.1:18443/local/ustar/connect_bridge.php',
        bridge_key_id=KEY_ID,bridge_key_file=directory+'bridge.key',bridge_ca_file=directory+'bridge.ca')
    return (json.dumps(value,sort_keys=True)+'\n').encode()


class Host:
    def gateway(self,runtime):
        import academy_https
        raw=academy_https.load_helper().command(['docker','inspect','--format','{{json .NetworkSettings.Networks}}',runtime['id']])
        networks=json.loads(raw)
        require(isinstance(networks,dict) and len(networks)==1,'ambiguous_backend_network')
        return next(iter(networks.values()))['Gateway']
    def port_free(self):
        with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as probe:
            try:probe.bind(('127.0.0.1',18443));return True
            except OSError:return False


class Readiness:
    """Only new delivery inputs. No source inventory/SQL/backup/cron rerun or key generation."""
    def __init__(self,reference,manager,installation,host=None,root=Path('/'),uid=0,lib=None):
        self.ref,self.https,self.inst=reference,manager,installation
        self.host,self.root,self.uid=host or Host(),Path(root),uid
        self.lib=lib
    def library(self):
        if self.lib is None:
            import academy_https
            self.lib=academy_https.load_helper()
        return self.lib
    def secure_parent(self,path,reason):
        for parent in (path,*path.parents):
            if not parent.is_relative_to(self.root):break
            st=parent.lstat()
            if not (stat.S_ISDIR(st.st_mode) and not parent.is_symlink()
                    and st.st_uid==self.uid and not st.st_mode&0o022):
                error=Refusal(reason)
                error.details={'path':str(parent),'uid':st.st_uid,'gid':st.st_gid,'mode':oct(stat.S_IMODE(st.st_mode))}
                raise error
    def read_private(self,path,limit=16384):
        self.secure_parent(path.parent,'unsafe_private_parent')
        st=path.lstat()
        require(stat.S_ISREG(st.st_mode) and st.st_nlink==1 and st.st_uid==self.uid
            and stat.S_IMODE(st.st_mode)==0o600 and st.st_size<=limit,'unsafe_private_control')
        return self.library().read_file(path,self.uid,True,limit)
    def check(self):
        ref=self.ref
        require(isinstance(ref,dict) and set(ref)=={'schema','tool_commit','payload_commit','files'}
            and ref['schema']==1 and re.fullmatch('[a-f0-9]{40}',ref['tool_commit'])
            and ref['payload_commit']==PAYLOAD and set(ref['files'])==set(SECURITY_FILES),'invalid_transport_reference')
        s=self.https.load()
        require(s.get('phase')=='active' and s.get('tool_sha')==LEGACY_HTTPS_SHA,'accepted_HTTPS_ownership_required')
        require(not s.get('bridge_enabled',False),'bridge_already_configured')
        self.https.guards(s)
        code=self.https.p.old.config.parent;web=code/'public'
        # A root-owned include is not protected if PHP can rename its parent.
        # Refuse that actual layout rather than silently changing code ownership.
        self.secure_parent(code,'unsafe_include_parent')
        new_paths=[code/'.ustar-connect',self.inst.config/'bridge.key',self.inst.config/'bridge.ca',
            *[self.inst.units/('ustar-connect-'+n+'.service.d') for n in ('receiver','probe')]]
        require(not any(os.path.lexists(p) for p in new_paths),'existing_private_bridge_path')
        lib=self.library()
        for name,expected in ref['files'].items():
            require(re.fullmatch('[a-f0-9]{64}',expected)
                and hashlib.sha256(lib.read_file(web/name)).hexdigest()==expected,'transport_source_drift')
        current=self.inst.current();require(current is not None,'receiver_not_installed')
        units=self.inst.check_units(self.inst.release_files(current))
        require(set(units)=={'ustar-connect-'+n+'.service' for n in ('receiver','probe','notify')}
            and all(v is not None for v in units.values()),'managed_receiver_units_required')
        require(not self.inst.host.active('ustar-connect-notify.service'),'notification_activation_requires_separate_plan')
        for unit in ('receiver','probe'):receiver_settings(self.read_private(self.inst.config/(unit+'.json')),unit)
        token=self.read_private(self.inst.config/'telegram.token',512).decode().strip()
        require(re.fullmatch('[1-9][0-9]{4,19}:[A-Za-z0-9_-]{20,256}',token),'invalid_private_bot_token')
        # Existing HTTPS verification checks the live CA/leaf/cookie and fixed
        # directory bind. It may create a normal anonymous Moodle session/cache.
        # It neither consumes Telegram updates nor writes employee/link state.
        self.https.verify(s)
        runtime=self.https.host.runtime(self.https.p.old.config)
        require(self.host.gateway(runtime)==PEER,'backend_peer_changed')
        require(self.host.port_free(),'private_bridge_port_occupied')
        return {'kind':'connect_private_bridge_delivery_readiness','phase':'ready_for_private_bridge_preparation',
            'tool_commit':ref['tool_commit'],'payload_commit':PAYLOAD,'reviewed_transport_files':len(SECURITY_FILES),
            'include_parent_protected':True,'current_HTTPS_verified':True,'backend_peer_candidate':PEER,
            'effective_private_peer_proven':False,'port_observed_free':True,'port_reserved':False,
            'receiver_source_commit':current.name,'receiver_active':self.inst.host.active('ustar-connect-receiver.service'),
            'configuration_changed':False,'key_generated':False,'telegram_API_called':False,
            'database_version_requeried':False,'application_or_cron_or_backup_launched':False,
            'activation_package_ready':False}


def bundle(path):
    expected={'__main__.py','connect_private_bridge.py','academy_https.py','connect_install.py','REFERENCE.json','MANIFEST.json'}
    require(zipfile.is_zipfile(path),'verified_bundle_required')
    with zipfile.ZipFile(path) as archive:
        names=archive.namelist()
        require(len(names)==len(expected) and set(names)==expected,'bundle_inventory_changed')
        require(all(info.file_size<=131072 and not info.is_dir() for info in archive.infolist()),'bundle_too_large')
        manifest=json.loads(archive.read('MANIFEST.json'),object_pairs_hook=unique)
        require(set(manifest)=={'schema','tool_commit','files'} and manifest['schema']==1
            and set(manifest['files'])==expected-{'MANIFEST.json'},'invalid_bundle_manifest')
        for name,digest in manifest['files'].items():
            require(hashlib.sha256(archive.read(name)).hexdigest()==digest,'bundle_digest_changed')
        reference=json.loads(archive.read('REFERENCE.json'),object_pairs_hook=unique)
        require(reference['tool_commit']==manifest['tool_commit'],'bundle_source_mismatch')
        return reference


@contextmanager
def locks(reader):
    # Preserve existing order: deployment → HTTPS → cron → backup. The receiver
    # installer has only its own last lock, so no reverse cross-service ordering.
    names=('var/lib/ustar-connect-academy-update/lock','var/lib/ustar-connect-chat-link-update/lock',
        'var/lib/ustar-academy-https/lock','run/ustar-cron/lock','run/ustar-backup/lock',
        'run/ustar-connect-install.lock')
    with ExitStack() as stack:
        for name in names:
            path=reader.root/name
            reader.read_private(path,4096)
            fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK);stack.callback(os.close,fd)
            info=os.fstat(fd);now=path.lstat()
            require((info.st_dev,info.st_ino)==(now.st_dev,now.st_ino),'managed_lock_replaced')
            try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            except BlockingIOError:raise Refusal('managed_operation_busy_retry_later') from None
        yield


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check',action='store_true',help='Read only private delivery prerequisites; no activation')
    args=parser.parse_args(argv)
    if not args.check:
        print('{"phase":"plan_only","host_access":false}');return 0
    try:
        require(os.geteuid()==0 and sys.version_info>=(3,12),'root_python312_required')
        path=Path(sys.argv[0]).resolve()
        require(not any(p.is_symlink() for p in (Path(sys.argv[0]),*Path(sys.argv[0]).parents)),'unsafe_bundle_path')
        st=path.stat();require(st.st_uid==0 and st.st_nlink==1 and stat.S_ISREG(st.st_mode)
            and stat.S_IMODE(st.st_mode)==0o600 and st.st_size<=1048576,'root_private_bundle_required')
        for parent in path.parents:
            info=parent.stat()
            require(stat.S_ISDIR(info.st_mode) and info.st_uid==0 and not info.st_mode&0o022,'unsafe_bundle_parent')
        reference=bundle(path)
        import academy_https,connect_install
        reader=Readiness(reference,academy_https.Manager(),connect_install.Installation())
        with locks(reader):result=reader.check()
        print(json.dumps(result,sort_keys=True,indent=2));return 0
    except BaseException as error:
        known=isinstance(error,Refusal) or error.__class__.__name__=='Refusal'
        reason=str(error) if known and re.fullmatch('[A-Za-z0-9_]{1,100}',str(error)) else 'private_bridge_check_refused'
        result={'phase':'refused','reason':reason,'configuration_changed':False}
        if isinstance(error,Refusal) and hasattr(error,'details'):result['details']=error.details
        print(json.dumps(result));return 2


if __name__=='__main__':raise SystemExit(main())
