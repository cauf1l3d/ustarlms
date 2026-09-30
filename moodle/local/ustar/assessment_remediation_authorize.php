<?php
require_once(__DIR__ . '/../../config.php');
require_login();

global $USER;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('Некорректный метод запроса');
}

require_sesskey();
$runtimeid = required_param('runtimeid', PARAM_INT);

\local_ustar\assessment_lifecycle::authorize_remediation((int)$USER->id, $runtimeid);

$ishrd = \local_ustar\hr_access::can_view_hrd_escalations((int)$USER->id);

$returnurl = $ishrd
    ? new moodle_url('/local/ustar/hr_quiz_grading.php')
    : new moodle_url('/local/ustar/team.php');

redirect(
    $returnurl,
    'Переобучение согласовано',
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
