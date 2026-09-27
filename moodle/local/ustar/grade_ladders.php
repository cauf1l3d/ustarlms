<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/ustar:hrmanage', $context);
\local_ustar\view_as::assert_writable();

$ladderid = optional_param('id', 0, PARAM_INT);
$positionid = optional_param('positionid', '', PARAM_ALPHANUMEXT);
$notice = '';

/** Parse a short human-editable list, not arbitrary JSON from the browser. */
function ustar_grade_lines(string $text): array {
    $grades = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        if (trim($line) === '') { continue; }
        $pair = explode('|', $line, 2);
        if (count($pair) !== 2) {
            throw new invalid_parameter_exception('Формат ступени: ключ | название.');
        }
        $grades[] = ['id' => trim($pair[0]), 'name' => trim($pair[1])];
    }
    return $grades;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        $action = required_param('action', PARAM_ALPHA);
        if ($action === 'create') {
            $ladder = \local_ustar\grade_ladders::create(
                required_param('name', PARAM_TEXT),
                ustar_grade_lines(required_param('grades', PARAM_RAW)), (int)$USER->id
            );
            redirect(new moodle_url('/local/ustar/grade_ladders.php', ['id' => (int)$ladder->id]));
        }
        if ($action === 'save') {
            \local_ustar\grade_ladders::save_draft(required_param('id', PARAM_INT),
                ustar_grade_lines(required_param('grades', PARAM_RAW)),
                required_param('revision', PARAM_INT), (int)$USER->id);
            redirect(new moodle_url('/local/ustar/grade_ladders.php', ['id' => $ladderid, 'saved' => 1]));
        }
        if ($action === 'publish') {
            \local_ustar\grade_ladders::publish(required_param('id', PARAM_INT),
                required_param('revision', PARAM_INT), (int)$USER->id);
            redirect(new moodle_url('/local/ustar/grade_ladders.php', ['id' => $ladderid, 'published' => 1]));
        }
        if ($action === 'archive') {
            \local_ustar\grade_ladders::archive(required_param('id', PARAM_INT),
                required_param('revision', PARAM_INT), (int)$USER->id);
            redirect(new moodle_url('/local/ustar/grade_ladders.php', ['id' => $ladderid]));
        }
        if ($action === 'bind') {
            $positionid = required_param('positionid', PARAM_ALPHANUMEXT);
            \local_ustar\grade_ladders::bind($positionid, required_param('versionid', PARAM_INT),
                required_param('revision', PARAM_INT), (int)$USER->id,
                required_param('reason', PARAM_TEXT));
            redirect(new moodle_url('/local/ustar/grade_ladders.php', [
                'id' => $ladderid, 'positionid' => $positionid, 'bound' => 1,
            ]));
        }
        if ($action === 'unbind') {
            $positionid = required_param('positionid', PARAM_ALPHANUMEXT);
            \local_ustar\grade_ladders::unbind($positionid,
                required_param('revision', PARAM_INT), (int)$USER->id,
                required_param('reason', PARAM_TEXT));
            redirect(new moodle_url('/local/ustar/grade_ladders.php', [
                'id' => $ladderid, 'positionid' => $positionid, 'unbound' => 1,
            ]));
        }
    } catch (Throwable $e) { $notice = $e->getMessage(); }
}

$ladders = \local_ustar\grade_ladders::all();
$ladder = $ladderid ? $DB->get_record('local_ustar_grade_ladders', ['id' => $ladderid], '*', MUST_EXIST) : null;
$versions = $ladder ? $DB->get_records('local_ustar_grade_ladder_ver',
    ['ladderid' => $ladderid], 'versionno DESC') : [];
$positions = \local_ustar\people::position_map(\local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE));
$binding = $positionid !== '' ? \local_ustar\grade_ladders::binding($positionid) : null;

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/grade_ladders.php', ['id' => $ladderid]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Лестницы грейдов | USTAR Academy');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/stage6.css'));
echo $OUTPUT->header();
echo html_writer::start_div('u-grades');
echo html_writer::start_tag('header', ['class' => 'u-grades__header']);
echo html_writer::tag('p', 'Развитие · USTAR Академия', ['class' => 'u-grades__eyebrow']);
echo html_writer::tag('h1', 'Настройка лестниц');
echo html_writer::tag('p', 'Создайте ступени, опубликуйте версию, затем привяжите её к должности. '
    . 'Изменение черновика не меняет действующие назначения.', ['class' => 'u-grades__intro']);
echo html_writer::end_tag('header');
if ($notice !== '') { echo $OUTPUT->notification(s($notice), 'notifyproblem'); }
foreach (['saved' => 'Черновик сохранён.', 'published' => 'Версия опубликована.',
        'bound' => 'Привязка должности сохранена.',
        'unbound' => 'Привязка снята с сохранением истории.'] as $flag => $message) {
    if (optional_param($flag, 0, PARAM_BOOL)) { echo $OUTPUT->notification($message, 'notifysuccess'); }
}

echo html_writer::start_div('u-stage6-tabs');
echo html_writer::link(new moodle_url('/local/ustar/grades.php'), 'Грейды');
echo html_writer::link(new moodle_url('/local/ustar/grade_rules.php'), 'Правила переходов');
echo html_writer::end_div();

echo html_writer::tag('h2', 'Лестницы');
echo html_writer::start_tag('ul', ['class' => 'u-grades__catalog']);
foreach ($ladders as $item) {
    echo html_writer::tag('li', html_writer::link(new moodle_url('/local/ustar/grade_ladders.php',
        ['id' => (int)$item->id]), format_string((string)$item->name))
        . ' · ' . ($item->status === 'active' ? 'Действует' : 'Архив'));
}
echo html_writer::end_tag('ul');

if (!$ladder) {
    echo html_writer::start_div('u-stage6-card u-grades__request');
    echo html_writer::tag('h2', 'Новая лестница');
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-grades__editor']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'create']);
    echo html_writer::tag('label', 'Название', ['for' => 'ladder-name']);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'name', 'id' => 'ladder-name',
        'required' => 'required', 'class' => 'form-control']);
    echo html_writer::tag('label', 'Ступени: ключ | название, по одной строке', ['for' => 'ladder-grades']);
    echo html_writer::tag('textarea', '', ['name' => 'grades', 'id' => 'ladder-grades',
        'rows' => 6, 'required' => 'required', 'class' => 'form-control']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Создать черновик',
        'class' => 'u-btn u-btn--primary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
} else {
    $grades = json_decode((string)$ladder->draftjson, true) ?: [];
    $lines = implode("\n", array_map(static fn(array $row): string =>
        (string)$row['id'] . ' | ' . (string)$row['name'], $grades));
    echo html_writer::start_div('u-stage6-card u-grades__request');
    echo html_writer::tag('h2', format_string((string)$ladder->name));
    echo html_writer::tag('p', 'Черновик · ревизия ' . (int)$ladder->revision);
    if ($ladder->status === 'active') {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-grades__editor']);
        foreach (['sesskey' => sesskey(), 'action' => 'save', 'id' => $ladderid,
                'revision' => (int)$ladder->revision] as $field => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
        }
        echo html_writer::tag('label', 'Ступени: ключ | название, порядок имеет значение',
            ['for' => 'ladder-edit']);
        echo html_writer::tag('textarea', s($lines), ['name' => 'grades', 'id' => 'ladder-edit',
            'rows' => max(5, count($grades) + 1), 'required' => 'required', 'class' => 'form-control']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Сохранить черновик', 'class' => 'u-btn']);
        echo html_writer::end_tag('form');
        foreach (['publish' => 'Опубликовать новую версию', 'archive' => 'Архивировать лестницу'] as $action => $label) {
            echo html_writer::start_tag('form', ['method' => 'post']);
            foreach (['sesskey' => sesskey(), 'action' => $action, 'id' => $ladderid,
                    'revision' => (int)$ladder->revision] as $field => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
            }
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => $label, 'class' => 'u-btn']);
            echo html_writer::end_tag('form');
        }
    }
    echo html_writer::end_div();
    echo html_writer::tag('h2', 'Опубликованные версии');
    foreach ($versions as $version) {
        echo html_writer::tag('p', 'Версия ' . (int)$version->versionno . ' · '
            . s(substr((string)$version->gradehash, 0, 12)));
    }
    if ($versions && $ladder->status === 'active') {
        echo html_writer::start_div('u-stage6-card u-grades__request');
        echo html_writer::tag('h2', 'Привязать к должности');
        echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'u-grades__search']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $ladderid]);
        $positionoptions = ['' => '— Выберите должность —'];
        foreach ($positions as $position) { $positionoptions[(string)$position['id']] = (string)$position['name']; }
        echo html_writer::tag('label', 'Должность', ['for' => 'binding-position']);
        echo html_writer::select($positionoptions, 'positionid', $positionid, false,
            ['id' => 'binding-position', 'class' => 'form-select']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Показать', 'class' => 'u-btn']);
        echo html_writer::end_tag('form');
        if ($positionid !== '' && isset($positions[$positionid])) {
            echo html_writer::tag('p', $binding && !empty($binding->ladderversionid)
                ? 'Действующая версия #' . (int)$binding->ladderversionid
                : 'Лестница не назначена.');
            $affected = (int)$DB->count_records('local_ustar_employee_grades', ['positionid' => $positionid]);
            $pending = (int)$DB->count_records_sql(
                'SELECT COUNT(*) FROM {local_ustar_grade_requests} r
                   JOIN {local_ustar_employee_grades} g ON g.userid = r.userid
                  WHERE g.positionid = :positionid AND r.status = :status',
                ['positionid' => $positionid, 'status' => \local_ustar\grade_promotion::STATUS_PENDING]);
            echo html_writer::tag('p', 'Затронуты текущие грейды: ' . $affected
                . ' · ожидающие заявки: ' . $pending
                . '. После смены версии переход потребует проверки HR, история сохраняется.',
                ['class' => 'u-grades__hint']);
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-grades__assignment']);
            foreach (['sesskey' => sesskey(), 'action' => 'bind', 'id' => $ladderid,
                    'positionid' => $positionid, 'revision' => $binding ? (int)$binding->revision : 0]
                    as $field => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
            }
            $options = [];
            foreach ($versions as $version) { $options[(int)$version->id] = 'Версия ' . (int)$version->versionno; }
            echo html_writer::tag('label', 'Опубликованная версия', ['for' => 'binding-version']);
            echo html_writer::select($options, 'versionid', $binding ? (int)$binding->ladderversionid : '',
                false, ['id' => 'binding-version', 'class' => 'form-select']);
            echo html_writer::tag('label', 'Основание изменения', ['for' => 'binding-reason']);
            echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'reason', 'id' => 'binding-reason',
                'required' => 'required', 'class' => 'form-control']);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Привязать версию',
                'class' => 'u-btn u-btn--primary']);
            echo html_writer::end_tag('form');
            if ($binding && !empty($binding->ladderversionid)) {
                echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-grades__assignment']);
                foreach (['sesskey' => sesskey(), 'action' => 'unbind', 'id' => $ladderid,
                        'positionid' => $positionid, 'revision' => (int)$binding->revision]
                        as $field => $value) {
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
                }
                echo html_writer::tag('label', 'Основание снятия привязки', ['for' => 'binding-remove-reason']);
                echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'reason',
                    'id' => 'binding-remove-reason', 'required' => 'required', 'class' => 'form-control']);
                echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Снять привязку',
                    'class' => 'u-btn']);
                echo html_writer::end_tag('form');
            }
        }
        echo html_writer::end_div();
    }
}
echo html_writer::end_div();
echo $OUTPUT->footer();
