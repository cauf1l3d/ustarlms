#!/usr/bin/env python3
"""Export the immutable CON-02 source profile; plain-file packaging is separate."""
import contextlib
import io
import json
from pathlib import Path
import re
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from connect_con02_contract import (APP_COMMIT, BASELINE_COMMIT, LEGACY_COMMIT, OVERLAY_SHA,
                                    EmbeddedBundle, require, sha)

ROOT = Path(__file__).resolve().parents[2]


def reference_from_git(repo, tool_commit):
    repo = Path(repo)
    require(re.fullmatch('[a-f0-9]{40}', tool_commit), 'exact_tool_commit_required')
    def git(*args):
        return subprocess.check_output(['git', '-C', str(repo), *args], stderr=subprocess.DEVNULL, timeout=30)
    require(git('rev-parse', '--verify', tool_commit + '^{commit}').decode().strip() == tool_commit,
            'exact_tool_commit_required')
    def export(commit, roots):
        result = {}
        for entry in git('ls-tree', '-r', '-z', commit, '--', *roots).split(b'\0'):
            if not entry: continue
            meta, path = entry.split(b'\t', 1)
            mode, kind, oid = meta.decode().split()
            require(kind == 'blob' and mode in ('100644', '100755'), 'regular_git_source_required')
            result[path.decode()] = git('cat-file', 'blob', oid)
        return result
    def release(commit):
        raw = {k.removeprefix('moodle/'): v for k, v in export(commit, ['moodle/local/ustar', 'moodle/theme/ustar']).items()}
        fields, indexes = {}, {}
        for table in ET.fromstring(raw['local/ustar/db/install.xml']).findall('.//TABLE'):
            name = table.attrib['NAME']
            fields[name] = {f.attrib['NAME']: {'type': f.attrib['TYPE'], 'length': f.attrib.get('LENGTH'),
                'notnull': f.attrib.get('NOTNULL') == 'true'} for f in table.findall('./FIELDS/FIELD')}
            indexes[name] = [{'fields': k.attrib['FIELDS'].split(','),
                'unique': k.attrib.get('TYPE') in ('primary', 'unique') or k.attrib.get('UNIQUE') == 'true'}
                for k in [*table.findall('./KEYS/KEY'), *table.findall('./INDEXES/INDEX')]
                if k.attrib.get('TYPE') != 'foreign']
        versions = {}
        for key, scope in [('local_ustar', 'local/ustar'), ('theme_ustar', 'theme/ustar')]:
            found = re.findall(rb'(?m)^\s*\$plugin->version\s*=\s*([0-9]{10})\s*;', raw[scope + '/version.php'])
            require(len(found) == 1, 'release_version')
            versions[key] = int(found[0])
        return {'files': {k: sha(v) for k, v in sorted(raw.items())}, 'tables': sorted(fields),
                'versions': versions, 'schema_fields': fields, 'schema_indexes': indexes}, raw
    baseline, _ = release(BASELINE_COMMIT)
    overlay = git('show', LEGACY_COMMIT + ':moodle/local/ustar/classes/connect/notification_delivery.php')
    require(sha(overlay) == OVERLAY_SHA, 'accepted_policy_overlay')
    baseline['files']['local/ustar/classes/connect/notification_delivery.php'] = OVERLAY_SHA
    candidate, payload = release(APP_COMMIT)
    require((len(baseline['files']), len(candidate['files']), len(baseline['tables']), len(candidate['tables']))
            == (552, 557, 99, 100), 'frozen_release_counts')
    ref = {'schema': 1, 'tool_commit': tool_commit, 'app_commit': APP_COMMIT, 'worker_commit': APP_COMMIT,
           'baseline_commit': BASELINE_COMMIT, 'legacy_commit': LEGACY_COMMIT,
           'baseline': baseline, 'candidate': candidate}
    # Only frozen builder source executes, with ROOT explicitly bound to this Git store.
    source = git('show', APP_COMMIT + ':connect-worker/deploy/build_release.py')
    namespace = {'__name__': '_frozen_worker_builder', '__file__': str(repo / 'connect-worker/deploy/build_release.py')}
    exec(compile(source, namespace['__file__'], 'exec'), namespace)
    namespace['ROOT'] = repo
    with tempfile.TemporaryDirectory() as td, contextlib.redirect_stdout(io.StringIO()):
        worker = namespace['build'](APP_COMMIT, td).read_bytes()
    legacy = export(LEGACY_COMMIT, ['scripts/infra/connect_notification_pilot.py',
        'scripts/infra/connect_bridge_activation.py', 'scripts/infra/connect_private_bridge.py',
        'scripts/infra/academy_https.py', 'connect-worker/deploy/install.py'])
    return EmbeddedBundle(ref, {}, payload, worker, legacy)
