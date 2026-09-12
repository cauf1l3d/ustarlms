import json
from pathlib import Path
import shutil
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'scripts'))
import refresh_context as refresh
import collect_runtime as runtime
from production_manifest import git


class Snapshot(unittest.TestCase):
    def test_preserves_dirty_context_and_separates_runtime(self):
        with tempfile.TemporaryDirectory() as tmp:
            base=Path(tmp);repo=base/'repo';repo.mkdir();prod=base/'prod'
            git(repo,'init','-q');git(repo,'config','user.name','Fixture');git(repo,'config','user.email','fixture@example.invalid')
            (repo/'scripts').mkdir();shutil.copy2(ROOT/'scripts/build_context_index.py',repo/'scripts/build_context_index.py')
            for scope in ['local/ustar','theme/ustar']:
                (repo/'moodle'/scope).mkdir(parents=True);(prod/scope).mkdir(parents=True)
                (repo/'moodle'/scope/'a.php').write_text('same');(prod/scope/'a.php').write_text('same')
            (repo/'context').mkdir();(repo/'context/existing.md').write_text('before')
            git(repo,'add','.');git(repo,'commit','-qm','fixture')
            (repo/'context/existing.md').write_text('uncommitted')
            diff=git(repo,'diff','--binary')
            values={'measured_at':'2026-09-12T00:00:00Z','git_source':{'commit':'fixture','branch':'fixture','dirty':True},
                    'production_runtime':{},'server':{},'errors':[], 'moodle_versions':{'host_root':str(prod),'php':'8.3.33'}}
            args=['refresh','--repo',str(repo),'--output-dir',str(base/'out'),'--commit','HEAD']
            with patch.object(refresh,'collect',return_value=values),patch.object(sys,'argv',args):
                self.assertEqual(refresh.main(),0)
            self.assertEqual(git(repo,'diff','--binary'),diff)
            self.assertEqual((base/'out/working.patch').read_bytes(),diff)
            snapshot=json.loads((base/'out/context/runtime/git_state.yaml').read_text())
            self.assertEqual(snapshot['kind'],'git_source')
            snapshot=json.loads((base/'out/context/runtime/moodle.yaml').read_text())
            self.assertEqual(snapshot['kind'],'production_runtime')
            self.assertEqual(snapshot['versions']['php'],'8.3.33')
            with patch.object(refresh,'collect',return_value=values),patch.object(sys,'argv',args):
                with self.assertRaises(FileExistsError):refresh.main()

    def test_failed_probe_is_explicit(self):
        def fake(args,input=None):
            if args[0]=='git':return 'fixture'
            raise RuntimeError('failed')
        with tempfile.TemporaryDirectory() as tmp,patch.object(runtime,'run',side_effect=fake):
            r=runtime.collect(Path(tmp),Path(tmp)/'out')
            self.assertEqual(len(r['errors']),2)
            self.assertNotIn('moodle_versions',r)
            self.assertTrue((Path(tmp)/'out/runtime.json').exists())
