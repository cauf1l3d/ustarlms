import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts/infra'))
import remaining_survey as s

class Privacy(unittest.TestCase):
    def test_docker_result_excludes_secrets_and_health_commands(self):
        data={'id':'a'*64,'image':'sha256:'+'b'*64,'running':True,'health':'healthy',
              'log_options':{'max-size':'10m','secret':'PRIVATE_SENTINEL'},
              'Env':['PASSWORD=PRIVATE_SENTINEL'],'health_test':['PRIVATE_SENTINEL'],
              'mounts':[{'Source':'/opt/ustar/data/postgres','Destination':'/var/lib/postgresql/data','RW':True,'Type':'bind'}]}
        result=s.docker_public(data)
        self.assertNotIn('PRIVATE_SENTINEL',json.dumps(result))
        self.assertEqual(result['log_options'],{'max-size':'10m'})
        self.assertEqual(result['mounts'][0]['source'],'/opt/ustar/data/postgres')
    def test_ssh_effective_fields_omit_arbitrary_directives(self):
        value=s.ssh_public('passwordauthentication yes\npubkeyauthentication yes\nauthorizedkeyscommand PRIVATE_SENTINEL\n')
        self.assertEqual(value,{'passwordauthentication':'yes','pubkeyauthentication':'yes'})
    def test_backup_manifest_whitelist(self):
        value=s.backup_public({'phase':'complete','success':True,'PRIVATE':'PRIVATE_SENTINEL',
                'manifest':{'archive':'/srv/ustar-storage/backups/managed/a.tar.gz.age','sha256':'a'*64,'password':'PRIVATE_SENTINEL'}})
        self.assertNotIn('PRIVATE_SENTINEL',json.dumps(value))
        self.assertTrue(value['success']);self.assertEqual(value['manifest']['sha256'],'a'*64)
    def test_smart_reports_counters_without_device_logs(self):
        value=s.smart_public({'smart_status':{'passed':True},'serial_number':'PRIVATE_SENTINEL',
              'ata_smart_attributes':{'table':[{'id':197,'name':'Current_Pending_Sector','raw':{'value':2,'string':'PRIVATE_SENTINEL'}}]},
              'ata_smart_error_log':{'PRIVATE':'PRIVATE_SENTINEL'}})
        self.assertEqual(value['ata_counters']['197'],2)
        self.assertNotIn('PRIVATE_SENTINEL',json.dumps(value))

class Boundaries(unittest.TestCase):
    def test_default_is_offline(self):
        r=subprocess.run([sys.executable,'-I','-B',str(ROOT/'scripts/infra/remaining_survey.py')],capture_output=True,timeout=5)
        self.assertEqual(r.returncode,0,r.stderr)
        self.assertEqual(json.loads(r.stdout),{'phase':'PLAN_ONLY','configuration_changes':False,'provider_called':False})
    def test_command_error_does_not_leak_stderr(self):
        runner=s.Runner(budget=5)
        result=runner.run([sys.executable,'-c','import sys;sys.stderr.write("PRIVATE_SENTINEL");sys.exit(1)'])
        self.assertNotIn('PRIVATE_SENTINEL',json.dumps(result));self.assertEqual(result['error'],'command_failed')
    def test_timeout_is_partial_and_child_is_reaped(self):
        runner=s.Runner(budget=5)
        result=runner.run([sys.executable,'-c','import time;time.sleep(30)'],timeout=.1)
        self.assertEqual(result['error'],'timeout')
    def test_unknown_ssh_context_refused(self):
        self.assertIsNone(s.ssh_context('bad PRIVATE_SENTINEL'))
        value=s.ssh_context('192.0.2.1 50000 192.0.2.2 22')
        self.assertEqual(value['addr'],'192.0.2.1');self.assertEqual(value['lport'],'22')
    def test_metadata_does_not_read_secret_and_does_not_follow_symlink(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'secret.key';p.write_text('PRIVATE_SENTINEL');p.chmod(0o600)
            link=Path(d)/'link';link.symlink_to(p)
            result=s.metadata(link)
            self.assertEqual(result['kind'],'symlink');self.assertNotIn('PRIVATE_SENTINEL',json.dumps(result))
    def test_json_file_size_limit(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'large.json';p.write_bytes(b'x'*100)
            self.assertEqual(s.read_json(p,maximum=10)['error'],'size_or_type')

class Review(unittest.TestCase):
    def test_producer_error_text_is_not_exported(self):
        self.assertNotIn('PRIVATE_SENTINEL',json.dumps(s.backup_public({'error':'PRIVATE_SENTINEL'})))
        self.assertNotIn('PRIVATE_SENTINEL',json.dumps(s.smart_public({'error':'PRIVATE_SENTINEL'})))
    def test_partial_probes_are_explicit(self):
        from unittest.mock import patch
        class FakeRunner:
            def run(self,*a,**kw):return {'error':'unavailable'}
            def parsed(self,*a,**kw):return {'error':'unavailable'}
        with patch.object(s,'permission_survey',side_effect=OSError('PRIVATE_SENTINEL')), \
             patch.object(s,'backup_survey',return_value={}), \
             patch.object(s,'maintenance_survey',return_value={}):
            report=s.collect(FakeRunner())
        self.assertEqual(report['phase'],'survey_partial')
        self.assertEqual(len(report['sections']),8)
        self.assertNotIn('PRIVATE_SENTINEL',json.dumps(report))
    def test_task_metrics_are_aggregate_and_disabled_tasks_are_excluded(self):
        class FakeRunner:
            queries=[]
            def parsed(self,argv,**kw):
                self.queries.append(kw['raw'].decode())
                if len(self.queries)==1:return {'task_columns':[{'relname':'mdl_task_scheduled','attname':k} for k in ('nextruntime','faildelay','disabled')]}
                return {'tasks':'fixture'}
        r=FakeRunner();s.postgres_survey(r)
        self.assertTrue(all(q.startswith('BEGIN READ ONLY;') and q.endswith('ROLLBACK;') for q in r.queries))
        self.assertIn('disabled',r.queries[-1])
        self.assertTrue(all('SET LOCAL search_path=pg_catalog;' in q for q in r.queries))
        self.assertIn('SET LOCAL ROLE ustar_app;',r.queries[-1])
        self.assertNotIn('mdl_user',r.queries[-1])

class SQLInput(unittest.TestCase):
    def test_invalid_prefix_never_reaches_database(self):
        class Trap:
            def parsed(self,*a,**kw):raise AssertionError('SQL executed')
        self.assertEqual(s.postgres_survey(Trap(),prefix="bad'; DROP ROLE x;--"),{'error':'invalid_task_prefix'})

class StatusPrivacy(unittest.TestCase):
    def test_cron_status_only_exports_counts_dates_and_versions(self):
        raw={'installation':{'local_ustar':'2026102300','password':'PRIVATE_SENTINEL'},
             'last_run':{'exit_code':0,'started_utc':'2026-10-25T00:30:00Z','stderr':'PRIVATE_SENTINEL'}}
        value=s.status_public(raw)
        self.assertNotIn('PRIVATE_SENTINEL',json.dumps(value))
        self.assertIn({'context':'last_run','field':'exit_code','value':0},value)
        self.assertIn({'context':'installation','field':'local_ustar','value':'2026102300'},value)

class FirewallPrivacy(unittest.TestCase):
    def test_rule_comments_and_string_payloads_are_not_exported(self):
        text=':INPUT DROP [0:0]\n-A INPUT -s 192.0.2.1/32 -p tcp --dport 22 -m comment --comment "PRIVATE_SENTINEL" -j ACCEPT\n-A INPUT -m string --string "PRIVATE_SENTINEL" -j DROP\n'
        value=s.iptables_public(text)
        self.assertNotIn('PRIVATE_SENTINEL',json.dumps(value))
        self.assertEqual(value['chains'][0]['policy'],'DROP')
        self.assertIn('--dport',value['rules'][0])

if __name__=='__main__':unittest.main()
