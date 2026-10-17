"""Run on ANOTHER LAN computer: python3.12 observer.pyz --configure, then observer.pyz.

Independent disk/outbox and outbound Telegram path survive loss of server1.
No commands, Academy accounts, keys or backup files are used by this observer.
"""
import argparse
import getpass
import json
import os
from pathlib import Path
import socket
import ssl
import time
from urllib.parse import urlsplit
import urllib.error
import urllib.request

from .alerts import Queue,Telegram,DeliveryError
from .collector import NoRedirect
from .data import stamp


def probe(url,ca=None,method='HEAD'):
    context=ssl.create_default_context(cafile=str(ca)) if ca else ssl.create_default_context()
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect(),
                                      urllib.request.HTTPSHandler(context=context))
    started=time.monotonic()
    try:
        with opener.open(urllib.request.Request(url,method=method),timeout=5) as response:
            code=response.status
        return {'status':'ok' if code==200 else 'critical','code':code,'ms':round((time.monotonic()-started)*1000,1)}
    except urllib.error.HTTPError as error:
        code=error.code;error.close()
        return {'status':'critical','code':code,'ms':None}
    except (OSError,ValueError,urllib.error.URLError):
        return {'status':'critical','code':None,'ms':None}


def validate(value):
    console=urlsplit(value['console']);academy=urlsplit(value['academy'])
    if console.scheme!='https' or not console.hostname or console.path or console.username or console.password or console.query or console.fragment:
        raise ValueError('Console HTTPS origin required')
    if academy.scheme not in ('http','https') or not academy.hostname or academy.username or academy.password or academy.query or academy.fragment:
        raise ValueError('Academy URL required')
    # Refuse an observer bound to the very same address as the observed console.
    with socket.socket() as local:
        try: local.bind((console.hostname,0))
        except OSError: pass
        else: raise ValueError('Run the observer on another LAN computer')
    if not Path(value['ca']).is_file(): raise ValueError('Trusted console CA file required')
    ids=value['ids']
    if not isinstance(ids,list) or not ids or len(ids)>10 or any(type(n) is not int or not 0<n<2**52 for n in ids):
        raise ValueError('Numeric private chat IDs required')
    Telegram(value['token'])


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--configure',action='store_true')
    parser.add_argument('--directory',type=Path,default=Path.home()/'ustar-observer')
    args=parser.parse_args()
    os.umask(0o077)
    args.directory.mkdir(mode=0o700,parents=True,exist_ok=True)
    config=args.directory/'config.json'
    if args.configure:
        value=dict(console=input('Console HTTPS origin: ').rstrip('/'),
            academy=input('Academy login URL in this LAN: '),ca=str(Path(input('Local console CA .crt path: ')).resolve()),
            token=getpass.getpass('Dedicated observer bot token (different from server bot): '),
            ids=[int(n) for n in input('Private numeric user/chat IDs, comma separated: ').split(',')])
        validate(value)
        if config.exists(): raise SystemExit('Config already exists; edit locally rather than overwriting a token.')
        config.write_text(json.dumps(value,indent=2));os.chmod(config,0o600)
        print('Configured locally. Start the observer on this computer and send /start to its dedicated bot.')
        return
    if config.is_symlink() or (os.name!='nt' and config.stat().st_mode&0o077):
        raise SystemExit('Observer config must be private to this OS account.')
    value=json.loads(config.read_text());validate(value)
    api=Telegram(value['token'])
    if api('getWebhookInfo',{}).get('url'): raise SystemExit('Use a dedicated bot without a webhook')
    queue=Queue(args.directory/'observer.sqlite3',value['ids'])
    while True:
        results={'observer':probe(value['console']+'/healthz',value['ca'],method='GET'),
                 'http_external':probe(value['academy'])}
        checks=[dict(id=key,label='Пульт/server1 из независимой точки' if key=='observer' else 'Академия из независимой точки',
                     status=row['status'],reason_code='independent_probe',observed_at=stamp()) for key,row in results.items()]
        queue.evaluate(dict(checks=checks,host={},containers=[],stale=False,scope='independent_observer'))
        try: queue.deliver(api)
        except DeliveryError: pass
        receipt=dict(observed_at=stamp(),results=results,outbox=queue.status())
        (args.directory/'last-observation.json').write_text(json.dumps(receipt))
        time.sleep(30)
