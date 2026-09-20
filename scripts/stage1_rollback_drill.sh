#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE="$ROOT/tests/stage/rollback-compose.yaml"
ART="$ROOT/artifacts/rollback-drill"
STAGE_INPUT="$ROOT/.stage-input"

cd "$ROOT"

test -f "$COMPOSE" || { echo "Missing $COMPOSE" >&2; exit 1; }
test -d "$ROOT/.git" || { echo "Run from a Git checkout" >&2; exit 1; }

cleanup() {
    docker compose -f "$COMPOSE" down --volumes >/dev/null 2>&1 || true
}
trap cleanup EXIT

rm -rf "$STAGE_INPUT" "$ART"
mkdir -p "$ART"

python3 scripts/ci/prepare_stage.py

docker compose -f "$COMPOSE" config > "$ART/compose-resolved.yaml"

if grep -Eq '/opt/ustar|ustar_moodle|ustar_postgres|/var/www/moodledata' "$ART/compose-resolved.yaml"; then
    echo "REFUSING_PRODUCTION_REFERENCE_IN_ROLLBACK_DRILL" >&2
    exit 65
fi
if grep -Eq '^[[:space:]]*ports:' "$ART/compose-resolved.yaml"; then
    echo "REFUSING_HOST_PORTS_IN_ROLLBACK_DRILL" >&2
    exit 65
fi

docker compose -f "$COMPOSE" build --pull
docker compose -f "$COMPOSE" up --abort-on-container-exit --exit-code-from runner
docker image ls --digests --no-trunc > "$ART/host-images.txt"

grep -qx 'ROLLBACK_DRILL=PASS' "$ART/result.txt"
echo "STAGE1_ROLLBACK_DRILL=PASS"
