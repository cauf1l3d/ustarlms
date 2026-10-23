<?php
// Synthetic native DB privilege gate. No production invocation is supported.
if (__DIR__ !== '/source/tests/stage' || getenv('USTAR_DB_PRIVILEGE_FIXTURE') !== '1'
        || is_dir('/opt/ustar') || !is_file('/stage/moodle/config.php')) {
    throw new RuntimeException('REFUSING_NON_ISOLATED_PRIVILEGE_FIXTURE');
}
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
if ($CFG->dbhost !== 'db' || $CFG->dbname !== 'ustar_stage1' || $CFG->dbuser !== 'ustar_fixture'
        || $CFG->prefix !== 'stage_' || $CFG->dataroot !== '/stage/data/stage_'
        || empty($CFG->noemailever)) {
    throw new RuntimeException('REFUSING_NON_ISOLATED_PRIVILEGE_DATABASE');
}

function privilege_role(): array {
    global $DB;
    $row = (array)$DB->get_record_sql("SELECT session_user AS login_role, current_user AS effective_role,
        r.rolsuper::int AS superuser, r.rolcreaterole::int AS createrole,
        r.rolcreatedb::int AS createdb, r.rolreplication::int AS replication,
        r.rolbypassrls::int AS bypassrls,
        (pg_get_userbyid(d.datdba) = current_user)::int AS database_owner
        FROM pg_catalog.pg_roles r JOIN pg_catalog.pg_database d ON d.datname = current_database()
        WHERE r.rolname = current_user", [], MUST_EXIST);
    foreach (['superuser', 'createrole', 'createdb', 'replication', 'bypassrls', 'database_owner'] as $key) {
        $row[$key] = (int)$row[$key];
    }
    $row['dangerous_memberships'] = (int)$DB->get_field_sql("SELECT count(*) FROM pg_catalog.pg_roles r
        WHERE r.rolname <> current_user AND (r.rolsuper OR r.rolbypassrls
          OR r.rolname IN ('pg_read_server_files', 'pg_write_server_files', 'pg_execute_server_program'))
        AND pg_has_role(current_user, r.oid, 'MEMBER')");
    file_put_contents('/artifacts/db-privileges-role.json', json_encode($row, JSON_THROW_ON_ERROR));
    if ($row['login_role'] !== 'ustar_fixture' || $row['effective_role'] !== 'ustar_fixture'
            || $row['database_owner'] !== 1 || $row['dangerous_memberships'] !== 0
            || array_sum(array_intersect_key($row, array_flip([
                'superuser', 'createrole', 'createdb', 'replication', 'bypassrls']))) !== 0) {
        throw new RuntimeException('APP_ROLE_HAS_CLUSTER_PRIVILEGES');
    }
    return $row;
}

if (($argv[1] ?? '') !== 'role') {
    throw new RuntimeException('INVALID_PRIVILEGE_PROBE_MODE');
}
privilege_role();
echo "DB_PRIVILEGES_APP_ROLE=PASS\n";
