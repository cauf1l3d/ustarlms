import runpy
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[2]
class KnowledgeSources(unittest.TestCase):
    def test_every_infrastructure_source_is_reviewed_without_adding_actions(self):
        catalog=runpy.run_path(str(ROOT/'server-console/tools/build_knowledge.py'))
        sources={p.stem if p.name!='server_facts.sh' else 'server_facts_sh'
                 for p in (ROOT/'scripts/infra').iterdir() if p.suffix in ('.py','.sh')}
        metadata=catalog['METADATA'];internal=catalog.get('SOURCE_ONLY',set())
        self.assertEqual(sources-(set(metadata)|internal),set())
        self.assertEqual(set(metadata)&internal,set())
        for name in ('academy_https','code_hardening','connect_telegram_update','postgres_app_handoff'):
            self.assertIsNone(metadata[name][-1])
