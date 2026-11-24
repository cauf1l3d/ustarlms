<?php
// Included only by the authenticated tasks.php entrypoint.
defined('MOODLE_INTERNAL') || die();
use local_ustar\task_workspace\{service, policy, calendar, page, personal_plan, recipients};
use local_ustar\{communication, learning_tasks, task_files};
use local_ustar\briefing\commands;

$actorid = (int)$USER->id;
$view = optional_param('view', 'overview', PARAM_ALPHA);
if (!in_array($view, ['overview', 'kanban', 'plan', 'calendar', 'briefing', 'meetings', 'checklists', 'control', 'analytics', 'rules'], true)) { $view = 'overview'; }
$scope = optional_param('scope', service::can_manage($actorid) ? 'team' : 'mine', PARAM_ALPHA);
if (!in_array($scope, ['mine', 'team', 'outgoing'], true)) { $scope = 'mine'; }
if ($view === 'plan') { $scope = 'mine'; }
if ($scope !== 'mine' && !service::can_manage($actorid)
        && in_array($view, ['meetings', 'briefing'], true)) {
    $scope = 'mine';
}
service::scope_sql($actorid, $scope);
$state = ['view' => $view, 'scope' => $scope, 'page' => max(0, optional_param('pageno', 0, PARAM_INT)),
    'filter' => optional_param('filter', 'all', PARAM_ALPHA), 'q' => optional_param('q', '', PARAM_TEXT),
    'month' => optional_param('month', '', PARAM_RAW_TRIMMED),
    'taskid' => optional_param('taskid', 0, PARAM_INT), 'form' => optional_param('form', '', PARAM_ALPHA),
    'targetmode' => optional_param('targetmode', 'person', PARAM_ALPHA),
    'departmentid' => optional_param('departmentid', '', PARAM_ALPHANUMEXT),
    'positionid' => optional_param('positionid', '', PARAM_ALPHANUMEXT),
    'lookup' => optional_param('lookup', '', PARAM_TEXT), 'assigneeid' => optional_param('assigneeid', 0, PARAM_INT),
    'templateid' => optional_param('templateid', 0, PARAM_INT), 'parentid' => optional_param('parentid', 0, PARAM_INT)];
$state += [
    'briefweek' => optional_param('briefweek', '', PARAM_RAW_TRIMMED),
    'briefsection' => optional_param('briefsection', '', PARAM_ALPHA),
    'briefdept' => optional_param('briefdept', '', PARAM_ALPHANUMEXT),
    'briefreport' => optional_param('briefreport', 0, PARAM_INT),
    'meetingsection' => optional_param('meetingsection', '', PARAM_ALPHA),
    'meetingdate' => optional_param('meetingdate', '', PARAM_RAW_TRIMMED),
    'meetingpage' => max(0, min(10000, optional_param('meetingpage', 0, PARAM_INT))),
    'meetingid' => optional_param('meetingid', 0, PARAM_INT),
    'slotstart' => optional_param('slotstart', 0, PARAM_INT),
    'slotend' => optional_param('slotend', 0, PARAM_INT),
    'slotroom' => optional_param('slotroom', 0, PARAM_INT),
    'boardstatus' => optional_param('boardstatus', '', PARAM_ALPHANUMEXT),
    'taskref' => optional_param('taskref', 0, PARAM_INT),
];
if ($view === 'briefing' && optional_param('briefexport', 0, PARAM_BOOL)) {
    \local_ustar\briefing\service::download($actorid, $state['briefweek']);
}
$notice = '';
$returnparams = ['view' => $view, 'scope' => $scope];
if ($view === 'kanban' || $view === 'plan') {
    $returnparams += ['q' => $state['q'], 'boardstatus' => $state['boardstatus'], 'pageno' => $state['page']];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    service::actor($actorid);
    try {
        $action = required_param('action', PARAM_ALPHANUMEXT);
        if ($action === 'assignment_topics') {
            $user = required_param('assigneeid', PARAM_INT);
            $topics = [];
            if (learning_tasks::can_assign($actorid, $user)) {
                try {
                    foreach (\local_ustar\forced_retraining::topics_for_user($actorid, $user) as $topic) {
                        $topics[(int)$topic['policyid']] = strip_tags($topic['label']);
                    }
                } catch (\required_capability_exception $error) {
                    // Task assignment and repeat-learning permissions are independent.
                }
            }
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
            echo service::json(['topics' => (object)$topics]);
            exit;
        }
        if (($view === 'briefing' && str_starts_with($action, 'brief_'))
                || ($view === 'meetings' && str_starts_with($action, 'meeting_'))) {
            $result = commands::run($action, $actorid, $state);
            if ($action === 'meeting_rsvp') {
                $surface = optional_param('meetingsurface', '', PARAM_ALPHA);
                $message = optional_param('rsvp', '', PARAM_ALPHA) === 'accepted'
                    ? 'Вы приняли приглашение.' : 'Вы отклонили приглашение.';
                if ($surface === 'home') {
                    redirect(new moodle_url('/local/ustar/home.php', [], 'home-meetings'), $message);
                }
                if ($surface === 'list') {
                    unset($result['meetingid'], $result['saved']);
                    $result['meetingpage'] = $state['meetingpage'];
                    redirect(new moodle_url('/local/ustar/tasks.php', $result), $message);
                }
            }
            redirect(new moodle_url('/local/ustar/tasks.php', $result));
        } else if ($view === 'plan' && str_starts_with($action, 'plan_')) {
            personal_plan::update($actorid, required_param('revision', PARAM_INT), $action,
                optional_param('column', '', PARAM_ALPHANUMEXT), optional_param('movetaskid', 0, PARAM_INT),
                optional_param('columntitle', '', PARAM_TEXT), optional_param('columncolor', 'neutral', PARAM_ALPHA));
            redirect(new moodle_url('/local/ustar/tasks.php', $returnparams));
        } else if ($action === 'create') {
            $p = service::policy_for($actorid);
            $p['timezone'] = required_param('timezone', PARAM_RAW_TRIMMED);
            if (optional_param('repeat', 0, PARAM_BOOL)) {
                $p['weekdays'] = optional_param_array('weekdays', [], PARAM_INT);
                $p['excludedates'] = array_values(array_filter(array_map('trim', preg_split('/\R/u',
                    optional_param('excludedates', '', PARAM_RAW)) ?: [])));
            }
            $input = [
                'kind' => required_param('kind', PARAM_ALPHA), 'title' => required_param('title', PARAM_TEXT),
                'description' => optional_param('description', '', PARAM_TEXT),
                'date' => required_param('duedate', PARAM_RAW_TRIMMED), 'clock' => required_param('dueclock', PARAM_RAW_TRIMMED),
                'templateid' => optional_param('templateid', 0, PARAM_INT), 'policyid' => optional_param('policyid', 0, PARAM_INT),
                'parentid' => optional_param('parentid', 0, PARAM_INT), 'kpiweight' => optional_param('kpiweight', 10, PARAM_INT),
                'requirephoto' => optional_param('requirephoto', 0, PARAM_BOOL), 'policy' => $p,
                'briefreport' => $state['briefreport'], 'briefkey' => optional_param('briefkey', '', PARAM_ALPHANUM),
                'repeat' => optional_param('repeat', 0, PARAM_BOOL), 'enddate' => optional_param('enddate', '', PARAM_RAW_TRIMMED),
            ];
            $uploads = task_files::uploaded();
            $result = service::create_target($actorid, [
                'mode' => $state['targetmode'], 'department' => $state['departmentid'], 'position' => $state['positionid'],
                'user' => $state['assigneeid'], 'token' => required_param('assignmenttoken', PARAM_ALPHANUM),
                'request' => required_param('assignmentrequest', PARAM_ALPHANUM),
            ], $input, $uploads);
            if ($result['count'] > 1) {
                $SESSION->ustar_task_assignment_count = $result['count'];
                redirect(new moodle_url('/local/ustar/tasks.php', ['scope' => 'outgoing']));
            }
            redirect(new moodle_url('/local/ustar/tasks.php', ['taskid' => $result['first'], 'scope' => $scope, 'saved' => 1,
                'view' => $view, 'briefweek' => $state['briefweek']], 'uw-detail'));
        } else if ($view === 'kanban' && $action === 'kanban_move') {
            $taskid = required_param('movetaskid', PARAM_INT);
            $task = service::detail($taskid, $actorid);
            $target = required_param('target', PARAM_ALPHANUMEXT);
            $move = service::kanban_moves($task, $actorid)[$target] ?? '';
            if (in_array($move, ['report', 'return'], true)) {
                $url = new moodle_url('/local/ustar/tasks.php', $returnparams + ['taskid' => $taskid]);
                $url->set_anchor('uw-detail');
                redirect($url);
            }
            if (!in_array($move, ['start', 'approve'], true)) {
                throw new moodle_exception('kanbantransitionunavailable', 'local_ustar');
            }
            learning_tasks::transition($taskid, $actorid, $move, required_param('version', PARAM_INT));
            redirect(new moodle_url('/local/ustar/tasks.php', $returnparams + ['saved' => 1]));
        } else if ($action === 'report') {
            $answers = [];
            foreach (service::fields(service::meta(required_param('taskid', PARAM_INT))) as $field) {
                $answers[$field['key']] = optional_param('answer_' . $field['key'], '', PARAM_RAW);
            }
            service::report(required_param('taskid', PARAM_INT), $actorid, required_param('version', PARAM_INT),
                $answers, optional_param('comment', '', PARAM_TEXT), required_param('final', PARAM_BOOL), task_files::uploaded());
        } else if ($action === 'transition') {
            learning_tasks::transition(required_param('taskid', PARAM_INT), $actorid,
                required_param('taskaction', PARAM_ALPHA), required_param('version', PARAM_INT),
                optional_param('comment', '', PARAM_TEXT));
        } else if ($action === 'revise') {
            $task = service::detail(required_param('taskid', PARAM_INT), $actorid);
            if (!$task['canedit']) { throw new required_capability_exception($context, 'local/ustar:viewteam', 'nopermissions', ''); }
            learning_tasks::revise($task['id'], $actorid, required_param('version', PARAM_INT), $task['assigneeid'],
                calendar::timestamp(required_param('duedate', PARAM_RAW_TRIMMED), required_param('dueclock', PARAM_RAW_TRIMMED), $task['timezone']));
        } else if ($action === 'reassign') {
            $task = service::detail(required_param('taskid', PARAM_INT), $actorid);
            if (!$task['canreassign']) {
                service::deny();
            }
            $assignee = required_param('assigneeid', PARAM_INT);
            recipients::validate($actorid, $assignee, required_param('departmentid', PARAM_ALPHANUMEXT),
                required_param('positionid', PARAM_ALPHANUMEXT));
            learning_tasks::revise($task['id'], $actorid, required_param('version', PARAM_INT), $assignee, $task['dueat']);
        } else if ($action === 'template') {
            $labels = required_param_array('fieldlabel', PARAM_TEXT);
            $types = required_param_array('fieldtype', PARAM_ALPHA);
            $keys = required_param_array('fieldkey', PARAM_ALPHANUMEXT);
            $required = required_param_array('fieldrequired', PARAM_INT);
            $fields = [];
            foreach ($labels as $i => $label) { $fields[] = ['key' => $keys[$i] ?? '', 'label' => $label,
                'type' => $types[$i] ?? '', 'required' => $required[$i] ?? false]; }
            service::save_template($actorid, optional_param('templateid', 0, PARAM_INT),
                required_param('revision', PARAM_INT), required_param('title', PARAM_TEXT), $fields,
                required_param('kpiweight', PARAM_INT), optional_param('requirephoto', 0, PARAM_BOOL));
            redirect(new moodle_url('/local/ustar/tasks.php', ['view' => 'checklists', 'scope' => $scope, 'saved' => 1]));
        } else if ($action === 'rules') {
            $p = service::policy_for($actorid);
            $p['timezone'] = required_param('timezone', PARAM_RAW_TRIMMED);
            $p['mode'] = required_param('mode', PARAM_ALPHA);
            $p['startclock'] = required_param('startclock', PARAM_RAW_TRIMMED);
            $p['endclock'] = required_param('endclock', PARAM_RAW_TRIMMED);
            $p['remindminutes'] = required_param('remindminutes', PARAM_INT);
            $p['reviewminutes'] = required_param('reviewminutes', PARAM_INT);
            $p['weekdays'] = required_param_array('weekdays', PARAM_INT);
            $p['excludedates'] = array_values(array_filter(array_map('trim', preg_split('/\R/u',
                optional_param('excludedates', '', PARAM_RAW)) ?: [])));
            $minutes = optional_param_array('levelminutes', [], PARAM_INT);
            $recipients = optional_param_array('levelrecipient', [], PARAM_ALPHA);
            $p['levels'] = [];
            foreach ($minutes as $i => $delay) { $p['levels'][] = ['minutes' => $delay, 'recipient' => $recipients[$i] ?? '']; }
            service::save_policy($actorid, $p, required_param('revision', PARAM_INT));
        } else if ($action === 'series') {
            service::set_series(required_param('seriesid', PARAM_INT), $actorid, required_param('revision', PARAM_INT),
                required_param('seriesstatus', PARAM_ALPHA));
        } else { throw new invalid_parameter_exception('Неизвестное действие задач.'); }
        if (optional_param('returnhome', 0, PARAM_BOOL) && in_array($action, ['report','transition'], true)) {
            redirect(new moodle_url('/local/ustar/home.php'), 'Отчёт сохранён и отправлен на проверку.');
        }
        $url = new moodle_url('/local/ustar/tasks.php', $returnparams + ['taskid' => $state['taskid'], 'saved' => 1]);
        if ($view === 'kanban' && $state['taskid']) {
            $url->set_anchor('uw-detail');
        }
        redirect($url);
    } catch (Throwable $e) {
        $notice = $e instanceof dml_missing_record_exception ? 'Запись больше не доступна.' : $e->getMessage();
        if ($e instanceof \local_ustar\briefing\input_error) {
            $notice = $e->user_message();
        }
        if (!empty($_FILES['attachments']['name'])) { $notice .= ' Файлы для новой отправки приложите повторно.'; }
        $state['form'] = ['create' => 'create', 'template' => 'template'][$action ?? ''] ?? $state['form'];
        // Keep rejected form text in this response only; never persist it or expose uploads.
        $state['input'] = [];
        foreach ($_POST as $key => $value) {
            if ($e instanceof \local_ustar\briefing\conflict) {
                // Keep the winner in the live form. Echo only this actor's rejected text for copying.
                if ($action === 'brief_minutes' && $key === 'briefminutes' && is_string($value)) {
                    $state['conflictcopy']['Протокол'] = \core_text::substr($value, 0, 24000);
                } else if ($action === 'brief_report' && is_string($value)
                        && preg_match('/\Abriefanswer_(\d{1,2})\z/', $key, $match)
                        && (int)$match[1] < \local_ustar\briefing\service::MAX_FIELDS) {
                    $state['conflictcopy']['Ответ ' . ((int)$match[1] + 1)] = \core_text::substr($value, 0, 12000);
                }
                continue;
            }
            if (in_array($key, ['sesskey', 'action', 'version', 'revision'], true)) { continue; }
            if (is_string($value)) {
                $limit = match (true) {
                    $key === 'briefminutes' => 24000,
                    $key === 'agenda' => 12000,
                    str_starts_with($key, 'briefanswer_') => 12000,
                    default => 10000,
                };
                $state['input'][$key] = \core_text::substr($value, 0, $limit);
            }
            else if (is_array($value)) {
                $state['input'][$key] = array_map(static fn($v) => is_scalar($v) ? \core_text::substr((string)$v, 0, 10000) : '', array_slice($value, 0, 100));
            }
        }
    }
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/tasks.php', ['view' => $view, 'scope' => $scope]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Задачи и контроль | USTAR Academy');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/task_workspace.css', ['v' => '20261123-assignment']));
$PAGE->requires->js(new moodle_url('/local/ustar/task_workspace.js', ['v' => '20261123-assignment']));
if ($state['taskid'] && !empty($CFG->messaging)) {
    $PAGE->requires->css(new moodle_url('/local/ustar/styles/workchat.css', ['v' => communication::ASSET_VERSION]));
    $PAGE->requires->js(new moodle_url('/local/ustar/messages.js', ['v' => communication::ASSET_VERSION]));
}
echo $OUTPUT->header();
if ($notice !== '') { echo $OUTPUT->notification(s($notice), 'notifyproblem'); }
if (!empty($state['conflictcopy'])) {
    echo '<section class="uw-panel"><h2>Ваша несохранённая версия</h2><p>Скопируйте нужный текст. '
        . 'В форме ниже открыта последняя сохранённая версия.</p>';
    foreach ($state['conflictcopy'] as $label => $value) {
        echo '<label class="uw-field"><span>' . s($label) . '</span><textarea readonly rows="4">' . s($value) . '</textarea></label>';
    }
    echo '</section>';
}
if (optional_param('saved', 0, PARAM_BOOL)) { echo $OUTPUT->notification('Изменения сохранены.', 'notifysuccess'); }
if ($view === 'overview' && $scope === 'outgoing' && isset($SESSION->ustar_task_assignment_count)) {
    $count = $SESSION->ustar_task_assignment_count;
    unset($SESSION->ustar_task_assignment_count);
    echo $OUTPUT->notification(get_string('taskassignmentcreated', 'local_ustar', $count), 'notifysuccess');
}
echo page::render($actorid, $state);
echo $OUTPUT->footer();
