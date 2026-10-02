<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$id = required_param('id', PARAM_INT);
$context = context_system::instance();
$item = \local_ustar\material_studio::by_content($id, (int)$USER->id);
$preview = optional_param('preview', 0, PARAM_BOOL);
if ($preview && !\local_ustar\material_studio::can_manage((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/ustar:hrmanage', 'nopermissions', '');
}
$result = null;
$notice = '';
$answers = [];
$attemptnonce = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? required_param('attemptnonce', PARAM_ALPHANUMEXT) : bin2hex(random_bytes(16));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)$item['kind'] === 'assessment') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    try {
        $answers = [];
        foreach ((array)$item['questions'] as $index => $question) {
            $answers[$index] = optional_param('answer_' . $index, '', PARAM_TEXT);
        }
        $result = \local_ustar\material_studio::submit_assessment($id, (int)$USER->id, $answers,
            required_param('sourceversion', PARAM_INT), $preview, $attemptnonce);
        $attemptnonce = bin2hex(random_bytes(16));
    } catch (\Throwable $e) {
        $notice = $e->getMessage();
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/material_player.php', ['id' => $id]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title((string)$item['title'] . ' | USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/stage6.css'));
$PAGE->set_heading('USTAR Academy');
echo $OUTPUT->header();
echo $OUTPUT->heading(s((string)$item['title']));
if ((string)$item['summary'] !== '') {
    echo html_writer::tag('p', s((string)$item['summary']));
}
if ($notice !== '') {
    echo $OUTPUT->notification(s($notice), 'notifyproblem');
}
if ($result) {
    echo $OUTPUT->notification(
        !empty($result['preview']) ? 'Предпросмотр: ' . (int)$result['score'] . '%. Учебный результат не записан.' : ($result['passed']
            ? 'Аттестация отправлена. Результат: ' . (int)$result['score'] . '%. Пройдено.'
            : 'Аттестация отправлена. Результат: ' . (int)$result['score'] . '%. Попробуйте ещё раз.'),
        $result['passed'] ? 'notifysuccess' : 'notifywarning'
    );
}

if ((string)$item['kind'] === 'assessment') {
    if ($preview) { echo $OUTPUT->notification('Предпросмотр автора — без записи результата.', 'notifyinfo'); }
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-stage6-card u-material-assessment']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sourceversion', 'value' => $item['sourceversion']]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'preview', 'value' => (int)$preview]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptnonce', 'value' => $attemptnonce]);
    foreach ((array)$item['questions'] as $index => $question) {
        echo html_writer::start_tag('fieldset', ['class' => 'u-material-question']);
        echo html_writer::tag('legend', s((string)$question['question']));
        foreach (array_values((array)$question['options']) as $optionindex => $option) {
            $field = 'answer_' . $index;
            $value = $optionindex + 1;
            $idattr = $field . '_' . $value;
            echo html_writer::start_tag('label', ['for' => $idattr, 'style' => 'display:block']);
            $attrs = [
                'id' => $idattr, 'type' => 'radio', 'name' => $field, 'value' => $value, 'required' => 'required',
            ];
            if ((string)($answers[$index] ?? '') === (string)$value
                    || (string)($answers[$index] ?? '') === (string)$option) {
                $attrs['checked'] = 'checked';
            }
            echo html_writer::empty_tag('input', $attrs);
            echo ' ' . s((string)$option);
            echo html_writer::end_tag('label');
        }
        echo html_writer::end_tag('fieldset');
    }
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Отправить аттестацию', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
} else {
    if ((string)$item['outline'] !== '') {
        echo html_writer::tag('h3', 'План');
        echo html_writer::tag('p', nl2br(s((string)$item['outline'])));
    }
    echo html_writer::tag('div', format_text((string)$item['body'], FORMAT_HTML, ['filter' => false]), ['class' => 'u-material-player-body']);
    if ((string)$item['kind'] === 'scorm') {
        echo html_writer::tag('h3', 'Интерактивное обучение');
        if (\local_ustar\material_studio::runtime_ready($id)) {
            echo html_writer::tag('p', 'SCORM запускается из шага учебного маршрута. Moodle сохраняет попытку, прогресс и результат.');
            echo html_writer::tag('p', html_writer::link(
                new moodle_url('/local/ustar/route.php'), 'Открыть учебный маршрут', ['class' => 'btn btn-primary']));
            if (\local_ustar\material_studio::can_manage((int)$USER->id)
                    && (string)$item['packageurl'] !== '') {
                echo html_writer::tag('p', html_writer::link((string)$item['packageurl'], 'Скачать исходный ZIP'));
            }
        } else {
            echo $OUTPUT->notification('Автор ещё не импортировал пакет для текущей версии.', 'notifyinfo');
        }
    }
}
echo $OUTPUT->footer();
