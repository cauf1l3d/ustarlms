#!/usr/bin/env bash
# Add only the reduced-role delta to the existing disposable Connect fixture.
set -Eeuo pipefail
trap 'printf "DB_PRIVILEGES_FAILURE_LINE=%s\n" "$LINENO" >&2' ERR
test "$PWD" = /stage/moodle
test "${USTAR_DB_PRIVILEGE_FIXTURE:-}" = 1
test ! -e /opt/ustar
test -d /source/moodle/local/ustar
test -f /stage/telegram-presentation-state.json
test ! -e /stage/db-privileges-private
umask 077
export USTAR_DB_PRIVILEGE_FIXTURE=1
DBP=/stage/db-privileges-private
mkdir "$DBP"
# Private SQL/dumps stay beneath the0700 directory. Sanitized artifact files must
# remain readable by the host uploader after the disposable runner exits.
umask 022
probe=/source/tests/stage/db_privileges_probe.php

# Cluster preparation stays privileged; the app connection and object owner stay
# ustar_fixture, matching the owner-preserving production role proposal.
psql -X -w -h db -U ustar_fixture -d ustar_stage1 -v ON_ERROR_STOP=1 \
  -c "CREATE ROLE ustar_privilege_admin LOGIN SUPERUSER PASSWORD 'fixture-only-not-a-production-secret';" \
  > "$DBP/admin-create.log" 2>&1
psql -X -w -h db -U ustar_privilege_admin -d ustar_stage1 -v ON_ERROR_STOP=1 \
  -c 'ALTER ROLE ustar_fixture NOSUPERUSER NOCREATEROLE NOCREATEDB NOREPLICATION NOBYPASSRLS;' \
  > "$DBP/app-flags.log" 2>&1
psql -X -w -qAt -h db -U ustar_privilege_admin -d postgres -v ON_ERROR_STOP=1 \
  -c "SELECT json_build_object('role',current_user,'superuser',rolsuper::int) FROM pg_roles WHERE rolname=current_user;" \
  > /artifacts/db-privileges-admin.json
# Negative control: zero own flags must not hide SET ROLE access to CREATEDB.
psql -X -w -h db -U ustar_privilege_admin -d postgres -v ON_ERROR_STOP=1 \
  -c 'CREATE ROLE ustar_privilege_holder NOLOGIN CREATEDB; GRANT ustar_privilege_holder TO ustar_fixture;' \
  > "$DBP/membership-grant.log" 2>&1
if php "$probe" role > "$DBP/membership-refused.log" 2>&1; then
    echo 'UNEXPECTED_PRIVILEGED_MEMBERSHIP_ACCEPTED' >&2
    exit 1
fi
grep -q 'APP_ROLE_HAS_CLUSTER_PRIVILEGES' "$DBP/membership-refused.log"
cp /artifacts/db-privileges-role.json /artifacts/db-privileges-member-refused.json
psql -X -w -h db -U ustar_privilege_admin -d postgres -v ON_ERROR_STOP=1 \
  -c 'REVOKE ustar_privilege_holder FROM ustar_fixture; DROP ROLE ustar_privilege_holder;' \
  > "$DBP/membership-revoke.log" 2>&1
php "$probe" role
baseline=$(php "$probe" version)

# Existing paired-recovery runner has restored2200; exercise the real2300 upgrade
# and its repeat with a fresh ordinary app session, not SET ROLE in a superuser PHP session.
rm -rf public/local/ustar public/theme/ustar
cp -a /source/moodle/local/ustar public/local/ustar
cp -a /source/moodle/theme/ustar public/theme/ustar
php admin/cli/upgrade.php --non-interactive > /artifacts/db-privileges-upgrade.log 2>&1
target=$(php "$probe" version)
php admin/cli/upgrade.php --non-interactive > /artifacts/db-privileges-repeat.log 2>&1
repeat=$(php "$probe" version)
php "$probe" exercise
timeout --kill-after=3s 90s php admin/cli/scheduled_task.php \
  --execute='\local_ustar\task\prune_connect_identity' --force \
  > /artifacts/db-privileges-scheduled-task.log 2>&1
php "$probe" cron
php "$probe" denials
php /source/tests/stage/schema_snapshot.php > "$DBP/schema-before.json"

# Preserve the exact old globals command's semantics. An unrelated client failure
# is not accepted as a permission denial. Neither globals file becomes an artifact.
if PGHOST=db POSTGRES_USER=ustar_fixture sh -c \
  'exec pg_dumpall -w -U "${POSTGRES_USER:-postgres}" --globals-only' \
  > "$DBP/app-globals.sql" 2> "$DBP/app-globals.error"; then
    echo 'UNEXPECTED_APP_GLOBALS_ACCESS' >&2
    exit 1
fi
grep -q 'permission denied' "$DBP/app-globals.error"
grep -q 'pg_authid' "$DBP/app-globals.error"
PGHOST=db POSTGRES_USER=ustar_privilege_admin sh -c \
  'exec pg_dumpall -w -U "${POSTGRES_USER:-postgres}" --globals-only' \
  > "$DBP/admin-globals.sql" 2> "$DBP/admin-globals.error"
test -s "$DBP/admin-globals.sql"
grep -q '^CREATE ROLE ustar_privilege_admin;' "$DBP/admin-globals.sql"

PGOPTIONS='-c default_transaction_read_only=on' \
  pg_dump -w -h db -U ustar_fixture -d ustar_stage1 --format=custom \
  > "$DBP/database.dump" 2> "$DBP/database-dump.log"
test -s "$DBP/database.dump"
psql -X -w -h db -U ustar_privilege_admin -d postgres -v ON_ERROR_STOP=1 \
  -c 'DROP DATABASE ustar_stage1 WITH (FORCE);' > "$DBP/drop.log" 2>&1
psql -X -w -h db -U ustar_privilege_admin -d postgres -v ON_ERROR_STOP=1 \
  -c 'CREATE DATABASE ustar_stage1 OWNER ustar_fixture;' > "$DBP/create.log" 2>&1
pg_restore -w -h db -U ustar_privilege_admin -d ustar_stage1 --role=ustar_fixture \
  --no-owner --no-acl --single-transaction --exit-on-error "$DBP/database.dump" \
  > "$DBP/restore.log" 2>&1
php "$probe" verify
php /source/tests/stage/schema_snapshot.php > "$DBP/schema-after.json"
cmp "$DBP/schema-before.json" "$DBP/schema-after.json"

python3 - "$baseline" "$target" "$repeat" <<'PY'
import json, sys
from pathlib import Path
out = Path('/artifacts')
app = json.loads((out/'db-privileges-role.json').read_text())
proof = {
    'result': 'DB_PRIVILEGES_NATIVE=PASS', 'postgres_major': int(app['postgres_major']),
    'application_versions': [int(v) for v in sys.argv[1:]], 'app': app,
    'admin': json.loads((out/'db-privileges-admin.json').read_text()),
    'membership_refusal': json.loads((out/'db-privileges-member-refused.json').read_text()),
    'checks': dict.fromkeys(['privileged_membership_denied', 'xmldb_ddl', 'domain_writes', 'file_api', 'scheduled_task',
        'pg_dump_app', 'pg_restore_admin_set_app_role', 'schema_equal_after_restore',
        'data_equal_after_restore', 'globals_app_denied', 'globals_admin_private',
        'globals_not_uploaded'], True),
    'denied_sqlstates': json.loads((out/'db-privileges-denials.json').read_text()),
    'external_provider_called': False,
}
(out/'db-privileges-native.json').write_text(json.dumps(proof, sort_keys=True)+'\n')
PY
python3 /source/tests/stage/db_privileges_verify.py /artifacts/db-privileges-native.json
echo 'DB_PRIVILEGES_NATIVE=PASS'
