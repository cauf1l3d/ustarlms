<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$id = required_param('id', PARAM_INT);
$record = \local_ustar\board_retirement::get_for_owner($id, (int)$USER->id);
if (!$record) {
    throw new required_capability_exception(context_system::instance(), 'local/ustar:use', 'nopermissions', '');
}
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/ustar/board_archive.php', ['id' => $id]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Архив доски | USTAR Academy');
$PAGE->set_heading('USTAR Academy');
echo $OUTPUT->header();
echo $OUTPUT->heading('Архив: ' . s((string)$record->title));
echo $OUTPUT->notification('Доски DJGMS выведены из продукта. Этот архив доступен только владельцу и предназначен для сохранения истории.', 'notifyinfo');
echo html_writer::tag('pre', s((string)$record->documentjson), ['class' => 'u-board-archive']);
echo html_writer::tag('p', html_writer::link(new moodle_url('/local/ustar/tasks.php', ['tab' => 'notebook']), 'Перейти в личный блокнот'));
echo $OUTPUT->footer();
