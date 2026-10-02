<?php
define('CLI_SCRIPT', true);
require('/var/www/html/public/config.php');

global $CFG, $DB;

if (!in_array($CFG->wwwroot, [
    'http://127.0.0.1:18080',
    'http://127.0.0.1:18081',
    'http://127.0.0.1:18082',
], true) || empty($CFG->noemailever)) {
    fwrite(STDERR, "Refusing non-isolated Moodle instance\n");
    exit(2);
}

function table_exists(string $name): bool {
    global $DB;
    return $DB->get_manager()->table_exists(new xmldb_table($name));
}

function count_distinct(string $table, string $field, string $where = '', array $params = []): int {
    global $DB;
    $sql = "SELECT COUNT(DISTINCT {$field}) FROM {{$table}}";
    if ($where !== '') {
        $sql .= " WHERE {$where}";
    }
    return (int)$DB->get_field_sql($sql, $params);
}

$tables = [
    'notifications',
    'messages',
    'message_conversations',
    'message_conversation_members',
    'message_user_actions',
    'message_popup_notifications',
    'message_providers',
    'message_processors',
    'task_scheduled',
    'task_adhoc',
    'local_ustar_goals',
    'local_ustar_reviews',
    'local_ustar_hr_actions',
];

$result = [
    'guard' => 'isolated',
    'wwwroot' => $CFG->wwwroot,
    'tables' => [],
];
foreach ($tables as $table) {
    $result['tables'][$table] = table_exists($table);
}

if (table_exists('notifications')) {
    $records = $DB->get_records('notifications', null, '', 'id,component,eventtype,contexturl,timecreated,timeread');
    $components = [];
    $eventtypes = [];
    $externalurls = 0;
    $relativeurls = 0;
    $emptyurls = 0;
    $readbeforecreated = 0;
    foreach ($records as $record) {
        $component = (string)$record->component;
        $eventtype = (string)$record->eventtype;
        $components[$component] = ($components[$component] ?? 0) + 1;
        $eventtypes[$eventtype] = ($eventtypes[$eventtype] ?? 0) + 1;
        $url = trim((string)$record->contexturl);
        if ($url === '') {
            $emptyurls++;
        } else if (preg_match('~^https?://~i', $url)) {
            $externalurls++;
        } else {
            $relativeurls++;
        }
        if (!empty($record->timeread) && (int)$record->timeread < (int)$record->timecreated) {
            $readbeforecreated++;
        }
    }
    arsort($components);
    arsort($eventtypes);
    $result['notifications'] = [
        'total' => count($records),
        'unread' => $DB->count_records_select('notifications', 'timeread IS NULL'),
        'recipients' => count_distinct('notifications', 'useridto'),
        'ustar_component' => $DB->count_records('notifications', ['component' => 'local_ustar']),
        'components' => $components,
        'eventtypes' => $eventtypes,
        'external_urls' => $externalurls,
        'relative_urls' => $relativeurls,
        'empty_urls' => $emptyurls,
        'read_before_created' => $readbeforecreated,
        'priority_field' => $DB->get_manager()->field_exists(new xmldb_table('notifications'), new xmldb_field('priority')),
        'deadline_field' => $DB->get_manager()->field_exists(new xmldb_table('notifications'), new xmldb_field('deadline')),
        'ack_field' => $DB->get_manager()->field_exists(new xmldb_table('notifications'), new xmldb_field('acknowledged')),
    ];
}

$messagecounts = [];
foreach (['messages', 'message_conversations', 'message_conversation_members', 'message_user_actions', 'message_popup_notifications'] as $table) {
    if (table_exists($table)) {
        $messagecounts[$table] = $DB->count_records($table);
    }
}
$result['messaging'] = $messagecounts;

if (table_exists('message_processors')) {
    $processors = [];
    foreach ($DB->get_records('message_processors', null, 'name ASC', 'id,name,enabled') as $processor) {
        $processors[(string)$processor->name] = !empty($processor->enabled);
    }
    $result['message_processors'] = $processors;
}
if (table_exists('message_providers')) {
    $result['ustar_message_providers'] = $DB->count_records('message_providers', ['component' => 'local_ustar']);
}

if (table_exists('local_ustar_goals')) {
    $now = time();
    $goals = $DB->get_records('local_ustar_goals', null, '', 'id,userid,targettype,duedate,completed,timecreated');
    $targettypes = [];
    $overdue = 0;
    $noexpiry = 0;
    foreach ($goals as $goal) {
        $targettype = (string)$goal->targettype;
        $targettypes[$targettype] = ($targettypes[$targettype] ?? 0) + 1;
        if (empty($goal->duedate)) {
            $noexpiry++;
        } else if (empty($goal->completed) && (int)$goal->duedate < $now) {
            $overdue++;
        }
    }
    $result['goals'] = [
        'total' => count($goals),
        'users' => count_distinct('local_ustar_goals', 'userid'),
        'completed' => $DB->count_records('local_ustar_goals', ['completed' => 1]),
        'open' => $DB->count_records('local_ustar_goals', ['completed' => 0]),
        'overdue_open' => $overdue,
        'without_due_date' => $noexpiry,
        'targettypes' => $targettypes,
        'status_field' => $DB->get_manager()->field_exists(new xmldb_table('local_ustar_goals'), new xmldb_field('status')),
        'completed_at_field' => $DB->get_manager()->field_exists(new xmldb_table('local_ustar_goals'), new xmldb_field('completedat')),
        'owner_or_assignee_field' => $DB->get_manager()->field_exists(new xmldb_table('local_ustar_goals'), new xmldb_field('ownerid')),
        'history_table' => table_exists('local_ustar_goal_events'),
    ];
}

if (table_exists('local_ustar_reviews')) {
    $result['reviews'] = [
        'total' => $DB->count_records('local_ustar_reviews'),
        'targets' => count_distinct('local_ustar_reviews', 'userid'),
        'reviewers' => count_distinct('local_ustar_reviews', 'reviewerid'),
        'min_score' => (int)$DB->get_field_sql('SELECT COALESCE(MIN(score), 0) FROM {local_ustar_reviews}'),
        'max_score' => (int)$DB->get_field_sql('SELECT COALESCE(MAX(score), 0) FROM {local_ustar_reviews}'),
        'version_field' => $DB->get_manager()->field_exists(new xmldb_table('local_ustar_reviews'), new xmldb_field('version')),
        'status_field' => $DB->get_manager()->field_exists(new xmldb_table('local_ustar_reviews'), new xmldb_field('status')),
        'approved_by_field' => $DB->get_manager()->field_exists(new xmldb_table('local_ustar_reviews'), new xmldb_field('approvedby')),
    ];
}

if (table_exists('local_ustar_hr_actions')) {
    $actions = [];
    $invalidjson = 0;
    foreach ($DB->get_records('local_ustar_hr_actions', null, '', 'id,action,detailsjson') as $record) {
        $action = (string)$record->action;
        $actions[$action] = ($actions[$action] ?? 0) + 1;
        json_decode((string)$record->detailsjson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $invalidjson++;
        }
    }
    arsort($actions);
    $result['hr_actions'] = [
        'total' => array_sum($actions),
        'actors' => count_distinct('local_ustar_hr_actions', 'actorid'),
        'targets' => count_distinct('local_ustar_hr_actions', 'targetuserid', 'targetuserid IS NOT NULL'),
        'null_actor' => $DB->count_records_select('local_ustar_hr_actions', 'actorid IS NULL'),
        'null_target' => $DB->count_records_select('local_ustar_hr_actions', 'targetuserid IS NULL'),
        'invalid_json' => $invalidjson,
        'actions' => $actions,
    ];
}

$scheduled = [];
if (table_exists('task_scheduled')) {
    foreach ($DB->get_records('task_scheduled', null, '', 'id,classname,disabled') as $task) {
        if (str_contains((string)$task->classname, 'local_ustar')) {
            $scheduled[(string)$task->classname] = !empty($task->disabled) ? 'disabled' : 'enabled';
        }
    }
}
$adhoc = 0;
if (table_exists('task_adhoc')) {
    foreach ($DB->get_records('task_adhoc', null, '', 'id,classname') as $task) {
        if (str_contains((string)$task->classname, 'local_ustar')) {
            $adhoc++;
        }
    }
}
$result['ustar_tasks'] = [
    'scheduled' => $scheduled,
    'adhoc' => $adhoc,
    'official_task_table' => table_exists('local_ustar_tasks'),
    'personal_task_table' => table_exists('local_ustar_personal_tasks'),
    'notification_rule_table' => table_exists('local_ustar_notification_rules'),
    'delivery_attempt_table' => table_exists('local_ustar_notification_deliveries'),
    'escalation_table' => table_exists('local_ustar_escalations'),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
