import os
import sqlite3

from fastapi.testclient import TestClient
import pytest

from conftest import PASSWORD
from ustar_console.auth import Auth, Denied
from ustar_console.server import COOKIE, create_app


@pytest.mark.parametrize('path',['overview','session','events','history?metric=cpu'])
def test_data_needs_auth(client,path):
    assert client.get('/api/'+path).status_code == 401


def test_secure_opaque_cookie(client,settings):
    reply=client.post('/api/login',json=dict(username='owner',password=PASSWORD))
    cookie=reply.headers['set-cookie']
    assert all(text in cookie for text in ('Secure','HttpOnly','SameSite=strict','Path=/'))
    token=client.cookies.get(COOKIE)
    assert token and len(token)>40
    assert token.encode() not in settings.database.read_bytes()
    assert PASSWORD.encode() not in settings.database.read_bytes()
    assert os.stat(settings.database).st_mode & 0o777 == 0o600


@pytest.mark.parametrize('origin',[None,'https://evil.test','http://console.test','null'])
def test_origin_required(client,origin):
    client.headers.pop('origin')
    response=client.post('/api/login',json=dict(username='owner',password=PASSWORD),headers={'Origin':origin} if origin else {})
    assert response.status_code == 403


@pytest.mark.parametrize('path',['logout','activity','password'])
def test_csrf_required(logged,path):
    logged.headers.pop('X-CSRF-Token')
    body=dict(current=PASSWORD,replacement=PASSWORD+'new') if path=='password' else {}
    assert logged.post('/api/'+path,json=body).status_code == 403


def test_logout_invalidates_session(logged):
    token=logged.cookies.get(COOKIE)
    assert logged.post('/api/logout',json={}).status_code == 200
    logged.cookies.set(COOKIE,token,domain='console.test',path='/')
    assert logged.get('/api/overview').status_code == 401


def test_password_revokes_all_sessions(logged,app):
    second=TestClient(app,base_url='https://console.test',headers={'Origin':'https://console.test'})
    assert second.post('/api/login',json=dict(username='owner',password=PASSWORD)).status_code == 200
    assert logged.post('/api/password',json=dict(current=PASSWORD,replacement=PASSWORD+'next')).status_code == 200
    assert second.get('/api/session').status_code == 401
    assert logged.get('/api/session').status_code == 401
    assert second.post('/api/login',json=dict(username='owner',password=PASSWORD+'next')).status_code == 200


def test_wrong_current_password_does_not_change(logged):
    assert logged.post('/api/password',json=dict(current='incorrect',replacement=PASSWORD+'next')).status_code == 403
    assert logged.get('/api/session').status_code == 200


def test_reauth_is_rate_limited(logged):
    for _ in range(4):
        assert logged.post('/api/password',json=dict(current='wrong',replacement=PASSWORD+'new')).status_code==403
    assert logged.post('/api/password',json=dict(current=PASSWORD,replacement=PASSWORD+'new')).status_code==429


def test_cli_reset_revokes(logged,app):
    app.state.auth.set_user('owner',PASSWORD+'reset','viewer')
    assert logged.get('/api/session').status_code == 401


def test_backoff_persists_across_app_restart(client,settings):
    for _ in range(4):
        assert client.post('/api/login',json=dict(username='owner',password='wrong')).status_code == 401
    restarted=TestClient(create_app(settings),base_url='https://console.test',headers={'Origin':'https://console.test'})
    reply=restarted.post('/api/login',json=dict(username='owner',password=PASSWORD))
    assert reply.status_code == 429 and int(reply.headers['Retry-After']) > 0


def test_idle_not_extended_by_polling(logged,app):
    auth=app.state.auth
    now=auth.clock()
    auth.clock=lambda:now+1799
    assert logged.get('/api/session').status_code == 200
    auth.clock=lambda:now+1801
    assert logged.get('/api/session').status_code == 401


def test_activity_extends_idle_but_not_absolute(logged,app):
    auth=app.state.auth
    now=auth.clock()
    auth.clock=lambda:now+1700
    assert logged.post('/api/activity',json={}).status_code == 200
    auth.clock=lambda:now+1900
    assert logged.get('/api/session').status_code == 200
    auth.clock=lambda:now+43201
    assert logged.get('/api/session').status_code == 401


def test_viewer_has_no_host_operations(client,app):
    app.state.auth.set_user('viewer',PASSWORD,'viewer')
    assert client.post('/api/login',json=dict(username='viewer',password=PASSWORD)).status_code == 200
    assert client.get('/api/overview').status_code == 200
    for path in ('backup','restart','restore','exec','users'):
        assert client.post('/api/'+path,json={}).status_code == 404


def test_body_and_schema_limits(client):
    assert client.post('/api/login',content='a'*4097,headers={'Content-Type':'application/json'}).status_code == 413
    assert client.post('/api/login',data={'username':'owner','password':PASSWORD}).status_code == 415
    assert client.post('/api/login',json=dict(username='owner',password=PASSWORD,role='owner')).status_code == 422
    assert client.post('/api/login',json=dict(username='owner',password='a'*257)).status_code == 422
    assert 'a'*257 not in client.post('/api/login',json=dict(username='owner',password='a'*257)).text


def test_security_headers_and_host_guard(client):
    reply=client.get('/healthz')
    assert reply.headers['Cache-Control']=='no-store'
    assert "frame-ancestors 'none'" in reply.headers['Content-Security-Policy']
    assert reply.headers['X-Content-Type-Options']=='nosniff'
    assert client.get('/healthz',headers={'Host':'evil.test'}).status_code == 400


def test_password_hash_argon2id(settings):
    auth=Auth(settings)
    auth.set_user('owner',PASSWORD,'owner')
    with auth.connection() as db:
        assert db.execute('SELECT password FROM users').fetchone()[0].startswith('$argon2id$')


def test_database_symlink_rejected(settings,tmp_path):
    target=tmp_path/'target'
    target.write_text('sentinel')
    settings.database.symlink_to(target)
    with pytest.raises(OSError):
        Auth(settings)
    assert target.read_text()=='sentinel'
