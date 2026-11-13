#!/usr/bin/env python3
"""Install the qualified successor over the direct-broadcast Academy and worker."""
import hashlib
import io
import json
from pathlib import Path
import re
import sys
import types
from contextlib import redirect_stdout


FROZEN_SOURCE = None
PREDECESSOR_SOURCE = None
CORE_SOURCE = None
WORKER_INSTALLER = None
TARGET = None
BASELINE = 'd1e546240e9339dd5f3263ca567ed9def64cc478'
PREDECESSOR_COMMIT = 'eff21cae9e932bc7649827cdda62910de8a40e4a'
PREDECESSOR_SHA256 = 'e33ee8cb5662a7280d26690efed98fdb0a0861a2ff0d15b397ad09321b19677a'
STATE = Path('/var/lib/ustar-connect-delivery-repair-20261113')


def successor(source, target, core_source=None, worker_installer=None, *, predecessor_source=None):
    predecessor_source = PREDECESSOR_SOURCE if predecessor_source is None else predecessor_source
    if predecessor_source is None or hashlib.sha256(predecessor_source).hexdigest() != PREDECESSOR_SHA256:
        raise ValueError('frozen_broadcast_installer_changed')
    if not isinstance(target, str) or target == BASELINE or not re.fullmatch('[a-f0-9]{40}', target):
        raise ValueError('exact_successor_source_required')
    previous = types.ModuleType('_ustar_broadcast_frozen')
    previous.__file__ = __file__
    exec(compile(predecessor_source, '<verified-broadcast-installer>', 'exec'), previous.__dict__)
    engine = previous.successor(source, BASELINE, core_source, worker_installer)
    engine.require(engine.TARGET == BASELINE and engine.AFTER == 2026111201, 'unexpected_frozen_baseline')
    engine.BASES = (BASELINE,)
    engine.WORKER_BEFORE, engine.TARGET = BASELINE, target
    engine.BEFORE, engine.AFTER = 2026111201, 2026111300
    engine.STATE = STATE
    return engine


def main():
    engine = successor(FROZEN_SOURCE, TARGET, CORE_SOURCE, WORKER_INSTALLER)
    output = io.StringIO()
    with redirect_stdout(output):
        status = engine.main()
    result = json.loads(output.getvalue())
    if '--upgrade' in sys.argv or '--recover' in sys.argv:
        result['scheduled_delivery_times_rechecked'] = (
            True if result.get('database_changed') else None
        )
    print(json.dumps(result, sort_keys=True))
    return status


if __name__ == '__main__':
    sys.exit(main())
