import os
from pathlib import Path
import sys

import pytest
from fastapi.testclient import TestClient

sys.path.insert(0, str(Path(__file__).parents[1]/'backend'))
from ustar_console.config import Settings
from ustar_console.server import create_app

PASSWORD = 'test-only-password-12345'


@pytest.fixture
def settings(tmp_path):
    return Settings(origin='https://console.test', database=tmp_path/'auth.sqlite3', snapshot=tmp_path/'snapshot.json')


@pytest.fixture
def app(settings):
    value = create_app(settings)
    value.state.auth.set_user('owner', PASSWORD, 'owner')
    return value


@pytest.fixture
def client(app):
    with TestClient(app,base_url='https://console.test',headers={'Origin':'https://console.test'}) as value:
        yield value


@pytest.fixture
def logged(client):
    assert client.post('/api/login',json={'username':'owner','password':PASSWORD}).status_code == 200
    csrf=client.get('/api/session').json()['csrf']
    client.headers['X-CSRF-Token']=csrf
    return client
