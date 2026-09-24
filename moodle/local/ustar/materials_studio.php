<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
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
            if (optional_param('id', 0, PARAM_INT) === 0
                    && optional_param('kind', 'course', PARAM_ALPHA) === 'assessment') {
                throw new invalid_parameter_exception(
                    'Новые аттестации создаются в конструкторе Moodle Quiz. Откройте ссылку «Создать аттестацию».'
                );
            }
            $item = \local_ustar\material_studio::save(
                optional_param('id', 0, PARAM_INT), $_POST, (int)$USER->id
            );
            if (!empty($item['replay'])) {
                // The same new-material POST was already committed.
            } else if (!empty($_FILES['scormzip']['name'])) {
                $item = \local_ustar\material_studio::upload_scorm_zip((int)$item['id'], (int)$USER->id, $_FILES['scormzip']);
            } else if (optional_param('buildscorm', 0, PARAM_BOOL)) {
                $item = \local_ustar\material_studio::build_scorm((int)$item['id'], (int)$USER->id);
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
            $editingid = isset($item['id']) ? (int)$item['id'] : optional_param('id', 0, PARAM_INT);
            $postedediting = isset($item['id']) ? null : [
                'id' => $editingid,
                'creationtoken' => optional_param('creationtoken', '', PARAM_ALPHANUM),
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
                'pages' => is_array($_POST['scormpages'] ?? null) ? $_POST['scormpages'] : [],
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
    'pages' => [],
];
if (empty($editing['id']) && empty($editing['creationtoken'])) {
    $editing['creationtoken'] = bin2hex(random_bytes(16));
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/materials_studio.php', ['id' => (int)$editing['id']]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Студия материалов | USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/stage6.css'));
$PAGE->set_heading('USTAR Academy');
echo $OUTPUT->header();
echo $OUTPUT->heading('Студия материалов');
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/local/ustar/assessment_studio.php'), 'Создать аттестацию',
    ['class' => 'btn btn-primary']));

if ($notice !== '') {
    echo $OUTPUT->notification(s($notice), 'notifyproblem');
}
foreach (['saved' => 'Материал сохранён.', 'published' => 'Материал опубликован.', 'draft' => 'Материал возвращён в черновики.', 'deleted' => 'Материал удалён из рабочего каталога. История и доказательства сохранены.'] as $param => $message) {
    if (optional_param($param, 0, PARAM_BOOL)) {
        echo $OUTPUT->notification($message, 'notifysuccess');
    }
}

echo html_writer::tag('p',
    'Здесь создаются учебные материалы и SCORM. Новые аттестации открываются в конструкторе Moodle Quiz — его попытки, оценки и проверка являются общим источником результата. Импортированный ZIP запускается как активность Moodle; его содержимое нельзя править в этом редакторе. После изменения сценария импортируйте пакет текущей версии перед публикацией.');
echo html_writer::start_tag('form', [
    'method' => 'post', 'enctype' => 'multipart/form-data',
    'action' => (new moodle_url('/local/ustar/materials_studio.php'))->out(false),
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$editing['id']]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'creationtoken',
    'value' => (string)($editing['creationtoken'] ?? '')]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'expectedmodified', 'value' => (int)$editing['expectedmodified']]);
if ((string)$editing['status'] === 'published') {
    echo $OUTPUT->notification('Материал опубликован. Верните его в черновик перед изменением или заменой пакета.', 'notifyinfo');
    echo html_writer::start_tag('fieldset', ['disabled' => 'disabled']);
}
echo html_writer::tag('label', 'Тип материала');
$kinds = ['course' => 'Учебный материал', 'scorm' => 'SCORM-курс'];
if ((int)$editing['id'] > 0 && (string)$editing['kind'] === 'assessment') {
    $kinds['assessment'] = 'Аттестация, созданная ранее';
}
echo html_writer::select($kinds, 'kind', (string)$editing['kind'], false,
    ['class' => 'form-select', 'id' => 'studio-kind']);
echo html_writer::tag('label', 'Название');
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'required' => 'required',
    'value' => (string)$editing['title'], 'class' => 'form-control']);
echo html_writer::tag('label', 'Краткое описание');
echo html_writer::tag('textarea', s((string)$editing['summary']), ['name' => 'summary', 'rows' => 2, 'class' => 'form-control']);
echo html_writer::tag('label', 'План курса');
echo html_writer::tag('textarea', s((string)$editing['outline']), ['name' => 'outline', 'rows' => 3, 'class' => 'form-control']);
echo html_writer::start_div('', ['id' => 'studio-course-fields']);
echo html_writer::tag('label', 'Редактор содержимого');
echo html_writer::tag('textarea', s((string)$editing['body']), ['name' => 'body', 'rows' => 12, 'class' => 'form-control',
    'placeholder' => 'Добавьте текст, списки, ссылки и оформление курса.']);
echo html_writer::end_div();
echo html_writer::start_div('u-studio-scorm-pages', ['id' => 'studio-scorm-pages']);
echo html_writer::tag('h3', 'Страницы SCORM');
echo html_writer::tag('p', 'Добавьте страницы в порядке прохождения. Для обычного импорта ZIP страницы заполнять не требуется.');
$pages = (array)($editing['pages'] ?? []);
if (!$pages) { $pages = [['title' => '', 'body' => '']]; }
foreach ($pages as $index => $page) {
    echo html_writer::start_div('u-studio-scorm-page');
    echo html_writer::tag('label', 'Название страницы ' . ((int)$index + 1));
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'scormpages[' . (int)$index . '][title]',
        'value' => (string)($page['title'] ?? ''), 'class' => 'form-control']);
    echo html_writer::tag('label', 'Содержимое страницы');
    echo html_writer::start_div('u-scorm-toolbar');
    echo html_writer::tag('button', 'Жирный', ['type' => 'button', 'data-format' => 'bold', 'class' => 'btn btn-sm']);
    echo html_writer::tag('button', 'Список', ['type' => 'button', 'data-format' => 'insertUnorderedList', 'class' => 'btn btn-sm']);
    echo html_writer::end_div();
    echo html_writer::tag('div', format_text((string)($page['body'] ?? ''), FORMAT_HTML, ['filter' => false]),
        ['class' => 'u-scorm-rich-editor', 'contenteditable' => 'true', 'role' => 'textbox',
            'aria-label' => 'Содержимое страницы ' . ((int)$index + 1), 'aria-multiline' => 'true']);
    echo html_writer::tag('textarea', s((string)($page['body'] ?? '')),
        ['name' => 'scormpages[' . (int)$index . '][body]', 'rows' => 6,
            'class' => 'form-control', 'placeholder' => 'Текст страницы, списки и ссылки']);
    echo html_writer::tag('button', 'Удалить страницу', ['type' => 'button',
        'class' => 'btn btn-outline-secondary u-scorm-remove']);
    echo html_writer::end_div();

}
echo html_writer::tag('button', 'Добавить страницу', ['type' => 'button', 'id' => 'studio-add-page',
    'class' => 'btn btn-outline-secondary']);
echo html_writer::start_tag('label');
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'buildscorm', 'value' => 1]);
echo ' Собрать и импортировать SCORM из страниц при сохранении';
echo html_writer::end_tag('label');
echo html_writer::end_div();
echo html_writer::start_div('', ['id' => 'studio-assessment-fields']);
echo html_writer::tag('label', 'Вопросы аттестации');
echo html_writer::tag('p', 'Для аттестации добавьте по одному вопросу на строку: Вопрос | вариант 1 | вариант 2 | номер верного варианта.');
echo html_writer::tag('textarea', s((string)$editing['questionslines']), ['name' => 'questions', 'rows' => 6, 'class' => 'form-control']);
echo html_writer::tag('label', 'Проходной балл');
echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'passscore', 'min' => 1, 'max' => 100,
    'value' => (int)$editing['passscore'], 'class' => 'form-control']);
echo html_writer::end_div();
echo html_writer::start_div('', ['id' => 'studio-zip-fields']);
echo html_writer::tag('label', 'Импорт ZIP-пакета SCORM');
echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'scormzip', 'accept' => '.zip,application/zip']);
echo html_writer::end_div();
if ($editing['packagestatus'] === 'imported') {
    echo html_writer::tag('p', 'Подключён пакет: ' . s((string)$editing['packagefilename'])
        . ($editing['packageurl'] ? ' · ' . html_writer::link((string)$editing['packageurl'], 'скачать пакет') : ''));
    if ((string)$editing['kind'] === 'scorm' && (int)$editing['id'] > 0
            && \local_ustar\material_studio::runtime_ready((int)$editing['id'])) {
        echo $OUTPUT->notification('Пакет текущей версии импортирован в Moodle. В маршруте выберите этот материал: результаты и возобновление записывает Moodle.', 'notifyinfo');
    }
}
if ((string)$editing['status'] !== 'published') {
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Сохранить в черновик', 'class' => 'btn btn-primary']);
} else {
    echo html_writer::end_tag('fieldset');
}
echo html_writer::end_tag('form');
$PAGE->requires->js_init_code(<<<'JS'
(function() {
    const panel = document.getElementById('studio-scorm-pages');
    const add = document.getElementById('studio-add-page');
    if (!panel || !add) { return; }
    const kind = document.getElementById('studio-kind');
    function showFields() {
        document.getElementById('studio-course-fields').hidden = kind.value !== 'course';
        document.getElementById('studio-assessment-fields').hidden = kind.value !== 'assessment';
        panel.hidden = kind.value !== 'scorm';
        document.getElementById('studio-zip-fields').hidden = kind.value !== 'scorm';
    }
    kind.addEventListener('change', showFields);
    showFields();
    function init(page) {
        const source = page.querySelector('textarea');
        const rich = page.querySelector('.u-scorm-rich-editor');
        source.hidden = true;
        rich.addEventListener('input', function() { source.value = rich.innerHTML; });
        rich.addEventListener('paste', function(e) {
            e.preventDefault();
            document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
        });
    }
    panel.querySelectorAll('.u-studio-scorm-page').forEach(init);
    panel.closest('form').addEventListener('submit', function() {
        panel.querySelectorAll('.u-studio-scorm-page').forEach(function(page) {
            page.querySelector('textarea').value = page.querySelector('.u-scorm-rich-editor').innerHTML;
        });
    });
    add.addEventListener('click', function() {
        const pages = panel.querySelectorAll('.u-studio-scorm-page');
        if (pages.length >= 30) { return; }
        const node = pages[0].cloneNode(true);
        const index = Math.max(...Array.from(panel.querySelectorAll('[name^="scormpages["]'))
            .map(function(input) { return Number(input.name.match(/^scormpages\[(\d+)\]/)[1]); })) + 1;
        node.querySelectorAll('input,textarea').forEach(function(input) {
            input.name = input.name.replace(/^scormpages\[\d+\]/, 'scormpages[' + index + ']');
            input.value = '';
        });
        node.querySelector('.u-scorm-rich-editor').innerHTML = '';
        node.querySelector('label').textContent = 'Название страницы ' + (index + 1);
        panel.insertBefore(node, add);
        init(node);
    });
    panel.addEventListener('click', function(event) {
        if (event.target.matches('.u-scorm-remove')) {
            const page = event.target.closest('.u-studio-scorm-page');
            if (panel.querySelectorAll('.u-studio-scorm-page').length > 1) { page.remove(); }
            else { page.querySelectorAll('input,textarea').forEach(function(input) { input.value = ''; });
                page.querySelector('.u-scorm-rich-editor').innerHTML = ''; }
        }
        const button = event.target.closest('[data-format]');
        if (button) { event.preventDefault();
            const editor = button.closest('.u-studio-scorm-page').querySelector('.u-scorm-rich-editor');
            editor.focus(); document.execCommand(button.dataset.format, false);
        }
    });
}());
JS);

if ((int)$editing['id'] > 0) {
    echo html_writer::start_div('u-studio-actions');
    if ((string)$editing['kind'] === 'assessment') {
        echo html_writer::link(new moodle_url('/local/ustar/material_player.php',
            ['id' => (int)$editing['id'], 'preview' => 1]), 'Предпросмотр без записи результата',
            ['class' => 'btn btn-outline-secondary', 'target' => '_blank', 'rel' => 'noopener noreferrer']);
    }
    if ((string)$editing['kind'] === 'scorm' && !empty($editing['pages'])) {
        echo html_writer::link(new moodle_url('/local/ustar/studio_scorm_preview.php',
            ['id' => (int)$editing['id']]), 'Предпросмотр без записи результата',
            ['class' => 'btn btn-outline-secondary', 'target' => '_blank', 'rel' => 'noopener noreferrer']);
    }
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

    echo html_writer::start_div('u-studio-history');
    echo $OUTPUT->heading('История исходника', 3);
    echo html_writer::tag('p', 'Последние 50 сохранений. Ранние редакции без снимка исходника нельзя восстановить из номера версии.');
    foreach (\local_ustar\material_studio::source_history((int)$editing['id'], (int)$USER->id) as $revision) {
        $label = 'Версия ' . (int)$revision['version'] . ' · '
            . userdate((int)$revision['timecreated']) . ' · автор #' . (int)$revision['actorid'];
        echo html_writer::start_tag('details', ['class' => 'u-studio-revision']);
        echo html_writer::tag('summary', s($label));
        if (is_array($revision['source'])) {
            echo html_writer::tag('p', 'Название: ' . s($revision['title']));
            if ($revision['summary'] !== '') {
                echo html_writer::tag('p', 'Описание: ' . s($revision['summary']));
            }
            $source = $revision['source'];
            if (!empty($source['outline'])) {
                echo html_writer::tag('p', 'План: ' . s((string)$source['outline']));
            }
            if (!empty($source['body'])) {
                echo html_writer::tag('div', format_text((string)$source['body'], FORMAT_HTML),
                    ['class' => 'u-studio-revision-body']);
            }
            foreach ((array)($source['pages'] ?? []) as $page) {
                echo html_writer::tag('h4', s((string)($page['title'] ?? 'Страница')));
                echo html_writer::tag('div', format_text((string)($page['body'] ?? ''), FORMAT_HTML),
                    ['class' => 'u-studio-revision-body']);
            }
            if ($revision['hash'] !== '') {
                echo html_writer::tag('small', 'SHA-256 исходника: ' . s($revision['hash']));
            }
        } else {
            echo html_writer::tag('p', 'Для этой ранней версии сохранён только факт редактирования.');
        }
        echo html_writer::end_tag('details');
    }
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
