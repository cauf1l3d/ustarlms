<?php
// Seed and inspect only the isolated USTAR work migration stage.
if (getenv('USTAR_DISPOSABLE_FIXTURE') !== 'workplace') {
    throw new RuntimeException('Expected the work migration fixture.');
}
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
require_once($CFG->dirroot . '/user/lib.php');

use local_ustar\{chat_files, chat_groups};
use local_ustar\briefing\{meetings, service as briefing};
use local_ustar\task_workspace\{calendar, discussion, service as tasks};

if ($CFG->wwwroot !== 'http://ustar-stage1.invalid' || $CFG->prefix !== 'stage_' || $CFG->dbname !== 'ustar_stage1') {
    throw new RuntimeException('Refusing to operate outside the disposable fixture.');
}

function assert_fixture(bool $value, string $label): void {
    if (!$value) {
        throw new RuntimeException($label);
    }
}

$admin = (int)get_admin()->id;
\core\session\manager::set_user(get_admin());
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/ustar/tasks.php'));

if (($argv[1] ?? '') === 'seed') {
    assert_fixture((int)get_config('local_ustar', 'version') === 2026111800, 'Wrong briefing baseline');
    set_config('theme', 'ustar');
    set_config('messaging', 1);
    set_config('messagingallusers', 1);
    $role = create_role('Workplace fixture', 'workplace_fixture', 'Disposable work migration role');
    foreach (['local/ustar:use', 'moodle/site:sendmessage'] as $capability) {
        assign_capability($capability, CAP_ALLOW, $role, context_system::instance()->id);
    }
    $users = [];
    foreach (['member', 'colleague', 'outsider'] as $name) {
        $username = 'work_' . $name;
        $id = user_create_user((object)['username' => $username, 'firstname' => 'Work', 'lastname' => ucfirst($name),
            'email' => $username . '@example.invalid', 'confirmed' => 1, 'auth' => 'manual',
            'mnethostid' => $CFG->mnet_localhost_id,
            'password' => 'Fixture-Only-Password1!'], true, false);
        unset_user_preference('auth_forcepasswordchange', $id);
        role_assign($role, $id, context_system::instance()->id);
        $users[$name] = (int)$id;
    }

    $head = $DB->insert_record('local_ustar_staff_places', (object)['placecode' => 'work-lead',
        'departmentid' => 'retail', 'positionid' => 'retail_head']);
    $lead = $DB->insert_record('local_ustar_assignments', (object)['userid' => $admin, 'staffplaceid' => $head,
        'assignmenttype' => 'primary']);
    assert_fixture((int)$lead > 0, 'Missing manager position');
    foreach ($users as $id) {
        $place = $DB->insert_record('local_ustar_staff_places', (object)['placecode' => 'work-fixture-' . $id,
            'departmentid' => 'retail', 'positionid' => 'retail_seller', 'managerplaceid' => $head]);
        $DB->insert_record('local_ustar_assignments', (object)['userid' => $id, 'staffplaceid' => $place,
            'assignmenttype' => 'primary']);
    }
    $date = calendar::date(time() + DAYSECS * 2, briefing::ZONE);
    $start = calendar::timestamp($date, '11:00', briefing::ZONE);
    $task = tasks::create($admin, $users['member'], ['kind' => 'task', 'title' => 'Workplace review fixture',
        'description' => 'A synthetic task survives the upgrade', 'date' => $date, 'clock' => '16:00']);
    meetings::save_room($admin, 0, 0, 'Fixture small room', 2, true);
    $room = (int)array_key_first(meetings::rooms($admin));
    $meeting = meetings::save($admin, 0, 0, 'originalmeetingworkplacefixture', 'Private appointment name', 'Private agenda',
        $room, $start, $start + HOURSECS, [$users['member']]);
    \core\session\manager::set_user($DB->get_record('user', ['id' => $users['member']]));
    meetings::respond($users['member'], $meeting, 1, 'accepted');
    \core\session\manager::set_user(get_admin());
    $chat = chat_groups::create($admin, 'Archive pilot group', [$users['member'], $users['colleague']]);
    $now = time() - HOURSECS;
    $rows = [];
    for ($i = 1; $i <= 700; $i++) {
        $text = ($i === 700 ? 'archive-search-key ' : '') . 'History line ' . $i . ' ' . str_repeat('synthetic text ', 25);
        $rows[] = (object)['useridfrom' => $admin, 'conversationid' => $chat, 'fullmessage' => $text,
            'fullmessageformat' => FORMAT_PLAIN, 'smallmessage' => $text, 'timecreated' => $now + intdiv($i, 3)];
    }
    $DB->insert_records('messages', $rows);
    echo json_encode(['task' => $task, 'chat' => $chat, 'room' => $room, 'meeting' => $meeting, 'date' => $date,
        'member' => $users['member'], 'colleague' => $users['colleague'], 'outsider' => $users['outsider']], JSON_THROW_ON_ERROR), "\n";
    exit;
}

assert_fixture((int)get_config('local_ustar', 'version') === 2026112301, 'Wrong workplace version');
$task = $DB->get_record('local_ustar_learning_tasks', ['title' => 'Workplace review fixture'], '*', MUST_EXIST);
if ($argv[1] === 'inspect') {
    assert_fixture($task->status === 'assigned' && (int)$task->version === 1, 'Plan or chat modified the canonical task');
    $conversation = $DB->get_record('message_conversations', ['itemtype' => discussion::ITEMTYPE, 'itemid' => $task->id], '*', MUST_EXIST);
    assert_fixture((int)$conversation->enabled === 0, 'Task discussion must be separate from the native inbox');
    assert_fixture(!$DB->record_exists('message_conversation_members', ['conversationid' => $conversation->id]), 'Persistent native chat membership');
    $copies = $DB->get_records_sql("SELECT f.id, f.contenthash FROM {files} f JOIN {messages} m ON m.id = f.itemid
        WHERE f.component = 'local_ustar' AND f.filearea = :area AND f.filename = 'workplace-large.txt'
            AND m.conversationid = :conversation", ['area' => chat_files::AREA, 'conversation' => $conversation->id]);
    assert_fixture(count($copies) === 2 && count(array_unique(array_column($copies, 'contenthash'))) === 1, 'Blob deduplication');
    echo "WORKPLACE_NATIVE_MESSAGE_AND_FILE_POOL=OK\n";
    exit;
}
throw new RuntimeException('Unknown fixture action.');
