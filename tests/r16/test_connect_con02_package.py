"""Real deterministic plain.py, offline default and hostile inventory denial."""
import ast
import contextlib
import copy
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT/'scripts/infra'))
import connect_con02_contract as c
import build_connect_con02_update as builder


class Package(unittest.TestCase):
    def module(self):
        self.assertIsNotNone(importlib.util.find_spec('connect_con02_update'), 'strict plain-file CLI')
        import connect_con02_update as cli
        self.assertTrue(hasattr(builder, 'build'), 'plain-file builder')
        return cli

    def package(self):
        self.module()
        cls = self.__class__
        if not hasattr(cls, 'artifact'):
            cls.tmp = tempfile.TemporaryDirectory(); cls.addClassCleanup(cls.tmp.cleanup)
            cls.output = Path(cls.tmp.name)
            # An exact local Git snapshot of only these tool dependencies. The
            # main index/HEAD and unrelated files are never changed by this test.
            env = {**os.environ, 'GIT_INDEX_FILE': str(cls.output/'index'),
                   'GIT_AUTHOR_NAME': 'CON02 fixture', 'GIT_AUTHOR_EMAIL': 'fixture@example.invalid',
                   'GIT_COMMITTER_NAME': 'CON02 fixture', 'GIT_COMMITTER_EMAIL': 'fixture@example.invalid'}
            def git(*args): return subprocess.check_output(['git','-C',str(ROOT),*args], env=env, stderr=subprocess.DEVNULL).decode().strip()
            git('read-tree','HEAD')
            git('add', 'scripts/infra/build_connect_con02_update.py', *['scripts/infra/'+n+'.py' for n in builder.MODULES if n != 'production_manifest'])
            cls.commit = git('commit-tree', git('write-tree'), '-p', 'HEAD', '-m', 'CON02 package test snapshot')
            with contextlib.redirect_stdout(io.StringIO()): cls.artifact = builder.build(cls.commit, cls.output/'one')
        return cls.artifact

    def envelope(self):
        tree = ast.parse(self.package().read_text())
        constants = {node.targets[0].id: ast.literal_eval(node.value) for node in tree.body
                     if isinstance(node, ast.Assign) and isinstance(node.targets[0], ast.Name)
                     and node.targets[0].id in ('MANIFEST_JSON', 'SOURCES_JSON', 'ASSETS_JSON')}
        return tuple(json.loads(constants[k]) for k in ('MANIFEST_JSON', 'SOURCES_JSON', 'ASSETS_JSON'))

    def test_default_no_root_no_host_no_network(self):
        p = subprocess.run([sys.executable,'-I','-B',str(self.package())], capture_output=True,text=True,timeout=30)
        self.assertEqual(0,p.returncode,p.stderr)
        self.assertEqual({'phase':'PLAN_ONLY','host_access':False,'network_used':False},json.loads(p.stdout))

    def test_exact_commits_and_reproducible_plain_bytes(self):
        first = self.package()
        with contextlib.redirect_stdout(io.StringIO()): second = builder.build(self.commit,self.output/'two')
        self.assertEqual(first.read_bytes(),second.read_bytes()); self.assertEqual('.py',first.suffix)
        manifest, sources, assets = self.envelope()
        bundle = self.module().load_embedded(manifest,sources,assets)
        self.assertEqual(self.commit,bundle.reference['tool_commit'])
        self.assertEqual(c.APP_COMMIT,bundle.reference['app_commit'])
        self.assertEqual(557,len(bundle.payload))

    def test_tamper_and_inventory_denial(self):
        m, sources, assets = self.envelope(); cli = self.module()
        for changed in ({**assets,'../escape':'YQ=='}, {k:v for k,v in assets.items() if k!='worker.tar.gz'},
                        {**assets,'worker.tar.gz':assets['worker.tar.gz']+'\n'}):
            with self.assertRaises(c.Refusal): cli.load_embedded(m,sources,changed)
        bad = dict(sources); bad['connect_con02_contract.py'] += '\n# changed\n'
        with self.assertRaises(c.Refusal): cli.load_embedded(m,bad,assets)
        bad_m = copy.deepcopy(m); bad_m['app_commit']='a'*40
        with self.assertRaises(c.Refusal): cli.load_embedded(bad_m,sources,assets)

    def test_no_adjacent_module_import(self):
        first = self.package()
        poison = first.parent/'connect_con02_contract.py'; poison.write_text('raise RuntimeError("SIBLING_IMPORTED")\n')
        p = subprocess.run([sys.executable,'-B',str(first)],cwd=first.parent,capture_output=True,text=True,timeout=30)
        self.assertEqual(0,p.returncode,p.stderr);self.assertNotIn('SIBLING_IMPORTED',p.stdout+p.stderr)

    def test_invalid_combined_modes_before_host(self):
        cli = self.module()
        with patch.object(cli,'make_context',side_effect=AssertionError('host touched')):
            for args in (['--upgrade'],['--upgrade','--enable-ack','--acknowledge-outage'],
                         ['--check','--acknowledge-outage'],['--enable-ack','--discard-writes-since-snapshot'],
                         ['--root','/tmp/fixture']):
                with self.subTest(args=args), contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit) as e:
                    cli.main(args)
                self.assertEqual(2,e.exception.code)

    def test_recover_and_restore_flags_respect_public_boundary(self):
        cli = self.module()
        with patch.object(cli,'make_context',side_effect=AssertionError('host touched')):
            with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit): cli.main(['--restore'])
            with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit): cli.main(['--recover','--discard-writes-since-snapshot'])

    def test_dirty_builder_dependencies_refused(self):
        self.module()
        with tempfile.TemporaryDirectory() as td, self.assertRaises(c.Refusal):
            builder.build('7f833626f687a8baa8e98efaba7e4d3293b1b47a',Path(td))


if __name__=='__main__':unittest.main()
