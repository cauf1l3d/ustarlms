#!/usr/bin/env bash
# Compatibility entry point. Output is a new private directory, never a repo path.
set -euo pipefail
exec python3 -B "$(dirname "${BASH_SOURCE[0]}")/server_facts.py" collect "$@"
