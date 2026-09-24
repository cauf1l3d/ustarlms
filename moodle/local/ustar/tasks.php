<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
$canhrworkspace = \local_ustar\capabilities::has((int)$USER->id, \local_ustar\capabilities::COMPANY_READ);
if (!$canhrworkspace) {
    require_capability('local/ustar:use', $context);
}
$tab = optional_param('tab', 'assigned', PARAM_ALPHA);
if (!in_array($tab, ['checklists', 'notebook', 'assigned', 'outgoing'], true)) {
    $tab = 'assigned';
}
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    try {
        $action = required_param('action', PARAM_ALPHANUMEXT);
        if ($action === 'note') {
            \local_ustar\learning_tasks::create_note(
                (int)$USER->id, required_param('title', PARAM_TEXT), optional_param('description', '', PARAM_TEXT)
            );
            redirect(new moodle_url('/local/ustar/tasks.php', ['tab' => 'notebook', 'saved' => 1]));
        }
        if ($action === 'assign') {
            $duedate = optional_param('duedate', '', PARAM_RAW_TRIMMED);
            $dueat = 0;
            if ($duedate !== '') {
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $duedate, $parts)
                        || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
                    throw new invalid_parameter_exception('Укажите корректную дату срока.');
                }
                $dueat = make_timestamp((int)$parts[1], (int)$parts[2], (int)$parts[3], 23, 59, 59);
            }
            \local_ustar\learning_tasks::assign((int)$USER->id, required_param('assigneeid', PARAM_INT), [
                'title' => required_param('title', PARAM_TEXT),
                'description' => optional_param('description', '', PARAM_TEXT),
                'requirereview' => optional_param('requirereview', 0, PARAM_BOOL),
                'relatedtype' => optional_param('relatedtype', '', PARAM_ALPHANUMEXT),
                'relatedid' => optional_param('relatedid', 0, PARAM_INT),
                'dueat' => $dueat,
            ]);
            redirect(new moodle_url('/local/ustar/tasks.php', ['tab' => 'outgoing', 'assigned' => 1]));
        }
        if ($action === 'editnote') {
            \local_ustar\learning_tasks::update_note(required_param('id', PARAM_INT), (int)$USER->id,
                required_param('version', PARAM_INT), required_param('title', PARAM_TEXT),
                optional_param('description', '', PARAM_TEXT));
            redirect(new moodle_url('/local/ustar/tasks.php', ['tab' => 'notebook', 'saved' => 1]));
        }
        if ($action === 'deletenote') {
            if (!required_param('confirmdelete', PARAM_BOOL)) {
                throw new invalid_parameter_exception('Подтвердите удаление заметки.');
            }
            \local_ustar\learning_tasks::delete_note(required_param('id', PARAM_INT), (int)$USER->id,
                required_param('version', PARAM_INT));
            redirect(new moodle_url('/local/ustar/tasks.php', ['tab' => 'notebook', 'deleted' => 1]));
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
$canhr = $canhrworkspace;
if ($canhr) {
    $candidates = [];
    foreach ($DB->get_records_select('user', 'id > 1 AND deleted = 0 AND suspended = 0', [], 'lastname ASC, firstname ASC', 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename') as $user) {
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
echo html_writer::start_tag('main', ['class' => 'u-tasks']);
echo html_writer::tag('header', html_writer::tag('h1', 'Задачи') .
    html_writer::tag('p', 'Личные заметки, поручения и чек-листы в одном месте.'),
    ['class' => 'u-tasks__header']);

if ($notice !== '') { echo $OUTPUT->notification(s($notice), 'notifyproblem'); }
foreach (['saved' => 'Личная заметка сохранена.', 'assigned' => 'Задача назначена.',
        'changed' => 'Статус задачи изменён.', 'deleted' => 'Личная заметка удалена.'] as $key => $message) {
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
        'class' => $tab === $key ? 'is-active' : '',
        'aria-current' => $tab === $key ? 'page' : null];
    echo html_writer::tag('a', $label, $attrs);
}
echo html_writer::end_div();

$taskform = static function(array $task, array $actions) use ($tab): void {
    echo html_writer::start_tag('article', ['class' => 'u-stage6-card u-tasks__item']);
    echo html_writer::tag('h3', s((string)$task['title']));
    if ($task['description'] !== '') { echo $task['description']; }
    $statuses = ['open' => 'Открыта', 'assigned' => 'Назначена', 'in_progress' => 'В работе',
        'in_review' => 'На проверке', 'completed' => 'Выполнена', 'cancelled' => 'Отменена'];
    echo html_writer::tag('p', s($statuses[$task['status']] ?? (string)$task['status']),
        ['class' => 'u-tasks__status']);
    if (!empty($task['dueat'])) {
        echo html_writer::tag('p', 'Срок: ' . userdate((int)$task['dueat']));
    }
    foreach ($actions as $action => $label) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-tasks__action']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'transition']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$task['id']]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'version', 'value' => (int)$task['version']]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'taskaction', 'value' => $action]);
        if (in_array($action, ['return', 'cancel'], true)) {
            echo html_writer::tag('label', 'Причина', ['for' => 'task-reason-' . $action . '-' . (int)$task['id']]);
            echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'comment',
                'id' => 'task-reason-' . $action . '-' . (int)$task['id'], 'required' => 'required',
                'class' => 'form-control']);
        }
        if ($action === 'submit') {
            echo html_writer::tag('label', 'Результат', ['for' => 'result-' . (int)$task['id']]);
            echo html_writer::tag('textarea', '', ['name' => 'comment', 'id' => 'result-' . (int)$task['id'],
                'rows' => 3, 'class' => 'form-control']);
        }
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => $label, 'class' => 'u-btn']);
        echo html_writer::end_tag('form');
    }
    if (empty($task['private'])) {
        global $USER;
        foreach (\local_ustar\learning_tasks::events((int)$task['id'], (int)$USER->id) as $event) {
            if ($event['comment'] === '') { continue; }
            echo html_writer::tag('p', s(userdate($event['time']) . ': ' . $event['comment']));
        }
    }
    echo html_writer::end_tag('article');
};

$formvalue = static function(string $action, int $id, string $name, string $default = '') use ($notice): string {
    if ($notice !== '' && ($_POST['action'] ?? '') === $action && (int)($_POST['id'] ?? 0) === $id
            && isset($_POST[$name]) && is_scalar($_POST[$name])) {
        return (string)$_POST[$name];
    }
    return $default;
};

if ($tab === 'checklists') {
    echo html_writer::start_div('u-stage6-card u-tasks__empty');
    echo html_writer::tag('h2', 'Рабочие чек-листы');
    echo html_writer::tag('p', 'Проверяйте этапы работы в общем контуре задач Академии.');
    echo html_writer::link(new moodle_url('/local/ustar/checklists.php'), 'Открыть чек-листы',
        ['class' => 'u-btn u-btn--primary']);
    echo html_writer::end_div();
}
if ($tab === 'notebook') {
    echo html_writer::tag('p', 'Заметки видны только вам: их текст не попадает в поиск, уведомления, аналитику или экран руководителя.',
        ['class' => 'u-tasks__hint']);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-stage6-card u-tasks__editor']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'note']);
    echo html_writer::tag('h2', 'Новая заметка');
    echo html_writer::tag('label', 'Название', ['for' => 'task-note-title']);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'required' => 'required',
        'id' => 'task-note-title', 'value' => $formvalue('note', 0, 'title'), 'class' => 'form-control']);
    echo html_writer::tag('label', 'Текст', ['for' => 'task-note-body']);
    echo html_writer::tag('textarea', s($formvalue('note', 0, 'description')),
        ['name' => 'description', 'id' => 'task-note-body', 'rows' => 4, 'class' => 'form-control']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Добавить в блокнот', 'class' => 'u-btn u-btn--primary']);
    echo html_writer::end_tag('form');
    foreach ($notes as $task) {
        $taskform($task, (string)$task['status'] === 'open' ? ['complete' => 'Отметить выполненной'] : []);
        echo html_writer::start_tag('details', ['class' => 'u-tasks__edit']);
        echo html_writer::tag('summary', 'Редактировать заметку');
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-tasks__editform']);
        foreach (['sesskey' => sesskey(), 'action' => 'editnote', 'id' => $task['id'], 'version' => $task['version']] as $name => $value) {
            if ($name === 'version') { $value = (int)$formvalue('editnote', $task['id'], 'version', (string)$value); }
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        echo html_writer::tag('label', 'Название', ['for' => 'note-title-' . $task['id']]);
        echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'id' => 'note-title-' . $task['id'],
            'value' => $formvalue('editnote', $task['id'], 'title', $task['titleplain']), 'required' => 'required', 'class' => 'form-control']);
        echo html_writer::tag('label', 'Текст', ['for' => 'note-body-' . $task['id']]);
        echo html_writer::tag('textarea', s($formvalue('editnote', $task['id'], 'description', $task['descriptionplain'])), ['name' => 'description',
            'id' => 'note-body-' . $task['id'], 'rows' => 4, 'class' => 'form-control']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Сохранить', 'class' => 'u-btn u-btn--primary']);
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('details');
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-tasks__deleteform']);
        foreach (['sesskey' => sesskey(), 'action' => 'deletenote', 'id' => $task['id'], 'version' => $task['version']] as $name => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'confirmdelete',
            'value' => 1, 'required' => 'required']) . ' Удалить эту заметку без восстановления');
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Удалить', 'class' => 'u-btn u-btn--quiet']);
        echo html_writer::end_tag('form');
    }
}
if ($tab === 'assigned') {
    if (!$assigned) { echo html_writer::tag('p', 'Пока нет назначенных задач.', ['class' => 'u-stage6-card u-tasks__empty']); }
    foreach ($assigned as $task) {
        $actions = [];
        if ($task['status'] === 'assigned') { $actions['start'] = 'Начать'; }
        if (in_array($task['status'], ['assigned', 'in_progress'], true)) { $actions['submit'] = 'Отправить результат'; }
        $taskform($task, $actions);
    }
}
if ($tab === 'outgoing') {
    if ($candidates) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-stage6-card u-tasks__editor']);
        echo html_writer::tag('h2', 'Поставить задачу сотруднику');
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'assign']);
        $options = [];
        foreach ($candidates as $candidate) {
            $options[(int)$candidate['id']] = (string)$candidate['fullname'] . ((string)($candidate['position'] ?? '') ? ' · ' . $candidate['position'] : '');
        }
        echo html_writer::tag('label', 'Сотрудник', ['for' => 'task-assignee']);
        echo html_writer::select($options, 'assigneeid', $formvalue('assign', 0, 'assigneeid'), 'Выберите сотрудника',
            ['required' => 'required', 'class' => 'form-select', 'id' => 'task-assignee']);
        echo html_writer::tag('label', 'Название задачи', ['for' => 'task-title']);
        echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'required' => 'required',
            'id' => 'task-title', 'value' => $formvalue('assign', 0, 'title'), 'class' => 'form-control']);
        echo html_writer::tag('label', 'Описание', ['for' => 'task-description']);
        echo html_writer::tag('textarea', s($formvalue('assign', 0, 'description')),
            ['name' => 'description', 'id' => 'task-description', 'rows' => 3, 'class' => 'form-control']);
        echo html_writer::tag('label', 'Срок выполнения (необязательно)', ['for' => 'task-duedate']);
        echo html_writer::empty_tag('input', ['type' => 'date', 'name' => 'duedate', 'id' => 'task-duedate',
            'value' => $formvalue('assign', 0, 'duedate'), 'class' => 'form-control']);
        echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'requirereview', 'value' => 1]) . ' Нужна проверка результата');
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Назначить', 'class' => 'u-btn u-btn--primary']);
        echo html_writer::end_tag('form');
    }
    foreach ($outgoing as $task) {
        $actions = [];
        if ($task['status'] === 'in_review') { $actions = ['approve' => 'Принять', 'return' => 'Вернуть']; }
        if (!in_array($task['status'], ['completed', 'cancelled'], true)) { $actions['cancel'] = 'Отменить'; }
        $taskform($task, $actions);
    }
    if (!$outgoing && !$candidates) {
        echo html_writer::tag('p', 'Пока нет ваших назначений.', ['class' => 'u-stage6-card u-tasks__empty']);
    }
}
echo html_writer::end_tag('main');
echo $OUTPUT->footer();
