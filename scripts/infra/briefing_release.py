#!/usr/bin/env python3
"""Install the combined Telegram operator, briefing and Kanban successor.

The accepted starting point is the qualified Connect operator release 422fbfb3.
All writes use the already-qualified source/worker/cron transaction engine.
"""
import hashlib
import json
import os
from pathlib import Path
import stat
import sys
import types

FROZEN_SOURCE = None
OPERATOR_SOURCE = None
BROADCAST_SOURCE = None
CORE_SOURCE = None
WORKER_INSTALLER = None
TARGET = None

BASELINE = '422fbfb324db3b18447b61bc1c86a8a5481fe3e0'
OPERATOR_BASELINE = '33a8436b79c7dce453f10bb54b30d1d1721aab57'
OPERATOR_COMMIT = 'c13da3fb383650994c7d20268d72854c70d7767f'
OPERATOR_SHA256 = 'cb543470f1210e2dfdc6d4b16d4a2e9efaf3572c0880ea9a9204350b903cdf96'
BROADCAST_COMMIT = 'eff21cae9e932bc7649827cdda62910de8a40e4a'
PREVIOUS_STATE = Path('/var/lib/ustar-task-briefing-20261117')
PREVIOUS_PACKAGE = 'eb438c8b00270e3db8df36a4efb43529053401936f0da8ae9c661d73a3038bb4'
STATE = Path('/var/lib/ustar-task-briefing-20261117-v2')
NEW_DIRECTORY = 'classes/briefing'


def ensure_briefing_directory(folder, engine):
    """Create the new Moodle namespace with explicit traverse/read permissions."""
    engine.require(not folder.is_symlink(), 'unsafe_briefing_directory')
    if not folder.exists():
        folder.mkdir(mode=0o755)
        engine.require(folder.is_dir() and not folder.is_symlink(),
                       'briefing_directory_creation_failed')
        # main() sets umask(077), which masks mkdir(0755) to 0700.
        # www-data must traverse this directory to lint/load the new classes.
        os.chmod(folder, 0o755, follow_symlinks=False)
        engine.require(stat.S_IMODE(folder.lstat().st_mode) == 0o755,
                       'briefing_directory_permissions_invalid')
        engine.sync_directory(folder.parent)
    else:
        engine.require(folder.is_dir() and not folder.is_symlink(),
                       'unexpected_briefing_directory')
    return folder


def validate_previous_attempt(engine):
    """Never bypass an unfinished transaction when rotating the journal path."""
    if not os.path.lexists(PREVIOUS_STATE):
        return
    owner = os.geteuid()
    directory = PREVIOUS_STATE.lstat()
    engine.require(stat.S_ISDIR(directory.st_mode) and directory.st_uid == owner
                   and stat.S_IMODE(directory.st_mode) == 0o700,
                   'previous_attempt_journal_directory_unsafe')
    path = PREVIOUS_STATE / 'state.json'
    if not os.path.lexists(path):
        engine.require(False, 'previous_attempt_journal_missing')
    status = path.lstat()
    engine.require(stat.S_ISREG(status.st_mode) and status.st_uid == owner and status.st_nlink == 1
                   and stat.S_IMODE(status.st_mode) == 0o600 and status.st_size < 65536,
                   'previous_attempt_journal_file_unsafe')
    try:
        state = json.loads(path.read_bytes())
    except (OSError, ValueError):
        engine.require(False, 'previous_attempt_journal_invalid')
    engine.require(state.get('package') == PREVIOUS_PACKAGE
                   and state.get('baseline') == BASELINE
                   and state.get('step') == 'rolled_back',
                   'previous_attempt_not_rolled_back')


def successor(source, target, core_source=None, worker_installer=None,
              *, operator_source=None, broadcast_source=None):
    raw = OPERATOR_SOURCE if operator_source is None else operator_source
    broadcast = BROADCAST_SOURCE if broadcast_source is None else broadcast_source
    if raw is None or hashlib.sha256(raw).hexdigest() != OPERATOR_SHA256:
        raise ValueError('frozen_operator_installer_changed')
    operator = types.ModuleType('_ustar_operator_frozen')
    operator.__file__ = __file__
    exec(compile(raw, '<verified-operator-installer>', 'exec'), operator.__dict__)
    engine = operator.successor(source, target, core_source, worker_installer,
                                predecessor_source=broadcast)
    validate_previous_attempt(engine)
    engine.require(engine.BASES == (OPERATOR_BASELINE,) and engine.BEFORE == 2026111401
                   and engine.AFTER == 2026111402, 'unexpected_briefing_baseline')
    # The operator installation is already active on server1. Retain its exact
    # application and worker commit as the verified rollback/source baseline.
    engine.BASES = (BASELINE,)
    engine.WORKER_BEFORE = BASELINE
    engine.BEFORE, engine.AFTER = 2026111402, 2026111700
    engine.STATE = STATE

    class Host(engine.Host):
        """Support precisely one new source directory with strict ancestry checks."""
        def _briefing_directory(self):
            classes = self.core.safe_path(self.app, 'classes')
            engine.require(classes.is_dir() and not classes.is_symlink(),
                           'unexpected_classes_directory')
            folder = self.core.safe_path(self.app, NEW_DIRECTORY)
            return ensure_briefing_directory(folder, engine)

        def install_source(self, state):
            folder = self._briefing_directory()
            if not folder.exists():
                folder.mkdir(mode=0o755)
                engine.require(folder.is_dir() and not folder.is_symlink(),
                               'briefing_directory_creation_failed')
                # The release runs with umask 077; mkdir(mode=0755) therefore
                # creates 0700. Moodle's www-data PHP CLI must traverse this
                # new namespace before it can lint any candidate PHP file.
                os.chmod(folder, 0o755, follow_symlinks=False)
                engine.require(stat.S_IMODE(folder.lstat().st_mode) == 0o755,
                               'briefing_directory_permissions_invalid')
                engine.sync_directory(folder.parent)
            else:
                engine.require(folder.is_dir() and not folder.is_symlink(),
                               'unexpected_briefing_directory')
            return super().install_source(state)

        def restore_source(self, state):
            result = super().restore_source(state)
            folder = self._briefing_directory()
            if folder.exists():
                engine.require(folder.is_dir() and not folder.is_symlink(),
                               'unsafe_rollback_directory')
                folder.rmdir()
                engine.sync_directory(folder.parent)
            return result

    engine.Host = Host
    return engine


if __name__ == '__main__':
    sys.exit(successor(FROZEN_SOURCE, TARGET, CORE_SOURCE, WORKER_INSTALLER).main())
