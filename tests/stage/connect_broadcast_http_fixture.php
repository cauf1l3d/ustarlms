<?php
// Isolated native Moodle, private-bridge wire format and File API only.
if (__DIR__ !== '/source/tests/stage' || getenv('USTAR_STAGE_PREFIX') !== 'stage_'
        || getenv('USTAR_STAGE_BROADCAST_FIXTURE') !== '1') {
    throw new RuntimeException('isolated_broadcast_fixture_required');
}
if (PHP_SAPI === 'cli-server') {
    if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1'
            || $_SERVER['REQUEST_URI'] !== '/local/ustar/connect_bridge.php') {
        http_response_code(404);
        exit;
    }
    // Emulate only Apache's trusted HTTPS server variable in the loopback test fixture.
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SCRIPT_NAME'] = '/local/ustar/connect_bridge.php';
    require '/stage/moodle/public/local/ustar/connect_bridge.php';
    exit;
}
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
require_once($CFG->dirroot . '/user/lib.php');
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . ' ' . ($error->debuginfo ?? '') . "\n"
        . $error->getTraceAsString() . "\n");
    exit(1);
});

if (($argv[1] ?? '') === 'verify') {
    $row = $DB->get_record_sql('SELECT d.* FROM {local_ustar_notify_delivery} d
        JOIN {local_ustar_notifications} n ON n.id = d.notificationid
        WHERE n.eventtype = :event AND d.channel = :channel',
        ['event' => 'feed_broadcast', 'channel' => 'telegram'], MUST_EXIST);
    if ($row->status !== 'provider_accepted' || (int)$row->attempts !== 1 || $row->providerref !== '991') {
        throw new RuntimeException('unexpected_broadcast_receipt');
    }
    echo "CONNECT_BROADCAST_WIRE_RECEIPT=PASS\n";
    exit;
}

if (($argv[1] ?? '') === 'seed-widget') {
    $userid = user_create_user((object)[
        'username' => 'widgetfixture', 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id, 'firstname' => 'Synthetic',
        'lastname' => 'Checklist', 'email' => 'widgetfixture@example.invalid',
    ], false, false);
    $role = create_role('Widget fixture', 'widgetwire', '');
    $context = context_system::instance();
    assign_capability('local/ustar:use', CAP_ALLOW, $role, $context->id);
    role_assign($role, $userid, $context->id);
    \core\session\manager::set_user(get_admin());
    \local_ustar\checklists::save(['items' => [[
        'id' => 'widget_fixture', 'title' => 'Контроль зала', 'active' => true, 'positionIds' => [],
        'sections' => [['title' => 'Проверка', 'items' => [
            ['id' => 'cash', 'title' => 'Проверить кассу'],
            ['id' => 'shelf', 'title' => 'Проверить полки'],
        ]]],
    ]]]);
    \local_ustar\task_workspace\service::create((int)get_admin()->id, $userid, [
        'title' => 'Собрать заказ',
        'description' => 'Проверить позиции по накладной',
        'date' => gmdate('Y-m-d', time() + DAYSECS),
        'clock' => '17:00',
    ]);
    \core\session\manager::set_user(get_complete_user_data('id', $userid));
    $sender = new \local_ustar\connect\identity('telegram', '123456', '88888');
    $challenge = \local_ustar\connect\identity_service::issue('telegram', '123456');
    \local_ustar\connect\identity_service::claim_verified($challenge['code'], $sender, hash('sha256', $challenge['code']));
    \local_ustar\connect\identity_service::confirm($challenge['code']);
    echo $userid;
    exit;
}

if (($argv[1] ?? '') === 'verify-widget') {
    $userid = (int)getenv('USTAR_STAGE_WIDGET_USERID');
    $where = ['userid' => $userid, 'checklistkey' => 'widget_fixture'];
    $run = $DB->get_record('local_ustar_check_runs', $where, '*', MUST_EXIST);
    $answer = $DB->get_field('local_ustar_check_answers', 'checked',
        ['runid' => $run->id, 'itemkey' => 'shelf'], MUST_EXIST);
    if (!$userid || $run->status !== 'partial' || (int)$run->revision !== 1 || (int)$answer !== 0
            || $DB->count_records('local_ustar_check_submits', $where) !== 1) {
        throw new RuntimeException('unexpected_widget_receipt');
    }
    echo "CONNECT_CHECKLIST_WIDGET_WIRE=PASS\n";
    exit;
}

if (($argv[1] ?? '') === 'verify-task-widget') {
    $userid = (int)getenv('USTAR_STAGE_WIDGET_USERID');
    $task = $DB->get_record('local_ustar_learning_tasks', ['assigneeid' => $userid,
        'title' => 'Собрать заказ'], '*', MUST_EXIST);
    $events = $DB->count_records('local_ustar_learning_task_events', [
        'taskid' => $task->id, 'eventtype' => 'task_started', 'actorid' => $userid,
    ]);
    if (!$userid || $task->status !== 'in_progress' || (int)$task->version !== 2 || $events !== 1
            || $DB->record_exists('local_ustar_task_reports', ['taskid' => $task->id])) {
        throw new RuntimeException('unexpected_task_widget_receipt');
    }
    echo "CONNECT_TASK_WIDGET_WIRE=PASS\n";
    exit;
}

$userid = user_create_user((object)[
    'username' => 'broadcastfixture', 'auth' => 'manual', 'password' => 'Fixture-Only-Password1!',
    'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id, 'firstname' => 'Лента',
    'lastname' => 'Фикстура', 'email' => 'broadcastfixture@example.invalid',
]);
$context = context_system::instance();
$role = create_role('Broadcast fixture', 'broadcastwire', '');
assign_capability('local/ustar:use', CAP_ALLOW, $role, $context->id);
role_assign($role, $userid, $context->id);
\core\session\manager::set_user($DB->get_record('user', ['id' => $userid], '*', MUST_EXIST));
$sender = new \local_ustar\connect\identity('telegram', '123456', '77777');
$challenge = \local_ustar\connect\identity_service::issue('telegram', '123456');
\local_ustar\connect\identity_service::claim_verified($challenge['code'], $sender, hash('sha256', $challenge['code']));
\local_ustar\connect\identity_service::confirm($challenge['code']);
\local_ustar\connect\notification_preferences::save_own([
    'enabled' => true, 'categories' => ['feed'], 'quiet_start' => '00:00', 'quiet_end' => '00:00',
] + \local_ustar\connect\notification_preferences::defaults());

$admin = get_admin();
\core\session\manager::set_user($admin);
$actor = (int)$admin->id;
$post = \local_ustar\feed_service::create($actor, 'academy', 'academy', ['user:' . $userid], 'Склад РЦ · сообщение',
    true, 0, [], bin2hex(random_bytes(16)));
get_file_storage()->create_file_from_string([
    'contextid' => $context->id, 'component' => 'local_ustar',
    'filearea' => \local_ustar\feed_files::AREA, 'itemid' => $post, 'filepath' => '/v1/', 'filename' => 'График РЦ.txt',
], 'Synthetic attachment');
$preview = \local_ustar\connect\announcements::preview($actor, $post);
if (array_map('intval', array_column($preview['people'], 'id')) !== [(int)$userid]) {
    $event = \local_ustar\connect\announcements::BROADCAST_EVENT;
    $preferences = \local_ustar\connect\notification_preferences::for_user($userid);
    $publication = $DB->get_record('local_ustar_feed_posts', ['id' => $post], '*', MUST_EXIST);
    $notice = (object)[
        'userid' => $userid, 'eventtype' => $event, 'status' => 'unread', 'severity' => 'normal',
        'timecreated' => time(), 'idempotencykey' => $preview['sendable']
            ? \local_ustar\connect\broadcast_content::key($publication, $userid, $preview['stamp']) : '',
    ];
    $audience = $DB->get_fieldset_select('local_ustar_feed_audience', 'scopekind', 'postid = ?', [$post]);
    throw new RuntimeException('fixture_recipient_not_eligible: ' . json_encode([
        'announcements' => \local_ustar\connect\announcements::enabled(),
        'candidates' => count(\local_ustar\connect\notification_delivery::announcement_candidates()),
        'in_pilot' => \local_ustar\connect\notification_delivery::recipient_enabled($userid),
        'employment' => \local_ustar\employment::resolve($userid)['status'],
        'employee' => \local_ustar\accounts::is_business_account($userid),
        'feed_access' => has_capability('local/ustar:use', $context, $userid),
        'audience' => $audience,
        'opted_in' => $preferences['enabled'] && in_array('feed', $preferences['categories'], true),
        'permitted' => \local_ustar\connect\notification_policy::permitted($notice),
        'sendable' => $preview['sendable'], 'content_error' => $preview['broadcast_error'],
    ], JSON_THROW_ON_ERROR));
}
\local_ustar\connect\announcements::enqueue($actor, $post, $preview['fingerprint'], [(int)$userid]);
