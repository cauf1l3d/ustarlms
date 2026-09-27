<?php
// Compatibility entry point for links and forms created before the task tab.
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/ustar:use', context_system::instance());

$selected = optional_param('id', '', PARAM_ALPHANUMEXT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    $selected = required_param('checklistid', PARAM_ALPHANUMEXT);
    $definition = null;
    foreach (\local_ustar\checklist_service::list_for((int)$USER->id)['checklists'] as $checklist) {
        if ((string)$checklist['id'] === $selected) {
            $definition = $checklist;
            break;
        }
    }
    if (!$definition) {
        throw new required_capability_exception(context_system::instance(), 'local/ustar:use', 'nopermissions', '');
    }
    \local_ustar\checklist_service::submit((int)$USER->id, $selected,
        \local_ustar\checklist_service::posted_answers($definition),
        optional_param('comment', '', PARAM_TEXT),
        optional_param('mode', 'final', PARAM_ALPHA), -1,
        optional_param('correctionreason', '', PARAM_TEXT));
    redirect(new moodle_url('/local/ustar/tasks.php',
        ['tab' => 'checklists', 'id' => $selected, 'saved' => 1]));
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw new invalid_parameter_exception('Неподдерживаемый запрос.');
}
$date = \local_ustar\checklist_service::date_key(optional_param('date', '', PARAM_RAW_TRIMMED));
redirect(new moodle_url('/local/ustar/tasks.php',
    ['tab' => 'checklists'] + ($selected !== '' ? ['id' => $selected] : []) + ['date' => $date]));
