<?php
// Run only with a disposable Moodle database and fixture accounts.
if (getenv('USTAR_DISPOSABLE_FIXTURE') !== 'briefing') {
    throw new RuntimeException('Set USTAR_DISPOSABLE_FIXTURE=briefing for the isolated test database.');
}
define('CLI_SCRIPT', true);
require $argv[1] . '/config.php';
require_once($CFG->dirroot . '/user/lib.php');

use local_ustar\briefing\{conflict, meetings, page, service as briefing};
use local_ustar\connect\{bot_briefing, notification_policy};
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
$week = briefing::report_week();
$start = briefing::week_start($week);
check(!meetings::overlaps_briefing($start - HOURSECS, $start), 'Back-to-back before briefing');
check(meetings::overlaps_briefing($start - MINSECS, $start + MINSECS), 'Crossing briefing start');
check(!meetings::overlaps_briefing($start + HOURSECS, $start + 2 * HOURSECS), 'Back-to-back after briefing');
check(briefing::current_week(calendar::timestamp('2026-11-29', '23:59', briefing::ZONE)) === '2026-11-30', 'Moscow Sunday rollover');
$monday = briefing::week_start('2026-11-30');
check(briefing::report_week($monday - 1) === '2026-11-30', 'Current report through 09:59 Moscow');
check(briefing::report_week($monday) === '2026-12-07' && briefing::current_week($monday) === '2026-11-30',
    'Next report opens at 10:00 while agenda stays available');

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
check(briefing::save_report($manager, 'retail', $week, 0, 1, ['42,5'], false) === $reportid, 'Initial draft retry');
check((int)briefing::form($manager, 'retail', $week)['id'] === $reportid, 'Draft identity');
check(briefing::minutes($manager, $week)->content === 'Fixture minute', 'Shared minutes');

\core\session\manager::set_user(get_admin());
check(briefing::summary($admin, $week)['retail']['draft'] && !briefing::summary($admin, $week)['retail']['report'], 'Draft body private');
briefing::save_template($admin, 'retail', 1, 'Retail updated', 10, true, [['label' => 'New field', 'type' => 'text', 'required' => true]]);
\core\session\manager::set_user($DB->get_record('user', ['id' => $manager]));
check(briefing::form($manager, 'retail', $week)['title'] === 'Retail original', 'Pinned form schema');
briefing::save_report($manager, 'retail', $week, 1, 2, ['42,5'], true);
check(briefing::save_report($manager, 'retail', $week, 1, 2, ['42,5'], true) === $reportid, 'Submission retry');
reject(conflict::class, static fn() => briefing::save_report($manager, 'retail', $week, 1, 2, ['999'], true));
$firstsubmission = time() - 60;
$DB->set_field('local_ustar_brief_reports', 'submittedat', $firstsubmission, ['id' => $reportid]);
set_config('briefing_deadline_minutes', 60, 'local_ustar');
briefing::save_report($manager, 'retail', $week, 2, 2, ['44,5'], true);
$revised = briefing::form($manager, 'retail', $week);
check($revised['submittedat'] === $firstsubmission && $revised['deadlineat'] === $start - 30 * MINSECS
    && $revised['revision'] === 3, 'Corrections keep initial submission and deadline');

$state = ['view' => 'briefing', 'scope' => 'mine', 'briefweek' => $week, 'briefsection' => 'report', 'briefdept' => 'retail'];
$html = (new page($manager, $state))->briefing();
check(str_contains($html, 'Weekly revenue') && str_contains($html, '44,5'), 'Manager form HTML');
$followup = ['title' => 'Follow-up fixture', 'kind' => 'task', 'date' => calendar::date($start, briefing::ZONE),
    'clock' => '15:00', 'briefreport' => $reportid, 'briefkey' => random_string(32)];
$taskid = tasks::create($manager, $invitee, $followup);
check(tasks::create($manager, $invitee, $followup) === $taskid, 'Follow-up double submit is idempotent');
check(isset(briefing::followups($manager, $reportid)[$taskid]), 'Task linked to briefing');
check(tasks::rows($invitee, 'mine', 'all', 0, 0, 0, '', '', 'assigned')['total'] === 1, 'Kanban stage filtering');
$task = tasks::detail($taskid, $invitee);
check(tasks::kanban_moves($task, $invitee)['in_progress'] === 'start', 'Kanban takes canonical action');
check(!tasks::kanban_moves($task, $manager), 'Manager cannot start task for employee');
$retraining = array_replace($task, ['kind' => 'retraining']);
check(tasks::kanban_moves($retraining, $invitee) === ['in_progress' => 'start'], 'Retraining has no manual report');
$unreviewed = array_replace($task, ['requirereview' => false]);
check(tasks::kanban_moves($unreviewed, $invitee)['completed'] === 'report', 'Legacy task completes without review');
check(tasks::rows($invitee, 'mine', 'all', PHP_INT_MAX, 0, 0, '', '', 'assigned')['page'] === 0,
    'Kanban page remains valid after movement');

$key = random_string(32);
$when = $start + 2 * HOURSECS;
$meeting = meetings::save($manager, 0, 0, $key, 'Fixture appointment', 'Private agenda',
    $room, $when, $when + HOURSECS, [$invitee]);
check(meetings::save($manager, 0, 0, $key, 'Fixture appointment', 'Private agenda',
    $room, $when, $when + HOURSECS, [$invitee]) === $meeting, 'Double-submit is idempotent');
reject(conflict::class, static fn() => meetings::save($manager, 0, 0, $key, 'Different appointment', 'Private agenda',
    $room, $when, $when + HOURSECS, [$invitee]));
reject(invalid_parameter_exception::class, static fn() => meetings::save(
    $manager, 0, 0, random_string(32), 'Organizer overlap elsewhere', '', 0, $when, $when + MINSECS, []));
reject(invalid_parameter_exception::class, static fn() => meetings::save(
    $manager, 0, 0, random_string(32), 'Overlap', '', $room, $when, $when + MINSECS, []));
reject(invalid_parameter_exception::class, static fn() => meetings::save(
    $manager, 0, 0, random_string(32), 'Recurring overlap', '', $room, $start, $start + MINSECS, []));
check(count(meetings::busy($outsider, $week)[$room]) === 2, 'Occupancy without title');
reject(required_capability_exception::class, static fn() => meetings::detail($outsider, $meeting));
check(!meetings::visible($outsider, $start, $when + HOURSECS), 'Private appointment outside calendar');
\core\session\manager::set_user(get_admin());
$parallel = meetings::save($admin, 0, 0, random_string(32), 'Parallel pending invitation', '', 0,
    $when, $when + MINSECS, [$invitee]);

\core\session\manager::set_user($DB->get_record('user', ['id' => $invitee]));
check(isset(meetings::visible($invitee, $start, $when + 2 * HOURSECS)[$meeting]), 'Participant calendar');
meetings::respond($invitee, $meeting, 1, 'declined', 'pending');
check(!isset(meetings::visible($invitee, $start, $when + 2 * HOURSECS)[$meeting]), 'Declined removed from participant calendar');
reject(conflict::class, static fn() => meetings::respond($invitee, $meeting, 1, 'accepted', 'pending'));
meetings::respond($invitee, $meeting, 1, 'accepted', 'declined');
meetings::respond($invitee, $meeting, 1, 'accepted', 'declined');
reject(invalid_parameter_exception::class, static fn() => meetings::respond($invitee, $parallel, 1, 'accepted'));
bot_briefing::verify(['meetings' => [$meeting => 1]], $invitee);
\local_ustar\learning_tasks::transition($taskid, $invitee, 'start', 1);
check(tasks::rows($invitee, 'mine', 'all', 0, 0, 0, '', '', 'in_progress')['total'] === 1, 'Canonical Kanban movement');
\core\session\manager::set_user($DB->get_record('user', ['id' => $manager]));
meetings::save($manager, $meeting, 1, random_string(32), 'Renamed appointment', '', $room,
    $when + HOURSECS, $when + 2 * HOURSECS, [$invitee]);
check(meetings::detail($invitee, $meeting)['member']->rsvp === 'pending', 'Rescheduled meeting asks again');
check(meetings::save($manager, $meeting, 1, random_string(32), 'Renamed appointment', '', $room,
    $when + HOURSECS, $when + 2 * HOURSECS, [$invitee]) === $meeting, 'Repeated edit does not advance revision');
meetings::save($manager, $meeting, 2, random_string(32), 'Renamed appointment', '', $room,
    $when + HOURSECS, $when + 2 * HOURSECS, []);
reject(required_capability_exception::class, static fn() => meetings::detail($invitee, $meeting));
reject(InvalidArgumentException::class, static fn() => bot_briefing::verify(['meetings' => [$meeting => 2]], $invitee));
$removed = $DB->get_record('local_ustar_notifications', ['idempotencykey' => "meeting:$meeting:3:$invitee"], '*', MUST_EXIST);
check($removed->eventtype === 'meeting_removed' && $removed->actionurl === '/local/ustar/tasks.php?view=meetings'
    && notification_policy::permitted($removed), 'Removal notice carries no private meeting link or title');
meetings::save($manager, $meeting, 3, random_string(32), 'Renamed appointment', '', $room,
    $when + HOURSECS, $when + 2 * HOURSECS, [$invitee]);
check(!notification_policy::permitted($removed) && meetings::detail($invitee, $meeting)['member']->rsvp === 'pending',
    'Reinstated participant sees only the current invitation');
meetings::cancel($manager, $meeting, 4);
meetings::cancel($manager, $meeting, 4);
check(!isset(meetings::visible($invitee, $start, $when + 2 * HOURSECS)[$meeting]), 'Cancelled meeting removed from calendar');
check(count(meetings::busy($outsider, $week)[$room]) === 1, 'Cancellation releases room');
$night = calendar::timestamp($week, '23:30', briefing::ZONE);
$overnight = meetings::save($manager, 0, 0, random_string(32), 'Overnight', '', 0, $night, $night + HOURSECS, []);
$events = meetings::calendar_items($manager, $night - HOURSECS, $night + 2 * HOURSECS);
check(count($events) === 2 && !$events[0]['continued'] && $events[1]['continued']
    && $events[1]['params']['meetingid'] === $overnight, 'Meeting occupies both calendar days');

\core\session\manager::set_user(get_admin());
$state['briefsection'] = 'present';
$html = (new page($admin, $state))->briefing();
check(str_contains($html, 'is-presentation') && str_contains($html, '44,5') && !str_contains($html, '999'), 'Presentation HTML');
check(briefing::linked_report($admin, $reportid)->submittedat > 0, 'Submitted report visible in admin');
echo "BRIEFING_SMOKE=OK\n";
