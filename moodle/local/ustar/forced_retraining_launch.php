<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/ustar:use', context_system::instance());
\local_ustar\view_as::assert_writable();

global $USER;

$assignmentid = required_param('id', PARAM_INT);
$next = \local_ustar\forced_retraining::launch((int)$USER->id, $assignmentid);
$kind = (string)($next['kind'] ?? '');

if ($kind === 'assessment') {
    $cmid = (int)($next['cmid'] ?? 0);
    if ($cmid <= 0) {
        throw new moodle_exception('Не удалось определить аттестацию для переобучения.');
    }
    $prepared = \local_ustar\moodle_activity_bridge::prepare((int)$USER->id, $cmid);
    redirect(new moodle_url((string)$prepared['targeturl']));
}

if ($kind === 'scorm') {
    $cmid = (int)($next['cmid'] ?? 0);
    if ($cmid <= 0) {
        throw new moodle_exception('Не удалось определить SCORM-материал переобучения.');
    }
    $prepared = \local_ustar\moodle_activity_bridge::prepare((int)$USER->id, $cmid);
    if ((string)($prepared['modname'] ?? '') !== 'scorm') {
        throw new moodle_exception('Материал переобучения больше не является SCORM-активностью.');
    }
    // A forced retraining cycle must create fresh SCORM evidence. Do not reuse
    // the ordinary route session: this assignment has its own cutoff and audit.
    redirect(new moodle_url('/mod/scorm/view.php', [
        'id' => $cmid,
        'newattempt' => 'on',
    ]));
}

if ($kind === 'moodle_cm') {
    $cmid = (int)($next['cmid'] ?? 0);
    if ($cmid <= 0) {
        throw new moodle_exception('Не удалось определить материал переобучения.');
    }
    $prepared = \local_ustar\moodle_activity_bridge::prepare((int)$USER->id, $cmid);
    redirect(new moodle_url((string)$prepared['targeturl']));
}

if ($kind === 'course') {
    $courseid = (int)($next['courseid'] ?? 0);
    if ($courseid <= 0) {
        throw new moodle_exception('Не удалось определить курс переобучения.');
    }
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

$url = (string)($next['url'] ?? '');
if ($url !== '') {
    redirect(new moodle_url($url));
}

throw new moodle_exception('Для текущего шага переобучения нет доступного действия.');
