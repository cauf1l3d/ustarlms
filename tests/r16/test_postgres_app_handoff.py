"""Bounded tests for credential/config and ownership handoff boundaries."""
import copy
import base64
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import stat
import sys
import tempfile
import unittest
from contextlib import nullcontext

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/infra'))
import postgres_app_handoff as h

CONFIG = b"<?php\n$CFG = new stdClass();\n$CFG->dbtype = 'pgsql';\n$CFG->dbuser = 'moodle';\n$CFG->dbpass = 'old-private';\n$CFG->dbname = 'moodle';\n$CFG->prefix = 'mdl_';\nrequire_once(__DIR__ . '/public/lib/setup.php');\n"


def inventory():
    return {
        'database': {'name':'moodle', 'owner':10, 'acl':None},
        'roles': [
            {'name':'moodle','oid':10,'login':True,'super':True,'create_role':True,
             'create_db':True,'replication':True,'bypass':True,'memberships':[]},
            {'name':'ustar_pg_admin','oid':109344,'login':True,'super':True,'create_role':False,
             'create_db':False,'replication':False,'bypass':False,'memberships':[]},
            {'name':'ustar_app','oid':110395,'login':True,'super':False,'create_role':False,
             'create_db':False,'replication':False,'bypass':False,'memberships':[]}],
        'public_schema': {'owner':6171,'acl':None},
        'relations': [
            {'oid':12345,'name':'mdl_user','kind':'r','owner':10,'acl':None,'extension':False},
            {'oid':12346,'name':'mdl_user_id_seq','kind':'S','owner':10,'acl':None,'extension':False},
            {'oid':12347,'name':'mdl_user_pkey','kind':'i','owner':10,'acl':None,'extension':False}],
        'routines': [{'identity':'ustar_console.sample()','owner':'ustar_console_stats',
                      'super':False,'definer':True,'usage':False,'execute':False,
                      'path':['search_path=pg_catalog, pg_temp']}],
        'custom_types':0,'default_acls':0,'app_system_catalogs':0,
    }


class Configuration(unittest.TestCase):
    def test_changes_only_two_literals_and_handles_php_metacharacters(self):
        result = h.patch_config(CONFIG, "a'\\$x;é")
        self.assertEqual(result, CONFIG.replace(b"$CFG->dbuser = 'moodle';",b"$CFG->dbuser = 'ustar_app';")
            .replace(b"$CFG->dbpass = 'old-private';", "$CFG->dbpass = 'a\\'\\\\$x;é';".encode()))

    def test_refuses_duplicate_or_dynamic_db_assignments(self):
        for raw in [CONFIG+b"$CFG->dbuser='moodle';\n",CONFIG.replace(b"'moodle';\n$CFG->dbpass",b"getenv('DBUSER');\n$CFG->dbpass"),
                    CONFIG+b"$CFG->{'dbpass'}='other';\n"]:
            with self.subTest(raw=raw), self.assertRaises(h.Refusal): h.patch_config(raw,'distinct')

    def test_refuses_same_password_and_control_characters(self):
        for secret in ['old-private','','line\nbreak','nul\x00value']:
            with self.subTest(secret=secret), self.assertRaises(h.Refusal): h.patch_config(CONFIG,secret)

    def test_https_handoff_changes_only_current_config_hash(self):
        owner={'schema':1,'phase':'active','files':{'config':{'before':'a'*64,'after':h.sha(CONFIG),
            'uid':33,'gid':33,'mode':0o755,'backup':'/private/config.before'},'tls':{'after':'b'*64}},
            'runtime':{'id':'c'*64},'leaf':{'fingerprint':'d'*64}}
        raw=json.dumps(owner,sort_keys=True).encode()+b'\n'
        after=h.patch_https(raw,CONFIG,h.patch_config(CONFIG,'distinct'))
        result=json.loads(after)
        self.assertNotEqual(result['files']['config']['after'],h.sha(CONFIG))
        result['files']['config']['after']=h.sha(CONFIG)
        self.assertEqual(result,owner)  # Before-image metadata belongs to HTTPS rollback.
        with self.assertRaises(h.Refusal): h.patch_https(raw,b'drift',b'after')


class Ownership(unittest.TestCase):
    def test_accepts_separate_inaccessible_monitoring_definer(self):
        self.assertEqual(h.validate_inventory(inventory(), side='before')['relations'],3)

    def test_refuses_new_public_objects_elevation_membership_and_definer_access(self):
        variants=[]
        for field,value in [('name','unrelated'),('extension',True),('owner',109344),('kind','v')]:
            v=inventory();v['relations'][0][field]=value;variants.append(v)
        for field,value in [('super',True),('memberships',[109344]),('oid',110396)]:
            v=inventory();v['roles'][2][field]=value;variants.append(v)
        v=inventory();v['routines'][0]['usage']=True;v['routines'][0]['execute']=True;variants.append(v)
        v=inventory();v['routines'][0]['identity']='public.unknown()';variants.append(v)
        v=inventory();v['app_system_catalogs']=1;variants.append(v)
        for value in variants:
            with self.subTest(value=value),self.assertRaises(h.Refusal):h.validate_inventory(value,side='before')

    def test_expected_delta_preserves_monitoring_and_normalizes_owner_acl(self):
        before=inventory()
        before['relations'][0]['acl']=[{'grantor':10,'grantee':0,'privilege':'SELECT','grantable':False}]
        after=h.desired_inventory(before)
        self.assertEqual(after['database']['owner'],110395)
        self.assertEqual({r['owner'] for r in after['relations']},{110395})
        self.assertEqual(after['relations'][0]['acl'],[{'grantor':110395,'grantee':0,'privilege':'SELECT','grantable':False}])
        self.assertEqual(after['routines'],before['routines'])
        self.assertEqual(before['relations'][0]['owner'],10)
        self.assertEqual(h.validate_inventory(after,side='after')['relations'],3)

    def test_transaction_guards_exact_inventory_and_never_uses_broad_reassign(self):
        sql=h.ownership_sql(inventory(),h.desired_inventory(inventory()))
        self.assertTrue(sql.startswith('BEGIN;'))
        self.assertTrue(sql.rstrip().endswith('COMMIT;'))
        self.assertIn('ownership_inventory_drift',sql)
        self.assertNotIn('REASSIGN OWNED',sql)
        self.assertNotIn('DROP ',sql)


class Offline(unittest.TestCase):
    def test_no_arguments_are_offline_and_do_not_need_root_or_dependency(self):
        r=subprocess.run([sys.executable,'-I','-B',str(ROOT/'scripts/infra/postgres_app_handoff.py')],
                         capture_output=True,text=True,check=True)
        self.assertEqual(json.loads(r.stdout),{'phase':'PLAN_ONLY','host_access':False,'network_used':False})

    def test_writes_require_outage_acknowledgement_before_host_access(self):
        for option in ['--apply','--recover','--undo']:
            r=subprocess.run([sys.executable,'-I','-B',str(ROOT/'scripts/infra/postgres_app_handoff.py'),option],
                             capture_output=True,text=True)
            self.assertEqual(r.returncode,2)
            self.assertIn('explicit outage acknowledgement required',r.stderr)


class PrivateState(unittest.TestCase):
    def test_durable_private_images_roundtrip_and_unknown_drift_refusal(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);root.chmod(0o700)
            config=root/'config.php';config.write_bytes(CONFIG);config.chmod(0o600)
            store=h.Store(root/'private',uid=os.getuid())
            before=h.capture(config)
            after={**before,'raw':base64.b64encode(b'after').decode()}
            after['sha256']=h.sha(b'after')
            store.save({'schema':1,'phase':'prepared','files':{'config':{'before':before,'after':after}}})
            self.assertEqual(stat.S_IMODE(store.path.stat().st_mode),0o600)
            store.put('config','after')
            self.assertEqual(config.read_bytes(),b'after')
            store.put('config','before')
            self.assertEqual(config.read_bytes(),CONFIG)
            config.write_bytes(b'outside-edit')
            with self.assertRaises(h.Refusal):store.put('config','before')
            self.assertEqual(config.read_bytes(),b'outside-edit')

    def test_refuses_symlink_shared_parent_and_named_acl(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);root.chmod(0o700)
            path=root/'control';path.write_bytes(b'private');path.chmod(0o600)
            link=root/'link';link.symlink_to(path)
            with self.assertRaises(h.Refusal):h.capture(link)
            shared=root/'shared';shared.mkdir(mode=0o777);shared.chmod(0o777)
            with self.assertRaises(h.Refusal):h.Store(shared/'private',uid=os.getuid()).save({'schema':1})

    def test_exact_frozen_loader_does_not_invoke_the_operator_main(self):
        path=ROOT.parent/'output/ustar-telegram-update-3d1938ae.py'
        if not path.exists():self.skipTest('exact accepted standalone is checked separately in native CI')
        self.assertEqual(h.sha(path.read_bytes()),h.CONTROLLER_SHA)
        loaded=h.load_controller(path,require_private=False)
        self.assertEqual(loaded.bundle.reference['app_commit'],'6c56a5b6ab830b97b706a5d7028b49cfae31e7a4')
        self.assertEqual(loaded.bundle.reference['tool_commit'],'3d1938ae0f2997e79ebbd3e4c3c1c6aa9cd7c134')
        self.assertEqual(loaded.journal_class.__name__,'Journal')

    def test_wrong_frozen_bytes_refused_before_any_code_can_run(self):
        with tempfile.TemporaryDirectory() as td:
            p=Path(td)/'tool.py';p.write_text("raise RuntimeError('should-never-execute')\n")
            with self.assertRaisesRegex(h.Refusal,'frozen_controller_digest'):
                h.load_controller(p,require_private=False)


class PowerLoss(BaseException):pass


class HostFixture:
    """Only the external PG/systemd boundary is doubled; files/journal are real."""
    profile=h.PRODUCTION
    uid=os.getuid()
    def __init__(self,root):
        self.root=root;self.catalogue=inventory();self.running=True
        self.employee_data={'completed_course':True,'new_task':'kept'}
        self.config=root/'config.php';self.config.write_bytes(CONFIG);self.config.chmod(0o600)
        self.https=root/'https.json'
        self.https.write_text(json.dumps({'schema':1,'phase':'active','files':{'config':{
            'after':h.sha(CONFIG),'before':'b'*64,'uid':33,'gid':33,'mode':0o755}}})+'\n')
        self.https.chmod(0o600)
        self.fixed=root/'fixed';self.fixed.write_bytes(b'unchanged-journal');self.fixed.chmod(0o600)
    def verify_live(self,side,state=None):
        h.validate_inventory(self.catalogue,side=side)
        self.assert_config=side
        raw=self.config.read_bytes()
        if json.loads(self.https.read_bytes())['files']['config']['after']!=h.sha(raw):raise h.Refusal('HTTPS_drift')
        if h.literal(raw,'dbuser')[1]!=('moodle' if side=='before' else 'ustar_app'):raise h.Refusal('app_connection')
    def files(self):return {'config':h.capture(self.config),'https':h.capture(self.https)}
    def config_metadata(self,image):return {**image['metadata'],'mode':0o640}
    def invariants(self):return [h.descriptor(h.capture(self.fixed))]
    def services(self):return {'running':self.running}
    def policies(self):return {'restart':'unless-stopped'}
    def credentials(self,password):
        if password!='distinct':raise h.Refusal('credential_failure')
    def lint(self,raw):pass
    def backup(self,run):return {'sha256':'a'*64,'readback_verified':True}
    def stop(self):self.running=False
    def start_web(self):self.running=True
    def resume(self,services,policies):self.running=services['running']
    def observe(self):return copy.deepcopy(self.catalogue)
    def transfer(self,before,after):
        if self.catalogue!=before:raise h.Refusal('SQL_inventory_drift')
        self.catalogue=copy.deepcopy(after)


class Transaction(unittest.TestCase):
    def test_recovery_restarts_only_known_postgres_before_reading_catalogue(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);host=HostFixture(root);store=h.Store(root/'state',uid=os.getuid())
            def progress(phase):
                if phase=='ownership_committed':raise PowerLoss()
            with self.assertRaises(PowerLoss):h.Session(host,store,progress=progress).apply('distinct')
            original=host.observe;host.pg_running=False
            def observe():
                if not host.pg_running:raise h.Refusal('postgres_stopped')
                return original()
            def ensure_pg():host.pg_running=True
            host.observe=observe;host.ensure_pg=ensure_pg
            self.assertEqual(h.Session(host,store).recover()['phase'],'baseline_restored')
            self.assertTrue(host.pg_running)
            self.assertEqual(host.catalogue,inventory())

    def test_recovery_does_not_start_postgres_if_configuration_has_unknown_drift(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);host=HostFixture(root);store=h.Store(root/'state',uid=os.getuid())
            def progress(phase):
                if phase=='ownership_committed':raise PowerLoss()
            with self.assertRaises(PowerLoss):h.Session(host,store,progress=progress).apply('distinct')
            host.pg_running=False
            host.ensure_pg=lambda:setattr(host,'pg_running',True)
            host.config.write_bytes(b'unknown')
            with self.assertRaises(h.Refusal):h.Session(host,store).recover()
            self.assertFalse(host.pg_running)

    def test_failed_reapply_password_does_not_prevent_the_next_valid_retry(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);host=HostFixture(root);store=h.Store(root/'state',uid=os.getuid())
            op=h.Session(host,store);op.apply('distinct');op.undo()
            with self.assertRaises(h.Refusal):op.apply('wrong')
            self.assertEqual(op.apply('distinct')['phase'],'app_role_active')

    def test_switch_and_undo_preserve_business_data_and_old_journal(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);host=HostFixture(root);store=h.Store(root/'state',uid=os.getuid())
            op=h.Session(host,store)
            result=op.apply('distinct')
            self.assertEqual(result['phase'],'app_role_active')
            self.assertEqual(host.catalogue['database']['owner'],110395)
            self.assertEqual(host.config.stat().st_mode&0o777,0o640)
            host.employee_data['new_task']='written-after-cutover'
            result=op.undo()
            self.assertEqual(result['phase'],'baseline_restored')
            self.assertEqual(host.config.read_bytes(),CONFIG)
            self.assertEqual(host.catalogue['database']['owner'],10)
            self.assertEqual(host.employee_data['new_task'],'written-after-cutover')
            self.assertEqual(host.fixed.read_bytes(),b'unchanged-journal')
            self.assertTrue(host.running)

    def test_every_interrupted_boundary_recovers_without_business_restore(self):
        for boundary in ['prepared','backup_complete','writers_stopped','ownership_committed','config_written','https_written','web_verified']:
            with self.subTest(boundary=boundary),tempfile.TemporaryDirectory() as td:
                root=Path(td);host=HostFixture(root);store=h.Store(root/'state',uid=os.getuid())
                def progress(phase):
                    if phase==boundary:raise PowerLoss()
                with self.assertRaises(PowerLoss):h.Session(host,store,progress=progress).apply('distinct')
                self.assertEqual(h.Session(host,store).recover()['phase'],'baseline_restored')
                self.assertEqual(host.config.read_bytes(),CONFIG)
                self.assertEqual(host.catalogue,inventory())
                self.assertEqual(host.employee_data,{'completed_course':True,'new_task':'kept'})
                self.assertTrue(host.running)

    def test_unknown_drift_refuses_without_stopping_the_working_application(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);host=HostFixture(root);store=h.Store(root/'state',uid=os.getuid())
            op=h.Session(host,store);op.apply('distinct')
            host.config.write_bytes(b'outside-change')
            with self.assertRaises(h.Refusal):op.undo()
            self.assertTrue(host.running)
            self.assertEqual(host.catalogue['database']['owner'],110395)

    def test_wrong_password_refuses_before_backup_stop_or_ownership_write(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);host=HostFixture(root);store=h.Store(root/'state',uid=os.getuid())
            with self.assertRaises(h.Refusal):h.Session(host,store).apply('wrong')
            self.assertIsNone(store.load())
            self.assertEqual(host.catalogue,inventory())
            self.assertTrue(host.running)

    def test_power_loss_after_commit_or_rename_before_checkpoint_is_recovered(self):
        for where in ['SQL','config','https']:
            with self.subTest(where=where),tempfile.TemporaryDirectory() as td:
                root=Path(td);host=HostFixture(root);store=h.Store(root/'state',uid=os.getuid())
                original_transfer=host.transfer;original_put=store.put
                def transfer(before,after):
                    original_transfer(before,after)
                    if where=='SQL':raise PowerLoss()
                def put(name,side):
                    original_put(name,side)
                    if name==where:raise PowerLoss()
                host.transfer=transfer;store.put=put
                with self.assertRaises(PowerLoss):h.Session(host,store).apply('distinct')
                host.transfer=original_transfer;store.put=original_put
                self.assertEqual(h.Session(host,store).recover()['phase'],'baseline_restored')
                self.assertEqual(host.catalogue,inventory())
                self.assertEqual(host.config.read_bytes(),CONFIG)


class ActualUnixPermissions(unittest.TestCase):
    @unittest.skipUnless(os.geteuid()==0,'actual UID33 fixture requires root')
    def test_uid33_reads_but_cannot_write_or_replace_the_root_owned_config(self):
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);root.chmod(0o755)
            path=root/'config.php';path.write_bytes(CONFIG);path.chmod(0o755)
            before=h.capture(path)
            try:h.replace_image(h.image_with(before,b'private config',metadata={
                'uid':0,'gid':33,'mode':0o640,'xattrs':{}}))
            except OSError:
                if os.environ.get('USTAR_REQUIRE_ROOT_OWNER_FIXTURE')=='1':raise
                self.skipTest('this namespace maps only UID/GID0; strict native CI is mandatory')
            code="""import os,json,sys
os.setgroups([]);os.setgid(33);os.setuid(33)
p=sys.argv[1]
read=open(p,'rb').read()==b'private config'
try:
 open(p,'wb');write=True
except PermissionError:write=False
try:
 os.unlink(p);replace=True
except PermissionError:replace=False
print(json.dumps([read,write,replace]))
"""
            result=subprocess.run([sys.executable,'-c',code,str(path)],capture_output=True,text=True,check=True)
            self.assertEqual(json.loads(result.stdout),[True,False,False])


if __name__=='__main__': unittest.main()
