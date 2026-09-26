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
            $haszip = !empty($_FILES['scormzip']['name']);
            $buildscorm = optional_param('buildscorm', 0, PARAM_BOOL);
            if ($haszip && $buildscorm) {
                throw new invalid_parameter_exception(
                    'Выберите один способ подготовки SCORM: загрузить ZIP или собрать из страниц.'
                );
            }
            if (($haszip || $buildscorm) && optional_param('kind', 'course', PARAM_ALPHA) !== 'scorm') {
                throw new invalid_parameter_exception('SCORM-пакет можно добавить только к материалу типа SCORM.');
            }
            if (optional_param('id', 0, PARAM_INT) === 0
                    && optional_param('kind', 'course', PARAM_ALPHA) === 'assessment') {
                throw new invalid_parameter_exception(
                    'Новые аттестации создаются в конструкторе Moodle Quiz. Откройте ссылку «Создать аттестацию».'
                );
            }
            $input = $_POST;
            $input['scormimages'] = $_FILES['scormimages'] ?? [];
            $item = \local_ustar\material_studio::save(
                optional_param('id', 0, PARAM_INT), $input, (int)$USER->id
            );
            if (!empty($item['replay'])) {
                // The same new-material POST was already committed.
            } else if ($haszip) {
                $item = \local_ustar\material_studio::upload_scorm_zip((int)$item['id'], (int)$USER->id, $_FILES['scormzip']);
            } else if ($buildscorm) {
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
    ['class' => 'u-btn u-btn--primary']));

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
echo html_writer::start_tag('section', ['class' => 'u-catalog-editor', 'aria-label' => 'Редактор материала']);
echo html_writer::tag('h2', (int)$editing['id'] > 0 ? 'Редактирование материала' : 'Новый материал');
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
echo html_writer::tag('label', 'Тип материала', ['for' => 'studio-kind']);
$kinds = ['course' => 'Учебный материал', 'scorm' => 'SCORM-курс'];
if ((int)$editing['id'] > 0 && (string)$editing['kind'] === 'assessment') {
    $kinds['assessment'] = 'Аттестация, созданная ранее';
}
echo html_writer::select($kinds, 'kind', (string)$editing['kind'], false,
    ['class' => 'form-select', 'id' => 'studio-kind']);
echo html_writer::tag('label', 'Название', ['for' => 'studio-title']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'required' => 'required',
    'id' => 'studio-title', 'value' => (string)$editing['title'], 'class' => 'form-control']);
echo html_writer::tag('label', 'Краткое описание', ['for' => 'studio-summary']);
echo html_writer::tag('textarea', s((string)$editing['summary']),
    ['id' => 'studio-summary', 'name' => 'summary', 'rows' => 2, 'class' => 'form-control']);
echo html_writer::tag('label', 'План курса', ['for' => 'studio-outline']);
echo html_writer::tag('textarea', s((string)$editing['outline']),
    ['id' => 'studio-outline', 'name' => 'outline', 'rows' => 3, 'class' => 'form-control']);
echo html_writer::start_div('', ['id' => 'studio-course-fields']);
echo html_writer::tag('label', 'Редактор содержимого', ['for' => 'studio-body']);
echo html_writer::tag('textarea', s((string)$editing['body']), ['id' => 'studio-body', 'name' => 'body', 'rows' => 12, 'class' => 'form-control',
    'placeholder' => 'Добавьте текст, списки, ссылки и оформление курса.']);
echo html_writer::end_div();
echo html_writer::start_div('u-studio-scorm-pages', ['id' => 'studio-scorm-pages']);
echo html_writer::tag('h3', 'Страницы SCORM');
echo html_writer::tag('p', 'Добавьте страницы в порядке прохождения. Для обычного импорта ZIP страницы заполнять не требуется.');
echo html_writer::tag('p', 'Фото на странице будет включено в пакет при выборе «Собрать и импортировать SCORM из страниц».');
$pages = (array)($editing['pages'] ?? []);
if (!$pages) { $pages = [['title' => '', 'body' => '']]; }
foreach ($pages as $index => $page) {
    echo html_writer::start_div('u-studio-scorm-page');
    echo html_writer::tag('label', 'Название страницы ' . ((int)$index + 1),
        ['for' => 'studio-page-title-' . (int)$index]);
    echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'scormpages[' . (int)$index . '][title]',
        'id' => 'studio-page-title-' . (int)$index,
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
    echo html_writer::empty_tag('input', ['type' => 'hidden',
        'name' => 'scormpages[' . (int)$index . '][imagekey]',
        'value' => (string)($page['imagekey'] ?? '')]);
    echo html_writer::tag('label', 'Фото страницы (PNG, JPEG, WebP, до 1 МБ)',
        ['for' => 'studio-page-image-' . (int)$index]);
    echo html_writer::empty_tag('input', ['type' => 'file',
        'name' => 'scormimages[' . (int)$index . ']', 'id' => 'studio-page-image-' . (int)$index,
        'accept' => 'image/png,image/jpeg,image/webp']);
    if (!empty($page['image'])) {
        echo html_writer::empty_tag('img', ['src' => (string)$page['image'],
            'alt' => 'Фото страницы ' . ((int)$index + 1), 'class' => 'u-studio-scorm-image']);
        echo html_writer::start_tag('label', ['class' => 'u-studio-scorm-remove-image']);
        echo html_writer::empty_tag('input', ['type' => 'checkbox', 'value' => 1,
            'name' => 'scormpages[' . (int)$index . '][removeimage]']);
        echo ' Удалить фото при сохранении';
        echo html_writer::end_tag('label');
    }
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
echo html_writer::tag('label', 'Вопросы аттестации', ['for' => 'studio-questions']);
echo html_writer::tag('p', 'Для аттестации добавьте по одному вопросу на строку: Вопрос | вариант 1 | вариант 2 | номер верного варианта.');
echo html_writer::tag('textarea', s((string)$editing['questionslines']),
    ['id' => 'studio-questions', 'name' => 'questions', 'rows' => 6, 'class' => 'form-control']);
echo html_writer::tag('label', 'Проходной балл', ['for' => 'studio-passscore']);
echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'passscore', 'min' => 1, 'max' => 100,
    'id' => 'studio-passscore', 'value' => (int)$editing['passscore'], 'class' => 'form-control']);
echo html_writer::end_div();
echo html_writer::start_div('', ['id' => 'studio-zip-fields']);
echo html_writer::tag('label', 'Импорт ZIP-пакета SCORM', ['for' => 'studio-scorm-zip']);
echo html_writer::empty_tag('input', ['type' => 'file', 'id' => 'studio-scorm-zip',
    'name' => 'scormzip', 'accept' => '.zip,application/zip']);
echo html_writer::tag('p', 'Выберите загрузку ZIP или сборку из страниц — одновременно их использовать нельзя.');
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
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Сохранить в черновик',
        'class' => 'u-btn u-btn--primary']);
} else {
    echo html_writer::end_tag('fieldset');
}
echo html_writer::end_tag('form');
echo html_writer::end_tag('section');
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
            input.name = input.name.replace(/^scormimages\[\d+\]/, 'scormimages[' + index + ']');
            if (input.id && input.name.endsWith('[title]')) {
                input.id = 'studio-page-title-' + index;
            }
            if (input.type === 'file') { input.id = 'studio-page-image-' + index; }
            if (input.type === 'checkbox') { input.checked = false; }
            else { input.value = ''; }
        });
        const photo = node.querySelector('.u-studio-scorm-image');
        if (photo) { photo.remove(); }
        const removePhoto = node.querySelector('.u-studio-scorm-remove-image');
        if (removePhoto) { removePhoto.remove(); }
        node.querySelector('.u-scorm-rich-editor').innerHTML = '';
        const titleLabel = node.querySelector('label');
        titleLabel.textContent = 'Название страницы ' + (index + 1);
        titleLabel.htmlFor = 'studio-page-title-' + index;
        const photoLabel = node.querySelector('label[for^="studio-page-image-"]');
        if (photoLabel) { photoLabel.htmlFor = 'studio-page-image-' + index; }
        panel.insertBefore(node, add);
        init(node);
    });
    panel.addEventListener('click', function(event) {
        if (event.target.matches('.u-scorm-remove')) {
            const page = event.target.closest('.u-studio-scorm-page');
            if (panel.querySelectorAll('.u-studio-scorm-page').length > 1) { page.remove(); }
            else { page.querySelectorAll('input,textarea').forEach(function(input) { input.value = ''; });
                page.querySelector('.u-scorm-rich-editor').innerHTML = '';
                const photo = page.querySelector('.u-studio-scorm-image');
                if (photo) { photo.remove(); } }
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
            ['class' => 'u-btn', 'target' => '_blank', 'rel' => 'noopener noreferrer']);
    }
    if ((string)$editing['kind'] === 'scorm' && !empty($editing['pages'])) {
        echo html_writer::link(new moodle_url('/local/ustar/studio_scorm_preview.php',
            ['id' => (int)$editing['id']]), 'Предпросмотр без записи результата',
            ['class' => 'u-btn', 'target' => '_blank', 'rel' => 'noopener noreferrer']);
    }
    foreach ([
        $editing['status'] === 'published' ? 'unpublish' : 'publish' =>
            $editing['status'] === 'published' ? 'Вернуть в черновики' : 'Опубликовать',
    ] as $action => $label) {
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$editing['id']]);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => $label, 'class' => 'u-btn u-btn--primary']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'delete']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$editing['id']]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Удалить из каталога', 'class' => 'u-btn']);
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
