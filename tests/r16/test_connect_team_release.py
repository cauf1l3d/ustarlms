"""Installation boundaries for the Academy and Connect release."""

import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch

from scripts.infra import connect_team_release as release


class Host:
    def __init__(self, baseline):
        self.source = baseline
        self.worker = release.WORKER_BEFORE
        self.version = release.BEFORE
        self.maintenance_on = False
        self.announcements = False
        self.services = dict.fromkeys(release.SERVICES, False)
        self.services[release.SERVICES[0]] = True
        self.events = []
        self.cron_version = release.BEFORE
        self.fail = None

    def event(self, name):
        self.events.append(name)
        if self.fail == name:
            raise release.Refused(name + '_failed')

    def verify(self, *, worker, active, interrupted=False):
        self.event('verify_host')
        release.require(worker == self.worker, 'unexpected_worker')
        release.require(active or not self.announcements, 'announcements_enabled')
        return {'local_ustar': self.version, 'maintenance': self.maintenance_on,
                'announcements_enabled': self.announcements, 'needs_upgrade': False}

    def inspect_source(self):
        return self.source

    def worker_commit(self):
        return self.worker

    def service_states(self):
        return self.services.copy()

    def set_services(self, states):
        self.event('services_start' if any(states.values()) else 'services_stop')
        self.services = states.copy()

    def stage_worker(self):
        self.event('stage_worker')

    def cron_plan(self, expected=None):
        if expected is None:
            return {'before': [self.cron_version, release.THEME], 'binding': 'fixture'}
        release.require([self.cron_version, release.THEME] in (expected['before'], [release.AFTER, release.THEME]),
                        'cron_drift')
        return expected

    def commit_cron(self, plan):
        self.event('cron')
        self.cron_version = release.AFTER

    def backup(self, baseline):
        self.event('backup')
        return {'path': '/fixture/backup', 'sha256': baseline}

    def maintenance(self, enable):
        self.event('maintenance_on' if enable else 'maintenance_off')
        self.maintenance_on = enable

    def install_source(self, state):
        self.source = release.TARGET
        self.event('source')

    def restore_source(self, state):
        self.event('restore_source')
        self.source = state['baseline']

    def upgrade_database(self):
        self.version = release.AFTER
        self.event('database')

    def purge(self):
        self.event('purge')

    def switch_worker(self):
        self.event('switch_worker')
        self.worker = release.TARGET

    def smoke(self):
        self.event('smoke')


class TransactionTest(unittest.TestCase):
    def setUp(self):
        self.directory = self.enterContext(tempfile.TemporaryDirectory())
        self.enterContext(patch.object(release, 'STATE', Path(self.directory) / 'state'))
        self.host = Host(release.BASES[0])
        self.tx = release.Transaction(self.host, 'fixture-package')

    def test_both_previous_storefronts_apply_and_resume_is_idempotent(self):
        for baseline in release.BASES:
            with self.subTest(baseline=baseline):
                host = Host(baseline)
                with patch.object(release, 'STATE', Path(self.directory) / baseline):
                    tx = release.Transaction(host, 'fixture-package')
                    self.assertEqual(tx.check()['phase'], 'ready')
                    initial = host.service_states()
                    self.assertEqual(tx.upgrade()['phase'], 'active')
                    self.assertEqual(host.service_states(), initial)
                    self.assertEqual(host.cron_version, release.AFTER)
                    self.assertEqual(tx.check()['phase'], 'active')
                    self.assertEqual(tx.upgrade()['phase'], 'active')
                    for step in ('backup', 'database', 'switch_worker', 'services_start'):
                        self.assertEqual(host.events.count(step), 1)
                    self.assertLess(host.events.index('backup'), host.events.index('maintenance_on'))
                    self.assertLess(host.events.index('database'), host.events.index('switch_worker'))
                    self.assertLess(host.events.index('cron'), host.events.index('maintenance_off'))
                    self.assertFalse(host.maintenance_on)

    def test_refuses_unknown_source_and_existing_maintenance_before_backup(self):
        for source, maintenance, reason in (
                ('unknown', False, 'application_source_drift'),
                (release.BASES[0], True, 'existing_maintenance')):
            with self.subTest(reason=reason):
                self.host.source = source
                self.host.maintenance_on = maintenance
                with self.assertRaisesRegex(release.Refused, reason):
                    self.tx.upgrade()
                self.assertNotIn('backup', self.host.events)
                self.assertIsNone(self.tx.read())

    def test_announcements_enabled_blocks_outage(self):
        self.host.announcements = True
        with self.assertRaisesRegex(release.Refused, 'announcements_enabled'):
            self.tx.upgrade()
        self.assertNotIn('maintenance_on', self.host.events)

    def test_write_failure_before_database_restores_baseline(self):
        self.host.fail = 'source'
        with self.assertRaisesRegex(release.Refused, 'source_failed'):
            self.tx.upgrade()
        self.assertEqual(self.tx.read()['step'], 'rolled_back')
        self.assertEqual(self.host.source, release.BASES[0])
        self.assertEqual(self.host.version, release.BEFORE)
        self.assertEqual(self.host.worker, release.WORKER_BEFORE)
        self.assertEqual(self.host.cron_version, release.BEFORE)
        self.assertFalse(self.host.maintenance_on)
        self.assertNotIn('database', self.host.events)

    def test_recovery_after_savepoint_completes_forward_without_repeating_migration(self):
        self.host.fail = 'database'
        with self.assertRaisesRegex(release.Refused, 'database_failed'):
            self.tx.upgrade()
        self.assertEqual(self.tx.check()['phase'], 'recovery_required')
        self.assertEqual(self.host.source, release.TARGET)
        self.assertEqual(self.host.worker, release.WORKER_BEFORE)
        self.assertTrue(self.host.maintenance_on)
        self.host.fail = None
        self.assertEqual(self.tx.recover()['phase'], 'active')
        self.assertEqual(self.host.events.count('database'), 1)
        self.assertNotIn('restore_source', self.host.events)
        self.assertFalse(self.host.maintenance_on)

    def test_recovery_after_publication_does_not_repeat_database_or_pointer_switch(self):
        self.host.fail = 'services_start'
        with self.assertRaisesRegex(release.Refused, 'services_start_failed'):
            self.tx.upgrade()
        self.assertEqual(self.tx.read()['step'], 'app_ready')
        self.assertEqual(self.host.worker, release.TARGET)
        self.assertFalse(self.host.maintenance_on)
        self.host.fail = None
        self.assertEqual(self.tx.recover()['phase'], 'active')
        self.assertEqual(self.host.events.count('database'), 1)
        self.assertEqual(self.host.events.count('switch_worker'), 1)

    def test_journal_identity_required_for_recovery(self):
        self.host.fail = 'database'
        with self.assertRaises(release.Refused):
            self.tx.upgrade()
        path = release.STATE / 'state.json'
        data = json.loads(path.read_bytes())
        data['package'] = 'other-package'
        path.write_text(json.dumps(data))
        with self.assertRaisesRegex(release.Refused, 'journal_identity_mismatch'):
            self.tx.recover()


class CronContractTest(unittest.TestCase):
    def setUp(self):
        self.wrapper = b'#!/usr/bin/python3\n' + release.CRON_ORIGINAL_LINE + b'\n'
        self.enterContext(patch.object(release, 'CRON_ORIGINAL_SHA256', release.sha(self.wrapper)))
        self.info = release.canonical({'schema': 1, 'containers': {'web': 'fixture'},
                                       'versions': {'local_ustar': '2026102000', 'theme_ustar': '2026100202'},
                                       'script_sha256': release.sha(self.wrapper)})

    def test_updates_only_version_contract_and_resumes_partially_published_pair(self):
        plan, desired, info = release.cron_update(self.wrapper, self.info)
        self.assertEqual(plan['before'], [2026102000, 2026100202])
        self.assertEqual(desired, self.wrapper.replace(release.CRON_ORIGINAL_LINE,
                                                       release.cron_line(release.AFTER, release.THEME)))
        self.assertEqual(json.loads(info)['containers'], {'web': 'fixture'})
        with self.assertRaisesRegex(release.Refused, 'cron_contract_incomplete'):
            release.cron_update(desired, self.info)
        self.assertEqual(release.cron_update(desired, self.info, expected=plan), (plan, desired, info))
        self.assertEqual(release.cron_update(desired, info)[0]['before'], [release.AFTER, release.THEME])

    def test_refuses_modified_wrapper_or_different_installation_binding(self):
        with self.assertRaisesRegex(release.Refused, 'cron_wrapper_source_drift'):
            release.cron_update(self.wrapper + b'# altered\n', self.info)
        plan, _, _ = release.cron_update(self.wrapper, self.info)
        changed = release.canonical({**json.loads(self.info), 'containers': {'web': 'other'}})
        with self.assertRaisesRegex(release.Refused, 'cron_contract_drift'):
            release.cron_update(self.wrapper, changed, expected=plan)


class SourceFilesTest(unittest.TestCase):
    def setUp(self):
        root = Path(self.enterContext(tempfile.TemporaryDirectory()))
        core = release.embedded(subprocess.check_output([
            'git', 'show', release.CORE_COMMIT + ':scripts/infra/reward_shop_release.py',
        ]), release.CORE_SHA256, '_fixture_v3')
        core.HOST_APP = root / 'code/public'
        core.BACKUP_DIR = root / 'backup'
        self.app = core.HOST_APP / 'local/ustar'
        self.app.mkdir(parents=True)
        (self.app / 'version.php').write_bytes(b'<?php // Before')
        (self.app / 'version.php').chmod(0o640)
        theme = core.HOST_APP / 'theme/ustar'
        theme.mkdir(parents=True)
        folder = root / 'extracted'
        payload = folder / 'payload'
        payload.mkdir(parents=True)
        for name in ('version.php', 'new.php'):
            (payload / name).write_bytes(b'<?php // After')
        self.base = release.BASES[0]
        before = core.scan(self.app)
        after = core.scan(payload)
        worker = release.embedded(subprocess.check_output([
            'git', 'show', release.TARGET + ':connect-worker/deploy/install.py',
        ]), release.WORKER_INSTALLER_SHA256, '_fixture_worker')
        self.host = release.Host(folder, {'before': dict.fromkeys(release.BASES, before),
                                         'after': after, 'theme': {}}, {}, core, worker)
        self.enterContext(patch.object(self.host, 'php'))
        archive_path = core.BACKUP_DIR / 'ustar-reward-shop-fixture'
        archive_path.mkdir(parents=True)
        archive = archive_path / 'local-ustar-before.tar.gz'
        with tarfile.open(archive, 'w:gz') as tar:
            tar.add(self.app, arcname='local/ustar')
        self.state = {'baseline': self.base, 'backup': {'path': str(archive_path), 'sha256': core.digest(archive)}}

    def test_atomic_code_install_and_restore_preserve_existing_modes(self):
        self.assertEqual(self.host.inspect_source(), self.base)
        self.host.install_source(self.state)
        self.assertEqual(self.host.inspect_source(), release.TARGET)
        self.assertEqual(os.stat(self.app / 'version.php').st_mode & 0o777, 0o640)
        self.host.restore_source(self.state)
        self.assertEqual(self.host.inspect_source(), self.base)
        self.assertFalse((self.app / 'new.php').exists())
        self.assertEqual(os.stat(self.app / 'version.php').st_mode & 0o777, 0o640)

    def test_drift_during_recovery_is_refused_before_overwriting_code(self):
        self.host.install_source(self.state)
        unexpected = self.app / 'version.php'
        unexpected.write_bytes(b'<?php // unexpected edit')
        with self.assertRaisesRegex(release.Refused, 'transitional_application_drift'):
            self.host.restore_source(self.state)
        self.assertEqual(unexpected.read_bytes(), b'<?php // unexpected edit')
        self.assertTrue((self.app / 'new.php').exists())


if __name__ == '__main__':
    unittest.main()
