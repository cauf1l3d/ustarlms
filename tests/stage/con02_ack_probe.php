<?php
// Synthetic native upgrade/HTTP fixture. Never run on a real Academy.
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
require_once($CFG->dirroot.'/user/lib.php');
if ($CFG->dbhost !== 'db' || $CFG->dbname !== 'ustar_stage1' || $CFG->dbuser !== 'ustar_fixture'
        || $CFG->prefix !== 'stage_' || __DIR__ !== '/source/tests/stage') {
    throw new RuntimeException('REFUSING_NON_ISOLATED_CON02_ACK');
}
use local_ustar\connect\{identity,identity_service,notification_preferences,notification_delivery};

function con02_user(int $id): void {
    global $DB, $USER;
    \core\session\manager::set_user($DB->get_record('user', ['id' => $id], '*', MUST_EXIST));
    $USER->sesskey = 'synthetic-con02-cli-session';
}
function con02_notice(int $userid): int {
    return \local_ustar\target_core::notify(['userid' => $userid, 'severity' => 'action', 'eventtype' => 'general',
        'subject' => 'Synthetic CON02 acknowledgement', 'message' => 'Private details stay in Academy.',
        'idempotencykey' => 'con02-'.bin2hex(random_bytes(12))]);
}
function con02_hash(array $rows): string { return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)); }
function con02_snapshot(array $state): array {
    global $DB;
    return [
        'notice_hash' => con02_hash($DB->get_records_list('local_ustar_notifications', 'id', $state['notices'], 'id')),
        'delivery_hash' => con02_hash($DB->get_records_list('local_ustar_notify_delivery', 'notificationid', $state['notices'], 'id')),
        'task_hash' => con02_hash($DB->get_records('local_ustar_learning_tasks', ['id' => $state['task']], 'id')),
        'preferences_hash' => con02_hash($DB->get_records('user_preferences', ['userid' => $state['userid']], 'id')),
        'links_hash' => con02_hash($DB->get_records('local_ustar_conn_links', ['userid' => $state['userid']], 'id')),
        'bridge_operations' => \local_ustar\connect\bridge_auth::OPERATIONS,
        'schema' => [array_keys($DB->get_columns('local_ustar_notifications')), array_keys($DB->get_columns('local_ustar_notify_delivery'))],
    ];
}
$file = '/stage/con02-ack-state.json'; $mode = $argv[1] ?? '';
if ($mode === 'seed') {
    if ((int)get_config('local_ustar', 'version') !== 2026102000 || file_exists($file)) {
        throw new RuntimeException('Invalid CON02 baseline');
    }
    $userid = user_create_user((object)['auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
        'username' => 'con02_ack_employee', 'password' => hash_internal_user_password('Ack-Fixture-Only-Password1!'),
        'firstname' => 'Synthetic', 'lastname' => 'ACK', 'email' => 'con02-ack@example.invalid'], false, false);
    $foreign = user_create_user((object)['auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
        'username' => 'con02_ack_other', 'firstname' => 'Synthetic', 'lastname' => 'Other',
        'email' => 'con02-other@example.invalid'], false, false);
    $role = create_role('ACK fixture', 'con02_ack_fixture', 'Synthetic only');
    assign_capability('local/ustar:use', CAP_ALLOW, $role, \context_system::instance()->id);
    role_assign($role, $userid, \context_system::instance()->id);
    con02_user((int)get_admin()->id);
    $task = \local_ustar\task_workspace\service::create((int)get_admin()->id, (int)$userid,
        ['kind' => 'task', 'title' => 'Synthetic unchanged task', 'date' => gmdate('Y-m-d', time()+DAYSECS),
            'clock' => '17:00', 'policy' => \local_ustar\task_workspace\policy::validate(['timezone' => 'UTC', 'mode' => 'calendar'])]);
    con02_user((int)$userid);
    $code = identity_service::issue('telegram', '123456');
    identity_service::claim_verified($code['code'], new identity('telegram', '123456', '77788001'), hash('sha256', $code['code']));
    identity_service::confirm($code['code']);
    notification_preferences::save_own(['enabled' => true, 'categories' => ['tasks'], 'min_severity' => 'normal',
        'quiet_start' => '00:00', 'quiet_end' => '00:00', 'timezone' => 'UTC', 'interval_minutes' => 1]);
    $own = con02_notice((int)$userid); $other = con02_notice((int)$foreign); $ids = [$own, $other];
    foreach (['disabled', 'pending', 'provider_accepted', 'uncertain', 'failed', 'cancelled'] as $status) {
        $nid = con02_notice((int)$userid); $ids[] = $nid;
        $DB->insert_record('local_ustar_notify_delivery', (object)['notificationid' => $nid,
            'channel' => 'telegram', 'status' => $status, 'attempts' => 2, 'connectversion' => 0,
            'timecreated' => time(), 'timemodified' => time()]);
    }
    $DB->set_field('local_ustar_notifications', 'status', 'read', ['id' => $ids[2]]);
    \local_ustar\target_core::acknowledge_notification($ids[3], (int)$userid);
    $state = ['userid' => (int)$userid, 'task' => $task, 'own' => $own, 'foreign' => $other, 'notices' => $ids];
    $state['snapshot'] = con02_snapshot($state);
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR)); chmod($file, 0600);
    echo "CON02_ACK_BASELINE_SEEDED=PASS\n"; exit;
}
$state = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
if ($mode === 'verify') {
    if ((int)get_config('local_ustar', 'version') !== 2026102100 || con02_snapshot($state) !== $state['snapshot']) {
        throw new RuntimeException('ACK upgrade/repeat changed canonical history, task, link, preferences, scope or schema');
    }
    echo "CON02_ACK_UPGRADE_HISTORY_SCOPE_SCHEMA=PASS\n";
} else if ($mode === 'status') {
    $row = $DB->get_record('local_ustar_notifications', ['id' => $state['own']], '*', MUST_EXIST);
    echo json_encode(['status' => $row->status, 'ackat' => $row->ackat === null ? null : (int)$row->ackat,
        'foreign' => $DB->get_field('local_ustar_notifications', 'status', ['id' => $state['foreign']]),
        'task_unchanged' => con02_snapshot($state)['task_hash'] === $state['snapshot']['task_hash'],
        'delivery_unchanged' => con02_snapshot($state)['delivery_hash'] === $state['snapshot']['delivery_hash']]);
} else if ($mode === 'wire') {
    $CFG->ustar_connect_channels = ['telegram' => ['tenant' => '123456', 'username' => 'ustarcorp_bot']];
    $CFG->ustar_connect_notifications_enabled = true; $CFG->ustar_connect_notify_since = time()-1;
    $CFG->ustar_connect_notify_userids = [$state['userid']];
    con02_user($state['userid']);
    \local_ustar\workflow_notifications::enqueue($state['userid'], 'task_revised', 'Synthetic', 'No transport body',
        '/local/ustar/tasks.php', 'task-revised:'.$state['task'].':con02');
    $lease = notification_delivery::lease('tg_delivery', 'telegram', '123456');
    if (!$lease) { throw new RuntimeException('Legacy v1 lease missing'); }
    $permit = notification_delivery::begin($lease['delivery'], $lease['lease'], 'tg_delivery', 'telegram', '123456');
    $receipt = notification_delivery::finish($lease['delivery'], $lease['lease'], 'provider_accepted', '991', '', 0,
        'tg_delivery', 'telegram', '123456');
    if ($receipt !== ['status' => 'provider_accepted']) { throw new RuntimeException('Legacy v1 receipt changed'); }
    file_put_contents('/stage/con02-old-wire.json', json_encode(compact('lease', 'permit', 'receipt'), JSON_THROW_ON_ERROR));
    chmod('/stage/con02-old-wire.json', 0600);
    echo "CON02_ACK_NATIVE_V1_LEASE_BEGIN_FINISH=PASS\n";
} else if ($mode === 'restored') {
    if ((int)get_config('local_ustar', 'version') !== 2026102000 || con02_snapshot($state) !== $state['snapshot']) {
        throw new RuntimeException('CON02 paired restore changed baseline state');
    }
    echo "CON02_ACK_PAIRED_RESTORE=PASS\n";
} else { throw new RuntimeException('Unknown CON02 fixture mode'); }
