#!/usr/bin/env python3
"""Inventory an unmerged application tree separately from accepted production source."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET
import yaml

ROOT = Path(__file__).resolve().parents[1]


def generate(ref='HEAD'):
    state = yaml.safe_load((ROOT / 'context/roadmap/STATE.yaml').read_text())
    candidate = state.get('development_candidate')
    if not candidate:
        return
    scopes = ['moodle/local/ustar', 'moodle/theme/ustar']
    def git(*args):
        return subprocess.check_output(['git', '-C', str(ROOT), *args])
    names = git('ls-tree', '-r', '--name-only', ref, '--', *scopes).decode().splitlines()
    contents = {p: git('show', ref + ':' + p) for p in names}
    manifest = {
        'kind': 'unmerged_development_source', 'deployed': False,
        'baseline_commit': state['code_baseline']['commit'],
        'component_tree_ids': {p: git('rev-parse', ref + ':' + p).decode().strip() for p in scopes},
        'versions': {key: int(re.search(rb'\$plugin->version\s*=\s*(\d+)',
            contents[path + '/version.php'])[1]) for key, path in
            [('local_ustar', scopes[0]), ('theme_ustar', scopes[1])]},
        'counts': {'files': len(names), 'tables': len(ET.fromstring(
            contents[scopes[0] + '/db/install.xml']).findall('.//TABLE'))},
        'files': [{'path': p, 'sha256': hashlib.sha256(contents[p]).hexdigest()} for p in sorted(names)],
    }
    (ROOT / candidate['manifest']).write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
    print('DEVELOPMENT_MANIFEST files=%s tables=%s; NOT_DEPLOYED' % tuple(manifest['counts'].values()))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--ref', default='HEAD', help='Immutable commit/tree; use staged write-tree locally')
    generate(parser.parse_args().ref)
