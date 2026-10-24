<?php
// Synthetic native DB privilege gate. No production invocation is supported.
if (__DIR__ !== '/source/tests/stage' || getenv('USTAR_DB_PRIVILEGE_FIXTURE') !== '1'
        || is_dir('/opt/ustar') || !is_file('/stage/moodle/config.php')) {
    throw new RuntimeException('REFUSING_NON_ISOLATED_PRIVILEGE_FIXTURE');
}
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
if ($CFG->dbhost !== 'db' || $CFG->dbname !== 'ustar_stage1' || $CFG->dbuser !== 'ustar_app'
        || $CFG->prefix !== 'stage_' || $CFG->dataroot !== '/stage/data/stage_'
        || empty($CFG->noemailever)) {
    throw new RuntimeException('REFUSING_NON_ISOLATED_PRIVILEGE_DATABASE');
}

function privilege_role(): array {
    global $DB;
    $row = (array)$DB->get_record_sql("SELECT session_user AS login_role, current_user AS effective_role, r.oid::int AS role_oid,
        r.rolsuper::int AS superuser, r.rolcreaterole::int AS createrole,
        r.rolcreatedb::int AS createdb, r.rolreplication::int AS replication,
        r.rolbypassrls::int AS bypassrls,
        (pg_get_userbyid(d.datdba) = current_user)::int AS database_owner,
        (current_setting('server_version_num')::int / 10000) AS postgres_major
        FROM pg_catalog.pg_roles r JOIN pg_catalog.pg_database d ON d.datname = current_database()
        WHERE r.rolname = current_user", [], MUST_EXIST);
    foreach (['role_oid', 'superuser', 'createrole', 'createdb', 'replication', 'bypassrls', 'database_owner'] as $key) {
        $row[$key] = (int)$row[$key];
    }
    $row['dangerous_memberships'] = (int)$DB->get_field_sql("SELECT count(*) FROM pg_catalog.pg_roles r
        WHERE r.rolname <> current_user AND (r.rolsuper OR r.rolcreaterole
          OR r.rolcreatedb OR r.rolreplication OR r.rolbypassrls
          OR r.rolname IN ('pg_read_server_files', 'pg_write_server_files', 'pg_execute_server_program'))
        AND pg_has_role(current_user, r.oid, 'MEMBER')");
    file_put_contents('/artifacts/db-privileges-role.json', json_encode($row, JSON_THROW_ON_ERROR));
    if ($row['login_role'] !== 'ustar_app' || $row['effective_role'] !== 'ustar_app' || $row['role_oid'] <= 10
            || $row['database_owner'] !== 1 || $row['dangerous_memberships'] !== 0
            || array_sum(array_intersect_key($row, array_flip([
                'superuser', 'createrole', 'createdb', 'replication', 'bypassrls']))) !== 0) {
        throw new RuntimeException('APP_ROLE_HAS_CLUSTER_PRIVILEGES');
    }
    return $row;
}

const PRIVILEGE_STATE = '/stage/db-privileges-state.json';

function privilege_snapshot(array $state): string {
    global $DB;
    $file = get_file_storage()->get_file_by_id($state['file']);
    if (!$file) { throw new RuntimeException('PRIVILEGE_FILE_MISSING'); }
    return hash('sha256', json_encode([
        $DB->get_record('user', ['id' => $state['uid']], 'id,username', MUST_EXIST),
        $DB->get_record('course', ['id' => $state['course']], 'id,shortname', MUST_EXIST),
        $DB->record_exists('user_enrolments', ['userid' => $state['uid'], 'enrolid' => $state['enrol']]),
        $DB->get_record('local_ustar_learning_tasks', ['id' => $state['task']], '*', MUST_EXIST),
        $DB->get_record('local_ustar_notifications', ['id' => $state['notice']], '*', MUST_EXIST),
        get_user_preferences('db_privilege_probe', null, $state['uid']),
        hash('sha256', $file->get_content()),
    ], JSON_THROW_ON_ERROR));
}

privilege_role();
$mode = $argv[1] ?? '';
if ($mode === 'role') {
    echo "DB_PRIVILEGES_APP_ROLE=PASS\n";
} else if ($mode === 'version') {
    echo (int)get_config('local_ustar', 'version');
} else if ($mode === 'exercise') {
    if (file_exists(PRIVILEGE_STATE) || (int)get_config('local_ustar', 'version') !== 2026102300) {
        throw new RuntimeException('PRIVILEGE_TARGET_2300_REQUIRED');
    }
    // Actual XMLDB DDL, including a sequence and unique index; no global privilege.
    $dm = $DB->get_manager();
    $table = new xmldb_table('ustar_privilege_probe');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
    $table->add_field('label', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('label_uix', XMLDB_INDEX_UNIQUE, ['label']);
    $dm->create_table($table);
    $dm->add_field($table, new xmldb_field('revision', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'));
    $id = $DB->insert_record('ustar_privilege_probe', (object)['label' => 'before']);
    $DB->set_field('ustar_privilege_probe', 'label', 'after', ['id' => $id]);
    if ($DB->get_field('ustar_privilege_probe', 'label', ['id' => $id], MUST_EXIST) !== 'after') {
        throw new RuntimeException('PRIVILEGE_DDL_DML_FAILED');
    }
    $dm->drop_table($table);

    require_once($CFG->dirroot . '/user/lib.php');
    require_once($CFG->dirroot . '/course/lib.php');
    require_once($CFG->libdir . '/enrollib.php');
    \core\session\manager::set_user(get_admin());
    $uid = (int)user_create_user((object)['username' => 'privilege_employee', 'auth' => 'manual',
        'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id, 'firstname' => 'Synthetic',
        'lastname' => 'Privilege', 'email' => 'privilege@example.invalid'], false, false);
    $role = create_role('Privilege fixture', 'privilege_fixture', 'Synthetic only');
    assign_capability('local/ustar:use', CAP_ALLOW, $role, context_system::instance()->id);
    role_assign($role, $uid, context_system::instance()->id);
    $categories = $DB->get_records('course_categories', [], 'id', 'id', 0, 1);
    $category = reset($categories);
    if (!$category) { throw new RuntimeException('PRIVILEGE_CATEGORY_REQUIRED'); }
    $course = create_course((object)['fullname' => 'Synthetic privilege course',
        'shortname' => 'privilege-course', 'category' => $category->id]);
    $manual = enrol_get_plugin('manual');
    // create_course already installs the default manual instance. enrol_user needs
    // its record, whereas add_instance may return null when one already exists.
    $enrolinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
    $instance = (int)$enrolinstance->id;
    $student = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    $manual->enrol_user($enrolinstance, $uid, $student);
    $task = \local_ustar\task_workspace\service::create((int)get_admin()->id, $uid,
        ['kind' => 'task', 'title' => 'Synthetic privilege task',
         'date' => gmdate('Y-m-d', time() + DAYSECS), 'clock' => '17:00',
         'policy' => \local_ustar\task_workspace\policy::validate(['timezone' => 'UTC', 'mode' => 'calendar'])]);
    $file = get_file_storage()->create_file_from_string([
        'contextid' => context_user::instance($uid)->id, 'component' => 'user',
        'filearea' => 'private', 'itemid' => 0, 'filepath' => '/', 'filename' => 'privilege.txt',
    ], 'Synthetic privilege file');
    set_user_preference('db_privilege_probe', 'retained', $uid);
    \core\session\manager::set_user(get_complete_user_data('id', $uid));
    $notice = \local_ustar\target_core::notify(['userid' => $uid, 'severity' => 'normal',
        'eventtype' => 'task_assigned', 'subject' => 'Synthetic privilege notice',
        'message' => 'Never sent', 'idempotencykey' => 'privilege-notice']);
    \local_ustar\target_core::acknowledge_notification($notice, $uid);
    if (!$DB->record_exists('user_enrolments', ['userid' => $uid, 'enrolid' => $instance])
            || $DB->get_field('local_ustar_notifications', 'ackat', ['id' => $notice], MUST_EXIST) <= 0
            || $file->get_content() !== 'Synthetic privilege file') {
        throw new RuntimeException('PRIVILEGE_DOMAIN_WRITE_FAILED');
    }
    $state = ['uid' => $uid, 'course' => (int)$course->id, 'enrol' => $instance,
        'task' => $task, 'notice' => $notice, 'file' => $file->get_id(),
        'last_task_log_id' => (int)$DB->get_field_sql('SELECT COALESCE(MAX(id),0) FROM {task_log}')];
    $state['hash'] = privilege_snapshot($state);
    file_put_contents(PRIVILEGE_STATE, json_encode($state, JSON_THROW_ON_ERROR));
    chmod(PRIVILEGE_STATE, 0600);
    echo "DB_PRIVILEGES_XMLDB_DOMAIN_FILE=PASS\n";
} else if ($mode === 'verify' || $mode === 'cron') {
    $state = json_decode(file_get_contents(PRIVILEGE_STATE), true, 512, JSON_THROW_ON_ERROR);
    if ((int)get_config('local_ustar', 'version') !== 2026102300
            || privilege_snapshot($state) !== $state['hash']) {
        throw new RuntimeException('PRIVILEGE_RETAINED_STATE_CHANGED');
    }
    if ($mode === 'cron' && !$DB->record_exists_sql('SELECT id FROM {task_log}
            WHERE id > :id AND classname LIKE :name AND result = 0',
            ['id' => $state['last_task_log_id'], 'name' => '%prune_connect_identity'])) {
        throw new RuntimeException('PRIVILEGE_SCHEDULED_TASK_NOT_EXECUTED');
    }
    echo 'DB_PRIVILEGES_' . strtoupper($mode) . "=PASS\n";
} else if ($mode === 'denials') {
    $connection = pg_connect('host=db dbname=ustar_stage1 user=ustar_app '
        . "password=fixture-app-only-not-production-secret connect_timeout=5 options='-c statement_timeout=5000'",
        PGSQL_CONNECT_FORCE_NEW);
    if (!$connection) { throw new RuntimeException('PRIVILEGE_DENIAL_CONNECTION_FAILED'); }
    $states = [];
    foreach ([
        'create_role' => 'CREATE ROLE ustar_denied_role',
        'create_database' => 'CREATE DATABASE ustar_denied_database',
        'server_file' => "SELECT pg_catalog.pg_read_file('PG_VERSION',0,32)",
        'server_program' => "COPY (SELECT 1) TO PROGRAM 'true'",
        'role_password_catalog' => 'SELECT rolpassword FROM pg_catalog.pg_authid LIMIT 1',
    ] as $name => $sql) {
        if (!pg_send_query($connection, $sql)) { throw new RuntimeException('PRIVILEGE_QUERY_NOT_SENT'); }
        $result = pg_get_result($connection);
        $state = $result ? pg_result_error_field($result, PGSQL_DIAG_SQLSTATE) : null;
        while (pg_get_result($connection) !== false) { /* Drain exactly this statement. */ }
        if ($state !== '42501') { throw new RuntimeException('PRIVILEGE_EXPECTED_42501_' . $name); }
        $states[$name] = $state;
    }
    pg_close($connection);
    file_put_contents('/artifacts/db-privileges-denials.json', json_encode($states, JSON_THROW_ON_ERROR));
    echo "DB_PRIVILEGES_CLUSTER_DENIALS=PASS\n";
} else {
    throw new RuntimeException('INVALID_PRIVILEGE_PROBE_MODE');
}
