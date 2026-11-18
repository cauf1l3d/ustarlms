#!/usr/bin/env python3
"""Install one briefing update over either exact qualified production baseline."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import stat
import sys
import types

FROZEN_SOURCE = None
OPERATOR_SOURCE = None
BROADCAST_SOURCE = None
BRIEFING_SOURCE = None
CORE_SOURCE = None
WORKER_INSTALLER = None
TARGET = None

OPERATOR = '422fbfb324db3b18447b61bc1c86a8a5481fe3e0'
BRIEFING = 'b1df4f2b234b6b79260eb3bbd2423fc0daf7ef79'
BRIEFING_SHA256 = '962cab0313278e9f91eec6e006a7a1f3d0a6afc5c69b2a87363e229db624814f'
VERSIONS = {OPERATOR: 2026111402, BRIEFING: 2026111700}
AFTER = 2026111800
STATE = Path('/var/lib/ustar-task-briefing-20261118')
BRIEFING_PACKAGE = '29ffe2a7215033560c3a9c46a3a667f2fa3279f8a1cfd00e8b1f05fa87c19c99'
SCHEMA_CHECK = '''
define("CLI_SCRIPT", true);
require "/var/www/html/config.php";
$dbman = $DB->get_manager();
$tables = [];
foreach (["local_ustar_brief_templates", "local_ustar_brief_reports", "local_ustar_brief_minutes",
          "local_ustar_meeting_rooms", "local_ustar_meetings", "local_ustar_meeting_members"] as $name) {
    $tables[] = $dbman->table_exists(new xmldb_table($name));
}
$meta = new xmldb_table("local_ustar_task_meta");
$field = $dbman->field_exists($meta, "briefkey");
$index = $dbman->index_exists($meta, new xmldb_index("briefrequest", XMLDB_INDEX_UNIQUE, ["briefkey"]));
echo json_encode([...$tables, $field, $index], JSON_THROW_ON_ERROR);
'''


def prior_briefing_state(engine, folder):
    if not os.path.lexists(folder):
        return None
    directory = folder.lstat()
    owner = os.geteuid()
    engine.require(stat.S_ISDIR(directory.st_mode) and directory.st_uid == owner
                   and stat.S_IMODE(directory.st_mode) == 0o700, 'unsafe_prior_briefing_journal_directory')
    path = folder / 'state.json'
    engine.require(os.path.lexists(path), 'missing_prior_briefing_journal')
    status = path.lstat()
    engine.require(stat.S_ISREG(status.st_mode) and status.st_uid == owner and status.st_nlink == 1
                   and stat.S_IMODE(status.st_mode) == 0o600 and status.st_size < 65536,
                   'unsafe_prior_briefing_journal')
    try:
        state = json.loads(path.read_bytes())
    except (OSError, ValueError):
        raise engine.Refused('invalid_prior_briefing_journal') from None
    engine.require(isinstance(state, dict) and state.get('package') == BRIEFING_PACKAGE
                   and state.get('baseline') == OPERATOR and state.get('step') in ('active', 'rolled_back'),
                   'prior_briefing_recovery_required')
    return state['step']


def successor(source, target, core_source=None, worker_installer=None, *, operator_source=None,
              broadcast_source=None, briefing_source=None):
    raw = BRIEFING_SOURCE if briefing_source is None else briefing_source
    if raw is None or hashlib.sha256(raw).hexdigest() != BRIEFING_SHA256:
        raise ValueError('frozen_briefing_installer_changed')
    previous = types.ModuleType('_ustar_briefing_frozen')
    previous.__file__ = __file__
    exec(compile(raw, '<verified-briefing-installer>', 'exec'), previous.__dict__)
    # The same journal is checked under the shared deployment locks in select_baseline.
    validate_failed_attempt = previous.validate_previous_attempt
    previous.validate_previous_attempt = lambda engine: None
    engine = previous.successor(
        source, target, core_source, worker_installer,
        operator_source=OPERATOR_SOURCE if operator_source is None else operator_source,
        broadcast_source=BROADCAST_SOURCE if broadcast_source is None else broadcast_source,
    )
    engine.require(engine.BASES == (OPERATOR,) and engine.BEFORE == VERSIONS[OPERATOR]
                   and engine.AFTER == VERSIONS[BRIEFING], 'unexpected_briefing_predecessor')
    engine.BASES, engine.AFTER, engine.STATE = tuple(VERSIONS), AFTER, STATE
    load = engine.load_package

    def load_package(folder, core, worker):
        manifest, values, package = load(folder, core, worker)
        compatibility = manifest.get('compatibility', {})
        engine.require(set(compatibility) == {BRIEFING}, 'unexpected_compatible_baseline')
        alternate = compatibility[BRIEFING]
        engine.require(set(alternate) == {'local_ustar', 'worker_identity'}
                       and alternate['local_ustar'] == VERSIONS[BRIEFING]
                       and set(alternate['worker_identity']) == worker.FILES, 'invalid_compatible_baseline')
        return manifest, values, package

    class Host(engine.Host.__base__):
        resume_intermediate = False

        def briefing_directory(self):
            folder = self.core.safe_path(self.app, previous.NEW_DIRECTORY)
            if os.path.lexists(folder):
                engine.require(folder.is_dir() and not folder.is_symlink() and stat.S_IMODE(folder.stat().st_mode) == 0o755,
                               'unsafe_existing_briefing_directory')
            return folder

        def backup(self, baseline):
            folder = self.briefing_directory()
            return {**super().backup(baseline), 'briefing_directory_present': folder.exists()}

        def install_source(self, state):
            previous.ensure_briefing_directory(self.briefing_directory(), engine)
            return super().install_source(state)

        def restore_source(self, state):
            super().restore_source(state)
            folder = self.briefing_directory()
            if folder.exists() and not state['backup']['briefing_directory_present']:
                folder.rmdir()
                engine.sync_directory(folder.parent)

        def verify(self, *, worker, active, interrupted=False):
            status = super().verify(worker=worker, active=active, interrupted=interrupted)
            version = status['local_ustar']
            if version >= VERSIONS[BRIEFING] or not interrupted:
                schema = json.loads(self.php(['-r', SCHEMA_CHECK], 'briefing_schema_check_failed'))
                engine.require(schema == [version >= VERSIONS[BRIEFING]] * 8, 'briefing_schema_drift')
            if self.resume_intermediate and interrupted and version == VERSIONS[BRIEFING] and worker == OPERATOR:
                # The immutable engine retries Moodle CLI from BEFORE. The intervening savepoint is
                # also resumable when the source is already at TARGET and the worker is still at OPERATOR.
                status['local_ustar'] = VERSIONS[OPERATOR]
            return status

    class Transaction(engine.Transaction):
        baseline = None

        def select_baseline(self):
            validate_failed_attempt(engine)
            previous_phase = prior_briefing_state(engine, previous.STATE)
            state = self.read()
            self.baseline = state['baseline'] if state else self.host.inspect_source()
            engine.require(self.baseline in VERSIONS, 'unqualified_application_baseline')
            if previous_phase:
                expected = BRIEFING if previous_phase == 'active' else OPERATOR
                engine.require(self.baseline == expected, 'prior_briefing_journal_source_mismatch')
            engine.WORKER_BEFORE, engine.BEFORE = self.baseline, VERSIONS[self.baseline]
            if self.baseline == BRIEFING:
                self.host.manifest = {**self.host.manifest,
                                      'worker_identity': self.host.manifest['compatibility'][BRIEFING]['worker_identity']}

        def check(self):
            result = super().check()
            if result['phase'] == 'ready':
                engine.require(result['application_commit'] == self.baseline
                               and self.host.cron_plan()['before'] == [VERSIONS[self.baseline], engine.THEME],
                               'baseline_or_cron_version_mismatch')
                self.host.briefing_directory()
            return result

        def complete(self, state):
            self.host.resume_intermediate = self.baseline == OPERATOR and state['step'] == 'migration'
            try:
                return super().complete(state)
            finally:
                self.host.resume_intermediate = False

    engine.Host, engine.Transaction, engine.load_package = Host, Transaction, load_package
    return engine


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    for mode in ('verify', 'check', 'upgrade', 'recover'):
        modes.add_argument('--' + mode, dest='mode', action='store_const', const=mode)
    parser.add_argument('--acknowledge-outage', action='store_true')
    args = parser.parse_args(argv)
    engine = successor(FROZEN_SOURCE, TARGET, CORE_SOURCE, WORKER_INSTALLER)
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
        manifest, values, package = engine.load_package(folder, core, worker)
        if args.mode == 'verify':
            result = {'phase': 'package_verified', 'application_commit': TARGET, 'worker_commit': TARGET,
                      'baselines': VERSIONS}
        else:
            engine.require(args.mode == 'check' or args.acknowledge_outage, 'outage_acknowledgement_required')
            tx = engine.Transaction(engine.Host(folder, manifest, values, core, worker), package)
            with core.exclusive_operator_guards():
                tx.select_baseline()
                result = getattr(tx, args.mode)()
            result.update(schema_changed=bool(result.get('database_changed') and tx.baseline == OPERATOR),
                          business_data_changed=False)
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
    sys.exit(main())
