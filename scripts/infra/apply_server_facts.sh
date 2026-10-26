#!/usr/bin/env bash
# Validate and import immutable evidence. No commit, push or production operation.
set -euo pipefail
exec python3 -B "$(dirname "${BASH_SOURCE[0]}")/server_facts.py" import "$@"
