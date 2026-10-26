#!/usr/bin/env python3
"""Advance the exact cron v3 contract to the owner-installed Academy release."""
import argparse
import base64
from contextlib import contextmanager
from datetime import datetime, timezone
from decimal import Decimal
import fcntl
import hashlib
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
from types import ModuleType

LEGACY_SHA = '66fd51d364beb59a8874a63c695c122dca5452a720e61b86d070beb0b1939852'
UPDATED_SHA = 'c5eabdceaa2f137eccd35087b03d473f61257aaeb77624f4855583ebaf353638'
PAYLOAD = '830d3703e2ffa2f9864900c0d0c58f60049cb0c2'
PREVIOUS = {'local_ustar': '2026100204', 'theme_ustar': '2026100202'}
TARGET = {'local_ustar': '2026101905', 'theme_ustar': '2026100202'}
OLD_LINE = b"VERSIONS = {'local_ustar': '2026100204', 'theme_ustar': '2026100202'}"
NEW_LINE = b"VERSIONS = {'local_ustar': '2026101905', 'theme_ustar': '2026100202'}"
CRON_TEXT = (b'# Managed by USTAR_CRON_SETUP_20261005.py\nSHELL=/bin/sh\n'
             b'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n'
             b'MAILTO=""\n* * * * * root /usr/bin/python3 '
             b'/usr/local/sbin/USTAR_CRON_SETUP_20261005.py --tick >/dev/null 2>&1\n')


class Refusal(Exception): pass


def require(value, reason):
    if not value: raise Refusal(reason)


def sha(raw): return hashlib.sha256(raw).hexdigest()


def patch_source(raw):
    require(sha(raw) == LEGACY_SHA and raw.count(OLD_LINE) == 1, 'cron_source_changed')
    result = raw.replace(OLD_LINE, NEW_LINE)
    require(sha(result) == UPDATED_SHA, 'updated_source_digest_mismatch')
    compile(result, 'reviewed_cron_v3', 'exec')
    return result


class Paths:
    def __init__(self, root=Path('/')):
        self.root = Path(root)
        self.script = self.root / 'usr/local/sbin/USTAR_CRON_SETUP_20261005.py'
        self.installation = self.root / 'var/lib/ustar-cron/installation.json'
        self.cron = self.root / 'etc/cron.d/ustar_moodle'
        self.cron_lock = self.root / 'run/ustar-cron/lock'
        self.backup_lock = self.root / 'run/ustar-backup/lock'
        self.receipt = self.root / 'var/lib/ustar-connect-academy-update/active.json'
        self.base = self.installation.parent / 'release-20261020'
        self.record = self.base / 'transaction.json'


class Host:
    def inspect(self, source):
        # Execute only a fully pinned module definition. No main/install/tick runs.
        require(sha(source) == UPDATED_SHA, 'unreviewed_runtime_source')
        module = ModuleType('ustar_reviewed_cron_v3')
        module.__file__ = '/usr/local/sbin/USTAR_CRON_SETUP_20261005.py'
        exec(compile(source, module.__file__, 'exec'), module.__dict__)
        try:
            cfg = module.configuration()
            containers = {name: module.identity(name) for name in ('ustar_moodle', 'ustar_postgres')}
            query = b"BEGIN READ ONLY; SELECT value FROM mdl_config WHERE name='version'; COMMIT;"
            shell = 'exec psql -X -w -qAt -v ON_ERROR_STOP=1 -U "${POSTGRES_USER:-postgres}" -d moodle'
            core = module.command([module.DOCKER, 'exec', '-i', '-e',
                'PGOPTIONS=-c default_transaction_read_only=on -c statement_timeout=15000 -c lock_timeout=3000',
                module.POSTGRES, 'sh', '-c', shell], data=query, timeout=25).strip()
            active = module.command(['systemctl', 'is-active', 'cron']).strip()
        except Exception:
            raise Refusal('native_configuration_or_identity_refused') from None
        return {**cfg, 'containers': containers, 'core': core, 'cron_service': active}


class Manager:
    def __init__(self, paths=None, host=None, uid=0):
        self.p, self.host, self.uid = paths or Paths(), host or Host(), uid

    def secure(self, path, directory=False, private=False):
        require(path.is_relative_to(self.p.root), 'path_outside_root')
        st = path.lstat()
        require((stat.S_ISDIR(st.st_mode) if directory else stat.S_ISREG(st.st_mode))
                and st.st_uid == self.uid and not st.st_mode & (0o077 if private else 0o022)
                and (directory or st.st_nlink == 1), 'unsafe_managed_path')
        for parent in path.parents:
            if not parent.is_relative_to(self.p.root): break
            info = parent.lstat()
            require(stat.S_ISDIR(info.st_mode) and info.st_uid == self.uid
                    and not info.st_mode & 0o022, 'unsafe_parent')
        return st

    def read(self, path, private=False):
        require(self.secure(path, private=private).st_size <= 2 * 1024 ** 2, 'input_too_large')
        return path.read_bytes()

    def json(self, path):
        try:
            value = json.loads(self.read(path, True))
            require(isinstance(value, dict), 'invalid_object')
            return value
        except (ValueError, KeyError): raise Refusal('invalid_private_state') from None

    def metadata(self, path):
        st = self.secure(path)
        return {'uid': st.st_uid, 'gid': st.st_gid, 'mode': stat.S_IMODE(st.st_mode),
                'xattrs': {k: base64.b64encode(os.getxattr(path, k)).decode()
                          for k in os.listxattr(path)}}

    @staticmethod
    def sync(path):
        fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try: os.fsync(fd)
        finally: os.close(fd)

    def replace(self, path, raw, meta):
        self.secure(path.parent, directory=True)
        if os.path.lexists(path): self.secure(path)
        fd, name = tempfile.mkstemp(prefix='.cron-release-', dir=path.parent)
        try:
            with os.fdopen(fd, 'wb') as out:
                os.fchown(out.fileno(), meta['uid'], meta['gid'])
                os.fchmod(out.fileno(), meta['mode'])
                for k, v in meta['xattrs'].items(): os.setxattr(out.fileno(), k, base64.b64decode(v))
                out.write(raw); out.flush(); os.fsync(out.fileno())
            os.replace(name, path); self.sync(path.parent)
        finally:
            if os.path.exists(name): os.unlink(name)

    def private_write(self, path, raw):
        self.replace(path, raw, {'uid': self.uid, 'gid': os.getegid(), 'mode': 0o600, 'xattrs': {}})

    def save(self, record):
        self.private_write(self.p.record, (json.dumps(record, sort_keys=True) + '\n').encode())

    @contextmanager
    def locked(self):
        descriptors = []
        try:
            for path, mode in ((self.p.cron_lock, fcntl.LOCK_EX), (self.p.backup_lock, fcntl.LOCK_SH)):
                before = self.secure(path, private=True)
                fd = os.open(path, os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK)
                descriptors.append(fd)
                after = os.fstat(fd)
                require((before.st_dev, before.st_ino) == (after.st_dev, after.st_ino), 'lock_identity_changed')
                try: fcntl.flock(fd, mode | fcntl.LOCK_NB)
                except BlockingIOError: raise Refusal('cron_or_backup_busy') from None
            yield
        finally:
            for fd in reversed(descriptors): os.close(fd)

    def inputs(self):
        raw = self.read(self.p.script)
        require(sha(raw) in (LEGACY_SHA, UPDATED_SHA), 'cron_source_changed')
        source = patch_source(raw) if sha(raw) == LEGACY_SHA else raw
        state = self.json(self.p.installation)
        expected = PREVIOUS if sha(raw) == LEGACY_SHA else TARGET
        require(state.get('schema') == 1 and state.get('versions') == expected
                and state.get('script_sha256') == sha(raw), 'installation_contract_changed')
        require(self.read(self.p.cron) == CRON_TEXT, 'managed_schedule_changed')
        receipt = self.json(self.p.receipt)
        require(receipt.get('phase') == 'upgrade_complete' and receipt.get('public_released') is True
                and receipt.get('payload_commit') == PAYLOAD, 'accepted_release_receipt_required')
        actual = self.host.inspect(source)
        require(actual['containers'] == state['containers'], 'container_binding_changed')
        require(actual['versions'] == TARGET and actual['needs_upgrade'] is False
                and actual['cron_enabled'] == 1 and actual['maintenance'] == 0,
                'target_configuration_not_ready')
        require(Decimal(actual['core']) == Decimal('2025100608.00'), 'reviewed_core_changed')
        require(actual['cron_service'] == 'active', 'cron_service_not_active')
        return raw, source, state

    def check(self):
        if os.path.lexists(self.p.record):
            require(self.json(self.p.record)['phase'] != 'prepared', 'interrupted_pair_requires_apply_or_rollback')
        raw, source, state = self.inputs()
        updated = sha(raw) == UPDATED_SHA
        if updated:
            require(os.path.lexists(self.p.record) and self.json(self.p.record)['phase'] == 'active',
                    'updated_pair_has_no_transaction_receipt')
        return {'phase': 'already_applied' if updated else 'ready_for_cron_release_update',
                'versions': TARGET, 'tasks_launched_by_update': False, 'schedule_changed': False}

    def restore(self, record):
        require(record.get('schema') == 1 and record.get('phase') in ('prepared', 'active', 'rolled_back'),
                'invalid_transaction')
        require(sha(self.read(self.p.cron)) == record['schedule_sha'], 'managed_schedule_changed')
        entries = (('script', self.p.script), ('installation', self.p.installation))
        # Verify the entire pair and its before-images before restoring either file.
        for name, path in entries:
            data = record[name]
            require(sha(self.read(path)) in (data['before'], data['after']), 'administrator_changed_managed_file')
            require(sha(self.read(self.p.base / (name + '.before'), True)) == data['before'], 'before_image_changed')
        for name, path in entries:
            self.replace(path, self.read(self.p.base / (name + '.before'), True), record[name]['meta'])
        record['phase'] = 'rolled_back'; self.save(record)
        return {'phase': 'rolled_back', 'tasks_launched_by_update': False, 'schedule_changed': False}

    def apply(self):
        with self.locked():
            if os.path.lexists(self.p.record):
                previous = self.json(self.p.record)
                if previous['phase'] == 'prepared': self.restore(previous)
            result = self.check()
            if result['phase'] == 'already_applied': return result
            raw, source, state = self.inputs()
            installation = self.read(self.p.installation, True)
            desired = (json.dumps({**state, 'versions': TARGET, 'script_sha256': UPDATED_SHA},
                                  ensure_ascii=False, indent=2) + '\n').encode()
            if not os.path.lexists(self.p.base): self.p.base.mkdir(mode=0o700); self.sync(self.p.base.parent)
            self.secure(self.p.base, directory=True, private=True)
            record = {'schema': 1, 'phase': 'prepared', 'payload': PAYLOAD,
                      'prepared_at': datetime.now(timezone.utc).isoformat(), 'schedule_sha': sha(CRON_TEXT)}
            for name, path, before, after in (('script', self.p.script, raw, source),
                                              ('installation', self.p.installation, installation, desired)):
                record[name] = {'before': sha(before), 'after': sha(after), 'meta': self.metadata(path)}
                self.private_write(self.p.base / (name + '.before'), before)
            self.save(record)
            try:
                for name, path, content in (('script', self.p.script, source),
                                            ('installation', self.p.installation, desired)):
                    require(sha(self.read(path)) == record[name]['before'], 'baseline_changed')
                    self.replace(path, content, record[name]['meta'])
                self.inputs()
                record['phase'] = 'active'; self.save(record)
            except Exception:
                self.restore(record)
                raise
            return {**result, 'phase': 'applied_waiting_for_scheduled_run', 'script_sha256': UPDATED_SHA}

    def rollback(self):
        with self.locked(): return self.restore(self.json(self.p.record))


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group()
    for name in ('check', 'apply', 'rollback'): modes.add_argument('--' + name, action='store_true')
    args = parser.parse_args(argv)
    if not any(vars(args).values()):
        print('{"phase":"plan_only","host_access":false}'); return 0
    try:
        require(os.geteuid() == 0 and sys.version_info >= (3, 12), 'root_python312_required')
        os.umask(0o077)
        manager = Manager()
        result = getattr(manager, next(name for name in ('check', 'apply', 'rollback') if getattr(args, name)))()
        print(json.dumps(result, ensure_ascii=False, indent=2)); return 0
    except Exception as error:
        print(json.dumps({'phase': 'refused', 'reason': str(error) if isinstance(error, Refusal)
                          else type(error).__name__})); return 2


if __name__ == '__main__': sys.exit(main())
