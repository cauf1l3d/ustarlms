#!/usr/bin/env python3
"""Read-only console TLS diagnosis; optional public CA copy repair, never root rotation."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import socket
import ssl
import subprocess
import tempfile


def export_public(authority,target,der_target):
    pem=authority.read_bytes()
    der=ssl.PEM_cert_to_DER_cert(pem.decode('ascii'))
    # Never overwrite an untrusted target or follow symlinks, including public files.
    for path in (target,der_target):
        if path.is_symlink() or (path.exists() and (not path.is_file() or path.stat().st_mode&0o022)):
            raise ValueError('Untrusted public certificate target')
    for path,payload in ((target,pem),(der_target,der)):
        descriptor,temporary=tempfile.mkstemp(prefix='.public-ca-',dir=path.parent)
        try:
            with os.fdopen(descriptor,'wb') as stream:
                os.fchmod(stream.fileno(),0o644);stream.write(payload);stream.flush();os.fsync(stream.fileno())
            os.replace(temporary,path)
        finally:
            if os.path.exists(temporary):os.unlink(temporary)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repair-public-ca',action='store_true')
    args=parser.parse_args()
    if os.geteuid()!=0:raise SystemExit('Run with sudo')
    from ustar_console.config import load
    from ustar_console.data import open_regular
    settings=load();base=Path('/etc/ustar-console')
    ca=base/'authority/ca.crt';leaf=base/'tls/server.crt'
    for path in (ca,leaf):
        descriptor,_=open_regular(path,owner=0,limit=65536);os.close(descriptor)
    verified=subprocess.run(['openssl','verify','-CAfile',str(ca),'-verify_ip',settings.listen,str(leaf)],
        capture_output=True,timeout=5).returncode==0
    der=ssl.PEM_cert_to_DER_cert(ca.read_text())
    expected=ssl.PEM_cert_to_DER_cert(leaf.read_text())
    value=dict(origin=settings.origin,chain_and_IP_SAN='pass' if verified else 'fail',
        ca_sha256=hashlib.sha256(der).hexdigest(),client_trust='requires_import_on_each_client',
        phone_route='not_measured',mini_app='not_accepted')
    if args.repair_public_ca:
        if not verified:raise SystemExit('Certificate chain/SAN/expiry failed; CA unchanged')
        export_public(ca,base/'ustar-console-ca.crt',base/'ustar-console-ca.cer')
        value['public_CA_export']='repaired_without_changing_authority'
    try:
        context=ssl.create_default_context(cafile=str(ca));context.minimum_version=ssl.TLSVersion.TLSv1_2
        with socket.create_connection((settings.listen,settings.port),timeout=5) as raw:
            with context.wrap_socket(raw,server_hostname=settings.listen) as connection:
                actual=connection.getpeercert(binary_form=True)
                value['live_certificate']='matches_verified_leaf' if actual==expected else 'unexpected_leaf'
    except (OSError,ValueError,ssl.SSLError):
        value['live_certificate']='verification_failed'
    print(json.dumps(value,ensure_ascii=False,indent=2))
    if not verified or value['live_certificate']!='matches_verified_leaf':raise SystemExit(1)


if __name__=='__main__':main()
