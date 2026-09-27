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
$pageno = max(0, min(100000, optional_param('pageno', 0, PARAM_INT)));
$selectedid = max(0, optional_param('taskid', 0, PARAM_INT));
$notice = '';
$parseDueDate = static function(string $duedate): int {
    if ($duedate === '') { return 0; }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $duedate, $parts)
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
        throw new invalid_parameter_exception('Укажите корректную дату срока.');
    }
    return make_timestamp((int)$parts[1], (int)$parts[2], (int)$parts[3], 23, 59, 59);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    try {
        $action = required_param('action', PARAM_ALPHANUMEXT);
        if ($action === 'checklistsave') {
            $checklistid = required_param('checklistid', PARAM_ALPHANUMEXT);
            $available = \local_ustar\checklist_service::list_for((int)$USER->id);
            $definition = null;
            foreach ($available['checklists'] as $checklist) {
                if ((string)$checklist['id'] === $checklistid) {
                    $definition = $checklist;
                    break;
                }
            }
            if (!$definition) {
                throw new required_capability_exception($context, 'local/ustar:use', 'nopermissions', '');
            }
            \local_ustar\checklist_service::submit((int)$USER->id, $checklistid,
                \local_ustar\checklist_service::posted_answers($definition),
                optional_param('comment', '', PARAM_TEXT),
                optional_param('mode', 'final', PARAM_ALPHA),
                required_param('revision', PARAM_INT),
                optional_param('correctionreason', '', PARAM_TEXT),
                required_param('definitionversion', PARAM_INT));
            redirect(new moodle_url('/local/ustar/tasks.php',
                ['tab' => 'checklists', 'id' => $checklistid, 'saved' => 1]));
        }
        if ($action === 'note') {
            $note = \local_ustar\learning_tasks::create_note(
                (int)$USER->id, required_param('title', PARAM_TEXT), optional_param('description', '', PARAM_TEXT),
                \local_ustar\task_files::uploaded()
            );
            redirect(new moodle_url('/local/ustar/tasks.php',
                ['tab' => 'notebook', 'taskid' => $note['id'], 'saved' => 1]));
        }
        if ($action === 'assign') {
            $dueat = $parseDueDate(optional_param('duedate', '', PARAM_RAW_TRIMMED));
            $task = \local_ustar\learning_tasks::assign((int)$USER->id, required_param('assigneeid', PARAM_INT), [
                'title' => required_param('title', PARAM_TEXT),
                'description' => optional_param('description', '', PARAM_TEXT),
                'requirereview' => optional_param('requirereview', 0, PARAM_BOOL),
                'relatedtype' => optional_param('relatedtype', '', PARAM_ALPHANUMEXT),
                'relatedid' => optional_param('relatedid', 0, PARAM_INT),
                'dueat' => $dueat,
            ], \local_ustar\task_files::uploaded());
            redirect(new moodle_url('/local/ustar/tasks.php',
                ['tab' => 'outgoing', 'taskid' => $task['id'], 'assigned' => 1]));
        }
        if ($action === 'editnote') {
            \local_ustar\learning_tasks::update_note(required_param('id', PARAM_INT), (int)$USER->id,
                required_param('version', PARAM_INT), required_param('title', PARAM_TEXT),
                optional_param('description', '', PARAM_TEXT), \local_ustar\task_files::uploaded());
            redirect(new moodle_url('/local/ustar/tasks.php',
                ['tab' => 'notebook', 'taskid' => required_param('id', PARAM_INT), 'saved' => 1]));
        }
        if ($action === 'revisetask') {
            $task = \local_ustar\learning_tasks::revise(required_param('id', PARAM_INT),
                (int)$USER->id, required_param('version', PARAM_INT),
                required_param('assigneeid', PARAM_INT),
                $parseDueDate(optional_param('duedate', '', PARAM_RAW_TRIMMED)));
            redirect(new moodle_url('/local/ustar/tasks.php',
                ['tab' => 'outgoing', 'taskid' => $task['id'], 'changed' => 1]));
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
            $task = \local_ustar\learning_tasks::transition(
                required_param('id', PARAM_INT), (int)$USER->id, required_param('taskaction', PARAM_ALPHANUMEXT),
                required_param('version', PARAM_INT), optional_param('comment', '', PARAM_TEXT),
                \local_ustar\task_files::uploaded()
            );
            redirect(new moodle_url('/local/ustar/tasks.php',
                ['tab' => $tab, 'taskid' => $task['id'], 'changed' => 1]));
        }
    } catch (\Throwable $e) {
        $notice = $e->getMessage();
    }
}

$assigned = $tab === 'assigned' ? \local_ustar\learning_tasks::assigned_to((int)$USER->id, $pageno) : [];
$notes = $tab === 'notebook' ? \local_ustar\learning_tasks::notes_for_owner((int)$USER->id, $pageno) : [];
$outgoing = $tab === 'outgoing' ? \local_ustar\learning_tasks::assigned_by((int)$USER->id, $pageno) : [];
$count = \local_ustar\learning_tasks::count_for((int)$USER->id, $tab);
$lookup = $tab === 'outgoing' ? optional_param('lookup', '', PARAM_TEXT) : '';
$candidates = $tab === 'outgoing'
    ? \local_ustar\learning_tasks::candidates((int)$USER->id, $lookup) : [];
$selected = null;
if ($selectedid && $tab !== 'checklists') {
    try {
        $candidate = \local_ustar\learning_tasks::view($selectedid, (int)$USER->id);
        $belongs = ($tab === 'notebook' && $candidate['private'])
            || ($tab === 'assigned' && !$candidate['private'] && $candidate['assigneeid'] === (int)$USER->id)
            || ($tab === 'outgoing' && !$candidate['private'] && $candidate['assignerid'] === (int)$USER->id);
        if ($belongs) { $selected = $candidate; }
    } catch (\Throwable $e) {
        // An old bookmark or an object outside this actor's scope opens the list.
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/tasks.php', ['tab' => $tab]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Задачи | USTAR Academy');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/stage6.css'));
$attachmentlimit = display_size(\local_ustar\task_files::max_file_bytes());
echo $OUTPUT->header();
echo html_writer::start_div('u-tasks');
echo html_writer::tag('header', html_writer::tag('h1', 'Задачи') .
    html_writer::tag('p', 'Личные заметки, поручения и чек-листы в одном месте.'),
    ['class' => 'u-tasks__header']);

if ($notice !== '') { echo $OUTPUT->notification(s($notice), 'notifyproblem'); }
foreach (['saved' => $tab === 'checklists' ? 'Чек-лист сохранён.' : 'Личная заметка сохранена.', 'assigned' => 'Задача назначена.',
        'changed' => 'Статус задачи изменён.', 'deleted' => 'Личная заметка удалена.'] as $key => $message) {
    if (optional_param($key, 0, PARAM_BOOL)) { echo $OUTPUT->notification($message, 'notifysuccess'); }
}

$tabs = [
    'assigned' => 'Назначено мне',
    'outgoing' => 'Мои поручения',
    'checklists' => 'Чек-листы',
    'notebook' => 'Личный блокнот',
];
echo html_writer::start_div('u-stage6-tabs');
foreach ($tabs as $key => $label) {
    $attrs = ['href' => (new moodle_url('/local/ustar/tasks.php', ['tab' => $key]))->out(false),
        'class' => $tab === $key ? 'is-active' : '',
        'aria-current' => $tab === $key ? 'page' : null];
    echo html_writer::tag('a', $label, $attrs);
}
echo html_writer::end_div();

$taskform = static function(array $task, array $actions) use ($tab, $pageno, $attachmentlimit): void {
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
    echo html_writer::link(new moodle_url('/local/ustar/tasks.php',
        ['tab' => $tab, 'pageno' => $pageno, 'taskid' => $task['id']]),
        'Открыть карточку и файлы', ['class' => 'u-tasks__open']);
    foreach ($actions as $action => $label) {
        echo html_writer::start_tag('form', ['method' => 'post',
            'enctype' => $action === 'submit' ? 'multipart/form-data' : null, 'class' => 'u-tasks__action']);
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
            echo html_writer::tag('label', 'Файлы результата (до 10, каждый до ' . $attachmentlimit . ')',
                ['for' => 'result-files-' . (int)$task['id']]);
            echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'attachments[]', 'multiple' => 'multiple',
                'id' => 'result-files-' . (int)$task['id']]);
        }
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => $label, 'class' => 'u-btn']);
        echo html_writer::end_tag('form');
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
    $data = \local_ustar\checklist_service::present((int)$USER->id,
        optional_param('id', '', PARAM_ALPHANUMEXT),
        optional_param('date', '', PARAM_RAW_TRIMMED));
    $data['tasksurl'] = (new moodle_url('/local/ustar/tasks.php'))->out(false);
    echo $OUTPUT->render_from_template('local_ustar/checklists', $data);
}
if ($tab === 'notebook') {
    echo html_writer::tag('p', 'Заметки видны только вам: их текст не попадает в поиск, уведомления, аналитику или экран руководителя.',
        ['class' => 'u-tasks__hint']);
    echo html_writer::start_tag('details', ['class' => 'u-stage6-card u-tasks__create']);
    echo html_writer::tag('summary', 'Новая заметка');
    echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data',
        'class' => 'u-tasks__editor']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'note']);
    echo html_writer::tag('h2', 'Новая заметка');
    echo html_writer::tag('label', 'Название', ['for' => 'task-note-title']);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'required' => 'required',
        'id' => 'task-note-title', 'value' => $formvalue('note', 0, 'title'), 'class' => 'form-control']);
    echo html_writer::tag('label', 'Текст', ['for' => 'task-note-body']);
    echo html_writer::tag('textarea', s($formvalue('note', 0, 'description')),
        ['name' => 'description', 'id' => 'task-note-body', 'rows' => 4, 'class' => 'form-control']);
    echo html_writer::tag('label', 'Вложения (до 10, каждый до ' . $attachmentlimit . ')',
        ['for' => 'task-note-files']);
    echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'attachments[]',
        'id' => 'task-note-files', 'multiple' => 'multiple']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Добавить в блокнот', 'class' => 'u-btn u-btn--primary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('details');
    foreach ($notes as $task) {
        $taskform($task, (string)$task['status'] === 'open' ? ['complete' => 'Отметить выполненной'] : []);
        if ($selectedid !== (int)$task['id']) { continue; }
        echo html_writer::start_tag('details', ['class' => 'u-tasks__edit']);
        echo html_writer::tag('summary', 'Редактировать заметку');
        echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data',
            'class' => 'u-tasks__editform']);
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
        echo html_writer::tag('label', 'Добавить файлы', ['for' => 'note-files-' . $task['id']]);
        echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'attachments[]',
            'id' => 'note-files-' . $task['id'], 'multiple' => 'multiple']);
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
    if (\local_ustar\capabilities::has((int)$USER->id, \local_ustar\capabilities::COMPANY_READ)
            || \local_ustar\capabilities::has((int)$USER->id, \local_ustar\capabilities::TEAM_READ)) {
        echo html_writer::start_tag('details', ['class' => 'u-stage6-card u-tasks__create',
            'open' => $lookup !== '' ? 'open' : null]);
        echo html_writer::tag('summary', 'Поставить задачу сотруднику');
        echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'u-tasks__lookup']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'outgoing']);
        if ($selectedid) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'taskid', 'value' => $selectedid]);
        }
        echo html_writer::tag('label', 'Найти сотрудника по имени или логину', ['for' => 'task-lookup']);
        echo html_writer::empty_tag('input', ['type' => 'search', 'name' => 'lookup', 'value' => $lookup,
            'id' => 'task-lookup', 'minlength' => 2, 'class' => 'form-control']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Найти', 'class' => 'u-btn']);
        echo html_writer::end_tag('form');
        if ($lookup !== '' && !$candidates) {
            echo html_writer::tag('p', 'Сотрудники в доступной области не найдены.');
        }
        if ($candidates) {
        echo html_writer::start_tag('form', ['method' => 'post', 'enctype' => 'multipart/form-data',
            'class' => 'u-tasks__editor']);
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
        echo html_writer::tag('label', 'Вложения (до 10, каждый до ' . $attachmentlimit . ')',
            ['for' => 'task-files']);
        echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'attachments[]',
            'id' => 'task-files', 'multiple' => 'multiple']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Назначить', 'class' => 'u-btn u-btn--primary']);
        echo html_writer::end_tag('form');
        }
        echo html_writer::end_tag('details');
    }
    foreach ($outgoing as $task) {
        $actions = [];
        if ($task['status'] === 'in_review') { $actions = ['approve' => 'Принять', 'return' => 'Вернуть']; }
        if (!in_array($task['status'], ['completed', 'cancelled'], true)) { $actions['cancel'] = 'Отменить'; }
        $taskform($task, $actions);
    }
    if (!$outgoing) {
        echo html_writer::tag('p', 'Пока нет ваших назначений.', ['class' => 'u-stage6-card u-tasks__empty']);
    }
}
if ($selected) {
    echo html_writer::start_tag('section', ['class' => 'u-stage6-card u-tasks__detail',
        'aria-label' => 'Карточка и файлы']);
    echo html_writer::tag('h2', s($selected['title']));
    if ($tab === 'outgoing' && in_array($selected['status'], ['assigned', 'in_progress'], true)) {
        echo html_writer::start_tag('details', ['class' => 'u-tasks__edit']);
        echo html_writer::tag('summary', 'Изменить срок или исполнителя');
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-tasks__editform']);
        foreach (['sesskey' => sesskey(), 'action' => 'revisetask', 'id' => $selected['id'],
                'version' => $selected['version']] as $name => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        echo html_writer::tag('label', 'Срок', ['for' => 'task-revise-date']);
        echo html_writer::empty_tag('input', ['type' => 'date', 'id' => 'task-revise-date',
            'name' => 'duedate', 'value' => $selected['dueat'] ? userdate($selected['dueat'], '%Y-%m-%d') : '',
            'class' => 'form-control']);
        echo html_writer::tag('label', 'Исполнитель', ['for' => 'task-revise-assignee']);
        $assigneeoptions = [$selected['assigneeid'] => 'Текущий сотрудник'];
        if ($selected['status'] === 'assigned') {
            foreach ($candidates as $candidate) {
                $assigneeoptions[(int)$candidate['id']] = $candidate['fullname'];
            }
        }
        echo html_writer::select($assigneeoptions, 'assigneeid', $selected['assigneeid'], false,
            ['id' => 'task-revise-assignee', 'class' => 'form-select']);
        echo html_writer::tag('p', 'Для выбора другого сотрудника сначала найдите его в форме выше. Сменить исполнителя можно до начала работы.');
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Сохранить изменения', 'class' => 'u-btn']);
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('details');
    }
    $files = \local_ustar\task_files::list_for((int)$selected['id'], (int)$USER->id);
    if ($files) {
        echo html_writer::tag('h3', 'Вложения и результаты');
        echo html_writer::start_tag('ul');
        foreach ($files as $file) {
            echo html_writer::tag('li', s($file['label'] . ': ') . html_writer::link($file['url'], $file['name']) .
                ' · ' . display_size($file['size']));
        }
        echo html_writer::end_tag('ul');
    }
    if (!$selected['private']) {
        echo html_writer::tag('h3', 'История');
        foreach (\local_ustar\learning_tasks::events((int)$selected['id'], (int)$USER->id) as $event) {
            if ($event['comment'] !== '') {
                echo html_writer::tag('p', s(userdate($event['time']) . ': ' . $event['comment']));
            }
        }
    }
    echo html_writer::end_tag('section');
}
if ($count > 25) {
    echo $OUTPUT->paging_bar($count, $pageno, 25,
        new moodle_url('/local/ustar/tasks.php', ['tab' => $tab]));
}
echo html_writer::end_div();
echo $OUTPUT->footer();
