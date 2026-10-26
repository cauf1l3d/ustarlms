import importlib.util
from pathlib import Path
import hashlib
import ssl
import subprocess


def test_public_ca_repair_preserves_authority_and_rejects_symlink(tmp_path):
    spec=importlib.util.spec_from_file_location('console_access',Path(__file__).parents[1]/'tools/console_access.py')
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    ca=tmp_path/'ca.crt'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-keyout',str(tmp_path/'key'),'-out',str(ca),'-subj','/CN=Fixture CA'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    original=ca.read_bytes();target=tmp_path/'public.crt';der=tmp_path/'public.cer'
    module.export_public(ca,target,der)
    assert target.read_bytes()==original and ca.read_bytes()==original
    assert der.read_bytes()==ssl.PEM_cert_to_DER_cert(original.decode())
    target.unlink();target.symlink_to(ca)
    import pytest
    with pytest.raises(ValueError):module.export_public(ca,target,der)


def test_reusing_existing_authority_repairs_missing_public_copy(tmp_path,monkeypatch):
    import os,pytest
    if os.geteuid()!=0:pytest.skip('Root ownership path; actual PID1 fixture covers native setup')
    from ustar_console import setup
    monkeypatch.setattr(setup,'CONFIG',tmp_path)
    (tmp_path/'authority').mkdir(mode=0o700);(tmp_path/'tls').mkdir(mode=0o750)
    for path in ('authority/ca.crt','authority/ca.key','tls/server.crt','tls/server.key'):
        target=tmp_path/path;target.write_text('fixture-only-'+path);target.chmod(0o600)
    calls=[];monkeypatch.setattr(setup,'run',lambda argv,**kwargs:calls.append(argv))
    setup.certificate('192.168.1.60',0)
    assert (tmp_path/'ustar-console-ca.crt').read_bytes()==(tmp_path/'authority/ca.crt').read_bytes()
    assert len(calls)==1 and calls[0][1]=='verify'
