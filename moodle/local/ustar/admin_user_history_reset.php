<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
admin_externalpage_setup('local_ustar_user_history_reset');
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$PAGE->set_url(new moodle_url('/local/ustar/admin_user_history_reset.php'));
$PAGE->set_title('Сброс учебного маршрута');
$PAGE->set_heading('USTAR · Сброс учебного маршрута');
$form = new \local_ustar\form\user_history_reset_form();
if ($data = $form->get_data()) {
    $result = \local_ustar\user_history_reset::execute((int)$data->userid, (int)$USER->id);
    $b = $result['before']; $a = $result['after'];
    $message = 'Маршрут сброшен: ' . s($b['fullname']) . ' [' . s($b['username']) . ']. До: route=' . (int)$b['routeprogress'] . ', cycles=' . (int)$b['confirmedcycles'] . ', rewards=' . (int)$b['routerewards'] . ', quiz=' . (int)$b['quizattempts'] . '. После: route=' . (int)$a['routeprogress'] . ', cycles=' . (int)$a['confirmedcycles'] . ', rewards=' . (int)$a['routerewards'] . ', quiz=' . (int)$a['quizattempts'] . '.';
    redirect(new moodle_url('/local/ustar/admin_user_history_reset.php'), $message, null, \core\output\notification::NOTIFY_SUCCESS);
}
echo $OUTPUT->header();
echo $OUTPUT->notification('Администратор может сбросить учебный маршрут любого сотрудника. Проверьте имя и последствия перед подтверждением.', \core\output\notification::NOTIFY_INFO);
$form->display();
echo $OUTPUT->footer();
