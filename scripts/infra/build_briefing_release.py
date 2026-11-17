#!/usr/bin/env python3
"""Build an exact, reproducible briefing/meeting/Kanban release from Git.

Delivers PR125 briefing/meetings/Kanban over the owner's qualified and installed
PR124 Connect operator source/worker baseline, without a second DB.
"""
import argparse
import json
from pathlib import Path
import xml.etree.ElementTree as ET

from build_connect_broadcast_release import build_successor, git_file
import briefing_release as release

NEW_TABLES = frozenset({
    'local_ustar_brief_templates', 'local_ustar_brief_reports',
    'local_ustar_brief_minutes', 'local_ustar_meeting_rooms',
    'local_ustar_meetings', 'local_ustar_meeting_members',
})


def verify_schema(before: Path, after: Path):
    original = ET.parse(before).getroot()
    candidate = ET.parse(after).getroot()
    # XMLDB VERSION is a release marker, not a table/column mutation.
    candidate.set('VERSION', original.get('VERSION'))
    oldtables = {row.get('NAME') for row in original.findall('./TABLES/TABLE')}
    newtables = {row.get('NAME') for row in candidate.findall('./TABLES/TABLE')}
    if newtables - oldtables != NEW_TABLES or oldtables - newtables:
        raise ValueError('unexpected_briefing_tables')

    for table in list(candidate.findall('./TABLES/TABLE')):
        if table.get('NAME') in NEW_TABLES:
            candidate.find('./TABLES').remove(table)

    oldmeta = original.find("./TABLES/TABLE[@NAME='local_ustar_task_meta']")
    newmeta = candidate.find("./TABLES/TABLE[@NAME='local_ustar_task_meta']")
    if oldmeta is None or newmeta is None:
        raise ValueError('task_meta_missing')
    field = newmeta.find("./FIELDS/FIELD[@NAME='briefkey']")
    index = newmeta.find("./INDEXES/INDEX[@NAME='briefrequest']")
    if field is None or index is None or field.get('TYPE') != 'char' or field.get('LENGTH') != '64' \
            or index.get('UNIQUE') != 'true' or index.get('FIELDS') != 'briefkey':
        raise ValueError('unexpected_briefkey_schema')
    newmeta.find('./FIELDS').remove(field)
    newmeta.find('./INDEXES').remove(index)
    if ET.canonicalize(ET.tostring(original), strip_text=True) != \
            ET.canonicalize(ET.tostring(candidate), strip_text=True):
        raise ValueError('unexpected_existing_schema_delta')


def build(repo: Path, target: str, output: Path):
    operator = git_file(repo, release.OPERATOR_COMMIT, 'scripts/infra/connect_operator_release.py')
    broadcast = git_file(repo, release.BROADCAST_COMMIT, 'scripts/infra/connect_broadcast_release.py')
    return build_successor(
        repo, target, output, release, 'scripts/infra/briefing_release.py',
        {'bot_cards.py', 'notification_events.py'}, 'ustar-briefing-kanban',
        schema_check=verify_schema, new_subdirs=('classes/briefing',),
        OPERATOR_SOURCE=operator, BROADCAST_SOURCE=broadcast,
    )


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--target', required=True)
    parser.add_argument('--output', type=Path, required=True)
    options = parser.parse_args()
    print(json.dumps(build(options.repo.resolve(), options.target, options.output), sort_keys=True))
