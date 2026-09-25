<?php
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/ustar:use', context_system::instance());
$role = \local_ustar\academy_guide::role();
$labels = ['employee' => 'Сотрудник', 'manager' => 'Руководитель подразделения', 'hrd' => 'HRD', 'executive' => 'Генеральный директор'];
$PAGE->set_url('/local/ustar/guide.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Гид по Академии');
$PAGE->set_heading('Гид по Академии');
echo $OUTPUT->header();
echo html_writer::start_div('u-product-page');
echo html_writer::tag('h1', 'Гид по Академии · ' . s($labels[$role] ?? 'Просмотр'));
echo html_writer::tag('p', 'Открывайте разделы по порядку. При первом посещении появятся подсказки. Ссылка повторного запуска позволяет вернуться к объяснению. Закрытие подсказки не завершает обучение и не начисляет награду.', ['class' => 'u-guide-intro']);
if ($role === '') { echo $OUTPUT->notification('Выйдите из режима «Просмотр как», чтобы пройти гид под своей учётной записью.', 'info'); }
foreach (\local_ustar\academy_guide::chapters($role) as $chapter) {
    echo html_writer::start_tag('section', ['class' => 'u-card u-guide-chapter']);
    echo html_writer::tag('h2', s($chapter['title']));
    echo html_writer::tag('p', s($chapter['goal']));
    if (!empty($chapter['entry'])) {
        echo html_writer::link(new moodle_url($chapter['entry']), 'Открыть раздел →', ['class' => 'u-btn u-btn--primary']);
    } else { echo html_writer::tag('p', s($chapter['how'])); }
    echo html_writer::start_tag('details', ['class' => 'u-guide-details']);
    echo html_writer::tag('summary', 'Прочитать инструкцию целиком');
    foreach ($chapter['steps'] as $step) {
        echo html_writer::tag('h3', s($step[1]));
        echo html_writer::tag('p', s($step[2]));
    }
    echo html_writer::end_tag('details');
    echo html_writer::end_tag('section');
}
echo html_writer::end_div();
echo $OUTPUT->footer();
