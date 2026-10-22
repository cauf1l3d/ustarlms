"""Fixed release and read-only contracts; no remote host/provider is used."""
import copy
import importlib.util
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/infra'))


class Contract(unittest.TestCase):
    def modules(self):
        self.assertIsNotNone(importlib.util.find_spec('connect_con02_contract'), 'new contract interface')
        import connect_con02_contract as c
        import build_connect_con02_update as b
        return c, b

    def reference(self):
        c, b = self.modules()
        if not hasattr(self.__class__, 'bundle'):
            self.__class__.bundle = b.reference_from_git(ROOT, '7f833626f687a8baa8e98efaba7e4d3293b1b47a')
        return c, self.bundle

    def test_frozen_counts_and_hashes(self):
        c, bundle = self.reference()
        b, t = bundle.reference['baseline'], bundle.reference['candidate']
        self.assertEqual((552, 557, 99, 100), (len(b['files']), len(t['files']), len(b['tables']), len(t['tables'])))
        self.assertEqual({'local_ustar_conn_acktokens'}, set(t['tables']) - set(b['tables']))
        self.assertEqual(c.OVERLAY_SHA, b['files']['local/ustar/classes/connect/notification_delivery.php'])
        self.assertEqual(c.APP_COMMIT, bundle.reference['worker_commit'])
        self.assertEqual(set(t['files']), set(bundle.payload))
        self.assertTrue(bundle.worker_archive)

    def test_native_PG_foreign_key_index_inventory(self):
        _, bundle = self.reference()
        indexes = bundle.reference['candidate']['schema_indexes']['local_ustar_conn_acktokens']
        # Observed native PG16 output from job120014562880 includes both the
        # foreign-key userid index and its explicit XMLDB index (two entries).
        self.assertEqual(9, len(indexes))
        self.assertEqual(2, sum(i['fields']==['userid'] and not i['unique'] for i in indexes))

    def test_XMLDB_index_field_whitespace_is_canonical(self):
        _,bundle=self.reference()
        self.assertTrue(all(f==f.strip() for table in bundle.reference['baseline']['schema_indexes'].values()
                            for index in table for f in index['fields']))

    def test_XMLDB_foreign_unique_indexes_are_unique(self):
        _,bundle=self.reference()
        index=next(i for i in bundle.reference['baseline']['schema_indexes']['local_ustar_employment'] if i['fields']==['userid'])
        self.assertTrue(index['unique'])

    def test_target_preserves_old99_and_custom_tasks(self):
        c, _ = self.modules()
        ref = {'baseline': {'tables': ['local_ustar_old'], 'versions': {'local_ustar': 2026102000},
                'schema_fields':{'local_ustar_old':{'id':{'type':'int','length':'10','notnull':True}}},
                'schema_indexes':{'local_ustar_old':[{'fields':['id'],'unique':True}]}},
               'candidate': {'tables': ['local_ustar_new', 'local_ustar_old'], 'versions': {'local_ustar': 2026102200},
                             'schema_fields': {'local_ustar_new': {'id': {'type': 'int', 'length': '10', 'notnull': True}}},
                             'schema_indexes': {'local_ustar_new': [{'fields': ['id'], 'unique': True}]}}}
        before = {'core': c.CORE, 'addons': {'local_ustar': '2026102000', 'third_party': '42'},
                  'tasks': {'prune': [1, 1]}, 'tables': ['local_ustar_old'],
                  'schema': [['local_ustar_old', 'id', 'bigint', 'int8', None, 64, 0, 'NO', None]],
                  'indexes': [['local_ustar_old', 'CREATE UNIQUE INDEX old ON mdl_local_ustar_old USING btree (id)']], 'other_clients': 0}
        after = copy.deepcopy(before)
        after['addons']['local_ustar'] = '2026102200'
        after['tables'] = ref['candidate']['tables']
        after['schema'].append(['local_ustar_new', 'id', 'bigint', 'int8', None, 64, 0, 'NO', None])
        after['indexes'].append(['local_ustar_new', 'CREATE UNIQUE INDEX new ON mdl_local_ustar_new USING btree (id)'])
        c.verify_database(after, before, ref, target=True)
        for key, change in [('tasks', {'prune': [0, 0]}), ('indexes', after['indexes'][1:]),
                            ('schema', after['schema'][1:]), ('tables', after['tables'] + ['local_ustar_extra'])]:
            bad = copy.deepcopy(after); bad[key] = change
            with self.subTest(key=key), self.assertRaises(c.Refusal):
                c.verify_database(bad, before, ref, target=True)

    def test_initial_baseline_schema_is_not_self_adopted(self):
        c,_=self.modules()
        fields={'local_ustar_old':{'id':{'type':'int','length':'10','notnull':True,'sequence':False,'default':None}}}
        indexes={'local_ustar_old':[{'fields':['id'],'unique':True}]}
        profile={'tables':['local_ustar_old'],'versions':{'local_ustar':2026102000},'schema_fields':fields,'schema_indexes':indexes}
        ref={'baseline':profile,'candidate':copy.deepcopy(profile)}
        baseline={'core':c.CORE,'addons':{'local_ustar':'2026102000'},'tasks':{'prune':[1,1]},'tables':profile['tables'],
                  'schema':[['local_ustar_old','id','bigint','int8',None,64,0,'NO',None]],
                  'indexes':[['mdl_local_ustar_old','CREATE UNIQUE INDEX old ON mdl_local_ustar_old USING btree (id)']]}
        c.verify_database(baseline,baseline,ref,target=False)
        for field,value in ((1,'unknown'),(3,'int2'),(7,'YES'),(8,'1')):
            changed=copy.deepcopy(baseline);changed['schema'][0][field]=value
            with self.subTest(field=field),self.assertRaises(c.Refusal):c.verify_database(changed,changed,ref,target=False)
        changed=copy.deepcopy(baseline);changed['indexes'].append(['mdl_local_ustar_old','CREATE INDEX unknown ON mdl_local_ustar_old USING btree (id)'])
        with self.assertRaises(c.Refusal):c.verify_database(changed,changed,ref,target=False)

    def test_fresh_index_parity_includes_prefixed_catalog_tables(self):
        self.modules()
        spec=importlib.util.spec_from_file_location('con02_native_fixture',ROOT/'tests/stage/con02_update_native.py')
        native=importlib.util.module_from_spec(spec);spec.loader.exec_module(native)
        self.assertTrue(hasattr(native,'new_schema'),'fresh parity needs explicit index identity normalization')
        table='local_ustar_conn_acktokens'
        target={'schema':[], 'indexes':[['stage_'+table,'CREATE UNIQUE INDEX stage_ack ON stage_'+table+' USING btree (id)']]}
        fresh={'schema':[], 'indexes':[['fresh_'+table,'CREATE UNIQUE INDEX fresh_ack ON fresh_'+table+' USING btree (id)']]}
        self.assertEqual(native.new_schema(target,'stage_'),native.new_schema(fresh,'fresh_'))
        fresh['indexes']=[]
        self.assertNotEqual(native.new_schema(target,'stage_'),native.new_schema(fresh,'fresh_'))

    def test_production_journal_rejects_unknown_or_retargeted_images(self):
        c,_=self.modules()
        self.assertTrue(hasattr(c.Host,'validate_journal'),'production recovery must bind a fixed path inventory')
        host=c.Host(c.Context(None,Path('/'),0,None,None))
        paths=host.control_paths()
        paths.update(cron_script=Path('/usr/local/sbin/USTAR_CRON_SETUP_20261005.py'),
                     cron_installation=Path('/var/lib/ustar-cron/installation.json'),cron_schedule=Path('/etc/cron.d/ustar_moodle'))
        row={'files':{n:{side:{'path':str(path)} for side in ('before','after')} for n,path in paths.items()}}
        host.validate_journal({'app':row,'runtime':None})
        bad=copy.deepcopy(row);bad['files']['include']['after']['path']='/etc/another-root-file'
        with self.assertRaises(c.Refusal):host.validate_journal({'app':bad,'runtime':None})
        bad=copy.deepcopy(row);bad['files'].pop('token')
        with self.assertRaises(c.Refusal):host.validate_journal({'app':bad,'runtime':None})

    def test_prefix_injection_refused(self):
        c, _ = self.modules()
        with self.assertRaises(c.Refusal): c.validate_prefix('mdl_; DROP TABLE x')

    def test_pg_catalog_index_table_prefix(self):
        c, _ = self.modules()
        ref = {'baseline': {'tables':['local_ustar_old'],'versions':{'local_ustar':2026102000},
                'schema_fields':{'local_ustar_old':{'id':{'type':'int','length':'10','notnull':True}}},
                'schema_indexes':{'local_ustar_old':[{'fields':['id'],'unique':True}]}},
               'candidate': {'tables':['local_ustar_new','local_ustar_old'],'versions':{'local_ustar':2026102200},
                'schema_fields':{'local_ustar_new':{'id':{'type':'int','length':'10','notnull':True}}},
                'schema_indexes':{'local_ustar_new':[{'fields':['id'],'unique':True}]}}}
        before={'core':c.CORE,'addons':{'local_ustar':'2026102000'},'tasks':{'prune':[1,1]},
                'tables':['local_ustar_old'],'schema':[['local_ustar_old','id','bigint','int8',None,64,0,'NO',None]],
                'indexes':[['mdl_local_ustar_old','CREATE UNIQUE INDEX old ON public.mdl_local_ustar_old USING btree (id)']]}
        after=copy.deepcopy(before);after['addons']['local_ustar']='2026102200';after['tables']=ref['candidate']['tables']
        after['schema'].append(['local_ustar_new','id','bigint','int8',None,64,0,'NO',None])
        after['indexes'].append(['mdl_local_ustar_new','CREATE UNIQUE INDEX new ON public.mdl_local_ustar_new USING btree (id)'])
        c.verify_database(after,before,ref,target=True)

    def test_revoked_pilot_is_valid_baseline(self):
        c, _ = self.modules()
        before = {'notification_pilot_userids': [130], 'notification_subjects': ['12345'],
                  'notify_since': 1792583817, 'notifications_enabled': True, 'linking_enabled': True,
                  'last_link_state': 'revoked'}
        self.assertEqual(before, c.validate_fence(before))
        with self.assertRaises(c.Refusal): c.validate_fence({**before, 'notification_pilot_userids': []})

    def test_unknown2200_is_not_adopted(self):
        c, _ = self.modules()
        with self.assertRaises(c.Refusal): c.select_target(None, {'local_ustar': '2026102200'})

    def test_authorized_renewal_between_operations(self):
        c, _ = self.modules()
        class Host:
            leaf = 'old'
            def https_snapshot(self): return {'verified': True, 'leaf': self.leaf, 'owner': 'https'}
        host = Host()
        ctx = c.Context(None, Path('/'), 0, host, None)
        old = c.https_snapshot(ctx); host.leaf = 'new'
        self.assertNotEqual(old, c.https_snapshot(ctx))
        with self.assertRaises(c.Refusal): c.require_https_unchanged(ctx, old)

    def test_readonly_has_no_business_or_nonce_writes(self):
        c, _ = self.modules()
        # A rootless read-only context cannot silently initialize a host adapter.
        import tempfile
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            with self.assertRaises(c.Refusal): c.check(c.Context(None, root, 1234, None, None))
            self.assertEqual([], list(root.iterdir()))

    def test_positive_readonly_check_uses_only_read_adapters_and_existing_locks(self):
        c,_=self.modules()
        import os,tempfile
        from types import SimpleNamespace
        import connect_con02_state as state
        with tempfile.TemporaryDirectory() as td:
            root=Path(td);fields={'local_ustar_old':{'id':{'type':'int','length':'10','notnull':True}}}
            profile={'tables':['local_ustar_old'],'versions':{'local_ustar':2026102000},
                     'schema_fields':fields,'schema_indexes':{'local_ustar_old':[{'fields':['id'],'unique':True}]},'files':{'local/ustar/version.php':'a'*64}}
            ref={'baseline':profile,'candidate':copy.deepcopy(profile),'tool_commit':'a'*40}
            db={'core':c.CORE,'addons':{'local_ustar':'2026102000'},'tasks':{'prune':[1,1]},'tables':profile['tables'],
                'schema':[['local_ustar_old','id','bigint','int8',None,64,0,'NO',None]],
                'indexes':[['mdl_local_ustar_old','CREATE UNIQUE INDEX old ON mdl_local_ustar_old USING btree (id)']]}
            calls=[]
            class ReadHost:
                def prefix(self):calls.append('prefix');return 'mdl_'
                def database(self,prefix):calls.append('SQL_readonly');return db
                def source(self,*,target):calls.append('source_read')
                def controls(self,journal):calls.append('controls_read')
                def https_snapshot(self):calls.append('HTTPS_owner_read');return {'verified':True}
            ctx=c.Context(SimpleNamespace(reference=ref),root,os.getuid(),ReadHost(),SimpleNamespace(base=root/'new-state'))
            for path in state.Locks(ctx).paths[1:]:
                path.parent.mkdir(parents=True,exist_ok=True);path.touch(mode=0o600)
            inventory=lambda:{str(p.relative_to(root)):(p.stat().st_ino,p.stat().st_mode,p.read_bytes()) for p in root.rglob('*') if p.is_file()}
            before=inventory();result=c.check(ctx)
            self.assertEqual('baseline_ready',result['phase']);self.assertFalse(result['host_changes'])
            self.assertEqual(before,inventory());self.assertFalse(ctx.engine.base.exists())
            self.assertEqual(['prefix','SQL_readonly','source_read','controls_read','HTTPS_owner_read'],calls)

    def test_recovery_binds_saved_containers_while_stopped(self):
        c,_=self.modules()
        self.assertTrue(hasattr(c.Host,'bind_identities'),'recovery must not adopt a running-only inventory')
        from types import SimpleNamespace
        from unittest.mock import Mock
        images={'ustar_moodle':'sha256:'+'1'*64,'ustar_postgres':'sha256:'+'2'*64}
        identities={n:{'Id':str(i)*64,'Image':image} for i,(n,image) in enumerate(images.items(),1)}
        class Control:
            def __init__(self,d,identities,log):self.identities=identities
            def inspect(self):return {n:{'State':{'Running':False}} for n in self.identities}
        p=SimpleNamespace(IMAGES=images,containers=Mock(side_effect=AssertionError('running-only inventory called')))
        ctx=c.Context(None,Path('/'),0,None,SimpleNamespace(p=p,tx=SimpleNamespace(Host=Control)))
        host=c.Host(ctx);host.bind_identities(identities)
        self.assertFalse(any(r['State']['Running'] for r in host.inspect().values()));p.containers.assert_not_called()


if __name__ == '__main__': unittest.main()
