<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
admin_externalpage_setup('local_ustar_user_history_reset');
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$PAGE->set_url(new moodle_url('/local/ustar/admin_user_history_reset.php'));
$PAGE->set_title('Очистка учебной истории');
$PAGE->set_heading('USTAR · Очистка учебной истории сотрудника');
$form = new \local_ustar\form\user_history_reset_form();
if ($data = $form->get_data()) {
    $result = \local_ustar\user_history_reset::execute((int)$data->userid, (int)$USER->id);
    $b = $result['before']; $a = $result['after'];
    $message = 'История очищена: ' . s($b['fullname']) . ' [' . s($b['username']) . ']. До: route=' . (int)$b['routeprogress'] . ', runtime=' . (int)$b['assessruntime'] . ', quiz=' . (int)$b['quizattempts'] . ', scorm=' . (int)$b['scormattempts'] . ', completion=' . (int)$b['cmcompletion'] . '. После: route=' . (int)$a['routeprogress'] . ', runtime=' . (int)$a['assessruntime'] . ', quiz=' . (int)$a['quizattempts'] . ', scorm=' . (int)$a['scormattempts'] . ', completion=' . (int)$a['cmcompletion'] . '.';
    redirect(new moodle_url('/local/ustar/admin_user_history_reset.php'), $message, null, \core\output\notification::NOTIFY_SUCCESS);
}
echo $OUTPUT->header();
echo $OUTPUT->notification('Административный инструмент полного сброса учебного следа выбранного сотрудника. Профиль и оргструктура сохраняются.', \core\output\notification::NOTIFY_INFO);
$form->display();
echo $OUTPUT->footer();
