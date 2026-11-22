#!/usr/bin/env python3
"""Exercise the delivered operator with real source files and simulated host commands."""

import json
import os
from pathlib import Path
import sys
import tarfile
import tempfile
import types
import unittest


REPO = Path(__file__).resolve().parents[1]
PACKAGE = Path(sys.argv.pop(1)).resolve()


class WorkplaceReleaseTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        temporary = tempfile.TemporaryDirectory(prefix='ustar-workplace-package-')
        cls.addClassCleanup(temporary.cleanup)
        cls.package = Path(temporary.name)
        with tarfile.open(PACKAGE, 'r:gz') as tar:
            names = tar.getnames()
            assert 'worker.tar.gz' not in names and len(names) == len(set(names))
            tar.extractall(cls.package, filter='data')
        cls.release = types.ModuleType('workplace_delivery')
        exec(compile((cls.package / 'update.py').read_bytes(), 'update.py', 'exec'), cls.release.__dict__)

    def setUp(self):
        temp = tempfile.TemporaryDirectory(prefix='ustar-workplace-host-')
        self.addCleanup(temp.cleanup)
        lab = self.lab = Path(temp.name)
        release = self.release
        engine = types.ModuleType('workplace_test_engine')
        exec(compile(release.FROZEN_SOURCE, 'transaction.py', 'exec'), engine.__dict__)
        self.engine = release.configure(engine, release.SCHEMA_CHECK)
        engine.STATE = lab / 'journal'
        core = engine.embedded(release.CORE_SOURCE, engine.CORE_SHA256, 'core')
        worker = engine.embedded(release.WORKER_INSTALLER, engine.WORKER_INSTALLER_SHA256, 'worker')
        manifest, package = release.load_package(self.package, core, engine, worker)
        core.archive(REPO, release.BASELINE, lab)
        core.HOST_APP = lab / 'moodle'
        core.BACKUP_DIR = lab / 'backups'
        core.cli_preflight = lambda: None
        state = {'version': release.BEFORE, 'cron': release.BEFORE, 'worker': release.BASELINE,
                 'maintenance': False, 'saves': 0, 'copies': 0, 'smoke_failure': False, 'schema_ready': True}
        self.runtime = state
        self.baseline = manifest['before'][release.BASELINE]

        def backup(_manifest):
            state['copies'] += 1
            snapshot = core.BACKUP_DIR / 'ustar-reward-shop-fixture'
            snapshot.mkdir(mode=0o700, parents=True)
            with tarfile.open(snapshot / 'local-ustar-before.tar.gz', 'w:gz') as tar:
                tar.add(core.HOST_APP / 'local/ustar', arcname='local/ustar')
            (snapshot / 'postgres-before.dump').write_bytes(b'Simulated database backup')
            return snapshot

        core.backup = backup

        class FixtureHost(engine.Host):
            services = dict.fromkeys(engine.SERVICES, True)

            def run(self, argv, reason, **kwargs):
                assert argv[:2] == ['docker', 'inspect'], (argv, reason)
                return json.dumps({'id': 'isolated-fixture', 'running': True, 'mounts': [
                    {'Source': str(core.HOST_APP.parent), 'Destination': '/var/www/html'},
                    {'Source': str(engine.DATA), 'Destination': '/var/www/moodledata'},
                ]}).encode()

            def php(self, args, reason, **kwargs):
                if args[0] == '-l':
                    source = self.app / args[1].removeprefix(core.CONTAINER_APP + '/local/ustar/')
                    return core.run(['php', '-l', str(source)], timeout=15).stdout
                assert args[0] == '-r', reason
                if args[1] == release.SCHEMA_CHECK:
                    return json.dumps([state['schema_ready']] * 8).encode()
                return json.dumps({'core': 2025100608, 'theme_ustar': release.THEME, 'tables_ready': True,
                                   'data': '/var/www/moodledata', 'maintenance_scheduled': False, 'announcements_enabled': True,
                                   'needs_upgrade': False, 'local_ustar': state['version'], 'maintenance': state['maintenance']}).encode()

            def worker_commit(self):
                return state['worker']

            def worker_files(self, commit):
                assert commit == release.BASELINE
                return release.WORKER_UNITS

            def verify_units(self, values):
                assert values == release.WORKER_UNITS

            def maintenance(self, enable):
                state['maintenance'] = enable

            def service_states(self):
                return dict(self.services)

            def set_services(self, services):
                self.services = dict(services)

            def cron_plan(self, expected=None):
                return {'before': [state['cron'], release.THEME], 'binding': 'synthetic-cron'}

            def commit_cron(self, plan):
                assert plan['before'] == [release.BEFORE, release.THEME]
                state['cron'] = release.AFTER

            def upgrade_database(self):
                assert state['maintenance'] and not any(self.services.values())
                state['version'] = release.AFTER
                state['saves'] += 1

            def purge(self):
                pass

            def smoke(self):
                engine.require(not state['smoke_failure'], 'moodle_backend_login_failed')

        self.host = FixtureHost(self.package, manifest, release.WORKER_UNITS, core, worker)
        self.transaction = engine.Transaction(self.host, package)

    def test_exact_source_upgrade_and_repeated_check(self):
        self.assertEqual(self.transaction.check()['phase'], 'ready')
        self.assertEqual(self.runtime['copies'], 0)
        self.assertEqual(self.transaction.upgrade()['phase'], 'active')
        self.assertEqual(self.host.source(self.host.app), self.host.manifest['after'])
        self.assertEqual(self.transaction.upgrade()['worker_commit'], self.release.BASELINE)
        self.assertEqual(self.runtime['saves'], 1)
        self.assertEqual(self.runtime['copies'], 1)
        self.assertFalse(self.runtime['maintenance'])
        self.assertTrue(all(self.host.services.values()))

    def test_baseline_drift_refused_before_backup(self):
        self.runtime['cron'] -= 1
        with self.assertRaisesRegex(self.engine.Refused, 'baseline_cron_version_mismatch'):
            self.transaction.upgrade()
        self.runtime['cron'] = self.release.BEFORE
        self.runtime['schema_ready'] = False
        with self.assertRaisesRegex(self.engine.Refused, 'briefing_schema_drift'):
            self.transaction.upgrade()
        self.runtime['schema_ready'] = True
        self.runtime['worker'] = self.release.TARGET
        with self.assertRaisesRegex(self.engine.Refused, 'unexpected_worker'):
            self.transaction.upgrade()
        self.runtime['worker'] = self.release.BASELINE
        (self.host.app / 'messages.php').write_text('different from the known base')
        with self.assertRaisesRegex(self.engine.Refused, 'application_source_drift'):
            self.transaction.upgrade()
        self.assertEqual(self.runtime['copies'], 0)

    def test_failure_before_savepoint_rolls_back_the_actual_payload(self):
        write_payload = self.host.install_source

        def fail_after_writing(state):
            write_payload(state)
            raise self.engine.Refused('fixture_failed_before_savepoint')

        self.host.install_source = fail_after_writing
        with self.assertRaisesRegex(self.engine.Refused, 'fixture_failed_before_savepoint'):
            self.transaction.upgrade()
        self.assertEqual(self.host.source(self.host.app), self.baseline)
        self.assertEqual(self.transaction.read()['step'], 'rolled_back')
        self.assertEqual(self.runtime['version'], self.release.BEFORE)
        self.assertEqual(self.transaction.check()['phase'], 'ready')

    def test_resume_from_completed_savepoint(self):
        commit_cron = self.host.commit_cron

        def fail_after_savepoint(_plan):
            raise self.engine.Refused('fixture_failed_after_savepoint')

        self.host.commit_cron = fail_after_savepoint
        with self.assertRaisesRegex(self.engine.Refused, 'fixture_failed_after_savepoint'):
            self.transaction.upgrade()
        self.assertEqual(self.transaction.check()['step'], 'migration')
        self.assertTrue(self.runtime['maintenance'])
        self.host.commit_cron = commit_cron
        self.assertEqual(self.transaction.recover()['phase'], 'active')
        self.assertEqual(self.runtime['saves'], 1)

    def test_smoke_failure_keeps_maintenance_until_recovery(self):
        self.runtime['smoke_failure'] = True
        with self.assertRaisesRegex(self.engine.Refused, 'moodle_backend_login_failed'):
            self.transaction.upgrade()
        self.assertTrue(self.runtime['maintenance'])
        self.assertFalse(any(self.host.services.values()))
        self.assertEqual(self.transaction.check()['step'], 'app_ready')
        self.runtime['smoke_failure'] = False
        self.assertEqual(self.transaction.recover()['phase'], 'active')
        self.assertEqual(self.runtime['saves'], 1)

    def test_unfinished_predecessor_is_not_bypassed(self):
        release = self.release
        previous = release.BRIEFING_STATE
        self.addCleanup(setattr, release, 'BRIEFING_STATE', previous)
        release.BRIEFING_STATE = self.lab / 'briefing-journal'
        release.assert_briefing_completed(self.engine)
        release.BRIEFING_STATE.mkdir(mode=0o700)
        journal = release.BRIEFING_STATE / 'state.json'
        info = {'package': release.BRIEFING_MANIFEST, 'baseline': release.BRIEFING_BASES[0]}
        journal.write_text(json.dumps({**info, 'step': 'migration'}))
        os.chmod(journal, 0o600)
        with self.assertRaisesRegex(self.engine.Refused, 'briefing_installation_not_completed'):
            release.assert_briefing_completed(self.engine)
        journal.write_text(json.dumps({**info, 'step': 'active'}))
        release.assert_briefing_completed(self.engine)


if __name__ == '__main__':
    unittest.main()
