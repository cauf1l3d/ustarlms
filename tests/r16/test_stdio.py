"""Real SDK handshake in an isolated fixture; no production service/network."""
import asyncio
import json
from pathlib import Path
import shutil
import sys
import tempfile
import unittest
from mcp import ClientSession, StdioServerParameters
from mcp.client.stdio import stdio_client

ROOT = Path(__file__).resolve().parents[2]


class Stdio(unittest.TestCase):
    def test_local_protocol(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            (root/'context').mkdir()
            (root/'context/ok.md').write_text('ALLOWED_FIXTURE')
            (root/'outside.md').write_text('OUTSIDE_CANARY')
            (root/'context/link.md').symlink_to(root/'outside.md')
            (root/'context/roadmap').mkdir()
            (root/'context/tasks').mkdir()
            (root/'context/roadmap/STATE.yaml').write_text(
                'execution:\n  status: awaiting_owner_instruction\n'
                'audit:\n  document: context/ok.md\n')
            (root/'context/roadmap/BACKLOG.yaml').write_text('tasks: []\n')
            (root/'context/tasks/ACTIVE.md').write_text('STOP_AFTER_GITHUB')
            dest=root/'harness/ustar-contextd'
            dest.mkdir(parents=True)
            for name in ['server.py','safe_context.py']:
                shutil.copy2(ROOT/'harness/ustar-contextd'/name,dest/name)
            async def check():
                params=StdioServerParameters(command=sys.executable,args=[str(dest/'server.py')])
                async with stdio_client(params) as (read,write):
                    async with ClientSession(read,write) as session:
                        await session.initialize()
                        listing=await session.list_tools()
                        self.assertTrue({'get_context_file','get_current_handoff','get_audit_context'}
                                        <= {t.name for t in listing.tools})
                        handoff=await session.call_tool('get_current_handoff',{})
                        self.assertIn('awaiting_owner_instruction',str(handoff))
                        self.assertIn('STOP_AFTER_GITHUB',str(handoff))
                        audit=await session.call_tool('get_audit_context',{})
                        self.assertIn('ALLOWED_FIXTURE',str(audit))
                        good=await session.call_tool('get_context_file',{'path':'context/ok.md'})
                        self.assertIn('ALLOWED_FIXTURE',str(good))
                        for path in ['../outside.md','context/link.md',str(root/'outside.md')]:
                            bad=await session.call_tool('get_context_file',{'path':path})
                            self.assertNotIn('OUTSIDE_CANARY',str(bad))
                            self.assertIn('error',str(bad))
                        hits=await session.call_tool('search_context',{'query':'OUTSIDE_CANARY'})
                        self.assertNotIn('outside.md',str(hits))
            asyncio.run(asyncio.wait_for(check(),20))
