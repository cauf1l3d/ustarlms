<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/ustar:use', $context);
$tab = optional_param('tab', 'assigned', PARAM_ALPHA);
if (!in_array($tab, ['checklists', 'notebook', 'assigned', 'outgoing'], true)) {
    $tab = 'assigned';
}
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        $action = required_param('action', PARAM_ALPHANUMEXT);
        if ($action === 'note') {
            \local_ustar\learning_tasks::create_note(
                (int)$USER->id, required_param('title', PARAM_TEXT), optional_param('description', '', PARAM_TEXT)
            );
            redirect(new moodle_url('/local/ustar/tasks.php', ['tab' => 'notebook', 'saved' => 1]));
        }
        if ($action === 'assign') {
            \local_ustar\learning_tasks::assign((int)$USER->id, required_param('assigneeid', PARAM_INT), [
                'title' => required_param('title', PARAM_TEXT),
                'description' => optional_param('description', '', PARAM_TEXT),
                'requirereview' => optional_param('requirereview', 0, PARAM_BOOL),
                'relatedtype' => optional_param('relatedtype', '', PARAM_ALPHANUMEXT),
                'relatedid' => optional_param('relatedid', 0, PARAM_INT),
                'dueat' => 0,
            ]);
            redirect(new moodle_url('/local/ustar/tasks.php', ['tab' => 'outgoing', 'assigned' => 1]));
        }
        if ($action === 'transition') {
            \local_ustar\learning_tasks::transition(
                required_param('id', PARAM_INT), (int)$USER->id, required_param('taskaction', PARAM_ALPHANUMEXT),
                required_param('version', PARAM_INT), optional_param('comment', '', PARAM_TEXT)
            );
            redirect(new moodle_url('/local/ustar/tasks.php', ['tab' => $tab, 'changed' => 1]));
        }
    } catch (\Throwable $e) {
        $notice = $e->getMessage();
    }
}

$assigned = \local_ustar\learning_tasks::assigned_to((int)$USER->id);
$notes = \local_ustar\learning_tasks::notes_for_owner((int)$USER->id);
$outgoing = \local_ustar\learning_tasks::assigned_by((int)$USER->id);
$candidates = [];
$scoped = \local_ustar\organization_model::manager_scope((int)$USER->id);
if (!empty($scoped['allowed'])) {
    $candidates = $scoped['employees'] ?? [];
}
$canhr = is_siteadmin((int)$USER->id) || has_capability('local/ustar:admin', $context)
    || has_capability('local/ustar:hr', $context) || has_capability('local/ustar:hrmanage', $context);
if ($canhr && !$candidates) {
    foreach ($DB->get_records_select('user', 'id > 1 AND deleted = 0 AND suspended = 0', [], 'lastname ASC, firstname ASC', 'id,firstname,lastname') as $user) {
        if ((int)$user->id !== (int)$USER->id && \local_ustar\learning_tasks::can_assign((int)$USER->id, (int)$user->id)) {
            $candidates[] = ['id' => (int)$user->id, 'fullname' => fullname($user), 'position' => ''];
        }
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/tasks.php', ['tab' => $tab]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Задачи | USTAR Academy');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/stage6.css'));
echo $OUTPUT->header();
echo $OUTPUT->heading('Задачи');

if ($notice !== '') { echo $OUTPUT->notification(s($notice), 'notifyproblem'); }
foreach (['saved' => 'Личная заметка сохранена.', 'assigned' => 'Задача назначена.', 'changed' => 'Статус задачи изменён.'] as $key => $message) {
    if (optional_param($key, 0, PARAM_BOOL)) { echo $OUTPUT->notification($message, 'notifysuccess'); }
}

$tabs = [
    'checklists' => 'Чек-листы',
    'notebook' => 'Личный блокнот',
    'assigned' => 'Назначено мне',
    'outgoing' => 'Мои назначения',
];
echo html_writer::start_div('u-stage6-tabs');
foreach ($tabs as $key => $label) {
    $attrs = ['href' => (new moodle_url('/local/ustar/tasks.php', ['tab' => $key]))->out(false),
        'class' => $tab === $key ? 'is-active' : ''];
    echo html_writer::tag('a', $label, $attrs);
}
echo html_writer::end_div();

$taskform = static function(array $task, array $actions) use ($tab): void {
    echo html_writer::start_div('u-stage6-card');
    echo html_writer::tag('h3', s((string)$task['title']));
    if ($task['description'] !== '') { echo $task['description']; }
    echo html_writer::tag('p', 'Статус: ' . s((string)$task['status']));
    foreach ($actions as $action => $label) {
        echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'transition']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$task['id']]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'version', 'value' => (int)$task['version']]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'taskaction', 'value' => $action]);
        if (in_array($action, ['return', 'cancel'], true)) {
            echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'comment', 'placeholder' => 'Причина', 'required' => 'required']);
        }
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => $label, 'class' => 'btn']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();
};

if ($tab === 'checklists') {
    echo html_writer::tag('p', 'Рабочие чек-листы остаются частью Академии и открываются в этом же контуре задач.');
    echo html_writer::tag('p', html_writer::link(new moodle_url('/local/ustar/checklists.php'), 'Открыть чек-листы'));
}
if ($tab === 'notebook') {
    echo html_writer::tag('p', 'Этот блок видите только вы. Текст заметок не попадает в поиск, уведомления, аналитику или экран руководителя.');
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'note']);
    echo html_writer::tag('label', 'Заметка');
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'required' => 'required', 'class' => 'form-control']);
    echo html_writer::tag('textarea', '', ['name' => 'description', 'rows' => 4, 'class' => 'form-control']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Добавить в блокнот', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    foreach ($notes as $task) {
        $taskform($task, (string)$task['status'] === 'open' ? ['complete' => 'Отметить выполненной'] : []);
    }
}
if ($tab === 'assigned') {
    if (!$assigned) { echo $OUTPUT->notification('Нет назначенных задач.', 'notifyinfo'); }
    foreach ($assigned as $task) {
        $actions = [];
        if ($task['status'] === 'assigned') { $actions['start'] = 'Начать'; }
        if (in_array($task['status'], ['assigned', 'in_progress'], true)) { $actions['submit'] = 'Отправить результат'; }
        $taskform($task, $actions);
    }
}
if ($tab === 'outgoing') {
    if ($candidates) {
        echo html_writer::heading('Поставить задачу сотруднику', 3);
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'assign']);
        $options = [];
        foreach ($candidates as $candidate) {
            $options[(int)$candidate['id']] = (string)$candidate['fullname'] . ((string)($candidate['position'] ?? '') ? ' · ' . $candidate['position'] : '');
        }
        echo html_writer::select($options, 'assigneeid', false, 'Выберите сотрудника', ['required' => 'required', 'class' => 'form-select']);
        echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'required' => 'required', 'placeholder' => 'Задача', 'class' => 'form-control']);
        echo html_writer::tag('textarea', '', ['name' => 'description', 'rows' => 3, 'class' => 'form-control']);
        echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'requirereview', 'value' => 1]) . ' Нужна проверка результата');
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Назначить', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    }
    foreach ($outgoing as $task) {
        $actions = [];
        if ($task['status'] === 'in_review') { $actions = ['approve' => 'Принять', 'return' => 'Вернуть']; }
        if (!in_array($task['status'], ['completed', 'cancelled'], true)) { $actions['cancel'] = 'Отменить'; }
        $taskform($task, $actions);
    }
}
echo $OUTPUT->footer();
