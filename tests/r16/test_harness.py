import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'harness/ustar-contextd'))
sys.path.insert(0,str(ROOT/'scripts'))
from safe_context import ContextReader, ContextDenied, MAX_BYTES, MAX_TOTAL
import production_manifest as pm
import collect_runtime as cr


class Boundary(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root/'context/runtime').mkdir(parents=True)
        (self.root/'context/runtime/ok.md').write_text('разрешено')
        (self.root/'outside.md').write_text('OUTSIDE_CANARY')
        self.reader = ContextReader(self.root)

    def test_allowed(self):
        self.assertEqual(self.reader.read('context/runtime/ok.md'),'разрешено')

    def test_bad_paths(self):
        for p in ['/etc/passwd',str(self.root/'outside.md'),'../outside.md','context/../outside.md',
                  'context/runtime/../../outside.md','context//runtime/ok.md','context/./runtime/ok.md',
                  'C:/context/a.md','context\\runtime\\ok.md','context/a\0.md','outside.md',
                  'context/.env','context/config.php','context/a.exe','context/','context',
                  'context/%2e%2e/outside.md']:
            with self.subTest(p=p), self.assertRaises(ContextDenied): self.reader.read(p)

    def test_missing(self):
        with self.assertRaises(ContextDenied): self.reader.read('context/missing.md')

    def test_directory(self):
        (self.root/'context/directory.md').mkdir()
        with self.assertRaises(ContextDenied): self.reader.read('context/directory.md')

    def test_symlinks(self):
        (self.root/'context/leaf.md').symlink_to(self.root/'outside.md')
        (self.root/'context/link').symlink_to(self.root, target_is_directory=True)
        (self.root/'context/inside.md').symlink_to(self.root/'context/runtime/ok.md')
        for path in ['context/leaf.md','context/link/outside.md','context/inside.md']:
            with self.subTest(path=path), self.assertRaises(ContextDenied): self.reader.read(path)
        self.assertNotIn('OUTSIDE_CANARY',str(self.reader.collect()))

    def test_root_symlink(self):
        (self.root/'context').rename(self.root/'old')
        (self.root/'context').symlink_to(self.root/'old',target_is_directory=True)
        with self.assertRaises(ContextDenied): self.reader.read('context/runtime/ok.md')
        self.assertEqual(self.reader.collect(),{})

    def test_hardlink(self):
        os.link(self.root/'outside.md',self.root/'context/hard.md')
        with self.assertRaises(ContextDenied): self.reader.read('context/hard.md')

    def test_types_and_limit(self):
        for raw in [b'a'*(MAX_BYTES+1),b'\xff',b'a\0b']:
            (self.root/'context/input.txt').write_bytes(raw)
            with self.assertRaises(ContextDenied): self.reader.read('context/input.txt')
        (self.root/'context/input.txt').write_bytes(b'a'*MAX_BYTES)
        self.assertEqual(len(self.reader.read('context/input.txt')),MAX_BYTES)

    def test_fifo_nonblocking(self):
        os.mkfifo(self.root/'context/fifo.txt')
        with self.assertRaises(ContextDenied): self.reader.read('context/fifo.txt')

    def test_aggregate_limit(self):
        for n in range(8): (self.root/f'context/{n}.txt').write_text('a'*MAX_BYTES)
        self.assertLessEqual(sum(len(v.encode()) for v in self.reader.collect().values()),MAX_TOTAL)

    def test_directory_swap_does_not_escape(self):
        # Swap the pathname after the directory descriptor has been opened.
        original = os.open
        def raced(path,*args,**kwargs):
            fd = original(path,*args,**kwargs)
            if path == 'runtime':
                (self.root/'context/runtime').rename(self.root/'kept')
                (self.root/'context/runtime').symlink_to(self.root, target_is_directory=True)
            return fd
        with patch('safe_context.os.open',side_effect=raced):
            self.assertEqual(self.reader.read('context/runtime/ok.md'),'разрешено')

    def test_leaf_swap_denied(self):
        original = os.open
        def raced(path,*args,**kwargs):
            if path == 'ok.md':
                (self.root/'context/runtime/ok.md').unlink()
                (self.root/'context/runtime/ok.md').symlink_to(self.root/'outside.md')
            return original(path,*args,**kwargs)
        with patch('safe_context.os.open',side_effect=raced), self.assertRaises(ContextDenied):
            self.reader.read('context/runtime/ok.md')

    def test_all_tools_use_reader(self):
        import server
        with patch.object(server,'reader',self.reader):
            self.assertIn('error',server.get_context_file('../outside.md'))
            self.assertEqual(server.search_context('OUTSIDE_CANARY'),[])
            self.assertNotIn('OUTSIDE_CANARY',str(server.get_runtime_state()))
            for func in [server.get_project_context,server.get_context_index,server.get_active_tasks,server.get_recent_context_changes]:
                with self.assertRaises(ContextDenied):func()


class Generator(unittest.TestCase):
    def test_index_excludes_itself_and_symlink(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);(root/'context/index').mkdir(parents=True)
            (root/'context/valid.md').write_text('ok')
            (root/'outside.md').write_text('outside')
            (root/'context/link.md').symlink_to(root/'outside.md')
            script=ROOT/'scripts/build_context_index.py'
            for _ in range(2):
                subprocess.run([sys.executable,str(script),'--root',tmp],check=True,stdout=subprocess.DEVNULL)
                data=json.loads((root/'context/index/context_index.json').read_text())
                self.assertEqual([f['path'] for f in data['files']],['context/valid.md'])

    def test_nested_mount_mapping(self):
        mounts=[{'Type':'bind','Source':'/host/base','Destination':'/var/www/html'},
                {'Type':'bind','Source':'/host/nested','Destination':'/var/www/html/public'}]
        self.assertEqual(cr.map_root('/var/www/html/public',mounts),'/host/nested')
        self.assertEqual(cr.map_root('/var/www/html/public',mounts[:1]),'/host/base/public')
        with self.assertRaises(ValueError):cr.map_root('/var/www/html-other',mounts)

    def test_health_uptime(self):
        from datetime import datetime,timezone,timedelta
        state={'started_at':(datetime.now(timezone.utc)-timedelta(hours=27)).isoformat(),
               'running':True,'health':'healthy'}
        with patch.object(cr,'run',return_value=json.dumps(state)):
            actual=cr.inspect('fake')
        self.assertEqual(actual['health'],'healthy')
        self.assertGreaterEqual(actual['uptime_seconds'],27*3600)

    def test_php_probe_uses_measured_fields(self):
        values={'dirroot':'/mounted/public','php':'8.3.33','moodle_db':'2025100601.03',
                'ustar_db':'2026082730','postgres':'16.15','ustar_disk':'2026082730'}
        with patch.object(cr,'run',return_value='USTAR_PROBE='+json.dumps(values)) as run:
            self.assertEqual(cr.probe_moodle('fake',[{'Destination':'/mounted'}]),values)
            cmd=run.call_args.args[0]
            self.assertIn('-r',cmd)
            self.assertNotIn('<?php',cmd[-2])
            self.assertIn('SET default_transaction_read_only = on',cmd[-2])
            self.assertIn('/mounted/public/config.php',cmd[-1])

    def test_manifest_classification_and_no_writes(self):
        with tempfile.TemporaryDirectory() as tmp:
            base=Path(tmp);repo=base/'git';repo.mkdir();prod=base/'prod'
            def g(*args):return pm.git(repo,*args)
            g('init','-q');g('config','user.name','Fixture');g('config','user.email','fixture@example.invalid')
            for scope in pm.SCOPES:
                (repo/'moodle'/scope).mkdir(parents=True)
                (prod/scope).mkdir(parents=True)
                (repo/'moodle'/scope/'same.php').write_text('same')
                (prod/scope/'same.php').write_text('same')
            (repo/'moodle/local/ustar/changed.php').write_text('old')
            (prod/'local/ustar/changed.php').write_text('new')
            (repo/'moodle/local/ustar/git.php').write_text('git')
            (prod/'local/ustar/prod.php').write_text('prod')
            g('add','.');g('commit','-qm','fixture')
            before={str(p):p.read_bytes() for p in prod.rglob('*') if p.is_file()}
            result=pm.audit(repo,prod,'HEAD',base/'report')
            self.assertEqual(result['comparison']['changed'],['local/ustar/changed.php'])
            self.assertEqual(result['comparison']['git_only'],['local/ustar/git.php'])
            self.assertEqual(result['comparison']['production_only'],['local/ustar/prod.php'])
            self.assertTrue(result['complete'])
            self.assertEqual(before,{str(p):p.read_bytes() for p in prod.rglob('*') if p.is_file()})
            (prod/'local/ustar/link.php').symlink_to(repo/'moodle/local/ustar/git.php')
            (prod/'local/ustar/config.php').write_text('DO_NOT_HASH')
            result=pm.audit(repo,prod,'HEAD',base/'report2')
            self.assertFalse(result['complete'])
            self.assertNotIn('local/ustar/config.php',result['production'])
            self.assertIn('local/ustar/link.php',result['errors'])


if __name__=='__main__':unittest.main()
