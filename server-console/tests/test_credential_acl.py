"""Real POSIX ACL credentials: accept the service reader, reject shared secrets."""
import json
import os
from pathlib import Path
import stat
import struct

import pytest

from ustar_console import alerts


UNDEFINED = 0xffffffff
VALUE = dict(token='123456789:'+('x'*35), users=[123], chats=[123])


def credential(tmp_path, entries=None, mode=0o400):
    path = tmp_path/'telegram'
    path.write_text(json.dumps(VALUE))
    path.chmod(mode)
    if entries is not None:
        acl = struct.pack('<I', 2)+b''.join(
            struct.pack('<HHI', *row) for row in sorted(entries, key=lambda row: (row[0], row[2])))
        os.setxattr(path, 'system.posix_acl_access', acl)
    return path


def service_acl():
    return [(1, 4, UNDEFINED), (2, 4, os.geteuid()), (4, 0, UNDEFINED),
            (16, 4, UNDEFINED), (32, 0, UNDEFINED)]


def test_systemd_readonly_named_user_acl_is_private(tmp_path):
    path = credential(tmp_path, service_acl())
    assert stat.S_IMODE(path.stat().st_mode) == 0o440
    assert alerts.config(path) == VALUE


@pytest.mark.parametrize('mode', [0o400, 0o600])
def test_owner_private_credentials_still_work(tmp_path, mode):
    assert alerts.config(credential(tmp_path, mode=mode)) == VALUE


@pytest.mark.parametrize('mode', [0o440, 0o640, 0o444, 0o604, 0o666])
def test_mode_bits_alone_never_authorize_shared_credentials(tmp_path, mode):
    path = credential(tmp_path, mode=mode)
    with pytest.raises(ValueError, match='Untrusted Telegram credentials'):
        alerts.config(path)


@pytest.mark.parametrize('extra', ['other_user', 'owning_group', 'named_group', 'other', 'write_mask'])
def test_acl_read_or_write_access_for_other_principals_is_rejected(tmp_path, monkeypatch, extra):
    rows = service_acl()
    if extra == 'other_user': rows.insert(2, (2, 4, 33 if os.geteuid() != 33 else 999))
    if extra == 'owning_group': rows[2] = (4, 4, UNDEFINED)
    if extra == 'named_group': rows.insert(3, (8, 4, os.getegid()))
    if extra == 'other': rows[-1] = (32, 4, UNDEFINED)
    if extra == 'write_mask': rows[-2] = (16, 6, UNDEFINED)
    # The local UID namespace maps only UID0; native CI exercises the service UID.
    # Inject only the unavailable foreign-user xattr, keeping a real 0440 ACL file.
    path = credential(tmp_path, service_acl() if extra == 'other_user' else rows)
    if extra == 'other_user':
        raw = struct.pack('<I', 2)+b''.join(struct.pack('<HHI', *row) for row in rows)
        monkeypatch.setattr(os, 'getxattr', lambda *args: raw)
    with pytest.raises(ValueError, match='Untrusted Telegram credentials'):
        alerts.config(path)


def test_unreadable_acl_cannot_fall_back_to_group_read_mode(tmp_path, monkeypatch):
    path = credential(tmp_path, service_acl())
    def denied(*args): raise PermissionError('fixture')
    monkeypatch.setattr(os, 'getxattr', denied)
    with pytest.raises(ValueError, match='Untrusted Telegram credentials'):
        alerts.config(path)


def test_linked_credentials_are_rejected(tmp_path):
    path = credential(tmp_path)
    os.link(path, tmp_path/'another-name')
    with pytest.raises(ValueError, match='Untrusted Telegram credentials'):
        alerts.config(path)


def test_symlink_and_special_files_are_rejected(tmp_path):
    path = credential(tmp_path)
    alias = tmp_path/'alias'; alias.symlink_to(path)
    fifo = tmp_path/'fifo'; os.mkfifo(fifo, 0o600)
    for invalid in (alias, fifo):
        with pytest.raises((ValueError, OSError)):
            alerts.config(invalid)


def test_oversized_credentials_are_rejected(tmp_path):
    path = credential(tmp_path, mode=0o600); path.write_text(' '*8193)
    with pytest.raises(ValueError, match='Untrusted Telegram credentials'):
        alerts.config(path)
