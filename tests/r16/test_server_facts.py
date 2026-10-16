import copy
from datetime import datetime, timedelta, timezone
import importlib.util
import io
import gzip
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('facts_under_test',ROOT/'scripts/infra/server_facts.py')
facts=importlib.util.module_from_spec(spec)
spec.loader.exec_module(facts)

def fixture():
    blocks={key:{'status':'error','observed_at':facts.stamp(),'reason':'unavailable','data':None}
            for key in facts.SCHEMAS}
    blocks['smart'].update(status='not_collected',reason='not_requested')
    return {'schema_version':1,'collected_utc':facts.stamp(),'host_alias':'server1',
            'script_sha256':'a'*64,'blocks':blocks}

def payload(value):
    counts={key:sum(r['status']==key for r in value['blocks'].values()) for key in facts.STATE}
    errors=sorted([{'block':k,'status':r['status'],'reason':r['reason']}
                  for k,r in value['blocks'].items() if r['status']!='ok'],key=lambda r:r['block'])
    data={'facts.json':facts.encode(value),'errors.json':facts.encode(errors),'README.md':facts.REPORT_README}
    data['manifest.json']=facts.encode({'schema_version':1,'collected_utc':value['collected_utc'],
      'script_sha256':value['script_sha256'],'status':'partial' if counts['error'] or counts['partial'] else 'complete',
      'counts':counts,'files':{name:facts.digest(raw) for name,raw in data.items()}})
    return data

class Schema(unittest.TestCase):
    def test_partial_failures_and_exclusions_are_retained(self):
        value,manifest=facts.validate_payload(payload(fixture()))
        self.assertEqual(manifest['status'],'partial')
        self.assertEqual(manifest['counts']['error'],len(facts.SCHEMAS)-1)
        self.assertEqual(value['blocks']['smart']['status'],'not_collected')

    def test_checksum_tampering_is_rejected(self):
        data=payload(fixture())
        data['facts.json']=data['facts.json'].replace(b'unavailable',b'invalid_data',1)
        with self.assertRaises(facts.Refusal):facts.validate_payload(data)

    def test_error_list_cannot_be_deleted_or_replaced(self):
        data=payload(fixture());data['errors.json']=b'[]\n'
        with self.assertRaises(facts.Refusal):facts.validate_payload(data)

    def test_unknown_raw_fields_rejected_even_with_recomputed_hashes(self):
        value=fixture()
        value['blocks']['pg_query_stats'].update(status='ok',reason='measured',
          data={'rows':[{'query_id':'123','calls':1,'mean_ms':1.,'total_ms':1.,'query':'select a private value'}]})
        with self.assertRaises(facts.Refusal):facts.validate_payload(payload(value))

    def test_required_checks_cannot_be_marked_not_requested(self):
        value=fixture();value['blocks']['pg_roles'].update(status='not_collected',reason='not_requested')
        with self.assertRaises(facts.Refusal):facts.validate_payload(payload(value))

    def test_duplicate_json_and_nan_rejected(self):
        for raw in (b'{"a":1,"a":2}',b'{"a":NaN}'):
            with self.assertRaises(facts.Refusal):facts.decode(raw)

    def test_actual_secret_values_and_all_private_ranges_rejected(self):
        for value in ('token=not-for-git','-----BEGIN PRIVATE KEY-----','10.1.2.3','172.16.2.3','192.168.1.2','fd00::1234'):
            with self.subTest(value=value),self.assertRaises(facts.Refusal):facts.shape(value,facts.TOKEN)

    def test_presence_and_password_policy_keys_are_not_secret_values(self):
        facts.shape('passwordauthentication',facts.IDENT)
        facts.shape('dbpass',facts.IDENT)
        facts.shape('16.15',facts.TOKEN)
        facts.shape('sha256:'+'c'*64,r'sha256:[a-f0-9]{64}')

class ReadBoundary(unittest.TestCase):
    def test_links_hardlinks_and_fifo_never_read(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);(root/'private').write_text('do not export')
            (root/'link').symlink_to(root/'private')
            os.link(root/'private',root/'hard')
            os.mkfifo(root/'pipe')
            (root/'dir').mkdir();(root/'parent').symlink_to(root/'dir',target_is_directory=True)
            (root/'dir/file').write_text('a')
            for path in ('link','hard','pipe','parent/file'):
                with self.subTest(path=path),self.assertRaises((OSError,facts.Refusal)):facts.read_regular(root/path)

    def test_file_limit_and_parent_escape_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'large';path.write_bytes(b'12345')
            with self.assertRaises(facts.Refusal):facts.read_regular(path,4)
            with self.assertRaises(facts.Refusal):facts.read_regular(Path(tmp)/'..'/'large')

    def test_bounded_stdout_and_timeout(self):
        with self.assertRaisesRegex(facts.Refusal,'output_limit'):
            facts.command([sys.executable,'-c','print("x"*1000000)'])
        with self.assertRaisesRegex(facts.Refusal,'timeout'):
            facts.command([sys.executable,'-c','import time; time.sleep(3)'],timeout=.1)

class StaticDiscovery(unittest.TestCase):
    def test_cron_worker_success_does_not_hide_task_failure(self):
        receipt={'finished_utc':'2026-10-13T18:30:00+00:00','cron_exit_code':0,
          'after':{'new_failures':[{'private':'not exported'}],'scheduled_failed':2,
                   'scheduled_overdue_10m':1,'adhoc_queued':3,'latest_task_utc':'2026-10-13T18:29:00Z'},
          'ustar':{'error_lines':4}}
        with patch.object(facts,'optional_json',return_value=receipt),patch.object(facts,'read_regular',return_value=b'original receipt'):
            value=facts.cron_receipt()
        facts.shape(value,facts.SCHEMAS['cron_receipt'])
        self.assertEqual(value['worker_exit_code'],0)
        self.assertEqual(value['new_task_failures'],1)
        self.assertEqual(value['ustar_error_lines'],4)
        self.assertNotIn('private',json.dumps(value))

    def test_malformed_source_does_not_abort_other_blocks(self):
        collector=facts.Collector()
        collector.add('cron_receipt',lambda: [].get('after'))
        self.assertEqual(collector.blocks['cron_receipt']['status'],'error')
        collector.add('clock',lambda:{'timezone':'UTC','ntp_enabled':True,'ntp_synchronized':False})
        self.assertEqual(collector.blocks['clock']['status'],'ok')

    def test_application_queries_never_fall_back_to_an_unknown_database(self):
        collector=facts.Collector()
        add=collector.add
        selected={'moodle_config','moodle_plugins','scheduled_tasks','adhoc_tasks'}
        def selected_add(name,fn,requested=True):
            if name in selected:add(name,fn,requested)
        with patch.object(collector,'add',side_effect=selected_add),patch.object(collector,'get',return_value={'db_name':None,'table_prefix':'mdl_'}),patch.object(facts,'sql',side_effect=AssertionError('unexpected SQL')):
            collector.collect()
        self.assertEqual(set(collector.blocks),selected)
        self.assertTrue(all(row['reason']=='dependency_unavailable' for row in collector.blocks.values()))

    def test_config_never_executes_and_dynamic_values_stay_unknown(self):
        raw="<?php\n/* $CFG->prefix='wrong_'; */\n$CFG->prefix='mdl_';\n$CFG->cookiesecure=false;\n$CFG->dbname=getenv('POSTGRES_DB');\n"
        self.assertEqual(facts.literal(raw,'prefix'),'mdl_')
        self.assertIs(facts.literal(raw,'cookiesecure'),False)
        self.assertIsNone(facts.literal(raw,'dbname'))
        self.assertIsNone(facts.literal(raw+"$CFG->prefix='again_';",'prefix'))

    def test_nested_public_and_real_config_discovered_from_mount(self):
        with tempfile.TemporaryDirectory() as tmp:
            outer=Path(tmp);public=outer/'public'
            for rel in ('local/ustar','theme/ustar'):
                p=public/rel;p.mkdir(parents=True);(p/'version.php').write_text('<?php $plugin->version = 2026100204;')
            (public/'version.php').write_text('<?php $version=2025100608.00;')
            (outer/'config.php').write_text("<?php $CFG->prefix='mdl_'; $CFG->dbname='moodle'; $CFG->dbuser='moodle'; $CFG->cookiesecure=true;")
            cts={'items':[{'name':'ustar_moodle','mounts':[{'source':str(outer),'destination':'/var/www/html','rw':True}]}]}
            value=facts.layout(cts)
            self.assertEqual(value['public_root'],str(public))
            self.assertEqual(value['config_path'],str(outer/'config.php'))
            self.assertEqual(value['db_name'],'moodle')
            self.assertTrue(value['flags']['cookiesecure'])
            self.assertIsNone(value['flags']['sslproxy'])

    def test_sql_uses_selected_database_readonly_timeouts_and_no_psqlrc(self):
        with patch.object(facts,'text',return_value='[]') as run:
            self.assertEqual(facts.sql(facts.SQL['pg_roles'],'real_db'),{'rows':[]})
        argv=run.call_args.args[0]
        self.assertIn('real_db',argv)
        self.assertIn('-X',facts.PSQL)
        self.assertIn('-w',facts.PSQL)
        self.assertIn('default_transaction_read_only=on',facts.PSQL)
        self.assertIn('statement_timeout=3000',facts.PSQL)
        self.assertIn('POSTGRES_USER',facts.PSQL)
        self.assertNotIn('query,',facts.SQL['pg_query_stats'])

    def test_prefix_injection_rejected(self):
        with self.assertRaises(facts.Refusal):facts.moodle_queries('mdl_;DROP TABLE')

    def test_source_manifest_excludes_credentials_and_preserves_theme_config_hash(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            for rel in ('local/ustar','theme/ustar'):(root/rel).mkdir(parents=True)
            (root/'local/ustar/config.php').write_text('credential')
            (root/'theme/ustar/config.php').write_text('theme metadata')
            (root/'local/ustar/a.php').write_text('<?php')
            value=facts.source_manifest(root)
            self.assertEqual({r['path'] for r in value['files']},{'local/ustar/a.php','theme/ustar/config.php'})
            self.assertEqual(value['skipped'],1)
            self.assertTrue(value['not_atomic'])

class ExportImport(unittest.TestCase):
    def test_archive_decompression_bomb_refused_before_tar_parsing(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'report.tar.gz'
            path.write_bytes(gzip.compress(b'0'*(5*facts.LIMIT)))
            with self.assertRaisesRegex(facts.Refusal,'archive_expansion'):
                facts.read_payload(path)

    def test_unknown_source_is_not_reported_as_a_mismatch(self):
        import yaml
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);repo=root/'repo';(repo/'context/roadmap').mkdir(parents=True)
            state_path=repo/'context/roadmap/STATE.yaml'
            state_path.write_text('updated_at: "2026-10-16"\nnext_task: INF-06\n')
            baseline=repo/'context/code_map/generated/source_manifest.json'
            baseline.parent.mkdir(parents=True)
            baseline.write_bytes(facts.encode({'source_commit':'b'*40,'files':[{'path':'moodle/local/ustar/a.php','sha256':'c'*64}]}))
            source=root/'snapshot';facts.export(fixture(),source)
            with patch.object(facts.subprocess,'run'):facts.import_report(source,repo)
            state=yaml.safe_load(state_path.read_text())
            self.assertIsNone(state['server_facts']['application_source_matches'])
            comparison=json.loads((repo/state['server_facts']['source_comparison']).read_text())
            self.assertFalse(comparison['evidence_complete'])
            self.assertEqual(comparison['only_in_git'],['local/ustar/a.php'])

    def test_future_snapshot_and_existing_symlink_touch_no_state(self):
        for kind in ('future','symlink'):
            with tempfile.TemporaryDirectory() as tmp:
                root=Path(tmp);repo=root/'repo';(repo/'context/roadmap').mkdir(parents=True)
                state_path=repo/'context/roadmap/STATE.yaml';before=b'updated_at: "2026-10-16"\nnext_task: INF-06\n'
                state_path.write_bytes(before)
                value=fixture()
                if kind=='future':value['collected_utc']=(datetime.now(timezone.utc)+timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M:%SZ')
                else:
                    (repo/'context/current.yaml').symlink_to(state_path)
                source=root/'snapshot';facts.export(value,source)
                with self.assertRaises((facts.Refusal,OSError)):facts.import_report(source,repo)
                self.assertEqual(state_path.read_bytes(),before)

    def test_private_export_and_round_trip(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/'snapshot'
            self.assertEqual(facts.export(fixture(),root),1)
            self.assertEqual(root.stat().st_mode&0o777,0o700)
            self.assertEqual((root/'facts.json').stat().st_mode&0o777,0o600)
            for source in (root,root/'report.tar.gz'):
                _,manifest=facts.validate_payload(facts.read_payload(source))
                self.assertEqual(manifest['status'],'partial')
            with self.assertRaises(facts.Refusal):facts.export(fixture(),root)

    def test_archive_traversal_and_links_refused_without_extraction(self):
        for name,kind in (('../facts.json',tarfile.REGTYPE),('facts.json',tarfile.SYMTYPE)):
            with tempfile.TemporaryDirectory() as tmp:
                path=Path(tmp)/'report.tar.gz'
                with tarfile.open(path,'w:gz') as archive:
                    member=tarfile.TarInfo(name);member.type=kind;member.linkname='/etc/shadow'
                    archive.addfile(member,io.BytesIO())
                with self.assertRaises(facts.Refusal):facts.read_payload(path)

    def test_import_preserves_evidence_and_does_not_close_tasks(self):
        import yaml
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);repo=root/'repo';(repo/'context/roadmap').mkdir(parents=True)
            state_path=repo/'context/roadmap/STATE.yaml'
            state_path.write_text('updated_at: "2026-10-16"\nnext_task: INF-06\n')
            (repo/'.codex/checkpoints').mkdir(parents=True)
            source=root/'snapshot';facts.export(fixture(),source)
            before=(source/'facts.json').read_bytes()
            with patch.object(facts.subprocess,'run'):
                facts.import_report(source,repo)
            state=yaml.safe_load(state_path.read_text())
            self.assertEqual(state['next_task'],'INF-06')
            self.assertFalse(state['server_facts']['functional_acceptance'])
            self.assertFalse(state['server_facts']['independently_authenticated_host'])
            self.assertEqual((repo/state['server_facts']['snapshot']/'facts.json').read_bytes(),before)
            self.assertEqual((source/'facts.json').read_bytes(),before)

    def test_failed_validation_touches_no_state(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);repo=root/'repo';repo.mkdir()
            source=root/'bad';source.mkdir();(source/'facts.json').write_text('{}')
            with self.assertRaises(facts.Refusal):facts.import_report(source,repo)
            self.assertEqual(list(repo.iterdir()),[])

    def test_failed_post_import_gate_rolls_back(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);repo=root/'repo';(repo/'context/roadmap').mkdir(parents=True)
            state_path=repo/'context/roadmap/STATE.yaml';before=b'updated_at: "2026-10-16"\nnext_task: INF-06\n'
            state_path.write_bytes(before)
            source=root/'snapshot';facts.export(fixture(),source)
            with patch.object(facts.subprocess,'run',side_effect=subprocess.CalledProcessError(1,'check')):
                with self.assertRaises(subprocess.CalledProcessError):facts.import_report(source,repo)
            self.assertEqual(state_path.read_bytes(),before)
            self.assertEqual(list((repo/'context/runtime/server-facts').iterdir()),[])

if __name__=='__main__':unittest.main()
