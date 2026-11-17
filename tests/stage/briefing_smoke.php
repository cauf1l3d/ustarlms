<?php
// Run only with a disposable Moodle database and fixture accounts.
if (getenv('USTAR_DISPOSABLE_FIXTURE') !== 'briefing') {
    throw new RuntimeException('Set USTAR_DISPOSABLE_FIXTURE=briefing for the isolated test database.');
}
define('CLI_SCRIPT', true);
require $argv[1] . '/config.php';
require_once($CFG->dirroot . '/user/lib.php');

use local_ustar\briefing\{conflict, meetings, page, service as briefing};
use local_ustar\task_workspace\{calendar, service as tasks};

if ($CFG->wwwroot !== 'http://ustar-stage1.invalid' || realpath($argv[1]) !== '/stage/moodle') {
    throw new RuntimeException('Expected the dedicated, isolated Moodle stage fixture.');
}

function check(bool $ok, string $test): void {
    if (!$ok) {
        throw new RuntimeException($test);
    }
}

function reject(string $class, callable $action): void {
    try {
        $action();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Expected ' . $class);
}

function fixture_user(string $name): int {
    $username = 'brief_' . $name . '_' . strtolower(random_string(10));
    return user_create_user((object)['username' => $username, 'email' => $username . '@example.test',
        'firstname' => ucfirst($name), 'lastname' => 'Fixture', 'confirmed' => 1], false, false);
}

$admin = (int)get_admin()->id;
\core\session\manager::set_user(get_admin());
check(briefing::available() && briefing::admin($admin), 'Plugin version and admin');
$week = briefing::current_week();
$start = briefing::week_start($week);
if ($start <= time()) {
    throw new RuntimeException('Run while the weekly fixture report is open (Tuesday through Monday 09:59 Moscow).');
}
check(!meetings::overlaps_briefing($start - HOURSECS, $start), 'Back-to-back before briefing');
check(meetings::overlaps_briefing($start - MINSECS, $start + MINSECS), 'Crossing briefing start');
check(!meetings::overlaps_briefing($start + HOURSECS, $start + 2 * HOURSECS), 'Back-to-back after briefing');
check(briefing::current_week(calendar::timestamp('2026-11-29', '23:59', briefing::ZONE)) === '2026-11-30', 'Moscow Sunday rollover');

$manager = fixture_user('manager');
$outsider = fixture_user('outsider');
$invitee = fixture_user('invitee');
$role = create_role('Briefing fixture', 'brief_' . strtolower(random_string(10)), 'Isolated test');
$system = context_system::instance();
assign_capability('local/ustar:viewteam', CAP_ALLOW, $role, $system->id);
assign_capability('local/ustar:use', CAP_ALLOW, $role, $system->id);
role_assign($role, $manager, $system->id);
role_assign($role, $outsider, $system->id);
role_assign($role, $invitee, $system->id);
role_unassign($role, $invitee, $system->id);
$head = $DB->insert_record('local_ustar_staff_places', (object)[
    'placecode' => 'brief-' . random_string(10), 'departmentid' => 'retail', 'positionid' => 'retail_head']);
$staff = $DB->insert_record('local_ustar_staff_places', (object)[
    'placecode' => 'brief-' . random_string(10), 'departmentid' => 'retail', 'positionid' => 'retail_seller', 'managerplaceid' => $head]);
$DB->insert_record('local_ustar_assignments', (object)['userid' => $manager, 'staffplaceid' => $head, 'assignmenttype' => 'primary']);
$DB->insert_record('local_ustar_assignments', (object)['userid' => $invitee, 'staffplaceid' => $staff, 'assignmenttype' => 'primary']);
$otherplace = $DB->insert_record('local_ustar_staff_places', (object)[
    'placecode' => 'brief-' . random_string(10), 'departmentid' => 'warehouse', 'positionid' => 'warehouse_worker']);
$DB->insert_record('local_ustar_assignments', (object)[
    'userid' => $outsider, 'staffplaceid' => $otherplace, 'assignmenttype' => 'primary']);

$fields = [['label' => 'Weekly revenue', 'type' => 'number', 'required' => true]];
briefing::save_template($admin, 'retail', 0, 'Retail original', 10, true, $fields);
briefing::save_minutes($admin, $week, 0, 'Fixture minute');
meetings::save_room($admin, 0, 0, 'Fixture room', 4, true);
$room = (int)array_key_first(meetings::rooms($admin));
meetings::schedule($admin, 30, $room);

\core\session\manager::set_user($DB->get_record('user', ['id' => $manager]));
check(briefing::allowed($manager) && !briefing::overview_allowed($manager), 'Manager access');
check(!briefing::allowed($outsider), 'Employee outside report');
reject(required_capability_exception::class, static fn() => briefing::form($outsider, 'retail', $week));
reject(required_capability_exception::class, static fn() => briefing::save_template($manager, 'retail', 1, 'Forbidden', 5, true, $fields));
reject(invalid_parameter_exception::class, static fn() => briefing::save_report($manager, 'retail', $week, 0, 1, ['bad'], true));
$reportid = briefing::save_report($manager, 'retail', $week, 0, 1, ['42,5'], false);
check((int)briefing::form($manager, 'retail', $week)['id'] === $reportid, 'Draft identity');
check(briefing::minutes($manager, $week)->content === 'Fixture minute', 'Shared minutes');

\core\session\manager::set_user(get_admin());
check(briefing::summary($admin, $week)['retail']['draft'] && !briefing::summary($admin, $week)['retail']['report'], 'Draft body private');
briefing::save_template($admin, 'retail', 1, 'Retail updated', 10, true, [['label' => 'New field', 'type' => 'text', 'required' => true]]);
\core\session\manager::set_user($DB->get_record('user', ['id' => $manager]));
check(briefing::form($manager, 'retail', $week)['title'] === 'Retail original', 'Pinned form schema');
briefing::save_report($manager, 'retail', $week, 1, 2, ['42,5'], true);
reject(conflict::class, static fn() => briefing::save_report($manager, 'retail', $week, 1, 2, ['999'], true));

$state = ['view' => 'briefing', 'scope' => 'mine', 'briefweek' => $week, 'briefsection' => 'report', 'briefdept' => 'retail'];
$html = (new page($manager, $state))->briefing();
check(str_contains($html, 'Weekly revenue') && str_contains($html, '42,5'), 'Manager form HTML');
$followup = ['title' => 'Follow-up fixture', 'kind' => 'task', 'date' => calendar::date($start, briefing::ZONE),
    'clock' => '15:00', 'briefreport' => $reportid, 'briefkey' => random_string(32)];
$taskid = tasks::create($manager, $invitee, $followup);
check(tasks::create($manager, $invitee, $followup) === $taskid, 'Follow-up double submit is idempotent');
check(isset(briefing::followups($manager, $reportid)[$taskid]), 'Task linked to briefing');
check(tasks::rows($invitee, 'mine', 'all', 0, 0, 0, '', '', 'assigned')['total'] === 1, 'Kanban stage filtering');
$task = tasks::detail($taskid, $invitee);
check(tasks::kanban_moves($task, $invitee)['in_progress'] === 'start', 'Kanban takes canonical action');
check(!tasks::kanban_moves($task, $manager), 'Manager cannot start task for employee');

$key = random_string(32);
$when = $start + 2 * HOURSECS;
$meeting = meetings::save($manager, 0, 0, $key, 'Fixture appointment', 'Private agenda',
    $room, $when, $when + HOURSECS, [$invitee]);
check(meetings::save($manager, 0, 0, $key, 'Fixture appointment', 'Private agenda',
    $room, $when, $when + HOURSECS, [$invitee]) === $meeting, 'Double-submit is idempotent');
reject(invalid_parameter_exception::class, static fn() => meetings::save(
    $manager, 0, 0, random_string(32), 'Overlap', '', $room, $when, $when + MINSECS, []));
reject(invalid_parameter_exception::class, static fn() => meetings::save(
    $manager, 0, 0, random_string(32), 'Recurring overlap', '', $room, $start, $start + MINSECS, []));
check(count(meetings::busy($outsider, $week)[$room]) === 2, 'Occupancy without title');
reject(required_capability_exception::class, static fn() => meetings::detail($outsider, $meeting));
check(!meetings::visible($outsider, $start, $when + HOURSECS), 'Private appointment outside calendar');

\core\session\manager::set_user($DB->get_record('user', ['id' => $invitee]));
check(isset(meetings::visible($invitee, $start, $when + 2 * HOURSECS)[$meeting]), 'Participant calendar');
meetings::respond($invitee, $meeting, 1, 'declined');
check(!meetings::visible($invitee, $start, $when + 2 * HOURSECS), 'Declined removed from participant calendar');
meetings::respond($invitee, $meeting, 1, 'accepted');
\local_ustar\learning_tasks::transition($taskid, $invitee, 'start', 1);
check(tasks::rows($invitee, 'mine', 'all', 0, 0, 0, '', '', 'in_progress')['total'] === 1, 'Canonical Kanban movement');
\core\session\manager::set_user($DB->get_record('user', ['id' => $manager]));
meetings::save($manager, $meeting, 1, random_string(32), 'Renamed appointment', '', $room,
    $when + HOURSECS, $when + 2 * HOURSECS, [$invitee]);
check(meetings::detail($invitee, $meeting)['member']->rsvp === 'pending', 'Rescheduled meeting asks again');
meetings::cancel($manager, $meeting, 2);
check(!meetings::visible($invitee, $start, $when + 2 * HOURSECS), 'Cancelled meeting removed from calendar');
check(count(meetings::busy($outsider, $week)[$room]) === 1, 'Cancellation releases room');

\core\session\manager::set_user(get_admin());
$state['briefsection'] = 'present';
$html = (new page($admin, $state))->briefing();
check(str_contains($html, 'is-presentation') && str_contains($html, '42,5') && !str_contains($html, '999'), 'Presentation HTML');
check(briefing::linked_report($admin, $reportid)->submittedat > 0, 'Submitted report visible in admin');
echo "BRIEFING_SMOKE=OK\n";
