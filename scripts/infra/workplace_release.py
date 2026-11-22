#!/usr/bin/env python3
"""Apply the qualified Workplace update over the exact briefing release."""

import argparse
import json
import os
from pathlib import Path
import re
import signal
import stat
import sys

FROZEN_SOURCE = None
CORE_SOURCE = None
WORKER_INSTALLER = None
WORKER_UNITS = None
SCHEMA_CHECK = None

TARGET = '331891426febf692d68598bbcbabe6fa3b7d9e7a'
BASELINE = '17b6141007d65207d9b594138753d28b39ab70bf'
BEFORE, AFTER, THEME = 2026111800, 2026112200, 2026110200
STATE = Path('/var/lib/ustar-workplace-20261122')
BRIEFING_STATE = Path('/var/lib/ustar-task-briefing-20261118')
BRIEFING_MANIFEST = '87ec89baadf8d0c64a2b3d6c6be149b6af68793a870127bf39629121bd9f64a5'
BRIEFING_BASES = ('422fbfb324db3b18447b61bc1c86a8a5481fe3e0', 'b1df4f2b234b6b79260eb3bbd2423fc0daf7ef79')


def assert_briefing_completed(engine):
    if not os.path.lexists(BRIEFING_STATE):
        return
    directory = BRIEFING_STATE.lstat()
    engine.require(stat.S_ISDIR(directory.st_mode) and directory.st_uid == os.geteuid()
                   and stat.S_IMODE(directory.st_mode) == 0o700, 'unsafe_briefing_journal_directory')
    path = BRIEFING_STATE / 'state.json'
    engine.require(os.path.lexists(path), 'missing_briefing_journal')
    status = path.lstat()
    engine.require(stat.S_ISREG(status.st_mode) and status.st_uid == os.geteuid() and status.st_nlink == 1
                   and stat.S_IMODE(status.st_mode) == 0o600 and status.st_size < 65536,
                   'unsafe_briefing_journal')
    state = json.loads(path.read_bytes())
    engine.require(isinstance(state, dict) and state.get('package') == BRIEFING_MANIFEST
                   and state.get('baseline') in BRIEFING_BASES and state.get('step') == 'active',
                   'briefing_installation_not_completed')


def configure(engine, schema_check):
    engine.BASES = (BASELINE,)
    engine.WORKER_BEFORE, engine.TARGET = BASELINE, TARGET
    engine.BEFORE, engine.AFTER, engine.THEME = BEFORE, AFTER, THEME
    engine.STATE = STATE

    class Host(engine.Host):
        def verify(self, *, worker, active, interrupted=False):
            # The shared state machine uses TARGET as a logical label after upgrade.
            # The actual Connect release identity stays pinned to the briefing build.
            engine.require(worker in (BASELINE, TARGET), 'unexpected_worker')
            status = super().verify(worker=BASELINE, active=True, interrupted=interrupted)
            schema = json.loads(self.php(['-r', schema_check], 'briefing_schema_check_failed'))
            engine.require(schema == [True] * 8, 'briefing_schema_drift')
            return status

        def stage_worker(self):
            engine.require(self.worker_commit() == BASELINE, 'unexpected_worker')
            self.worker_files(BASELINE)

        def switch_worker(self):
            raise engine.Refused('worker_switch_not_permitted')

    class Transaction(engine.Transaction):
        def check(self):
            result = super().check()
            if result['phase'] == 'ready':
                engine.require(self.host.cron_plan()['before'] == [BEFORE, THEME], 'baseline_cron_version_mismatch')
            if 'worker_commit' in result:
                result['worker_commit'] = BASELINE
            return result

        def complete(self, state):
            host = self.host
            engine.require(host.inspect_source() == TARGET, 'candidate_application_mismatch')
            status = host.verify(worker=BASELINE, active=True, interrupted=True)
            if state['step'] == 'migration':
                engine.require(status['local_ustar'] in (BEFORE, AFTER), 'unexpected_database_version')
                if not status['maintenance']:
                    host.maintenance(True)
                host.set_services(dict.fromkeys(engine.SERVICES, False))
                if status['local_ustar'] == BEFORE:
                    host.upgrade_database()
                host.purge()
                status = host.verify(worker=BASELINE, active=True)
                engine.require(status['local_ustar'] == AFTER and status['maintenance'], 'moodle_savepoint_not_effective')
                host.commit_cron(state['cron'])
                self.save(state, 'app_ready')
            engine.require(status['local_ustar'] == AFTER and host.cron_plan()['before'] == [AFTER, THEME],
                           'candidate_runtime_mismatch')
            try:
                if status['maintenance']:
                    host.maintenance(False)
                host.smoke()
                host.set_services(state['services'])
                final = host.verify(worker=BASELINE, active=True)
                engine.require(final['local_ustar'] == AFTER and not final['maintenance'], 'candidate_not_released')
                self.save(state, 'active')
            except Exception:
                host.maintenance(True)
                host.set_services(dict.fromkeys(engine.SERVICES, False))
                raise
            return {'phase': 'active', 'application_commit': TARGET, 'worker_commit': BASELINE,
                    'local_ustar': AFTER, 'theme_ustar': THEME, 'services_running': state['services'],
                    'backup': state['backup']['path'], 'database_changed': True}

    engine.Host, engine.Transaction = Host, Transaction
    return engine


def load_package(folder, core, engine, worker):
    checksums = {}
    for line in core.safe_path(folder, 'SHA256SUMS').read_text().splitlines():
        digest, separator, name = line.partition('  ')
        engine.require(separator and engine.path_name(name) and name not in checksums
                       and re.fullmatch('[a-f0-9]{64}', digest), 'invalid_checksum_list')
        path = core.safe_path(folder, name)
        engine.require(path.is_file() and core.digest(path) == digest, 'package_digest_mismatch')
        checksums[name] = digest
    manifest = json.loads(core.safe_path(folder, 'INSTALL.json').read_bytes())
    engine.require(manifest['schema'] == 1 and manifest['target'] == TARGET and manifest['worker_before'] == BASELINE
                   and set(manifest['before']) == {BASELINE} and manifest['local_ustar'] == AFTER
                   and manifest['theme_ustar'] == THEME and set(manifest['worker_identity']) == worker.FILES,
                   'unexpected_manifest_identity')
    before, after = manifest['before'][BASELINE], manifest['after']
    engine.require(all(engine.digest_index(index) for index in (before, after, manifest['theme'])), 'invalid_source_index')
    changed = engine.changes(before, after)
    expected = {'update.py', 'INSTALL.json'} | {'payload/' + name for name in changed}
    engine.require(set(checksums) == expected and all(checksums['payload/' + name] == after[name] for name in changed),
                   'package_inventory_mismatch')
    return manifest, checksums['INSTALL.json']


def main(engine, argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    for mode in ('verify', 'check', 'upgrade', 'recover'):
        modes.add_argument('--' + mode, dest='mode', action='store_const', const=mode)
    parser.add_argument('--acknowledge-outage', action='store_true')
    args = parser.parse_args(argv)
    os.umask(0o077)
    for signum in (signal.SIGTERM, signal.SIGHUP, signal.SIGINT):
        signal.signal(signum, engine.handle_signal)
    core = tx = None
    try:
        core = engine.embedded(CORE_SOURCE, engine.CORE_SHA256, '_ustar_reward_shop_v3')
        worker = engine.embedded(WORKER_INSTALLER, engine.WORKER_INSTALLER_SHA256, '_ustar_connect_worker_installer')
        folder = Path(__file__).absolute().parent
        if args.mode != 'verify':
            engine.require(os.geteuid() == 0, 'root_required')
            worker.safe(folder)
            worker.safe(folder / 'update.py')
        manifest, package = load_package(folder, core, engine, worker)
        if args.mode == 'verify':
            result = {'phase': 'package_verified', 'application_commit': TARGET, 'baseline_commit': BASELINE,
                      'worker_commit': BASELINE, 'payload_files': len(engine.changes(manifest['before'][BASELINE], manifest['after']))}
        else:
            engine.require(args.mode == 'check' or args.acknowledge_outage, 'outage_acknowledgement_required')
            tx = engine.Transaction(engine.Host(folder, manifest, WORKER_UNITS, core, worker), package)
            with core.exclusive_operator_guards():
                assert_briefing_completed(engine)
                result = getattr(tx, args.mode)()
        result.update(schema_changed=False, worker_changed=False)
        print(json.dumps(result, sort_keys=True))
        return 2 if result['phase'] == 'recovery_required' else 0
    except Exception as error:
        reason = str(error)
        if (not isinstance(error, (engine.Refused, core.Refused if core else engine.Refused))
                or not re.fullmatch(r'[a-z0-9_:/.-]{1,160}', reason)):
            reason = 'operation_failed'
        result = {'phase': 'refused', 'reason': reason}
        if tx:
            try:
                state = tx.read()
                if state and state['step'] in (*engine.RUNNABLE_STEPS, 'rolled_back'):
                    result.update(phase='rolled_back' if state['step'] == 'rolled_back' else 'recovery_required',
                                  step=state['step'])
            except Exception:
                pass
        print(json.dumps(result, sort_keys=True))
        return 2


if __name__ == '__main__':
    # The builder embeds the checked, immutable shared transaction engine here.
    import hashlib
    import types

    if FROZEN_SOURCE is None or hashlib.sha256(FROZEN_SOURCE).hexdigest() != (
            '36f50fb7fb80f45b1910852180df6a0459c48d93b29848f7a2db31a31d45d4d5'):
        sys.exit('embedded_transaction_engine_mismatch')
    frozen = types.ModuleType('_ustar_connect_team_frozen')
    exec(compile(FROZEN_SOURCE, '<verified-connect-team-installer>', 'exec'), frozen.__dict__)
    sys.exit(main(configure(frozen, SCHEMA_CHECK)))
