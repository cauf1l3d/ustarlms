import importlib
import importlib.util

from fastapi.testclient import TestClient
import pytest
from conftest import PASSWORD


def api(monkeypatch):
    assert importlib.util.find_spec('ustar_console.account_api'), 'Personal Telegram API missing'
    module=importlib.import_module('ustar_console.account_api')
    calls=[]
    def rpc(method,**kwargs):
        calls.append((method,kwargs))
        return dict(linked=False,preferences={},catalog=[],transport_status='ok') if method=='get_account' else dict(status='ok',code='test-only',expires_at=100)
    monkeypatch.setattr(module,'rpc',rpc)
    return module,calls


def test_account_requires_session_and_csrf(client,monkeypatch):
    _,calls=api(monkeypatch)
    assert client.get('/api/account/telegram').status_code==401
    assert client.post('/api/account/telegram/link',json=dict(password=PASSWORD)).status_code==401
    client.post('/api/login',json=dict(username='owner',password=PASSWORD))
    assert client.post('/api/account/telegram/link',json=dict(password=PASSWORD)).status_code==403
    assert calls==[]


def test_account_is_bound_to_current_identity_and_reauth(logged,monkeypatch):
    _,calls=api(monkeypatch)
    assert logged.get('/api/account/telegram').json()['linked'] is False
    assert calls[-1]==('get_account',dict(username='owner'))
    assert logged.post('/api/account/telegram/link',json=dict(password='wrong')).status_code==403
    assert len(calls)==1
    assert logged.post('/api/account/telegram/link',json=dict(password=PASSWORD)).status_code==200
    assert calls[-1]==('create_link',dict(username='owner'))
    assert logged.post('/api/account/telegram/link',json=dict(password=PASSWORD,username='other')).status_code==422
    assert logged.post('/api/account/telegram/unlink',json=dict(password=PASSWORD)).status_code==200
    assert calls[-1]==('unlink',dict(username='owner'))


def test_preferences_reauth_schema_and_notifier_failure(logged,monkeypatch):
    module,calls=api(monkeypatch)
    preferences=dict(enabled=False)
    result=logged.put('/api/account/notifications',json=dict(password=PASSWORD,preferences=preferences))
    assert result.status_code==200
    assert calls[-1]==('set_preferences',dict(username='owner',preferences=preferences))
    def failing(*args,**kwargs):raise OSError('private/internal/path TOKEN')
    monkeypatch.setattr(module,'rpc',failing)
    result=logged.get('/api/account/telegram')
    assert result.status_code==503 and 'TOKEN' not in result.text


def test_viewer_can_link_only_their_own_account(client,app,monkeypatch):
    _,calls=api(monkeypatch)
    app.state.auth.set_user('viewer',PASSWORD,'viewer')
    client.post('/api/login',json=dict(username='viewer',password=PASSWORD))
    client.headers['x-csrf-token']=client.get('/api/session').json()['csrf']
    assert client.post('/api/account/telegram/link',json=dict(password=PASSWORD)).status_code==200
    assert calls[-1][1]==dict(username='viewer')
    assert client.get('/api/admin/users').status_code==403


def test_inflight_link_cannot_follow_revoked_session(logged,app,monkeypatch):
    _,calls=api(monkeypatch)
    auth=app.state.auth;original=auth.authorize
    def revoked_after_reauth(session,password,otp=''):
        original(session,password,otp)
        with auth.connection() as db:db.execute('DELETE FROM sessions WHERE username=?',(session['username'],))
    monkeypatch.setattr(auth,'authorize',revoked_after_reauth)
    result=logged.post('/api/account/telegram/link',json=dict(password=PASSWORD))
    assert result.status_code==401
    assert not calls


def test_configured_notifier_failure_prevents_user_disable(logged,app,tmp_path,monkeypatch):
    module,_=api(monkeypatch)
    credential=tmp_path/'configured.json';credential.write_text('secret-not-read')
    monkeypatch.setattr(module,'TELEGRAM_CREDENTIAL',credential)
    app.state.auth.set_user('target',PASSWORD,'viewer')
    def unavailable(*args,**kwargs):raise OSError('internal secret error')
    monkeypatch.setattr(module,'rpc',unavailable)
    result=logged.patch('/api/admin/users/target',json=dict(enabled=False,password=PASSWORD))
    assert result.status_code==503 and 'secret' not in result.text
    with app.state.auth.connection() as db:
        assert db.execute("SELECT enabled FROM users WHERE username='target'").fetchone()[0]==1
