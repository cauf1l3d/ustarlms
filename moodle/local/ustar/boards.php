<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/ustar/boards.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Доски завершены | USTAR Academy');
$PAGE->set_heading('USTAR Academy');

echo $OUTPUT->header();
echo $OUTPUT->heading('Доски завершены');
echo $OUTPUT->notification(
    'Функция досок DJGMS больше не используется. Новые личные записи ведутся в личном блокноте, а рабочие задания — в сервисе задач.',
    'notifyinfo'
);
$archives = \local_ustar\board_retirement::own_archive((int)$USER->id);
if ($archives) {
    echo html_writer::tag('h3', 'Ваш архив досок');
    echo html_writer::start_tag('ul');
    foreach ($archives as $archive) {
        echo html_writer::tag('li', html_writer::link((string)$archive['url'], (string)$archive['title']));
    }
    echo html_writer::end_tag('ul');
}
echo html_writer::tag('p', html_writer::link(new moodle_url('/local/ustar/tasks.php'), 'Открыть задачи'));
echo $OUTPUT->footer();
