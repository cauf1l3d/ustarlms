#!/usr/bin/env python3
"""Regression test for the Moodle www-data permissions of new briefing source dirs."""
import os
from pathlib import Path
import stat
import sys
import tempfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts' / 'infra'))
import briefing_release


class Engine:
    @staticmethod
    def require(condition, reason):
        if not condition:
            raise AssertionError(reason)

    @staticmethod
    def sync_directory(path):
        fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)


old_umask = os.umask(0o077)
try:
    with tempfile.TemporaryDirectory(prefix='ustar-briefing-mode-') as tmp:
        parent = Path(tmp) / 'classes'
        parent.mkdir(mode=0o755)
        target = parent / 'briefing'
        briefing_release.ensure_briefing_directory(target, Engine)
        actual = stat.S_IMODE(target.lstat().st_mode)
        if actual != 0o755:
            raise AssertionError(f'expected 0755, got {actual:04o}')
        # Existing directory must remain accepted without widening/replacing it.
        briefing_release.ensure_briefing_directory(target, Engine)
        if stat.S_IMODE(target.lstat().st_mode) != 0o755:
            raise AssertionError('existing briefing directory mode changed')
finally:
    os.umask(old_umask)

print('BRIEFING_DIRECTORY_MODE=OK')

# The previous production attempt is reusable only after a verified rollback.
with tempfile.TemporaryDirectory(prefix='ustar-briefing-journal-') as tmp:
    journal = Path(tmp) / 'previous'
    journal.mkdir(mode=0o700)
    state_path = journal / 'state.json'
    state_path.write_text(__import__('json').dumps({
        'package': briefing_release.PREVIOUS_PACKAGE,
        'baseline': briefing_release.BASELINE,
        'step': 'rolled_back',
    }))
    state_path.chmod(0o600)
    previous = briefing_release.PREVIOUS_STATE
    try:
        briefing_release.PREVIOUS_STATE = journal
        briefing_release.validate_previous_attempt(Engine)
        state_path.write_text(__import__('json').dumps({
            'package': briefing_release.PREVIOUS_PACKAGE,
            'baseline': briefing_release.BASELINE,
            'step': 'prepared',
        }))
        state_path.chmod(0o600)
        try:
            briefing_release.validate_previous_attempt(Engine)
        except AssertionError as error:
            if str(error) != 'previous_attempt_not_rolled_back':
                raise
        else:
            raise AssertionError('unfinished previous transaction was accepted')
    finally:
        briefing_release.PREVIOUS_STATE = previous
