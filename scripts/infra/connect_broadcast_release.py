#!/usr/bin/env python3
"""Apply the direct-broadcast successor using the verified Connect installer."""

import hashlib
from pathlib import Path
import re
import sys
import types


FROZEN_SOURCE = None
CORE_SOURCE = None
WORKER_INSTALLER = None
TARGET = None
FROZEN_COMMIT = '9d77a1485d7fac5c7808fca2022cba3c03833723'
FROZEN_SHA256 = '36f50fb7fb80f45b1910852180df6a0459c48d93b29848f7a2db31a31d45d4d5'
BASELINE = '814a99d9b54196e34c5a2b86529f30e58027da2a'
STATE = Path('/var/lib/ustar-connect-broadcast-20261112')


def successor(source, target, core_source=None, worker_installer=None):
    if source is None or hashlib.sha256(source).hexdigest() != FROZEN_SHA256:
        raise ValueError('frozen_connect_installer_changed')
    if not isinstance(target, str) or target == BASELINE or not re.fullmatch('[a-f0-9]{40}', target):
        raise ValueError('exact_successor_source_required')
    engine = types.ModuleType('_ustar_connect_team_frozen')
    engine.__file__ = __file__
    exec(compile(source, '<verified-connect-team-installer>', 'exec'), engine.__dict__)
    engine.require(engine.TARGET == BASELINE and engine.AFTER == 2026111200, 'unexpected_frozen_baseline')
    engine.BASES = (BASELINE,)
    engine.WORKER_BEFORE = BASELINE
    engine.TARGET = target
    engine.BEFORE, engine.AFTER = 2026111200, 2026111201
    engine.STATE = STATE
    engine.CORE_SOURCE = core_source
    engine.WORKER_INSTALLER = worker_installer

    class Host(engine.Host):
        def verify(self, *, worker, active, interrupted=False):
            # The previous installation opened with announcements disabled.
            # This successor accepts either operator setting throughout the update.
            return super().verify(worker=worker, active=True, interrupted=interrupted)

    engine.Host = Host
    return engine


if __name__ == '__main__':
    sys.exit(successor(FROZEN_SOURCE, TARGET, CORE_SOURCE, WORKER_INSTALLER).main())
