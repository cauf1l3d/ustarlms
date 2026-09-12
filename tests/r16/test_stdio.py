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
                        self.assertIn('get_context_file',{t.name for t in listing.tools})
                        good=await session.call_tool('get_context_file',{'path':'context/ok.md'})
                        self.assertIn('ALLOWED_FIXTURE',str(good))
                        for path in ['../outside.md','context/link.md',str(root/'outside.md')]:
                            bad=await session.call_tool('get_context_file',{'path':path})
                            self.assertNotIn('OUTSIDE_CANARY',str(bad))
                            self.assertIn('error',str(bad))
                        hits=await session.call_tool('search_context',{'query':'OUTSIDE_CANARY'})
                        self.assertNotIn('outside.md',str(hits))
            asyncio.run(asyncio.wait_for(check(),20))
