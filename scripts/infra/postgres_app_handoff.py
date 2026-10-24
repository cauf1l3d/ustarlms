#!/usr/bin/env python3
"""Guarded PostgreSQL application-login handoff. Default: offline PLAN_ONLY."""
import argparse
import ast
import base64
import copy
from dataclasses import dataclass
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import uuid
import tempfile
from types import ModuleType, SimpleNamespace
from contextlib import contextmanager
import fcntl

CONTROLLER = Path('/root/ustar-telegram-update-3d1938ae.py')
CONTROLLER_SHA = '6f7729459e1ef25271fd86324e441c823a6d39763c7fec84baf7cf640a41eb31'
BASE = Path('/var/lib/ustar-postgres-app-handoff')
CONFIG = Path('/opt/ustar/data/moodle/public/config.php')


class Refusal(ValueError):
    """Only bounded public reason codes, never host values or SQL output."""


def require(value, reason):
    if not value: raise Refusal(reason)


def sha(raw): return hashlib.sha256(raw).hexdigest()


def unique(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'duplicate_json_key')
        result[key] = value
    return result


@dataclass(frozen=True)
class Profile:
    database: str = 'moodle'
    bootstrap: str = 'moodle'
    admin: str = 'ustar_pg_admin'
    app: str = 'ustar_app'
    bootstrap_oid: int = 10
    admin_oid: int = 109344
    app_oid: int = 110395
    prefix: str = 'mdl_'

    def __post_init__(self):
        require(all(re.fullmatch('[a-z][a-z0-9_]{0,62}',n) for n in
                    (self.database,self.bootstrap,self.admin,self.app,self.prefix)), 'profile_SQL_identity')
        require(self.bootstrap_oid==10 and self.admin_oid>10 and self.app_oid>10
                and len({self.bootstrap_oid,self.admin_oid,self.app_oid})==3,'profile_role_OIDs')


PRODUCTION = Profile()


def literal(raw, name):
    require(b'$CFG->{' not in re.sub(rb'\s+', b'', raw), 'dynamic_config_property')
    key = rb'\$CFG\s*->\s*' + name.encode() + rb'\b'
    matches = list(re.finditer(rb'(?m)^[ \t]*' + key +
        rb'\s*=\s*(\x27(?:\\.|[^\x27\\\r\n])*\x27|"[^"\\$\r\n]*")\s*;', raw))
    require(len(matches) == 1 and len(re.findall(key, raw)) == 1, 'unsupported_config_' + name)
    token = matches[0][1]
    value = token[1:-1]
    if token[:1] == b"'": value = re.sub(rb"\\([\\'])", rb'\1', value)
    return matches[0].span(1), value.decode('utf-8')


def patch_config(raw, password):
    require(isinstance(password, str) and 1 <= len(password.encode()) <= 1024
            and not any(ord(c) < 32 or ord(c) == 127 for c in password), 'invalid_app_password')
    for name, wanted in [('dbtype','pgsql'),('dbname','moodle'),('prefix','mdl_'),('dbuser','moodle')]:
        require(literal(raw, name)[1] == wanted, 'config_identity')
    pass_span, old_password = literal(raw, 'dbpass')
    require(password != old_password, 'app_password_matches_bootstrap_config')
    user_span, _ = literal(raw, 'dbuser')
    values = [(user_span, b"'ustar_app'"), (pass_span, ("'" + password.replace('\\','\\\\').replace("'","\\'") + "'").encode())]
    result = raw
    for (start,end), value in sorted(values, reverse=True): result = result[:start] + value + result[end:]
    return result


def patch_https(raw, before_config, after_config):
    state = json.loads(raw, object_pairs_hook=unique)
    require(state.get('schema') == 1 and state.get('phase') == 'active'
            and state['files']['config']['after'] == sha(before_config), 'HTTPS_config_baseline_drift')
    # uid/gid/mode/before/backup describe the ORIGINAL HTTP before-image. Keep
    # them intact; only the current managed config digest changes in this handoff.
    state['files']['config']['after'] = sha(after_config)
    return (json.dumps(state, sort_keys=True) + '\n').encode()


def validate_inventory(value, *, side, profile=PRODUCTION):
    require(side in ('before','after'), 'inventory_side')
    roles = {r['name']:r for r in value['roles']}
    require(set(roles) == {profile.bootstrap,profile.admin,profile.app}, 'role_inventory')
    b,a,r = (roles[n] for n in (profile.bootstrap,profile.admin,profile.app))
    require(b['oid'] == profile.bootstrap_oid == 10 and b['super'] and b['login']
            and a['oid'] == profile.admin_oid and a['super'] and a['login'], 'administrative_role_changed')
    require(r['oid'] == profile.app_oid and r['login'] and not r['memberships']
            and not any(r[k] for k in ('super','create_role','create_db','replication','bypass')),
            'app_role_elevated_or_changed')
    owner = profile.bootstrap_oid if side == 'before' else profile.app_oid
    require(value['database']['name'] == profile.database and value['database']['owner'] == owner,
            'database_owner_drift')
    require(value['public_schema']['owner'] == 6171, 'public_schema_needs_database_owner')
    rows = value['relations']
    require(rows and len({r['oid'] for r in rows}) == len(rows), 'relation_inventory')
    require(all(r['owner'] == owner and r['name'].startswith(profile.prefix)
            and re.fullmatch('[a-z][a-z0-9_]{0,62}',r['name']) and r['kind'] in ('r','i','S')
            and r['extension'] is False for r in rows), 'unknown_public_object')
    require(value['app_system_catalogs'] == value['custom_types'] == value['default_acls'] == 0,
            'unsupported_database_delegation')
    for routine in value['routines']:
        require(routine == {'identity':'ustar_console.sample()','owner':'ustar_console_stats',
            'super':False,'definer':True,'usage':False,'execute':False,
            'path':['search_path=pg_catalog, pg_temp']}, 'unreviewed_custom_routine')
    require(len(value['routines']) <= 1, 'custom_routine_inventory')
    return {'relations':len(rows),'tables':sum(r['kind']=='r' for r in rows),
            'sequences':sum(r['kind']=='S' for r in rows),'indexes':sum(r['kind']=='i' for r in rows)}


def desired_inventory(before, *, profile=PRODUCTION):
    validate_inventory(before, side='before', profile=profile)
    result = copy.deepcopy(before)
    def acl(value):
        if value is None: return None
        return sorted([{**row, **{key: profile.app_oid if row[key] == profile.bootstrap_oid else row[key]
                    for key in ('grantor','grantee')}} for row in value],
                    key=lambda r:(r['grantor'],r['grantee'],r['privilege']))
    result['database'].update(owner=profile.app_oid,acl=acl(result['database']['acl']))
    for row in result['relations']: row.update(owner=profile.app_oid,acl=acl(row['acl']))
    return result


def sql_string(value):
    require(isinstance(value,str) and '\x00' not in value, 'invalid_SQL_literal')
    return "E'" + value.replace('\\','\\\\').replace("'","''") + "'"


def inventory_expression(profile=PRODUCTION):
    def acl(expr):
        return "(SELECT jsonb_agg(jsonb_build_object('grantor',a.grantor::bigint,'grantee',a.grantee::bigint," \
            "'privilege',a.privilege_type,'grantable',a.is_grantable) ORDER BY a.grantor,a.grantee,a.privilege_type) " \
            "FROM aclexplode(" + expr + ") a)"
    role_names = ','.join(sql_string(n) for n in (profile.bootstrap,profile.admin,profile.app))
    return """jsonb_build_object(
      'database',(SELECT jsonb_build_object('name',d.datname,'owner',d.datdba::bigint,'acl',DBACL)
                    FROM pg_database d WHERE d.datname=current_database()),
      'roles',(SELECT jsonb_agg(jsonb_build_object('name',r.rolname,'oid',r.oid::bigint,'login',r.rolcanlogin,
                'super',r.rolsuper,'create_role',r.rolcreaterole,'create_db',r.rolcreatedb,
                'replication',r.rolreplication,'bypass',r.rolbypassrls,
                'memberships',COALESCE((SELECT jsonb_agg(m.roleid::bigint ORDER BY m.roleid)
                                        FROM pg_auth_members m WHERE m.member=r.oid),'[]'::jsonb))
                ORDER BY r.rolname) FROM pg_roles r WHERE r.rolname IN (ROLENAMES)),
      'public_schema',(SELECT jsonb_build_object('owner',n.nspowner::bigint,'acl',NSPACL)
                         FROM pg_namespace n WHERE n.nspname='public'),
      'relations',COALESCE((SELECT jsonb_agg(jsonb_build_object('oid',c.oid::bigint,'name',c.relname,
                    'kind',c.relkind,'owner',c.relowner::bigint,'acl',RELACL,
                    'extension',EXISTS(SELECT 1 FROM pg_depend e WHERE e.classid='pg_class'::regclass
                       AND e.objid=c.oid AND e.deptype='e')) ORDER BY c.oid)
                   FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                   WHERE n.nspname='public'),'[]'::jsonb),
      'routines',COALESCE((SELECT jsonb_agg(jsonb_build_object('identity',(p.oid::regprocedure)::text,
                  'owner',r.rolname,'super',r.rolsuper,'definer',p.prosecdef,
                  'usage',has_schema_privilege(APPROLE,n.oid,'USAGE'),
                  'execute',has_function_privilege(APPROLE,p.oid,'EXECUTE'),
                  'path',COALESCE((SELECT jsonb_agg(setting ORDER BY setting) FROM unnest(p.proconfig) cfg(setting)
                                   WHERE setting LIKE 'search_path=%'),'[]'::jsonb)) ORDER BY p.oid)
                    FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
                    JOIN pg_roles r ON r.oid=p.proowner
                    WHERE n.nspname NOT IN ('pg_catalog','information_schema')
                      AND n.nspname NOT LIKE 'pg_toast%' AND n.nspname NOT LIKE 'pg_temp_%'),'[]'::jsonb),
      'custom_types',(SELECT count(*) FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace
                        WHERE n.nspname='public' AND t.typtype<>'c' AND t.typelem=0),
      'default_acls',(SELECT count(*) FROM pg_default_acl),
      'app_system_catalogs',(SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                     WHERE n.nspname='pg_catalog' AND c.relowner=(SELECT oid FROM pg_roles WHERE rolname=APPROLE))
    )""".replace('DBACL',acl('d.datacl')).replace('NSPACL',acl('n.nspacl')).replace('RELACL',acl('c.relacl')) \
         .replace('ROLENAMES',role_names).replace('APPROLE',sql_string(profile.app))


def inventory_sql(profile=PRODUCTION):
    return "BEGIN READ ONLY; SET LOCAL statement_timeout='15s'; SET LOCAL search_path=pg_catalog; SELECT " \
        + inventory_expression(profile) + '; ROLLBACK;'


def ownership_sql(before, after, *, profile=PRODUCTION):
    require((before == desired_inventory(after,profile=profile)) if before['database']['owner']==profile.app_oid
            else (after == desired_inventory(before,profile=profile)), 'unsupported_ownership_delta')
    old,new = before['database']['owner'],after['database']['owner']
    name = profile.app if new == profile.app_oid else profile.bootstrap
    expression = inventory_expression(profile)
    # Both comparisons occur INSIDE the ownership transaction. A mismatched ACL,
    # new object or delegated grant aborts before COMMIT; no business-row SQL here.
    return "BEGIN; SET LOCAL statement_timeout='90s'; SET LOCAL lock_timeout='3s'; SET LOCAL search_path=pg_catalog;\n" \
        + 'DO $handoff$ DECLARE owned_relation record; BEGIN\nIF ' + expression + ' <> ' \
        + sql_string(json.dumps(before,sort_keys=True)) + "::jsonb THEN RAISE EXCEPTION 'ownership_inventory_drift'; END IF;\n" \
        + 'ALTER DATABASE "' + profile.database + '" OWNER TO "' + name + '";\n' \
        + "FOR owned_relation IN SELECT c.oid,c.relname,c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace " \
        + "WHERE n.nspname='public' AND c.relowner=" + str(old) + " AND c.relkind IN ('r','S') " \
        + "ORDER BY CASE WHEN c.relkind='S' THEN 1 ELSE 0 END,c.oid LOOP " \
        + "EXECUTE format('ALTER %s public.%I OWNER TO %I', CASE owned_relation.relkind WHEN 'S' THEN 'SEQUENCE' ELSE 'TABLE' END,owned_relation.relname," \
        + sql_string(name) + '); END LOOP;\nIF ' + expression + ' <> ' \
        + sql_string(json.dumps(after,sort_keys=True)) + "::jsonb THEN RAISE EXCEPTION 'ownership_delta_mismatch'; END IF;\n" \
        + 'END; $handoff$;\nCOMMIT;'


def parents(path):
    for parent in Path(path).parents:
        require(parent.is_dir() and not parent.is_symlink(), 'unsafe_control_parent')


def capture(path, maximum=64*1024**2):
    path=Path(path);parents(path)
    before=path.lstat()
    require(stat.S_ISREG(before.st_mode) and before.st_nlink==1 and before.st_size<=maximum,
            'unsafe_control_file')
    def identity(st):
        return (st.st_dev,st.st_ino,st.st_mode,st.st_nlink,st.st_uid,st.st_gid,st.st_size,st.st_mtime_ns,st.st_ctime_ns)
    with os.fdopen(os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK),'rb') as stream:
        require(identity(before)==identity(os.fstat(stream.fileno())),'control_changed_while_reading')
        raw=stream.read(maximum+1)
        require(len(raw)<=maximum and identity(before)==identity(os.fstat(stream.fileno()))
                ==identity(path.lstat()),'control_changed_while_reading')
    return {'path':str(path),'raw':base64.b64encode(raw).decode(),'sha256':sha(raw),
            'metadata':{'uid':before.st_uid,'gid':before.st_gid,'mode':stat.S_IMODE(before.st_mode),
                        'xattrs':{n:base64.b64encode(os.getxattr(path,n,follow_symlinks=False)).decode()
                                  for n in sorted(os.listxattr(path,follow_symlinks=False))}}}


def image_raw(image):
    raw=base64.b64decode(image['raw'],validate=True)
    require(sha(raw)==image['sha256'],'private_image_digest')
    return raw


def descriptor(image):return {k:v for k,v in image.items() if k!='raw'}


def image_with(image,raw,*,metadata=None):
    return {**image,'raw':base64.b64encode(raw).decode(),'sha256':sha(raw),
            'metadata':image['metadata'] if metadata is None else metadata}


def sync(path):
    fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:os.fsync(fd)
    finally:os.close(fd)


def replace_image(image):
    path=Path(image['path']);parents(path)
    raw=image_raw(image);meta=image['metadata']
    require('system.posix_acl_access' not in meta['xattrs'],'unreviewed_control_ACL')
    fd,name=tempfile.mkstemp(prefix='.ustar-db-login-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            os.fchown(out.fileno(),meta['uid'],meta['gid']);os.fchmod(out.fileno(),meta['mode'])
            for key in os.listxattr(out.fileno()):
                if key not in meta['xattrs']:os.removexattr(out.fileno(),key)
            for key,value in meta['xattrs'].items():os.setxattr(out.fileno(),key,base64.b64decode(value,validate=True))
            out.write(raw);out.flush();os.fsync(out.fileno())
        os.replace(name,path);sync(path.parent)
    finally:
        if os.path.exists(name):os.unlink(name)
    require(capture(path)==image,'control_write_readback_failed')


class Store:
    def __init__(self,base=BASE,*,uid=0):
        self.base,self.uid=Path(base),uid;self.path=self.base/'state.json'

    def directory(self,*,create=False):
        parents(self.base)
        if not os.path.lexists(self.base) and create:
            parent=self.base.parent.lstat()
            require(parent.st_uid==self.uid and not parent.st_mode&0o022
                    and 'system.posix_acl_access' not in os.listxattr(self.base.parent),'unsafe_state_parent')
            self.base.mkdir(mode=0o700);self.base.chmod(0o700);sync(self.base.parent)
        st=self.base.lstat()
        require(stat.S_ISDIR(st.st_mode) and st.st_uid==self.uid
                and stat.S_IMODE(st.st_mode)==0o700,'unsafe_state_directory')

    def load(self):
        if not os.path.lexists(self.path):return None
        self.directory()
        image=capture(self.path)
        require(image['metadata']=={'uid':self.uid,'gid':os.getegid(),'mode':0o600,'xattrs':{}},'unsafe_private_state')
        result=json.loads(image_raw(image),object_pairs_hook=unique)
        require(isinstance(result,dict) and result.get('schema')==1,'state_identity')
        return result

    def save(self,value):
        self.directory(create=True)
        raw=(json.dumps(value,sort_keys=True)+'\n').encode()
        replace_image({'path':str(self.path),'raw':base64.b64encode(raw).decode(),'sha256':sha(raw),
            'metadata':{'uid':self.uid,'gid':os.getegid(),'mode':0o600,'xattrs':{}}})

    def put(self,name,side):
        require(side in ('before','after'),'private_image_side')
        state=self.load();pair=state['files'][name];current=capture(pair['before']['path'])
        require(current in list(pair.values()),'unknown_control_drift')
        if current!=pair[side]:replace_image(pair[side])

    @contextmanager
    def locked(self,*,write=False):
        path=self.base/'lock'
        if not os.path.lexists(path) and not write:
            yield;return
        if write:self.directory(create=True)
        else:self.directory()
        fd=os.open(path,(os.O_RDWR|os.O_CREAT if write else os.O_RDONLY)|os.O_NOFOLLOW|os.O_NONBLOCK,0o600)
        try:
            st=os.fstat(fd)
            require(stat.S_ISREG(st.st_mode) and st.st_uid==self.uid and st.st_nlink==1
                    and stat.S_IMODE(st.st_mode)==0o600,'unsafe_handoff_lock')
            try:fcntl.flock(fd,(fcntl.LOCK_EX if write else fcntl.LOCK_SH)|fcntl.LOCK_NB)
            except BlockingIOError:raise Refusal('managed_operation_busy_retry_later') from None
            require((st.st_dev,st.st_ino)==(path.lstat().st_dev,path.lstat().st_ino),'handoff_lock_replaced')
            yield
        finally:os.close(fd)


def load_controller(path=CONTROLLER,*,require_private=True):
    """Load only pinned embedded sources/data, never the original command-line main."""
    image=capture(path)
    require(image['sha256']==CONTROLLER_SHA,'frozen_controller_digest')
    if require_private:
        require(image['metadata']['uid']==0 and image['metadata']['mode']==0o600
                and not image['metadata']['xattrs'],'unsafe_frozen_controller')
    source=image_raw(image)
    tree=ast.parse(source)
    values={}
    for node in tree.body:
        if isinstance(node,ast.Assign) and len(node.targets)==1 and isinstance(node.targets[0],ast.Name) \
                and node.targets[0].id in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON'):
            name=node.targets[0].id
            require(name not in values,'duplicate_embedded_constant')
            values[name]=json.loads(ast.literal_eval(node.value),object_pairs_hook=unique)
    require(set(values)=={'MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON'},'frozen_envelope')
    manifest,sources,assets=(values[n] for n in ('MANIFEST_JSON','SOURCES_JSON','ASSETS_JSON'))
    require(set(manifest['sources'])==set(sources),'frozen_source_inventory')
    for name,raw in sources.items():
        require(re.fullmatch('[a-z][a-z0-9_]*[.]py',name) and
                manifest['sources'][name]=={'sha256':sha(raw.encode()),'bytes':len(raw.encode())},'frozen_source_digest')
    import builtins
    import importlib
    modules={};prefix='_ustar_db_handoff_'+uuid.uuid4().hex+'_'
    facade=SimpleNamespace(**{k:v for k,v in vars(importlib).items() if k!='import_module'})
    facade.import_module=lambda name,package=None: load(name) if name+'.py' in sources else importlib.import_module(name,package)
    def load(name):
        if name in modules:return modules[name]
        require(name+'.py' in sources,'frozen_module_missing')
        module=ModuleType(prefix+name);modules[name]=module;sys.modules[module.__name__]=module
        module.__file__='<pinned-3d1938ae/'+name+'.py>'
        def scoped_import(target,globals=None,locals=None,fromlist=(),level=0):
            if level==0 and target+'.py' in sources:return load(target)
            if target=='importlib' and level==0 and not fromlist:return facade
            return builtins.__import__(target,globals,locals,fromlist,level)
        module.__dict__['__builtins__']={**vars(builtins),'__import__':scoped_import}
        exec(compile(sources[name+'.py'],module.__file__,'exec'),module.__dict__)
        return module
    t=load('connect_telegram_update');c=load('connect_con02_contract');state=load('connect_con02_state')
    bundle=t.load_embedded(manifest,sources,assets)
    return SimpleNamespace(t=t,c=c,state=state,bundle=bundle,journal_class=state.Journal,locks_class=state.Locks,
                           runtime_class=load('connect_con02_runtime').Runtime,
                           application_class=load('connect_con02_application').Application)


PHASES={'prepared','backup_complete','writers_stopped','ownership_committed','config_written',
        'https_written','web_verified','app_role_active','recovering','baseline_restored','recovery_required'}


class Session:
    """Metadata-only transaction; the adapter owns the actual PG/systemd boundary."""
    def __init__(self,host,store,*,progress=None):
        self.host,self.store=host,store
        self.progress=progress or (lambda phase:None)

    def load(self):
        value=self.store.load()
        if value is None:return None
        require(value.get('controller_sha')==CONTROLLER_SHA and value.get('phase') in PHASES
                and set(value.get('files',{}))=={'config','https'},'handoff_state_identity')
        return value

    def step(self,phase,**extra):
        require(phase in PHASES,'handoff_phase')
        value=self.load();value.update(extra,phase=phase);self.store.save(value)
        self.progress(phase)
        return value

    def guard_files(self):
        state=self.load();require(state is not None,'handoff_state_required')
        for name,pair in state['files'].items():
            require(set(pair)=={'before','after'} and pair['before']['path']==pair['after']['path'], 'image_paths_changed')
            require(capture(pair['before']['path']) in list(pair.values()),'unknown_control_drift')
        for image in state['invariants']:
            require(descriptor(capture(image['path']))==image,'unrelated_control_drift')
        # Re-derive the entire accepted delta instead of trusting saved arbitrary
        # after-images. This also verifies private images on every recovery run.
        b,a=(state['files']['config'][s] for s in ('before','after'))
        password=literal(image_raw(a),'dbpass')[1]
        require(image_raw(a)==patch_config(image_raw(b),password)
                and a['metadata']==self.host.config_metadata(b),'saved_config_delta_changed')
        b,a=(state['files']['https'][s] for s in ('before','after'))
        require(image_raw(a)==patch_https(image_raw(b),image_raw(state['files']['config']['before']),
                          image_raw(state['files']['config']['after'])) and a['metadata']==b['metadata'],
                'saved_HTTPS_delta_changed')
        require(state['database_after']==desired_inventory(state['database_before'],profile=self.host.profile),
                'saved_ownership_delta_changed')
        return state

    def guard(self):
        state=self.guard_files();current=self.host.observe()
        require(current in (state['database_before'],state['database_after']),'ownership_inventory_drift')
        return state,current

    def prepare(self,password):
        self.host.verify_live('before')
        before=self.host.observe();counts=validate_inventory(before,side='before',profile=self.host.profile)
        files=self.host.files();require(set(files)=={'config','https'},'control_inventory')
        raw=patch_config(image_raw(files['config']),password)
        self.host.credentials(password);self.host.lint(raw)
        desired={**files,'config':image_with(files['config'],raw,metadata=self.host.config_metadata(files['config']))}
        desired['https']=image_with(files['https'],patch_https(image_raw(files['https']),image_raw(files['config']),raw))
        state={'schema':1,'controller_sha':CONTROLLER_SHA,'generation':uuid.uuid4().hex,'phase':'prepared',
               'database_before':before,'database_after':desired_inventory(before,profile=self.host.profile),
               'files':{n:{'before':v,'after':desired[n]} for n,v in files.items()},
               'invariants':self.host.invariants(),'services':self.host.services(),'policies':self.host.policies(),
               'counts':counts,'business_rows_restored':False}
        if hasattr(self.host,'legacy_images'):state['legacy_before']=self.host.legacy_images()
        if hasattr(self.host,'owner_snapshot'):
            state['https_before']=self.host.owner_snapshot()
            state['https_after']=copy.deepcopy(state['https_before'])
            for name,key in [('config','config'),('state','https')]:
                state['https_after']['files'][name].update(sha256=desired[key]['sha256'],metadata=desired[key]['metadata'])
        self.store.save(state);self.progress('prepared')
        return state

    def apply(self,password):
        state=self.load()
        if state and state['phase']=='app_role_active':
            self.guard();self.host.verify_live('after',state)
            return {'phase':'app_role_active','verify_only':True,'business_rows_restored':False}
        if state and state['phase'] not in ('baseline_restored',):
            return {**self.recover(),'new_handoff_started':False}
        if state:
            # Retain the previous generation privately; never lose its recovery data.
            previous=self.store.base/('previous-'+state['generation']+'.json')
            raw=image_raw(capture(self.store.path))
            if previous.exists():require(image_raw(capture(previous))==raw,'previous_generation_collision')
            else:replace_image({'path':str(previous),'raw':base64.b64encode(raw).decode(),
                           'sha256':sha(raw),
                           'metadata':{'uid':self.store.uid,'gid':os.getegid(),'mode':0o600,'xattrs':{}}})
        state=self.prepare(password)
        try:
            run=self.store.base/('run-'+state['generation']);run.mkdir(mode=0o700);sync(self.store.base)
            receipt=self.host.backup(run)
            self.step('backup_complete',fresh_backup=receipt)
            return self.commit()
        except (Exception,KeyboardInterrupt):
            try:return {**self.recover(),'apply_succeeded':False}
            except BaseException:
                self.step('recovery_required')
                return {'phase':'recovery_required','business_rows_restored':False}

    def commit(self):
        # The PG transaction and two atomic file writes are individually durable.
        # Recovery observes exact before/after identity, even if the checkpoint
        # immediately after COMMIT or rename never reached disk.
        state,_=self.guard()
        with self.host.cold() if hasattr(self.host,'cold') else _nullcontext():
            self.host.stop();self.step('writers_stopped')
            self.guard()
            self.host.transfer(state['database_before'],state['database_after'])
            self.step('ownership_committed')
            self.store.put('config','after');self.step('config_written')
            self.store.put('https','after');self.step('https_written')
            self.host.start_web();self.host.verify_live('after',self.load());self.step('web_verified')
            self.host.resume(state['services'],state['policies'])
            self.guard();self.host.verify_live('after',self.load())
            if 'legacy_before' in state:
                require(self.host.legacy_images()==state['legacy_before'],'legacy_journal_changed_during_handoff')
            self.step('app_role_active')
            return {'phase':'app_role_active','app_role':self.host.profile.app,'objects':state['counts'],
                    'business_rows_restored':False,'bootstrap_admin_retained':True}

    def rollback(self):
        state,current=self.guard()
        self.step('recovering')
        with self.host.cold() if hasattr(self.host,'cold') else _nullcontext():
            self.host.stop()
            self.guard()
            current=self.host.observe()
            if current==state['database_after']:
                self.host.transfer(state['database_after'],state['database_before'])
            self.store.put('config','before');self.store.put('https','before')
            self.host.start_web();self.host.verify_live('before',self.load())
            self.host.resume(state['services'],state['policies'])
            self.guard();self.step('baseline_restored')
            return {'phase':'baseline_restored','business_rows_restored':False,'roles_dropped':False}

    def recover(self):
        state=self.load();require(state is not None,'handoff_state_required')
        if state['phase']=='app_role_active':
            self.guard();self.host.verify_live('after',state)
            return {'phase':'app_role_active','verify_only':True,'business_rows_restored':False}
        self.guard_files()  # Refuse unknown controls before any service start.
        if hasattr(self.host,'recover_backup'):self.host.recover_backup()
        if hasattr(self.host,'ensure_pg'):self.host.ensure_pg()
        return self.rollback()

    def undo(self):
        state,_=self.guard()
        if state['phase']=='app_role_active':
            state.update(services=self.host.services(),policies=self.host.policies());self.store.save(state)
        return self.rollback()


from contextlib import nullcontext as _nullcontext


class ProductionHost:
    profile=PRODUCTION
    uid=0

    def __init__(self,loaded,store,log):
        self.loaded,self.store,self.log=loaded,store,log
        self.ctx=loaded.t.make_context(loaded.bundle)
        self.ctx.host.log=log
        self.journal=loaded.journal_class(self.ctx);self.locks=loaded.locks_class(self.ctx)
        state=self.journal.load()
        require(state and state.get('app') and state['app']['phase']=='app_ready','accepted_application_required')
        self.ctx.host.bind_identities(state['app']['primitive']['identities'])
        self.ids={k:v['Id'] for k,v in self.ctx.engine.identities.items()}
        self.original_owner_snapshot=self.ctx.host.https_snapshot
        self.view_state=None
        self.runtime_validation=False
        self.ctx.host.https_snapshot=self.owner_view

    def run(self,argv,*,raw=None,timeout=45,allow_failure=False):
        result=subprocess.run(argv,input=raw,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout,
            env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C','LC_ALL':'C'})
        require(len(result.stdout)+len(result.stderr)<=8*1024**2,'host_output_bound')
        self.log.write(result.stdout);self.log.write(result.stderr);self.log.flush()
        if not allow_failure:require(result.returncode==0,'host_command_failed')
        return result

    def sql(self,query):
        command='exec psql -X -w -qAt -P pager=off -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"'
        return self.run(['docker','exec','-i',self.ids['ustar_postgres'],'sh','-eu','-c',command],
                        raw=query.encode(),timeout=110).stdout

    def observe(self):return json.loads(self.sql(inventory_sql()),object_pairs_hook=unique)

    def transfer(self,before,after):
        clients=json.loads(self.sql("BEGIN READ ONLY; SELECT count(*) FROM pg_stat_activity "
                                   "WHERE datname=current_database() AND pid<>pg_backend_pid(); ROLLBACK;"))
        require(clients==0,'other_database_clients_retry_later')
        self.sql(ownership_sql(before,after))

    def owner_snapshot(self):return self.original_owner_snapshot()

    def owner_view(self):
        current=self.owner_snapshot();state=self.view_state
        if state:
            require(current in (state['https_before'],state['https_after']),'HTTPS_owner_drift')
            # This is only a compatibility view for frozen runtime comparisons.
            # The canonical owner's real guards have already checked current bytes.
            return state['https_before']
        return current

    def files(self):
        manager=self.ctx.host.legacy().manager
        require(manager.p.old.config==CONFIG,'canonical_config_path_changed')
        return {'config':capture(CONFIG),'https':capture(manager.p.state)}

    def config_metadata(self,image):
        require('system.posix_acl_access' not in image['metadata']['xattrs'],'unreviewed_config_ACL')
        for parent in CONFIG.parents:
            st=parent.lstat()
            require(stat.S_ISDIR(st.st_mode) and st.st_uid==0 and not st.st_mode&0o022
                    and 'system.posix_acl_access' not in os.listxattr(parent),'writable_config_parent')
        return {**image['metadata'],'uid':0,'gid':33,'mode':0o640}

    def invariants(self):
        values=self.owner_snapshot()['files']
        paths=[v['path'] for n,v in values.items() if n not in ('config','state')]+[str(CONTROLLER)]
        return [descriptor(capture(p)) for p in sorted(set(paths))]

    def legacy_images(self):
        paths=[self.journal.path,Path('/var/lib/ustar-connect-con02-update/state.json')]
        return [descriptor(capture(p)) for p in paths]

    def verify_live(self,side,state=None):
        self.view_state=state
        previous=self.journal.load()
        require(previous and previous.get('app') and previous['app']['phase']=='app_ready', 'application_recovery_required')
        self.loaded.application_class(self.ctx,self.journal,self.locks).verify(previous['app'],target=True)
        require(previous.get('runtime') and previous['runtime']['phase'] in ('runtime_active','runtime_disabled'),
                'runtime_recovery_required')
        self.loaded.runtime_class(self.ctx,self.journal,self.locks).exact(
            'after' if previous['runtime']['phase']=='runtime_active' else 'before')
        self.loaded.c.https_snapshot(self.ctx)
        for name,(path,digest) in self.ctx.engine.w.HELPERS.items():
            if name in ('hdd_backup','hdd_scheduler','backup_engine'):
                require(capture(path)['sha256']==digest,'accepted_backup_helper_changed')
        data=self.observe();counts=validate_inventory(data,side=side)
        require(counts=={'relations':3479,'tables':657,'sequences':656,'indexes':2166},'public_inventory_changed_since_owner_receipt')
        if self.runtime_validation:
            if side=='after':self.effective_app()
            require(self.ctx.host.login()==200,'HTTPS_login_failed')

    def lint(self,raw):
        self.run(['docker','exec','-i',self.ids['ustar_moodle'],'php','-l'],raw=raw,timeout=30)

    def credentials(self,password):
        import ipaddress
        networks=json.loads(self.run(['docker','inspect','--format','{{json .NetworkSettings.Networks}}',
                                     self.ids['ustar_postgres']]).stdout)
        addresses={v['IPAddress'] for v in networks.values() if v.get('IPAddress')}
        require(len(addresses)==1,'PostgreSQL_internal_address_ambiguous')
        ip=next(iter(addresses));address=ipaddress.ip_address(ip)
        require(address.version==4 and address.is_private and not address.is_loopback,'PostgreSQL_internal_address')
        def attempt(role,query):
            conn='host='+ip+' dbname=moodle user='+role+' connect_timeout=5 require_auth=scram-sha-256 passfile=/dev/null'
            shell='IFS= read -r PGPASSWORD; export PGPASSWORD; exec psql -X -w -qAt -P pager=off -v ON_ERROR_STOP=1 "$1"'
            return self.run(['docker','exec','-i',self.ids['ustar_postgres'],'sh','-eu','-c',shell,'sh',conn],
                            raw=password.encode()+b'\n'+query.encode(),allow_failure=True)
        query="BEGIN READ ONLY; SET LOCAL statement_timeout='15s'; SELECT json_build_object('login',session_user," \
              "'effective',current_user,'oid',oid::bigint) FROM pg_roles WHERE rolname=current_user; ROLLBACK;"
        positive=attempt('ustar_app',query)
        require(positive.returncode==0,'app_SCRAM_login_failed')
        row=json.loads(positive.stdout)
        require(row=={'login':'ustar_app','effective':'ustar_app','oid':110395},'app_SCRAM_identity_changed')
        negative=attempt('moodle','BEGIN READ ONLY; SELECT 1; ROLLBACK;')
        require(negative.returncode!=0 and b'password authentication failed for user "moodle"' in negative.stderr,
                'app_password_bootstrap_isolation_failed')

    def effective_app(self):
        code=b'''<?php
define('CLI_SCRIPT',true); require '/var/www/html/config.php';
$DB->execute('BEGIN READ ONLY');
$row=(array)$DB->get_record_sql("SELECT session_user AS login, current_user AS effective, r.oid::int AS oid,
 r.rolsuper::int AS super, r.rolcreaterole::int AS createrole, r.rolcreatedb::int AS createdb,
 r.rolreplication::int AS replication, r.rolbypassrls::int AS bypassrls,
 (d.datdba=r.oid)::int AS owner FROM pg_roles r JOIN pg_database d ON d.datname=current_database()
 WHERE r.rolname=current_user");
$DB->execute('ROLLBACK');
echo json_encode(['role'=>$row,'config_user'=>$CFG->dbuser,
 'readable'=>is_readable('/var/www/html/config.php'),'writable'=>is_writable('/var/www/html/config.php')]);
'''
        row=json.loads(self.run(['docker','exec','-i','-u','33:33',self.ids['ustar_moodle'],'php'],raw=code).stdout)
        role=row['role']
        require(role['login']==role['effective']==row['config_user']=='ustar_app' and int(role['oid'])==110395
                and int(role['owner'])==1 and all(int(role[k])==0 for k in ('super','createrole','createdb','replication','bypassrls'))
                and row['readable'] is True and row['writable'] is False,'effective_app_role_or_permissions_failed')

    def services(self):
        return {n:{'active':self.ctx.host.service_active(n),'enabled':self.ctx.host.service_enabled(n)}
                for n in self.loaded.c.SERVICES}

    def policies(self):
        return self.ctx.engine.w.docker_conditions(self.ctx.engine.p,self.ctx.engine.identities)['restart_policies']

    def backup(self,run):
        self.ctx.engine.p.mount()
        return self.ctx.host.fresh_backup({'kind':'postgres_app_metadata_handoff','run_directory':str(run)})

    def recover_backup(self):self.ctx.host.recover_backup()
    def cold(self):return self.locks.cold()

    def stop(self):
        for unit in self.loaded.c.SERVICES:
            self.ctx.host.service('stop',unit)
            require(not self.ctx.host.service_active(unit),'worker_stop_not_confirmed')
        self.ctx.host.policy('no')
        if self.ctx.host.inspect()['ustar_moodle']['State']['Running']:
            self.ctx.host.stop_web(clean=True)
        require(self.ctx.host.inspect()['ustar_postgres']['State']['Running'],'PostgreSQL_not_running')

    def ensure_pg(self):
        # A reboot during the pending handoff may leave restart=no containers
        # stopped. Start only the exact previously verified PG ID; keep Moodle
        # stopped until metadata recovery has restored a consistent connection.
        import time
        if not self.ctx.host.inspect()['ustar_postgres']['State']['Running']:
            self.run(['docker','start',self.ids['ustar_postgres']])
        deadline=time.monotonic()+45
        while True:
            result=self.run(['docker','exec',self.ids['ustar_postgres'],'sh','-eu','-c',
                'exec pg_isready -q -U "${POSTGRES_USER:?}" -d "${POSTGRES_DB:?}"'],timeout=5,allow_failure=True)
            if result.returncode==0:return
            require(time.monotonic()<deadline,'PostgreSQL_not_ready')
            time.sleep(0.2)

    def start_web(self):self.ctx.host.start()

    def resume(self,services,policies):
        self.ctx.host.restore_policies(policies)
        for unit,old in reversed(list(services.items())):
            if self.ctx.host.service_enabled(unit)!=old['enabled']:
                self.ctx.host.service('enable' if old['enabled'] else 'disable',unit)
            if self.ctx.host.service_active(unit)!=old['active']:
                self.ctx.host.service('start' if old['active'] else 'stop',unit)
            require(self.ctx.host.service_active(unit)==old['active']
                    and self.ctx.host.service_enabled(unit)==old['enabled'],'worker_restore_failed')


def check(host,store):
    operation=Session(host,store);state=operation.load()
    if state is None:
        host.verify_live('before');counts=validate_inventory(host.observe(),side='before')
        # Validate permissions/ACL feasibility without writing any file.
        files=host.files();host.config_metadata(files['config'])
        raw=image_raw(files['config'])
        for name,value in [('dbtype','pgsql'),('dbname','moodle'),('dbuser','moodle'),('prefix','mdl_')]:
            require(literal(raw,name)[1]==value,'unsupported_static_config')
        literal(raw,'dbpass')
        return {'phase':'ready_for_app_handoff','objects':counts,'host_changes':False,'provider_called':False}
    operation.guard()
    if state['phase'] in ('app_role_active','baseline_restored'):
        host.verify_live('after' if state['phase']=='app_role_active' else 'before',state)
        return {'phase':state['phase'],'host_changes':False,'provider_called':False}
    return {'phase':'recovery_required','saved_phase':state['phase'],'host_changes':False,'provider_called':False}


def prompt_password():
    import getpass
    import warnings
    # Never allow getpass to fall back to visible stdin or a pipe.
    with open('/dev/tty','w') as tty,warnings.catch_warnings():
        warnings.simplefilter('error',getpass.GetPassWarning)
        return getpass.getpass('Пароль ранее созданной роли ustar_app: ',stream=tty)


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    group=parser.add_mutually_exclusive_group()
    for option in ('check','status','apply','recover','undo','enable-presentation','disable-presentation'):
        group.add_argument('--'+option,action='store_true')
    parser.add_argument('--acknowledge-outage',action='store_true')
    args=parser.parse_args(argv)
    write=any((args.apply,args.recover,args.undo))
    if write and not args.acknowledge_outage:parser.error('explicit outage acknowledgement required')
    if args.acknowledge_outage and not write:parser.error('outage flag requires apply, recover or undo')
    if not any((args.check,args.status,write,args.enable_presentation,args.disable_presentation)):
        print(json.dumps({'phase':'PLAN_ONLY','host_access':False,'network_used':False}));return 0
    log=None
    try:
        require(os.geteuid()==0,'root_required')
        store=Store()
        if args.status:
            state=store.load()
            print(json.dumps({'phase':state['phase'] if state else 'uninitialized','observation':'journal_only',
                              'business_rows_restored':False}));return 0
        loaded=load_controller()
        if write or args.enable_presentation or args.disable_presentation:
            own=capture(Path(sys.argv[0]))
            require(own['metadata']['uid']==0 and own['metadata']['mode']==0o600
                    and not own['metadata']['xattrs'],'unsafe_operator_file')
            store.directory(create=True)
            path=store.base/('private-'+uuid.uuid4().hex+'.log')
            log=path.open('xb');os.fchmod(log.fileno(),0o600)
        else:
            import io
            log=io.BytesIO()
        host=ProductionHost(loaded,store,log)
        def progress(phase):print(json.dumps({'phase':phase}),flush=True)
        def interrupted(*_):raise KeyboardInterrupt()
        for sig in (signal.SIGINT,signal.SIGTERM,signal.SIGHUP):signal.signal(sig,interrupted)
        operation=Session(host,store,progress=progress)
        if args.check:
            with store.locked(),host.locks.check():result=check(host,store)
        elif args.enable_presentation or args.disable_presentation:
            # Runtime uses its existing own locks/recovery protocol. It may update
            # its runtime journal; cutover itself never rewrites those journals.
            with store.locked(write=True):
                state=operation.load();require(state and state['phase']=='app_role_active','app_handoff_required')
                operation.guard();host.verify_live('after',state)
                runtime=loaded.runtime_class(host.ctx,host.journal,host.locks)
                result=runtime.enable() if args.enable_presentation else runtime.disable()
                operation.guard();host.verify_live('after',state)
        else:
            host.runtime_validation=True
            with store.locked(write=True),host.locks.prebackup():
                if args.apply:
                    state=operation.load()
                    password=prompt_password() if state is None or state['phase']=='baseline_restored' else ''
                    result=operation.apply(password)
                elif args.undo:result=operation.undo()
                else:result=operation.recover()
        print(json.dumps(result,sort_keys=True))
        return 2 if result.get('phase')=='recovery_required' or result.get('apply_succeeded') is False else 0
    except BaseException as error:
        if log is not None:
            import traceback
            log.write(traceback.format_exc().encode());log.flush()
        reason=str(error) if isinstance(error,Refusal) and re.fullmatch('[A-Za-z][A-Za-z0-9_]{0,100}',str(error)) \
            else 'host_operation_failed_private_log_retained'
        print(json.dumps({'phase':'refused','reason':reason,'business_rows_restored':False}));return 2
    finally:
        if log is not None:log.close()


if __name__=='__main__':sys.exit(main())
