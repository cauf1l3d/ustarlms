<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$id = required_param('id', PARAM_INT);
$context = context_system::instance();
$item = \local_ustar\material_studio::by_content($id, (int)$USER->id);
$result = null;
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)$item['kind'] === 'assessment') {
    require_sesskey();
    try {
        $answers = [];
        foreach ((array)$item['questions'] as $index => $question) {
            $answers[$index] = optional_param('answer_' . $index, '', PARAM_TEXT);
        }
        $result = \local_ustar\material_studio::submit_assessment($id, (int)$USER->id, $answers);
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
        $result['passed']
            ? 'Аттестация отправлена. Результат: ' . (int)$result['score'] . '%. Пройдено.'
            : 'Аттестация отправлена. Результат: ' . (int)$result['score'] . '%. Попробуйте ещё раз.',
        $result['passed'] ? 'notifysuccess' : 'notifywarning'
    );
}

if ((string)$item['kind'] === 'assessment') {
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    foreach ((array)$item['questions'] as $index => $question) {
        echo html_writer::tag('h3', s((string)$question['question']));
        foreach ((array)$question['options'] as $option) {
            $field = 'answer_' . $index;
            $idattr = $field . '_' . substr(hash('sha1', (string)$option), 0, 8);
            echo html_writer::start_tag('label', ['for' => $idattr, 'style' => 'display:block']);
            echo html_writer::empty_tag('input', [
                'id' => $idattr, 'type' => 'radio', 'name' => $field, 'value' => (string)$option, 'required' => 'required',
            ]);
            echo ' ' . s((string)$option);
            echo html_writer::end_tag('label');
        }
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
        echo html_writer::tag('h3', 'SCORM-пакет');
        if ((string)$item['packagestatus'] === 'imported' && (string)$item['packageurl'] !== '') {
            echo html_writer::tag('p', 'К этому курсу подключён SCORM ZIP: '
                . html_writer::link((string)$item['packageurl'], s((string)$item['packagefilename'])));
        } else {
            echo $OUTPUT->notification('Автор ещё не подключил ZIP-пакет. Редактируемый сценарий курса уже доступен выше.', 'notifyinfo');
        }
    }
}
echo $OUTPUT->footer();
