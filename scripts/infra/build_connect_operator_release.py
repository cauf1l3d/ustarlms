#!/usr/bin/env python3
"""Build the schema-neutral Academy update for Telegram operators."""
import argparse
import json
from pathlib import Path

from build_connect_broadcast_release import build_successor, git_file
import connect_operator_release as release


def build(repo, target, output):
    path = 'scripts/infra/connect_broadcast_release.py'
    previous = git_file(repo, release.PREDECESSOR_COMMIT, path)
    return build_successor(repo, target, output, release, 'scripts/infra/connect_operator_release.py',
                           set(), 'ustar-connect-operator', PREDECESSOR_SOURCE=previous)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--target', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.repo.resolve(), args.target, args.output), sort_keys=True))
