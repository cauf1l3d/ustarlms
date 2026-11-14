#!/usr/bin/env python3
"""Coordinate the checklist widget release over the accepted direct-delivery repair."""
import hashlib
from pathlib import Path
import sys
import types

FROZEN_SOURCE = None
PREDECESSOR_SOURCE = None
CORE_SOURCE = None
WORKER_INSTALLER = None
TARGET = None
BASELINE = '7fa3233f3016eb968b7fadd3ae410ec330186b93'
PREDECESSOR_COMMIT = 'eff21cae9e932bc7649827cdda62910de8a40e4a'
PREDECESSOR_SHA256 = 'e33ee8cb5662a7280d26690efed98fdb0a0861a2ff0d15b397ad09321b19677a'
STATE = Path('/var/lib/ustar-connect-checklist-widget-20261113')


def successor(source, target, core_source=None, worker_installer=None, *, predecessor_source=None):
    raw = PREDECESSOR_SOURCE if predecessor_source is None else predecessor_source
    if raw is None or hashlib.sha256(raw).hexdigest() != PREDECESSOR_SHA256:
        raise ValueError('frozen_broadcast_installer_changed')
    previous = types.ModuleType('_ustar_broadcast_frozen')
    previous.__file__ = __file__
    exec(compile(raw, '<verified-broadcast-installer>', 'exec'), previous.__dict__)
    engine = previous.successor(source, target, core_source, worker_installer)
    engine.require(target != BASELINE and engine.AFTER == 2026111201, 'unexpected_widget_release')
    engine.BASES = (BASELINE,)
    engine.WORKER_BEFORE = BASELINE
    engine.BEFORE, engine.AFTER = 2026111300, 2026111301
    engine.STATE = STATE
    return engine


if __name__ == '__main__':
    sys.exit(successor(FROZEN_SOURCE, TARGET, CORE_SOURCE, WORKER_INSTALLER).main())
