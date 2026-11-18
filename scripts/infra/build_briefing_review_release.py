#!/usr/bin/env python3
"""Build the reviewed briefing and Kanban delivery for both qualified baselines."""
import argparse
import json
from pathlib import Path
import xml.etree.ElementTree as ET

import briefing_release as previous
import briefing_review_release as release
from build_briefing_release import verify_schema
from build_connect_broadcast_release import build_successor, git_file


def build(repo: Path, target: str, output: Path):
    schema_path = 'moodle/local/ustar/db/install.xml'
    older = ET.fromstring(git_file(repo, release.BRIEFING, schema_path))
    candidate = ET.fromstring(git_file(repo, target, schema_path))
    candidate.set('VERSION', older.get('VERSION'))
    if ET.canonicalize(ET.tostring(older), strip_text=True) != ET.canonicalize(ET.tostring(candidate), strip_text=True):
        raise ValueError('unexpected_second_schema_change')
    return build_successor(
        repo, target, output, release, 'scripts/infra/briefing_review_release.py',
        {'bot_cards.py', 'notification_events.py'}, 'ustar-briefing-review',
        schema_check=verify_schema, new_subdirs=('classes/briefing',),
        compatible_baselines={release.BRIEFING: release.VERSIONS[release.BRIEFING]},
        OPERATOR_SOURCE=git_file(repo, previous.OPERATOR_COMMIT, 'scripts/infra/connect_operator_release.py'),
        BROADCAST_SOURCE=git_file(repo, previous.BROADCAST_COMMIT, 'scripts/infra/connect_broadcast_release.py'),
        BRIEFING_SOURCE=git_file(repo, release.BRIEFING, 'scripts/infra/briefing_release.py'),
    )


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--target', required=True)
    parser.add_argument('--output', type=Path, required=True)
    options = parser.parse_args()
    print(json.dumps(build(options.repo.resolve(), options.target, options.output), sort_keys=True))
