#!/usr/bin/env python3
"""Build the Connect delivery repair from the exact application and installer commits."""
import argparse
import json
from pathlib import Path

from build_connect_broadcast_release import build_successor, git_file
import connect_delivery_repair_release as release


def build(repo, target, output):
    operator = 'scripts/infra/connect_delivery_repair_release.py'
    previous = git_file(repo, release.PREDECESSOR_COMMIT, 'scripts/infra/connect_broadcast_release.py')

    return build_successor(repo, target, output, release, operator, {'notification_worker.py'},
                           'ustar-connect-delivery-repair', PREDECESSOR_SOURCE=previous)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--target', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.repo.resolve(), args.target, args.output), sort_keys=True))
