#!/usr/bin/env python3
"""Coordinate the task widget release over the qualified checklist widget."""
import hashlib
from pathlib import Path
import re
import sys
import types

FROZEN_SOURCE = None
PREDECESSOR_SOURCE = None
CHECKLIST_SOURCE = None
CORE_SOURCE = None
WORKER_INSTALLER = None
TARGET = None
BASELINE = '5607ab6fca13f744b1157db0c03b0adc27f30c7f'
CHECKLIST_SHA256 = 'e7295c5f64988cdb652d52126d4e599990e99fcde04ae066fa4f495dce236976'
STATE = Path('/var/lib/ustar-connect-task-widget-20261114')


def successor(source, target, core_source=None, worker_installer=None, *, predecessor_source=None, checklist_source=None):
    raw = CHECKLIST_SOURCE if checklist_source is None else checklist_source
    if raw is None or hashlib.sha256(raw).hexdigest() != CHECKLIST_SHA256:
        raise ValueError('frozen_checklist_installer_changed')
    if not isinstance(target, str) or target == BASELINE or not re.fullmatch('[a-f0-9]{40}', target):
        raise ValueError('exact_successor_source_required')
    checklist = types.ModuleType('_ustar_checklist_widget_frozen')
    checklist.__file__ = __file__
    exec(compile(raw, '<verified-checklist-installer>', 'exec'), checklist.__dict__)
    previous = PREDECESSOR_SOURCE if predecessor_source is None else predecessor_source
    engine = checklist.successor(source, BASELINE, core_source, worker_installer, predecessor_source=previous)
    engine.require(engine.TARGET == BASELINE and engine.AFTER == 2026111301, 'unexpected_checklist_baseline')
    engine.BASES = (BASELINE,)
    engine.WORKER_BEFORE, engine.TARGET = BASELINE, target
    engine.BEFORE, engine.AFTER = 2026111301, 2026111400
    engine.STATE = STATE
    return engine


if __name__ == '__main__':
    sys.exit(successor(FROZEN_SOURCE, TARGET, CORE_SOURCE, WORKER_INSTALLER).main())
