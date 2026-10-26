"""Isolated browser fixture. Never installed in the service wheel."""
from pathlib import Path
import subprocess
import tempfile

import uvicorn
from ustar_console.auth import Auth
from ustar_console.config import Settings
from ustar_console.server import create_app

with tempfile.TemporaryDirectory(prefix='ustar-console-browser-') as directory:
    root=Path(directory)
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
                    '-keyout',str(root/'key.pem'),'-out',str(root/'cert.pem'),'-subj','/CN=127.0.0.1'],
                   check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    settings=Settings('https://127.0.0.1:8173',root/'auth.sqlite3',root/'missing.json')
    auth=Auth(settings)
    auth.set_user('owner','browser-fixture-password','owner')
    uvicorn.run(create_app(settings),host='127.0.0.1',port=8173,
                ssl_certfile=str(root/'cert.pem'),ssl_keyfile=str(root/'key.pem'),access_log=False)
