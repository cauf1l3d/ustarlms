#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT"

STAMP=$(date +"%Y-%m-%d %H:%M:%S")

echo "=== USTAR CONTEXT REFRESH ==="

mkdir -p context/runtime

echo "[1/5] Git state"

cat > context/runtime/git_state.yaml <<EOF
git:
  branch: "$(git branch --show-current)"
  commit: "$(git rev-parse HEAD)"
  commit_short: "$(git rev-parse --short HEAD)"
  remote: "$(git remote get-url origin)"
  refreshed: "$STAMP"
EOF


echo "[2/5] Docker state"

cat > context/runtime/docker.yaml <<EOF
docker:
  refreshed: "$STAMP"
  containers:
EOF

docker ps --format '{{.Names}}|{{.Image}}|{{.Ports}}' |
while IFS="|" read name image ports
do

cat >> context/runtime/docker.yaml <<EOF
    - name: "$name"
      image: "$image"
      ports: "$ports"
EOF

done


echo "[3/5] Moodle runtime"

cat > context/runtime/moodle.yaml <<EOF
moodle:

  container:
    name: ustar_moodle

  filesystem:
    host: /opt/ustar/data/moodle/public
    container: /var/www/html/public

  plugin:
    local/ustar

  theme:
    theme/ustar

  database:
    engine: PostgreSQL
    version: 16

  refreshed:
    $STAMP
EOF


echo "[4/5] Server"

cat > context/runtime/server.yaml <<EOF
server:

  hostname:
    $(hostname)

  os:
    $(lsb_release -ds 2>/dev/null || echo unknown)

  kernel:
    $(uname -r)

  refreshed:
    "$STAMP"
EOF


echo "[5/5] Code map"

if [ -f scripts/build_code_map.sh ]; then

    chmod +x scripts/build_code_map.sh

    ./scripts/build_code_map.sh

else

    echo "WARNING: build_code_map.sh missing"

fi

echo "[6/6] Context index"

if [ -f scripts/build_context_index.py ]; then

    python3 scripts/build_context_index.py

else

    echo "WARNING: build_context_index.py missing"

fi

cat > context/runtime/context_refresh_report.md <<EOF
# USTAR Context Refresh Report

Generated:

$STAMP


## Git

Branch:

$(git branch --show-current)

Commit:

$(git rev-parse HEAD)


## Docker

$(docker ps --format "- {{.Names}} | {{.Image}}")


## Code Map

Updated:

$(date)


## Status

Context refreshed successfully.
EOF


echo
echo "=== DONE ==="
