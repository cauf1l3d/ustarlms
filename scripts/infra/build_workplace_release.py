#!/usr/bin/env python3
"""Build the Workplace installer from the qualified application and briefing Git objects."""

import argparse
import json
from pathlib import Path
import re
import tempfile
import xml.etree.ElementTree as ET

from briefing_review_release import SCHEMA_CHECK
from build_connect_broadcast_release import assemble, git_file
from connect_broadcast_release import FROZEN_COMMIT, successor
import workplace_release as release

OPERATOR = 'scripts/infra/workplace_release.py'


def build(repo, output):
    target, baseline = release.TARGET, release.BASELINE
    source = git_file(repo, FROZEN_COMMIT, 'scripts/infra/connect_team_release.py')
    engine = successor(source, target)
    core_source = git_file(repo, engine.CORE_COMMIT, 'scripts/infra/reward_shop_release.py')
    worker_source = git_file(repo, baseline, 'connect-worker/deploy/install.py')
    core = engine.embedded(core_source, engine.CORE_SHA256, '_ustar_reward_shop_v3')
    worker = engine.embedded(worker_source, engine.WORKER_INSTALLER_SHA256, '_ustar_connect_worker_installer')
    need = engine.require
    need(core.git(repo, 'merge-base', baseline, target) == baseline, 'wrong_application_ancestry')
    need(not core.git(repo, 'diff', '--name-only', baseline, target, '--', 'connect-worker'), 'unexpected_worker_delta')
    operator = git_file(repo, 'HEAD', OPERATOR)
    need(operator == Path(release.__file__).read_bytes(), 'operator_not_committed')
    need(git_file(repo, 'HEAD', 'scripts/infra/briefing_review_release.py') ==
         git_file(repo, baseline, 'scripts/infra/briefing_review_release.py'), 'briefing_schema_probe_drift')

    with tempfile.TemporaryDirectory(prefix='ustar-workplace-build-') as temporary:
        stage = Path(temporary)
        old, new = stage / 'before', stage / 'after'
        core.archive(repo, baseline, old)
        core.archive(repo, target, new)
        before, after = core.scan(old / core.APP_REL), core.scan(new / core.APP_REL)
        theme = core.scan(old / core.THEME_REL)
        need(theme == core.scan(new / core.THEME_REL), 'theme_changed')
        old_xml = ET.parse(old / core.APP_REL / 'db/install.xml').getroot()
        new_xml = ET.parse(new / core.APP_REL / 'db/install.xml').getroot()
        new_xml.set('VERSION', old_xml.get('VERSION'))
        need(ET.canonicalize(ET.tostring(old_xml), strip_text=True) ==
             ET.canonicalize(ET.tostring(new_xml), strip_text=True), 'unexpected_schema_change')
        for root, version in ((old, release.BEFORE), (new, release.AFTER)):
            declared = (root / core.APP_REL / 'version.php').read_text()
            match = re.search(r'\$plugin->version\s*=\s*(\d+)', declared)
            need(match and int(match[1]) == version, 'application_version_mismatch')
        delta = engine.changes(before, after)
        payload = {}
        for name in delta:
            path = new / core.APP_REL / name
            need((old / core.APP_REL / name).parent.is_dir(), 'new_source_directory_unsupported')
            if name.endswith('.php'):
                core.run(['php', '-l', str(path)], timeout=20)
            payload['payload/' + name] = path.read_bytes()

        worker_builder = 'connect-worker/deploy/build_release.py'
        need((repo / worker_builder).read_bytes() == git_file(repo, baseline, worker_builder), 'worker_builder_drift')
        core.run(['python3', '-I', '-B', str(repo / worker_builder), '--commit', baseline, '--output-dir', str(stage)], timeout=120)
        archive = stage / f'ustar-connect-receiver-{baseline[:8]}.tar.gz'
        commit, values = worker.validate_archive(archive, core.digest(archive))
        need(commit == baseline, 'unexpected_worker_commit')
        identity = engine.worker_identity(values)
        units = {name: values[name] for name in sorted(values) if name.startswith('units/')}

    manifest = {'schema': 1, 'target': target, 'before': {baseline: before}, 'after': after, 'theme': theme,
                'worker_before': baseline, 'worker_identity': identity, 'local_ustar': release.AFTER,
                'theme_ustar': release.THEME}
    for name, value in {'FROZEN_SOURCE': source, 'CORE_SOURCE': core_source, 'WORKER_INSTALLER': worker_source,
                        'WORKER_UNITS': units, 'SCHEMA_CHECK': SCHEMA_CHECK}.items():
        marker = ('^' + name + ' = None$').encode()
        operator, count = re.subn(marker, lambda _: (name + ' = ' + repr(value)).encode(), operator, flags=re.MULTILINE)
        need(count == 1, 'invalid_bootstrap_marker')
    compile(operator, '<workplace-operator>', 'exec')
    files = {'update.py': operator, 'INSTALL.json': engine.canonical(manifest), **payload}
    files['SHA256SUMS'] = ''.join(f'{engine.sha(raw)}  {name}\n' for name, raw in sorted(files.items())).encode()
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f'ustar-workplace-{target[:8]}.tar.gz'
    assemble(archive, files, engine)
    return {'archive': str(archive), 'sha256': core.digest(archive), 'members': len(files),
            'application_files': len(delta), 'application_commit': target, 'baseline_commit': baseline,
            'worker_commit': baseline}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.repo.resolve(), args.output), sort_keys=True))
