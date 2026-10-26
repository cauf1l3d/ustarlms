from concurrent.futures import ThreadPoolExecutor
import sqlite3
from threading import Barrier

import pytest

from conftest import PASSWORD
from ustar_console.auth import Auth, Denied, HASHER, digest


def owner_session(auth, username='owner'):
    return auth.session(auth.login(username, PASSWORD, 'test-peer'))


def test_migration_preserves_legacy_three_value_user_upserts(settings):
    auth = Auth(settings)
    encoded = HASHER.hash(PASSWORD)
    # Installed 1c881495 CLI writes all three values without naming columns.
    legacy_upsert = ('INSERT INTO users VALUES(?,?,?) ON CONFLICT(username) DO UPDATE '
                     'SET password=excluded.password, role=excluded.role')
    with auth.connection() as db:
        db.execute(legacy_upsert, ('legacy', encoded, 'viewer'))
        db.execute(legacy_upsert, ('legacy', encoded, 'owner'))
    actor = owner_session(auth, 'legacy')
    assert actor['role'] == 'owner'
    assert auth.list_users(actor) == [dict(
        username='legacy', role='owner', enabled=True, totp=False, sessions=1)]


def test_four_column_prerelease_migration_preserves_disabled_account(settings):
    encoded = HASHER.hash(PASSWORD)
    stored_token = digest('existing-session')
    with sqlite3.connect(settings.database) as db:
        db.executescript('''
            CREATE TABLE users(username TEXT PRIMARY KEY,password TEXT NOT NULL,role TEXT NOT NULL,
                               enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1)));
            CREATE TABLE sessions(token TEXT PRIMARY KEY,username TEXT NOT NULL,csrf TEXT NOT NULL,
                                  created REAL NOT NULL,seen REAL NOT NULL);
            CREATE TABLE factors(username TEXT PRIMARY KEY,secret TEXT NOT NULL,counter INTEGER NOT NULL);
        ''')
        db.execute('INSERT INTO users VALUES(?,?,?,?)', ('disabled', encoded, 'owner', 0))
        db.execute('INSERT INTO sessions VALUES(?,?,?,?,?)', (stored_token, 'disabled', 'csrf', 10, 10))
        db.execute('INSERT INTO factors VALUES(?,?,?)', ('disabled', 'kept-secret', 1))
    auth = Auth(settings, clock=lambda: 10)
    with auth.connection() as db:
        assert dict(db.execute('SELECT * FROM users').fetchone()) == dict(
            username='disabled', password=encoded, role='owner')
        assert db.execute('SELECT enabled FROM account_state WHERE username=?', ('disabled',)).fetchone()[0] == 0
        assert tuple(db.execute('SELECT * FROM sessions').fetchone()) == (
            stored_token, 'disabled', 'csrf', 10, 10)
        assert tuple(db.execute('SELECT * FROM factors').fetchone()) == ('disabled', 'kept-secret', 1)
        with pytest.raises(Denied):
            auth.active_session(db, dict(username='disabled', token=stored_token))
    with pytest.raises(Denied):
        auth.session('existing-session')
    with pytest.raises(Denied):
        auth.login('disabled', PASSWORD, 'test-peer')
    Auth(settings)  # Converted databases are repeatable and keep their disabled state.
    with auth.connection() as db:
        assert db.execute('SELECT enabled FROM account_state').fetchone()[0] == 0


def test_migration_keeps_existing_users_sessions_and_totp(settings):
    encoded = HASHER.hash(PASSWORD)
    with sqlite3.connect(settings.database) as db:
        db.executescript('''
            CREATE TABLE users(username TEXT PRIMARY KEY,password TEXT NOT NULL,role TEXT NOT NULL);
            CREATE TABLE sessions(token TEXT PRIMARY KEY,username TEXT NOT NULL,csrf TEXT NOT NULL,
                                  created REAL NOT NULL,seen REAL NOT NULL);
            CREATE TABLE factors(username TEXT PRIMARY KEY,secret TEXT NOT NULL,counter INTEGER NOT NULL);
        ''')
        db.execute('INSERT INTO users VALUES(?,?,?)', ('owner', encoded, 'owner'))
        db.execute('INSERT INTO sessions VALUES(?,?,?,?,?)', ('existing-session', 'owner', 'csrf', 10, 10))
        db.execute('INSERT INTO factors VALUES(?,?,?)', ('owner', 'kept-secret', 1))
    auth = Auth(settings)
    with auth.connection() as db:
        user = dict(db.execute('SELECT * FROM users').fetchone())
        assert user == dict(username='owner', password=encoded, role='owner')
        assert db.execute('SELECT token FROM sessions').fetchone()[0] == 'existing-session'
        assert db.execute('SELECT secret FROM factors').fetchone()[0] == 'kept-secret'
    Auth(settings)  # Migration is repeatable.


def test_owner_list_only_exposes_public_fields_and_active_session_counts(app):
    auth = app.state.auth
    owner = owner_session(auth)
    auth.set_user('viewer', PASSWORD, 'viewer')
    auth.login('viewer', PASSWORD, 'test-peer')
    with auth.connection() as db:
        db.execute('INSERT INTO factors VALUES(?,?,?)', ('viewer', 'private-secret', 3))
        db.execute('INSERT INTO sessions VALUES(?,?,?,?,?)', ('expired', 'viewer', 'old', 0, 0))
    assert auth.list_users(owner) == [
        dict(username='owner', role='owner', enabled=True, totp=False, sessions=1),
        dict(username='viewer', role='viewer', enabled=True, totp=True, sessions=1),
    ]
    with pytest.raises(Denied):
        auth.list_users(owner_session(auth, 'viewer'))


def test_create_collision_never_resets_existing_account(app):
    auth = app.state.auth
    owner = owner_session(auth)
    auth.create_user(owner, 'viewer', PASSWORD, 'viewer')
    viewer = owner_session(auth, 'viewer')
    with pytest.raises(ValueError, match='exists'):
        auth.create_user(owner, 'viewer', PASSWORD + 'changed', 'owner')
    assert auth.session(auth.login('viewer', PASSWORD, 'test-peer'))['role'] == 'viewer'
    with auth.connection() as db:
        assert db.execute('SELECT 1 FROM sessions WHERE token=?', (viewer['token'],)).fetchone()


@pytest.mark.parametrize('change', [dict(role='operator'), dict(enabled=False), dict(new_password=PASSWORD + 'new')])
def test_access_changes_revoke_all_sessions_before_mutation(app, change):
    auth = app.state.auth
    owner = owner_session(auth)
    auth.create_user(owner, 'target', PASSWORD, 'viewer')
    first = auth.login('target', PASSWORD, 'test-peer')
    second = auth.login('target', PASSWORD, 'test-peer')
    seen = []

    def before_change(username):
        # Notifier revocation sees the old identity and sessions.
        seen.append((username, auth.session(first)['role']))

    result = auth.update_user(owner, 'target', before_change=before_change, **change)
    assert seen == [('target', 'viewer')]
    assert set(result) == {'username', 'role', 'enabled', 'totp', 'sessions'}
    assert result['sessions'] == 0
    for token in (first, second):
        with pytest.raises(Denied):
            auth.session(token)
    password = change.get('new_password', PASSWORD)
    if change.get('enabled') is False:
        with pytest.raises(Denied):
            auth.login('target', password, 'test-peer')
    else:
        assert auth.session(auth.login('target', password, 'test-peer'))['role'] == change.get('role', 'viewer')


def test_failed_notifier_revocation_leaves_access_unchanged(app):
    auth = app.state.auth
    owner = owner_session(auth)
    auth.create_user(owner, 'target', PASSWORD, 'viewer')
    token = auth.login('target', PASSWORD, 'test-peer')

    def unavailable(username):
        raise OSError('notifier unavailable')

    with pytest.raises(OSError):
        auth.update_user(owner, 'target', enabled=False, before_change=unavailable)
    assert auth.session(token)['username'] == 'target'


def test_disabled_users_cannot_login_or_use_preexisting_sessions(app):
    auth = app.state.auth
    auth.set_user('target', PASSWORD, 'viewer')
    token = auth.login('target', PASSWORD, 'test-peer')
    stale = auth.session(token)
    with auth.connection() as db:
        db.execute('UPDATE account_state SET enabled=0 WHERE username=?', ('target',))
    with pytest.raises(Denied):
        auth.session(token)
    with pytest.raises(Denied):
        auth.login('target', PASSWORD, 'test-peer')
    with pytest.raises(Denied):
        auth.authorize(stale, PASSWORD)
    with pytest.raises(Denied):
        auth.password(stale, PASSWORD, PASSWORD + 'changed')


def test_last_active_owner_and_self_demotion_are_rejected_without_hook(app):
    auth = app.state.auth
    owner = owner_session(auth)
    calls = []
    with pytest.raises(ValueError):
        auth.update_user(owner, 'owner', enabled=False, before_change=calls.append)
    auth.create_user(owner, 'other', PASSWORD, 'owner')
    with pytest.raises(ValueError):
        auth.update_user(owner, 'owner', role='viewer', before_change=calls.append)
    assert not calls
    assert len([u for u in auth.list_users(owner) if u['enabled'] and u['role'] == 'owner']) == 2


def test_concurrent_owner_disable_keeps_an_active_owner(app):
    auth = app.state.auth
    first = owner_session(auth)
    auth.create_user(first, 'other', PASSWORD, 'owner')
    second = owner_session(auth, 'other')
    barrier = Barrier(2)

    def disable(actor, target):
        barrier.wait()
        try:
            auth.update_user(actor, target, enabled=False)
            return 'updated'
        except (Denied, ValueError):
            return 'rejected'

    with ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda pair: disable(*pair), [(first, 'other'), (second, 'owner')]))
    assert sorted(results) == ['rejected', 'updated']
    with auth.connection() as db:
        assert db.execute("SELECT count(*) FROM users u LEFT JOIN account_state a USING(username) "
                          "WHERE role='owner' AND COALESCE(a.enabled,1)=1").fetchone()[0] == 1


def test_cli_set_user_can_reset_and_reenable_migrated_account(app):
    auth = app.state.auth
    owner = owner_session(auth)
    auth.create_user(owner, 'target', PASSWORD, 'viewer')
    auth.update_user(owner, 'target', enabled=False)
    auth.set_user('target', PASSWORD + 'reset', 'operator')
    assert auth.session(auth.login('target', PASSWORD + 'reset', 'test-peer'))['role'] == 'operator'


def test_admin_list_requires_owner(client, app):
    assert client.get('/api/admin/users').status_code == 401
    for role in ('viewer', 'operator'):
        app.state.auth.set_user(role, PASSWORD, role)
        assert client.post('/api/login', json=dict(username=role, password=PASSWORD)).status_code == 200
        assert client.get('/api/admin/users').status_code == 403
        client.headers['X-CSRF-Token'] = client.get('/api/session').json()['csrf']
        assert client.post('/api/admin/users', json=dict(username='forbidden', role='owner',
            new_password=PASSWORD, password=PASSWORD)).status_code == 403
        assert client.patch('/api/admin/users/owner', json=dict(role='viewer', password=PASSWORD)).status_code == 403


def test_owner_can_create_list_and_update_user(logged):
    created = logged.post('/api/admin/users', json=dict(
        username='target', role='viewer', new_password=PASSWORD, password=PASSWORD))
    assert created.status_code == 201
    assert created.json() == {'user': dict(username='target', role='viewer', enabled=True, totp=False, sessions=0)}
    users = logged.get('/api/admin/users').json()['users']
    assert [u['username'] for u in users] == ['owner', 'target']
    changed = logged.patch('/api/admin/users/target', json=dict(role='operator', password=PASSWORD))
    assert changed.status_code == 200
    assert changed.json()['user']['role'] == 'operator'


@pytest.mark.parametrize('method,path,body', [
    ('post', '/api/admin/users', dict(username='new', role='viewer', new_password=PASSWORD)),
    ('patch', '/api/admin/users/owner', dict(new_password=PASSWORD + 'new')),
])
def test_admin_mutations_require_csrf_and_current_password(logged, method, path, body):
    send = getattr(logged, method)
    logged.headers.pop('X-CSRF-Token')
    assert send(path, json=dict(body, password=PASSWORD)).status_code == 403
    logged.headers['X-CSRF-Token'] = logged.get('/api/session').json()['csrf']
    assert send(path, json=dict(body, password='wrong')).status_code == 403
    assert logged.get('/api/admin/users').json()['users'][0]['username'] == 'owner'


@pytest.mark.parametrize('body', [
    dict(username='new', role='admin', new_password=PASSWORD, password=PASSWORD),
    dict(username='new', role='viewer', new_password='short', password=PASSWORD),
    dict(username='new', role='viewer', new_password='a' * 257, password=PASSWORD),
    dict(username='new', role='viewer', new_password=PASSWORD, password=PASSWORD, enabled=True),
    dict(username=123, role='viewer', new_password=PASSWORD, password=PASSWORD),
])
def test_admin_create_rejects_invalid_or_extra_fields_without_echo(logged, body):
    response = logged.post('/api/admin/users', json=body)
    assert response.status_code == 422
    assert PASSWORD not in response.text
    assert 'a' * 257 not in response.text


@pytest.mark.parametrize('change', [dict(enabled=0), dict(enabled='false'), dict(enabled=None),
                                  dict(role=None), dict(new_password=None), dict(role='root'),
                                  dict(unknown=True), {}])
def test_admin_patch_rejects_coercion_null_and_empty_changes(logged, change):
    response = logged.patch('/api/admin/users/owner', json=dict(change, password=PASSWORD))
    assert response.status_code == 422
    assert PASSWORD not in response.text


def test_admin_create_collision_and_self_change_are_clear_conflicts(logged):
    collision = logged.post('/api/admin/users', json=dict(
        username='owner', role='viewer', new_password=PASSWORD + 'new', password=PASSWORD))
    assert collision.status_code == 409
    assert collision.json()['detail'] == 'user_exists'
    changed = logged.patch('/api/admin/users/owner', json=dict(enabled=False, password=PASSWORD))
    assert changed.status_code == 409
    assert changed.json()['detail'] == 'cannot_change_own_owner'
    assert logged.get('/api/session').json()['role'] == 'owner'


def test_admin_unknown_user_is_not_created_by_patch(logged):
    response = logged.patch('/api/admin/users/missing', json=dict(role='viewer', password=PASSWORD))
    assert response.status_code == 404
    assert response.json()['detail'] == 'user_not_found'


def test_admin_totp_is_required_and_codes_cannot_be_replayed(logged, app):
    from ustar_console import totp
    auth = app.state.auth
    now = 1800000000
    auth.clock = lambda: now
    # Re-login so the session is created at the deterministic clock.
    assert logged.post('/api/login', json=dict(username='owner', password=PASSWORD)).status_code == 200
    logged.headers['X-CSRF-Token'] = logged.get('/api/session').json()['csrf']
    key = totp.secret()
    counter = now // 30
    with auth.connection() as db:
        db.execute('INSERT INTO factors VALUES(?,?,?)', ('owner', key, counter - 1))
    body = dict(username='one', role='viewer', new_password=PASSWORD, password=PASSWORD)
    assert logged.post('/api/admin/users', json=body).status_code == 403
    body['otp'] = totp.code(key, counter)
    assert logged.post('/api/admin/users', json=body).status_code == 201
    body['username'] = 'two'
    assert logged.post('/api/admin/users', json=body).status_code == 403


def test_admin_password_change_revokes_current_owner_session(logged):
    response = logged.patch('/api/admin/users/owner', json=dict(new_password=PASSWORD + 'new', password=PASSWORD))
    assert response.status_code == 200
    assert logged.get('/api/session').status_code == 401
