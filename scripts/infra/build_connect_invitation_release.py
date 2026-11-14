#!/usr/bin/env python3
"""Reproduce the Academy and Telegram worker invitation update from committed sources."""
import argparse
import json
from pathlib import Path
import xml.etree.ElementTree as ET

from build_connect_broadcast_release import build_successor, git_file
import connect_invitation_release as release


def verify_schema(before, after):
    expected = ET.parse(before).getroot()
    approved = {
        'local_ustar_conn_links': [
            ('state', dict(NAME='botinvited', TYPE='int', LENGTH='1', NOTNULL='true', DEFAULT='0')),
        ],
        'local_ustar_conn_challenges': [
            ('proofhash', dict(NAME='issuedby', TYPE='int', LENGTH='10', NOTNULL='true', DEFAULT='0')),
            ('issuedby', dict(NAME='expectedsubject', TYPE='char', LENGTH='16', NOTNULL='false')),
        ],
    }
    for table, fields in approved.items():
        parent = expected.find(f"./TABLES/TABLE[@NAME='{table}']/FIELDS")
        for previous, definition in fields:
            index = [field.attrib['NAME'] for field in parent].index(previous)
            parent.insert(index + 1, ET.Element('FIELD', definition))
    if ET.canonicalize(ET.tostring(expected), strip_text=True) != ET.canonicalize(after.read_text(), strip_text=True):
        raise ValueError('unexpected_invitation_schema_change')


def build(repo, target, output):
    previous = git_file(repo, release.PREDECESSOR_COMMIT, 'scripts/infra/connect_broadcast_release.py')
    return build_successor(repo, target, output, release, 'scripts/infra/connect_invitation_release.py',
                           {'bot_handler.py'}, 'ustar-connect-invitations',
                           schema_check=verify_schema, PREDECESSOR_SOURCE=previous)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--target', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.repo.resolve(), args.target, args.output), sort_keys=True))
