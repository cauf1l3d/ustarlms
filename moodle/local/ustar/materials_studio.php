<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
if (!\local_ustar\material_studio::can_manage((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/ustar:hrmanage', 'nopermissions', '');
}

$notice = '';
$editingid = optional_param('id', 0, PARAM_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    $action = required_param('action', PARAM_ALPHANUMEXT);
    try {
        if ($action === 'save') {
            $item = \local_ustar\material_studio::save(
                optional_param('id', 0, PARAM_INT), $_POST, (int)$USER->id
            );
            if (!empty($_FILES['scormzip']['name'])) {
                $item = \local_ustar\material_studio::upload_scorm_zip((int)$item['id'], (int)$USER->id, $_FILES['scormzip']);
            }
            redirect(new moodle_url('/local/ustar/materials_studio.php', ['id' => (int)$item['id'], 'saved' => 1]));
        }
        $id = required_param('id', PARAM_INT);
        if ($action === 'publish') {
            \local_ustar\content_admin::publish($id, (int)$USER->id);
            redirect(new moodle_url('/local/ustar/materials_studio.php', ['id' => $id, 'published' => 1]));
        }
        if ($action === 'unpublish') {
            \local_ustar\content_admin::unpublish($id, (int)$USER->id);
            redirect(new moodle_url('/local/ustar/materials_studio.php', ['id' => $id, 'draft' => 1]));
        }
        if ($action === 'delete') {
            \local_ustar\material_studio::delete($id, (int)$USER->id,
                optional_param('reason', '', PARAM_TEXT));
            redirect(new moodle_url('/local/ustar/materials_studio.php', ['deleted' => 1]));
        }
    } catch (\Throwable $e) {
        $notice = $e->getMessage();
        if ($action === 'save') {
            $editingid = optional_param('id', 0, PARAM_INT);
            $postedediting = [
                'id' => $editingid,
                'kind' => optional_param('kind', 'course', PARAM_ALPHA),
                'title' => optional_param('title', '', PARAM_TEXT),
                'summary' => optional_param('summary', '', PARAM_TEXT),
                'outline' => optional_param('outline', '', PARAM_TEXT),
                'body' => optional_param('body', '', PARAM_RAW),
                'questionslines' => optional_param('questions', '', PARAM_RAW),
                'passscore' => optional_param('passscore', 80, PARAM_INT),
                'expectedmodified' => optional_param('expectedmodified', 0, PARAM_INT),
                'status' => 'draft', 'packagestatus' => 'none',
                'packagefilename' => '', 'packageurl' => '',
            ];
        }
    }
}

$items = \local_ustar\material_studio::list_for_author((int)$USER->id);
$editing = null;
foreach ($items as $item) {
    if ((int)$item['id'] === $editingid) {
        $editing = $item;
        break;
    }
}
$editing = ($postedediting ?? $editing) ?: [
    'id' => 0, 'kind' => 'course', 'title' => '', 'summary' => '', 'outline' => '', 'body' => '',
    'questionslines' => '', 'passscore' => 80, 'expectedmodified' => 0, 'status' => 'draft',
    'packagestatus' => 'none', 'packagefilename' => '', 'packageurl' => '',
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/materials_studio.php', ['id' => (int)$editing['id']]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Студия материалов | USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/stage6.css'));
$PAGE->set_heading('USTAR Academy');
echo $OUTPUT->header();
echo $OUTPUT->heading('Студия материалов');

if ($notice !== '') {
    echo $OUTPUT->notification(s($notice), 'notifyproblem');
}
foreach (['saved' => 'Материал сохранён.', 'published' => 'Материал опубликован.', 'draft' => 'Материал возвращён в черновики.', 'deleted' => 'Материал удалён из рабочего каталога. История и доказательства сохранены.'] as $param => $message) {
    if (optional_param($param, 0, PARAM_BOOL)) {
        echo $OUTPUT->notification($message, 'notifysuccess');
    }
}

echo html_writer::tag('p',
    'В одном месте создаются курсы, аттестации и SCORM. Текст курса редактируется здесь, а импортированный ZIP хранится как пакет: его нельзя ошибочно выдать за редактируемый исходник.');
echo html_writer::start_tag('form', [
    'method' => 'post', 'enctype' => 'multipart/form-data',
    'action' => (new moodle_url('/local/ustar/materials_studio.php'))->out(false),
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$editing['id']]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'expectedmodified', 'value' => (int)$editing['expectedmodified']]);
echo html_writer::tag('label', 'Тип материала');
echo html_writer::select([
    'course' => 'Курс',
    'scorm' => 'SCORM-курс',
    'assessment' => 'Аттестация',
], 'kind', (string)$editing['kind'], false, ['class' => 'form-select']);
echo html_writer::tag('label', 'Название');
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'required' => 'required',
    'value' => (string)$editing['title'], 'class' => 'form-control']);
echo html_writer::tag('label', 'Краткое описание');
echo html_writer::tag('textarea', s((string)$editing['summary']), ['name' => 'summary', 'rows' => 2, 'class' => 'form-control']);
echo html_writer::tag('label', 'План курса');
echo html_writer::tag('textarea', s((string)$editing['outline']), ['name' => 'outline', 'rows' => 3, 'class' => 'form-control']);
echo html_writer::tag('label', 'Редактор содержимого');
echo html_writer::tag('textarea', s((string)$editing['body']), ['name' => 'body', 'rows' => 12, 'class' => 'form-control',
    'placeholder' => 'Добавьте текст, списки, ссылки и оформление курса.']);
echo html_writer::tag('label', 'Вопросы аттестации');
echo html_writer::tag('p', 'Для аттестации добавьте по одному вопросу на строку: Вопрос | вариант 1 | вариант 2 | номер верного варианта.');
echo html_writer::tag('textarea', s((string)$editing['questionslines']), ['name' => 'questions', 'rows' => 6, 'class' => 'form-control']);
echo html_writer::tag('label', 'Проходной балл');
echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'passscore', 'min' => 1, 'max' => 100,
    'value' => (int)$editing['passscore'], 'class' => 'form-control']);
echo html_writer::tag('label', 'Импорт ZIP-пакета SCORM');
echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'scormzip', 'accept' => '.zip,application/zip']);
if ($editing['packagestatus'] === 'imported') {
    echo html_writer::tag('p', 'Подключён пакет: ' . s((string)$editing['packagefilename'])
        . ($editing['packageurl'] ? ' · ' . html_writer::link((string)$editing['packageurl'], 'скачать пакет') : ''));
}
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Сохранить в черновик', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if ((int)$editing['id'] > 0) {
    echo html_writer::start_div('u-studio-actions');
    foreach ([
        $editing['status'] === 'published' ? 'unpublish' : 'publish' =>
            $editing['status'] === 'published' ? 'Вернуть в черновики' : 'Опубликовать',
    ] as $action => $label) {
        echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$editing['id']]);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => $label, 'class' => 'btn']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'delete']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$editing['id']]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Удалить из каталога', 'class' => 'btn btn-outline-danger']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
}

echo $OUTPUT->heading('Материалы студии', 3);
echo html_writer::start_tag('ul');
foreach ($items as $item) {
    $label = s((string)$item['title']) . ' · ' . s((string)$item['kind']) . ' · ' . s((string)$item['status']);
    echo html_writer::tag('li', html_writer::link(
        new moodle_url('/local/ustar/materials_studio.php', ['id' => (int)$item['id']]), $label
    ));
}
echo html_writer::end_tag('ul');
echo html_writer::tag('p', html_writer::link(new moodle_url('/local/ustar/materials.php'), 'Вернуться в каталог материалов'));
echo $OUTPUT->footer();
