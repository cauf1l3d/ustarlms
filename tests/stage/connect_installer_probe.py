#!/usr/bin/env python3
"""Native SQL/controller differential check, only against disposable stage data."""
import importlib.util
from decimal import Decimal
import json
import re
from pathlib import Path
import sys
from types import SimpleNamespace
import xml.etree.ElementTree as ET
from route_access_task_projection import TASK as ROUTE_ACCESS_TASK, project as project_route_task

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('connect_installer_stage', ROOT / 'scripts/infra/connect_academy_installer.py')
x = importlib.util.module_from_spec(spec)
spec.loader.exec_module(x)


def fields(path):
    return {t.attrib['NAME']: {f.attrib['NAME']: {'type': f.attrib['TYPE'],
        'length': f.attrib.get('LENGTH'), 'notnull': f.attrib.get('NOTNULL') == 'true'}
        for f in t.findall('./FIELDS/FIELD')}
        for t in ET.parse(path).findall('.//TABLE')}


if sys.argv[1:] == ['prepare']:
    target = ROOT / '.stage-input/connect-installer.sql'
    if not target.parent.is_dir():
        raise RuntimeError('stage_input_missing')
    target.write_text(x.db_query('stage_') + '\n')
elif sys.argv[1:] == ['verify']:
    folder = ROOT / 'artifacts'
    before, candidate, repeat, restored = [json.loads((folder / ('connect-installer-' + phase + '.json')).read_text())
        for phase in ('baseline', 'candidate', 'repeat', 'restored')]
    if Decimal(before['core']) != Decimal(x.CORE) or 'local_ustar_conn_links' in before['tables']:
        raise RuntimeError('wrong_fixture')
    baseline_fields = fields(ROOT / '.stage-input/connect-baseline/moodle/local/ustar/db/install.xml')
    candidate_fields = fields(ROOT / 'moodle/local/ustar/db/install.xml')
    def source_version(scope):
        value = (ROOT / 'moodle' / scope / 'version.php').read_text()
        matches = re.findall(r'(?m)^\s*\$plugin->version\s*=\s*([0-9]{10})\s*;', value)
        if len(matches) != 1:
            raise RuntimeError('candidate_version_ambiguous')
        return int(matches[0])
    d = SimpleNamespace(reference={'baseline': {'tables': sorted(baseline_fields)},
        'candidate': {'tables': sorted(candidate_fields), 'versions': {
            'local_ustar': source_version('local/ustar'), 'theme_ustar': source_version('theme/ustar')}}}, schema_fields=candidate_fields)
    x.verify_versions(d, before, before, False)
    if ROUTE_ACCESS_TASK in before['tasks'] or ROUTE_ACCESS_TASK in restored['tasks']:
        raise RuntimeError('route_access_task_in_legacy_fixture')
    # The old operator remains frozen. Check the one declared successor task
    # independently, then exercise its original exact task contract unchanged.
    x.verify_versions(d, project_route_task(candidate), before, True)
    x.verify_versions(d, project_route_task(repeat), before, True)
    if (candidate['schema'], candidate['indexes']) != (repeat['schema'], repeat['indexes']):
        raise RuntimeError('native_repeat_changed')
    x.verify_versions(d, restored, before, False)
    print('CONNECT_INSTALLER_NATIVE_SQL_VERSIONS_TASKS_COLUMNS_INDEXES=PASS')
    print('ROUTE_ACCESS_TASK_FLAGS_VERIFIED_SEPARATELY=PASS legacy_validator_unchanged=true')
else:
    raise RuntimeError('fixture_command_required')
