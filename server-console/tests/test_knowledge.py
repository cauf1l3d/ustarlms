import json
from pathlib import Path
import pytest


def test_knowledge_requires_login_and_returns_versioned_catalog(client,logged):
    # logged fixture logs in the shared client; explicitly clear to test boundary.
    client.cookies.clear()
    assert client.get('/api/knowledge').status_code==401
    client.post('/api/login',json={'username':'owner','password':'test-only-password-12345'})
    result=client.get('/api/knowledge')
    assert result.status_code==200
    value=result.json()
    assert len(value['source_commit'])==40 and len(value['scripts'])>=25 and len(value['documents'])>=20
    assert any(r['id']=='backup-transfer' for r in value['scripts'])
    assert all(r.get('action') in (None,'manual_backup','academy_restart','lab_start','lab_stop') for r in value['scripts'])
    assert all('content' in r and len(r['sha256'])==64 for r in value['documents'])


def test_installed_inventory_does_not_follow_symlinks_or_export_source(tmp_path):
    from ustar_console.knowledge import installed_inventory
    good=tmp_path/'USTAR_TEST.py';good.write_text('SECRET_NOT_FOR_EXPORT');good.chmod(0o600)
    (tmp_path/'USTAR_LINK.py').symlink_to(good)
    import os
    value=installed_inventory(roots=[tmp_path],owner=None)
    assert len(value['scripts'])==2
    assert next(r for r in value['scripts'] if r['server_path'].endswith('USTAR_TEST.py'))['status']=='present'
    assert next(r for r in value['scripts'] if r['server_path'].endswith('USTAR_LINK.py'))['status']=='unavailable'
    assert 'SECRET_NOT_FOR_EXPORT' not in json.dumps(value)


def test_catalog_never_accepts_client_filesystem_path(logged):
    assert logged.get('/api/knowledge/../../etc/shadow').status_code==404
