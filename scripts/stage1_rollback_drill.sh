#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE="$ROOT/tests/stage/rollback-compose.yaml"
ART="$ROOT/artifacts/rollback-drill"
STAGE_INPUT="$ROOT/.stage-input"

cd "$ROOT"
mkdir -p "$ART"
exec > >(tee "$ART/rollback-runner.log") 2>&1

cleanup() {
  docker compose -f "$COMPOSE" down --volumes >/dev/null 2>&1 || true
}
trap cleanup EXIT

on_error() {
  code=$?
  echo "ROLLBACK_WRAPPER_EXIT=$code" | tee "$ART/container-exit.txt"
  docker compose -f "$COMPOSE" ps -a || true
  docker compose -f "$COMPOSE" logs --no-color || true
  exit "$code"
}
trap on_error ERR

echo "ROLLBACK_DEBUG_START"
docker version || true
docker compose version || true

test -f "$COMPOSE"
git rev-parse --is-inside-work-tree >/dev/null

rm -rf "$STAGE_INPUT"
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
set +e
docker compose -f "$COMPOSE" up --abort-on-container-exit --exit-code-from runner
status=$?
set -e
if [ "$status" -ne 0 ]; then
  echo "ROLLBACK_CONTAINER_EXIT=$status" | tee "$ART/container-exit.txt"
  docker compose -f "$COMPOSE" ps -a || true
  docker compose -f "$COMPOSE" logs --no-color || true
  exit "$status"
fi

docker image ls --digests --no-trunc > "$ART/host-images.txt"
grep -qx 'ROLLBACK_DRILL=PASS' "$ART/result.txt"
echo "STAGE1_ROLLBACK_DRILL=PASS"
