#!/usr/bin/env python3
"""Reproduce the coordinated widget archive from committed application and worker source."""
import argparse
import json
from pathlib import Path

from build_connect_broadcast_release import build_successor, git_file
import connect_checklist_widget_release as release


def build(repo, target, output):
    operator = 'scripts/infra/connect_checklist_widget_release.py'
    previous = git_file(repo, release.PREDECESSOR_COMMIT, 'scripts/infra/connect_broadcast_release.py')
    changes = {'bot_handler.py', 'bot_widget.py', 'receiver.py', 'telegram_api.py'}
    return build_successor(repo, target, output, release, operator, changes, 'ustar-connect-checklist-widget',
                           PREDECESSOR_SOURCE=previous)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument('--target', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.repo.resolve(), args.target, args.output), sort_keys=True))
