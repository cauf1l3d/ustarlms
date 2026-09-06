<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/ustar:use', context_system::instance());
\local_ustar\view_as::assert_writable();

global $CFG, $DB, $SESSION, $USER;

$runtimeid = required_param('runtimeid', PARAM_INT);
$action = \local_ustar\assessment_lifecycle::start_remediation((int)$USER->id, $runtimeid);
$kind = (string)($action['kind'] ?? '');

if ($kind === 'scorm') {
    require_once($CFG->dirroot . '/mod/scorm/locallib.php');

    $cmid = (int)($action['cmid'] ?? 0);
    $cm = get_coursemodule_from_id('scorm', $cmid, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => (int)$cm->course], '*', MUST_EXIST);
    $scorm = $DB->get_record('scorm', ['id' => (int)$cm->instance], '*', MUST_EXIST);

    // Resolve a launchable SCO exactly as Moodle's own SCORM entry page does.
    $result = scorm_get_toc($USER, $scorm, (int)$cm->id, TOCFULLURL);
    if (!empty($result->sco->id)) {
        $sco = $result->sco;
    } else {
        $sco = scorm_get_sco($scorm->launch, SCO_ONLY);
    }
    if (!$sco || empty($sco->id)) {
        throw new moodle_exception('В SCORM не найден запускаемый учебный объект.');
    }

    $currentorg = '';
    if (($sco->organization ?? '') === '' && ($sco->launch ?? '') === '') {
        $currentorg = (string)($sco->identifier ?? '');
    } else {
        $currentorg = (string)($sco->organization ?? '');
    }

    $attemptbefore = (int)$DB->get_field_sql(
        "SELECT COALESCE(MAX(attempt),0)
           FROM {scorm_attempt}
          WHERE userid=:userid AND scormid=:scormid",
        ['userid' => (int)$USER->id, 'scormid' => (int)$scorm->id]
    );

    $runtime = $DB->get_record('local_ustar_assess_runtime', ['id' => $runtimeid, 'userid' => (int)$USER->id], '*', MUST_EXIST);
    $SESSION->ustar_scorm_route = [
        'mode' => 'assessment_remediation',
        'runtimeid' => $runtimeid,
        'cmid' => (int)$cm->id,
        'scormid' => (int)$scorm->id,
        'pointid' => (int)$runtime->remediationpointid,
        'versionid' => (int)$runtime->remediationversionid,
        'attemptbefore' => $attemptbefore,
        'startedat' => time(),
    ];

    $params = [
        'cm' => (int)$cm->id,
        'scoid' => (int)$sco->id,
        // Moodle player.php explicitly interprets newattempt=on and increments
        // the user's SCORM attempt before launching the SCO.
        'newattempt' => 'on',
    ];
    if ($currentorg !== '') {
        $params['currentorg'] = $currentorg;
    }

    redirect(new moodle_url('/mod/scorm/player.php', $params));
}

if (in_array($kind, ['moodle_cm', 'course', 'url'], true) && !empty($action['url'])) {
    redirect((string)$action['url']);
}

throw new moodle_exception('Не удалось определить способ запуска обязательного переобучения.');
