<?php
// Included only by the authenticated tasks.php entrypoint.
defined('MOODLE_INTERNAL') || die();
use local_ustar\task_workspace\{service, policy, calendar, page};
use local_ustar\{learning_tasks, task_files};

$actorid = (int)$USER->id;
$view = optional_param('view', 'overview', PARAM_ALPHA);
if (!in_array($view, ['overview', 'calendar', 'checklists', 'control', 'analytics', 'rules'], true)) { $view = 'overview'; }
$scope = optional_param('scope', service::can_manage($actorid) ? 'team' : 'mine', PARAM_ALPHA);
if (!in_array($scope, ['mine', 'team', 'outgoing'], true)) { $scope = 'mine'; }
service::scope_sql($actorid, $scope);
$state = ['view' => $view, 'scope' => $scope, 'page' => max(0, optional_param('pageno', 0, PARAM_INT)),
    'filter' => optional_param('filter', 'all', PARAM_ALPHA), 'q' => optional_param('q', '', PARAM_TEXT),
    'month' => optional_param('month', date('Y-m'), PARAM_RAW_TRIMMED),
    'taskid' => optional_param('taskid', 0, PARAM_INT), 'form' => optional_param('form', '', PARAM_ALPHA),
    'lookup' => optional_param('lookup', '', PARAM_TEXT), 'assigneeid' => optional_param('assigneeid', 0, PARAM_INT),
    'templateid' => optional_param('templateid', 0, PARAM_INT), 'parentid' => optional_param('parentid', 0, PARAM_INT)];
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    service::actor($actorid);
    try {
        $action = required_param('action', PARAM_ALPHANUMEXT);
        if ($action === 'create') {
            $p = service::policy_for($actorid);
            $p['timezone'] = required_param('timezone', PARAM_RAW_TRIMMED);
            if (optional_param('repeat', 0, PARAM_BOOL)) {
                $p['weekdays'] = optional_param_array('weekdays', [], PARAM_INT);
                $p['excludedates'] = array_values(array_filter(array_map('trim', preg_split('/\R/u',
                    optional_param('excludedates', '', PARAM_RAW)) ?: [])));
            }
            $taskid = service::create($actorid, required_param('assigneeid', PARAM_INT), [
                'kind' => required_param('kind', PARAM_ALPHA), 'title' => required_param('title', PARAM_TEXT),
                'description' => optional_param('description', '', PARAM_TEXT),
                'date' => required_param('duedate', PARAM_RAW_TRIMMED), 'clock' => required_param('dueclock', PARAM_RAW_TRIMMED),
                'templateid' => optional_param('templateid', 0, PARAM_INT), 'policyid' => optional_param('policyid', 0, PARAM_INT),
                'parentid' => optional_param('parentid', 0, PARAM_INT), 'kpiweight' => optional_param('kpiweight', 10, PARAM_INT),
                'requirephoto' => optional_param('requirephoto', 0, PARAM_BOOL), 'policy' => $p,
                'repeat' => optional_param('repeat', 0, PARAM_BOOL), 'enddate' => optional_param('enddate', '', PARAM_RAW_TRIMMED),
            ], task_files::uploaded());
            redirect(new moodle_url('/local/ustar/tasks.php', ['taskid' => $taskid, 'scope' => $scope, 'saved' => 1]));
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
        redirect(new moodle_url('/local/ustar/tasks.php', ['view' => $view, 'scope' => $scope,
            'taskid' => $state['taskid'], 'saved' => 1]));
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        if (!empty($_FILES['attachments']['name'])) { $notice .= ' Файлы для новой отправки приложите повторно.'; }
        $state['form'] = ['create' => 'create', 'template' => 'template'][$action ?? ''] ?? $state['form'];
        // Keep rejected form text in this response only; never persist it or expose uploads.
        $state['input'] = [];
        foreach ($_POST as $key => $value) {
            if (in_array($key, ['sesskey', 'action', 'version', 'revision'], true)) { continue; }
            if (is_string($value)) { $state['input'][$key] = \core_text::substr($value, 0, 10000); }
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
$PAGE->requires->css(new moodle_url('/local/ustar/styles/task_workspace.css'));
$PAGE->requires->js(new moodle_url('/local/ustar/task_workspace.js'));
echo $OUTPUT->header();
if ($notice !== '') { echo $OUTPUT->notification(s($notice), 'notifyproblem'); }
if (optional_param('saved', 0, PARAM_BOOL)) { echo $OUTPUT->notification('Изменения сохранены.', 'notifysuccess'); }
echo page::render($actorid, $state);
echo $OUTPUT->footer();
