#!/usr/bin/env bash
# Disposable synthetic CI only. No operator/server entry point.
set -euo pipefail
test "${CI:-}" = true
test "${RUNNER_OS:-}" = Linux
test -n "${GITHUB_RUN_ID:-}"
test "$PWD" = "${GITHUB_WORKSPACE:?}"
ustar_ci_project="ustar-ci-m53-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
ustar_ci_pg16="$ustar_ci_project-pg16"
ustar_ci_pg17="$ustar_ci_project-pg17"
ustar_ci_network="$ustar_ci_project-net"
ustar_ci_image16=$(python3 -c 'import json; print(json.load(open("tests/stage/patch-runtime.json"))["postgres_image"])')
ustar_ci_image17=$(python3 -c 'import json; print(json.load(open("scripts/infra/moodle53_profile_20261014.json"))["postgres17_image"])')
mkdir -p artifacts/major
ustar_ci_cleanup() {
    docker rm -f "$ustar_ci_pg17" "$ustar_ci_pg16" >/dev/null 2>&1 || true
    docker network rm "$ustar_ci_network" >/dev/null 2>&1 || true
}
trap ustar_ci_cleanup EXIT
docker pull --platform linux/amd64 "$ustar_ci_image16"
docker pull --platform linux/amd64 "$ustar_ci_image17"
test "$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$ustar_ci_image17")" = linux/amd64
docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges \
    --entrypoint postgres "$ustar_ci_image17" --version | tee artifacts/major/pg17-version.txt
grep -E '^postgres \(PostgreSQL\) 17[.]11([[:space:]]|$)' artifacts/major/pg17-version.txt
docker network create --internal "$ustar_ci_network"
for ustar_ci_major in 16 17; do
    ustar_ci_imagevar="ustar_ci_image$ustar_ci_major"
    ustar_ci_namevar="ustar_ci_pg$ustar_ci_major"
    docker run -d --name "${!ustar_ci_namevar}" --network "$ustar_ci_network" --restart no \
        --tmpfs /var/lib/postgresql/data:rw,mode=0700 \
        -e POSTGRES_USER=fixture_ci -e POSTGRES_DB=fixture_db \
        -e POSTGRES_PASSWORD=fixture-only-not-a-production-secret \
        -e 'POSTGRES_INITDB_ARGS=--encoding=UTF8 --locale=C.UTF-8' "${!ustar_ci_imagevar}"
    ustar_ci_ready=false
    for ustar_ci_try in $(seq 1 45); do
        if docker exec "${!ustar_ci_namevar}" pg_isready -h 127.0.0.1 -U fixture_ci -d fixture_db; then
            ustar_ci_ready=true
            break
        fi
        sleep 2
    done
    test "$ustar_ci_ready" = true
done
docker exec -i "$ustar_ci_pg16" psql -X -v ON_ERROR_STOP=1 -U fixture_ci -d fixture_db <<'SQL'
CREATE EXTENSION pgcrypto;
CREATE ROLE fixture_reader NOLOGIN;
CREATE TABLE fixture_payload (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                             body text NOT NULL, score numeric(8,2), token uuid NOT NULL);
INSERT INTO fixture_payload(body,score,token) VALUES
 ('synthetic SCORM record',23.50,'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'),
 ('Синтетический UTF-8 маршрут',17.50,'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');
GRANT SELECT ON fixture_payload TO fixture_reader;
SQL
docker exec "$ustar_ci_pg17" psql -X -v ON_ERROR_STOP=1 -U fixture_ci -d fixture_db \
    -c 'CREATE ROLE fixture_reader NOLOGIN;'
ustar_ci_fingerprint=$(cat <<'SQL'
SELECT jsonb_build_object(
 'rows',(SELECT jsonb_agg(to_jsonb(t) ORDER BY id) FROM fixture_payload t),
 'sequence',(SELECT jsonb_build_object('last_value',last_value,'is_called',is_called) FROM fixture_payload_id_seq),
 'reader_select',has_table_privilege('fixture_reader','fixture_payload','SELECT'),
 'pgcrypto_exists',EXISTS(SELECT 1 FROM pg_extension WHERE extname='pgcrypto'));
SQL
)
docker exec "$ustar_ci_pg16" psql -X -q -A -t -v ON_ERROR_STOP=1 -U fixture_ci -d fixture_db \
    -c "$ustar_ci_fingerprint" > artifacts/major/before.json
docker exec "$ustar_ci_pg16" pg_dump -U fixture_ci -d fixture_db -Fc > artifacts/major/baseline.dump
docker exec -i "$ustar_ci_pg17" pg_restore --exit-on-error --single-transaction \
    -U fixture_ci -d fixture_db < artifacts/major/baseline.dump
docker exec "$ustar_ci_pg17" psql -X -q -A -t -v ON_ERROR_STOP=1 -U fixture_ci -d fixture_db \
    -c "$ustar_ci_fingerprint" > artifacts/major/after-pg17.json
cmp artifacts/major/before.json artifacts/major/after-pg17.json
# Modify only the new synthetic target,then prove an independent redo restore.
docker exec "$ustar_ci_pg17" psql -X -v ON_ERROR_STOP=1 -U fixture_ci -d fixture_db \
    -c "INSERT INTO fixture_payload(body,score,token) VALUES ('new-target-only',1.00,'cccccccc-cccc-cccc-cccc-cccccccccccc');"
docker exec "$ustar_ci_pg17" createdb -U fixture_ci fixture_redo
docker exec -i "$ustar_ci_pg17" pg_restore --exit-on-error --single-transaction \
    -U fixture_ci -d fixture_redo < artifacts/major/baseline.dump
docker exec "$ustar_ci_pg17" psql -X -q -A -t -v ON_ERROR_STOP=1 -U fixture_ci -d fixture_redo \
    -c "$ustar_ci_fingerprint" > artifacts/major/redo-pg17.json
cmp artifacts/major/before.json artifacts/major/redo-pg17.json
docker exec "$ustar_ci_pg16" createdb -U fixture_ci fixture_rollback
docker exec -i "$ustar_ci_pg16" pg_restore --exit-on-error --single-transaction \
    -U fixture_ci -d fixture_rollback < artifacts/major/baseline.dump
docker exec "$ustar_ci_pg16" psql -X -q -A -t -v ON_ERROR_STOP=1 -U fixture_ci -d fixture_rollback \
    -c "$ustar_ci_fingerprint" > artifacts/major/rollback-pg16.json
cmp artifacts/major/before.json artifacts/major/rollback-pg16.json
printf 'SYNTHETIC_PG16_TO17_DATA_SEQUENCE_ACL_EXTENSION_REDO_RESTORE=PASS\n' | tee artifacts/major/pg-result.txt
