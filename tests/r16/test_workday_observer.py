"""Passive observer: real files/processes, hand-counted aggregates, no production access."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


def observer():
    path = ROOT / 'scripts/infra/workday_observer.py'
    assert path.is_file(), 'Bounded passive workday observer required'
    spec = importlib.util.spec_from_file_location('workday_observer', path)
    module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
    return module


class Summary(unittest.TestCase):
    def test_percentiles_merge_request_histograms_not_window_percentiles(self):
        m = observer()
        rows = [dict(at=100, web={'status':'ok','histogram':[[10,9],[100,1]],'errors_4xx':0,'errors_5xx':1}),
                dict(at=160, web={'status':'ok','histogram':[[1000,1]],'errors_4xx':1,'errors_5xx':0})]
        value = m.summarize(rows, 60)['web']
        self.assertEqual(11, value['requests'])
        self.assertEqual((10,1000,1000), tuple(value[k] for k in ('p50_ms','p95_ms','p99_ms')))
        self.assertEqual((1,1), (value['errors_4xx'],value['errors_5xx']))
        self.assertEqual('Apache processing including assets; not authenticated page latency', value['scope'])

    def test_missing_or_idle_source_never_becomes_measured_zero_latency(self):
        value = observer().summarize([{'at':100,'web':{'status':'no_data','reason':'no_new_requests'}}],60)
        self.assertEqual('no_data', value['web']['status'])
        self.assertIsNone(value['web']['p95_ms'])
        self.assertFalse(value['workday_SLO_accepted'])

    def test_restart_reset_version_change_or_long_gap_prevents_false_database_rates(self):
        m=observer()
        first={'at':100,'database':{'status':'ok','epoch':['start1',None,'2026102803'],
                                  'counters':{'xact_commit':100,'blks_read':10}}}
        good={'at':160,'database':{'status':'ok','epoch':['start1',None,'2026102803'],
                                 'counters':{'xact_commit':130,'blks_read':16}}}
        out=m.summarize([first,good],60)
        self.assertEqual(0.5,out['database_intervals'][0]['rates_per_second']['xact_commit'])
        for changed in [dict(good,at=400),dict(good,database={**good['database'],'epoch':['start2',None,'2026102803']}),
                        dict(good,database={**good['database'],'counters':{'xact_commit':1,'blks_read':16}}),
                        dict(good,database={**good['database'],'epoch':['start1',None,'2026102802']})]:
            with self.subTest(changed=changed):
                value=m.summarize([first,changed],60)['database_intervals'][0]
                self.assertIsNone(value['rates_per_second'])
                self.assertEqual('gap_or_epoch_change_or_counter_reset',value['reason'])


class NumericLog(unittest.TestCase):
    def test_copytruncate_regrowth_is_detected_even_when_inode_and_size_look_current(self):
        m=observer()
        with tempfile.TemporaryDirectory() as directory:
            p=Path(directory)/'numeric';p.write_text('099 200 1000\n'*4)
            log=m.NumericLog(p,owner=os.getuid());log.sample(100)
            p.write_text('101 200 2000\n'*10)
            value=log.sample(102)
            self.assertTrue(value['coverage_gap'])
            self.assertEqual([[2,10]],value['histogram'])

    def test_append_only_numeric_samples_are_counted_once_and_fragments_wait(self):
        m=observer()
        with tempfile.TemporaryDirectory() as directory:
            p=Path(directory)/'numeric';p.write_text('99 200 999\n')
            log=m.NumericLog(p,owner=os.getuid());log.sample(100)
            with p.open('a') as f:f.write('101 200 10001\n101 500 20000\n102 200 ')
            first=log.sample(102)
            self.assertEqual([[11,1],[20,1]],first['histogram'])
            self.assertEqual(1,first['errors_5xx'])
            self.assertEqual('no_data',log.sample(103)['status'])
            with p.open('a') as f:f.write('5000\n')
            self.assertEqual([[5,1]],log.sample(104)['histogram'])

    def test_rotation_invalid_records_and_symlinks_mark_coverage_gaps(self):
        m=observer()
        with tempfile.TemporaryDirectory() as directory:
            p=Path(directory)/'numeric';p.write_text('99 200 10\n')
            log=m.NumericLog(p,owner=os.getuid());log.sample(100)
            p.rename(p.with_name('old'));p.write_text('101 200 1000\n')
            self.assertTrue(log.sample(102)['coverage_gap'])
            with p.open('a') as f:f.write('SECRET token invalid\n')
            value=log.sample(103)
            self.assertEqual('no_data',value['status']);self.assertNotIn('SECRET',json.dumps(value))
            p.unlink();p.symlink_to(p.with_name('old'))
            self.assertEqual('source_unavailable_or_invalid',log.sample(104)['reason'])

    def test_size_budget_never_silently_reports_truncated_source_as_complete(self):
        m=observer()
        with tempfile.TemporaryDirectory() as directory:
            p=Path(directory)/'numeric';p.write_bytes(b'')
            log=m.NumericLog(p,owner=os.getuid());log.sample(100)
            p.write_bytes(b'101 200 1000\n'*(m.LOG_BUDGET//12+1))
            self.assertEqual('source_limit',log.sample(102)['reason'])


class Boundaries(unittest.TestCase):
    def test_capture_reads_the_outer_production_config_and_finishes_private_report(self):
        from unittest.mock import patch
        m=observer()
        def config(path, limit):
            self.assertEqual(Path('/opt/ustar/data/moodle/public/config.php'),path)
            return b"<?php $CFG->prefix = 'mdl_';"
        with tempfile.TemporaryDirectory() as directory:
            output=Path(directory)/'report'
            with patch.object(m.os,'geteuid',return_value=0), patch.object(m,'regular_read',side_effect=config), \
                 patch.object(m,'database_sample',return_value={'status':'ok'}), \
                 patch.object(m,'private_output',side_effect=lambda p:p.mkdir(mode=0o700)), \
                 patch.object(m,'host_sample',return_value={'status':'no_data'}), \
                 patch.object(m.NumericLog,'sample',return_value={'status':'no_data'}), \
                 patch.object(m.time,'monotonic',side_effect=[0,61,61]):
                self.assertTrue(m.collect(60,60,output)['completed_requested_duration'])
            self.assertEqual({'samples.jsonl','summary.json','SHA256SUMS'}, {p.name for p in output.iterdir()})
            self.assertFalse(json.loads((output/'summary.json').read_text())['workday_SLO_accepted'])

    def test_default_cli_is_offline_and_timing_rejection_precedes_host_access(self):
        m=observer()
        with contextlib.redirect_stdout(io.StringIO()) as out:self.assertEqual(0,m.main([]))
        self.assertEqual({'phase':'PLAN_ONLY','host_access':False,'network_used':False},json.loads(out.getvalue()))
        for duration,interval in [(0,60),(86401,60),(28800,59),(10,20)]:
            with self.subTest(duration=duration,interval=interval), self.assertRaises(ValueError):
                m.validate_timing(duration,interval)
        m.validate_timing(60,60);m.validate_timing(28800,60)

    def test_command_output_is_bounded_and_stderr_secrets_do_not_escape(self):
        m=observer()
        self.assertEqual({'error':'command_failed'},m.command([sys.executable,'-c',
            "import sys;sys.stderr.write('SECRET_PASSWORD');sys.exit(1)"]))
        self.assertEqual({'error':'output_limit'},m.command([sys.executable,'-c',
            "print('x'*70000)"]))
        self.assertEqual({'error':'timeout'},m.command([sys.executable,'-c','import time;time.sleep(2)'],timeout=.1))

    def test_database_shape_discards_private_fields_and_refuses_wrong_version(self):
        m=observer()
        raw={'local_ustar':'2026102803','theme_ustar':'2026102700','server_started_at':100,
             'stats_reset':None,'counters':{'xact_commit':10,'query':'SECRET'},
             'cron':{'adhoc_queued':0},'password':'SECRET','usernames':['Employee']}
        out=m.database_shape(raw)
        self.assertEqual('ok',out['status']);self.assertNotIn('SECRET',json.dumps(out))
        self.assertNotIn('Employee',json.dumps(out))
        for bad in [{**raw,'local_ustar':'2026102804'},{**raw,'theme_ustar':'2026100202'},
                    {**raw,'counters':{'xact_commit':-1}},{**raw,'cron':{'adhoc_queued':True}}]:
            with self.subTest(bad=bad),self.assertRaises(ValueError):m.database_shape(bad)

    def test_sql_is_readonly_bounded_and_prefix_cannot_inject(self):
        m=observer();sql=m.database_sql('mdl_')
        self.assertTrue(sql.startswith('BEGIN READ ONLY;'))
        self.assertTrue(sql.rstrip().endswith('ROLLBACK;'))
        for bad in ['mdl_;DELETE','x"','', 'a'*70]:
            with self.subTest(prefix=bad),self.assertRaises(ValueError):m.database_sql(bad)

    @unittest.skipUnless(os.getuid()==0,'actual root-owned output boundary')
    def test_private_report_directory_is_new_and_rejects_symlink_ancestors(self):
        m=observer()
        with tempfile.TemporaryDirectory(dir='/root') as directory:
            root=Path(directory);p=root/'new';m.private_output(p)
            self.assertEqual(0o700,p.stat().st_mode&0o777)
            with self.assertRaises(ValueError):m.private_output(p)
            (root/'link').symlink_to(p,target_is_directory=True)
            with self.assertRaises(ValueError):m.private_output(root/'link'/'new')
