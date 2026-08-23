<?php
define('CLI_SCRIPT', true);
require('/var/www/html/public/config.php');

global $CFG, $DB, $USER;

if (!in_array($CFG->wwwroot, [
    'http://127.0.0.1:18080',
    'http://127.0.0.1:18081',
    'http://127.0.0.1:18082',
], true) || empty($CFG->noemailever)) {
    fwrite(STDERR, "Refusing non-isolated Moodle instance\n");
    exit(2);
}

$employee = $DB->get_record('user', ['username' => 'audit_employee'], '*', MUST_EXIST);
$peer = $DB->get_record('user', ['username' => 'audit_retail_head'], '*', MUST_EXIST);
$hr = $DB->get_record('user', ['username' => 'audit_hr'], '*', MUST_EXIST);
$originaluser = $USER;
$started = time();
$expectedUnknownAction = getenv('USTAR_EXPECT_UNKNOWN_GOAL_ACTION') ?: 'rejected';
if (!in_array($expectedUnknownAction, ['accepted', 'rejected'], true)) {
    fwrite(STDERR, "USTAR_EXPECT_UNKNOWN_GOAL_ACTION must be accepted or rejected\n");
    exit(2);
}
$notificationids = [];
$goalid = 0;
$reviewid = 0;
$actionids = [];

$baseline = [
    'notifications' => $DB->count_records('notifications'),
    'ustar_notifications' => $DB->count_records('notifications', ['component' => 'local_ustar']),
    'goals' => $DB->count_records('local_ustar_goals'),
    'reviews' => $DB->count_records('local_ustar_reviews'),
    'hr_actions' => $DB->count_records('local_ustar_hr_actions'),
];

$result = [
    'baseline' => $baseline,
    'expected_unknown_goal_action' => $expectedUnknownAction,
    'notification_owner_list_only' => false,
    'notification_cross_user_mark_denied' => false,
    'notification_owner_mark_read' => false,
    'foreign_conversation_denied' => null,
    'goal_create' => false,
    'goal_cross_user_write_denied' => false,
    'goal_unknown_action_rejected' => false,
    'goal_unknown_action_accepted' => false,
    'goal_complete' => false,
    'goal_delete_hard_removes_row' => false,
    'review_employee_denied' => false,
    'review_invalid_score_denied' => false,
    'review_hr_create' => false,
    'review_audit_row_created' => false,
];

function insert_audit_notification(int $from, int $to, string $suffix): int {
    global $DB;
    return (int)$DB->insert_record('notifications', (object)[
        'useridfrom' => $from,
        'useridto' => $to,
        'subject' => '__audit_workflow_notification_' . $suffix . '__',
        'fullmessage' => '__audit_workflow_notification__',
        'fullmessageformat' => FORMAT_PLAIN,
        'fullmessagehtml' => '',
        'smallmessage' => '__audit_workflow_notification__',
        'component' => 'local_ustar',
        'eventtype' => 'audit_workflow',
        'contexturl' => '',
        'contexturlname' => '',
        'customdata' => null,
        'timecreated' => time(),
        'timeread' => null,
    ]);
}

try {
    $employeeNotification = insert_audit_notification((int)$hr->id, (int)$employee->id, 'employee');
    $peerNotification = insert_audit_notification((int)$hr->id, (int)$peer->id, 'peer');
    $notificationids = [$employeeNotification, $peerNotification];

    $employeeRows = \local_ustar\communication::notifications((int)$employee->id, 200);
    $employeeRowIds = array_map(static fn(array $row): int => (int)$row['id'], $employeeRows);
    $result['notification_owner_list_only'] =
        in_array($employeeNotification, $employeeRowIds, true) &&
        !in_array($peerNotification, $employeeRowIds, true);

    try {
        \local_ustar\communication::mark_notification((int)$employee->id, $peerNotification);
    } catch (\dml_missing_record_exception $e) {
        $result['notification_cross_user_mark_denied'] = true;
    }

    \local_ustar\communication::mark_notification((int)$employee->id, $employeeNotification);
    $result['notification_owner_mark_read'] = (int)$DB->get_field(
        'notifications',
        'timeread',
        ['id' => $employeeNotification, 'useridto' => (int)$employee->id],
        MUST_EXIST
    ) > 0;

    $foreignconversation = $DB->get_field_sql(
        'SELECT c.id
           FROM {message_conversations} c
          WHERE NOT EXISTS (
                    SELECT 1
                      FROM {message_conversation_members} m
                     WHERE m.conversationid = c.id
                       AND m.userid = :userid
                )',
        ['userid' => (int)$employee->id],
        IGNORE_MULTIPLE
    );
    if ($foreignconversation !== false) {
        $result['foreign_conversation_denied'] = false;
        try {
            \local_ustar\communication::conversation((int)$employee->id, (int)$foreignconversation);
        } catch (\required_capability_exception $e) {
            $result['foreign_conversation_denied'] = true;
        }
    }

    \core\session\manager::set_user($employee);
    $created = \local_ustar\external\save_goal::execute(
        'create',
        0,
        '__audit_workflow_goal__',
        time() + DAYSECS
    );
    $goalid = (int)$created['id'];
    $result['goal_create'] = $goalid > 0 && $DB->record_exists('local_ustar_goals', [
        'id' => $goalid,
        'userid' => (int)$employee->id,
    ]);

    \core\session\manager::set_user($peer);
    try {
        \local_ustar\external\save_goal::execute('complete', $goalid);
    } catch (\dml_missing_record_exception $e) {
        $result['goal_cross_user_write_denied'] = true;
    }

    \core\session\manager::set_user($employee);
    try {
        $unknown = \local_ustar\external\save_goal::execute('unknown', $goalid);
        $result['goal_unknown_action_accepted'] = ($unknown['status'] ?? '') === 'ok';
    } catch (\invalid_parameter_exception $e) {
        $result['goal_unknown_action_rejected'] = true;
    }

    \local_ustar\external\save_goal::execute('complete', $goalid);
    $result['goal_complete'] = (int)$DB->get_field('local_ustar_goals', 'completed', ['id' => $goalid], MUST_EXIST) === 1;
    \local_ustar\external\save_goal::execute('delete', $goalid);
    $result['goal_delete_hard_removes_row'] = !$DB->record_exists('local_ustar_goals', ['id' => $goalid]);
    $goalid = 0;

    \core\session\manager::set_user($employee);
    try {
        \local_ustar\external\hr_save_review::execute((int)$peer->id, 4, 'performance', '__audit__', '__audit__');
    } catch (\required_capability_exception $e) {
        $result['review_employee_denied'] = true;
    }

    \core\session\manager::set_user($hr);
    try {
        \local_ustar\external\hr_save_review::execute((int)$employee->id, 0, 'performance', '__audit__', '__audit__');
    } catch (\invalid_parameter_exception $e) {
        $result['review_invalid_score_denied'] = true;
    }

    $savedReview = \local_ustar\external\hr_save_review::execute(
        (int)$employee->id,
        4,
        'performance',
        '__audit_workflow_period__',
        '__audit_workflow_summary__'
    );
    $savedPayload = json_decode((string)$savedReview['json'], true);
    $reviewid = (int)($savedPayload['reviewid'] ?? 0);
    $result['review_hr_create'] = $reviewid > 0 && $DB->record_exists('local_ustar_reviews', [
        'id' => $reviewid,
        'userid' => (int)$employee->id,
        'reviewerid' => (int)$hr->id,
    ]);

    foreach ($DB->get_records_select(
        'local_ustar_hr_actions',
        'actorid = :actorid AND targetuserid = :targetuserid AND action = :action AND timecreated >= :started',
        [
            'actorid' => (int)$hr->id,
            'targetuserid' => (int)$employee->id,
            'action' => 'review_created',
            'started' => $started,
        ]
    ) as $action) {
        $details = json_decode((string)$action->detailsjson, true);
        if ((int)($details['reviewid'] ?? 0) === $reviewid) {
            $actionids[] = (int)$action->id;
        }
    }
    $result['review_audit_row_created'] = count($actionids) === 1;
} finally {
    \core\session\manager::set_user($originaluser);
    foreach ($notificationids as $id) {
        $DB->delete_records('notifications', ['id' => $id, 'component' => 'local_ustar', 'eventtype' => 'audit_workflow']);
    }
    if ($goalid > 0) {
        $DB->delete_records('local_ustar_goals', ['id' => $goalid, 'userid' => (int)$employee->id]);
    }
    if ($reviewid > 0) {
        $DB->delete_records('local_ustar_reviews', ['id' => $reviewid, 'reviewerid' => (int)$hr->id]);
    }
    foreach ($actionids as $id) {
        $DB->delete_records('local_ustar_hr_actions', ['id' => $id, 'action' => 'review_created']);
    }
}

$final = [
    'notifications' => $DB->count_records('notifications'),
    'ustar_notifications' => $DB->count_records('notifications', ['component' => 'local_ustar']),
    'goals' => $DB->count_records('local_ustar_goals'),
    'reviews' => $DB->count_records('local_ustar_reviews'),
    'hr_actions' => $DB->count_records('local_ustar_hr_actions'),
];
$result['final'] = $final;
$result['baseline_restored'] = $baseline === $final;
$result['runtime_boundary_pass'] =
    $result['notification_owner_list_only'] &&
    $result['notification_cross_user_mark_denied'] &&
    $result['notification_owner_mark_read'] &&
    $result['foreign_conversation_denied'] !== false &&
    $result['goal_create'] &&
    $result['goal_cross_user_write_denied'] &&
    (($expectedUnknownAction === 'rejected' && $result['goal_unknown_action_rejected'] && !$result['goal_unknown_action_accepted']) ||
        ($expectedUnknownAction === 'accepted' && $result['goal_unknown_action_accepted'] && !$result['goal_unknown_action_rejected'])) &&
    $result['goal_complete'] &&
    $result['goal_delete_hard_removes_row'] &&
    $result['review_employee_denied'] &&
    $result['review_invalid_score_denied'] &&
    $result['review_hr_create'] &&
    $result['review_audit_row_created'] &&
    $result['baseline_restored'];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['runtime_boundary_pass'] ? 0 : 1);
