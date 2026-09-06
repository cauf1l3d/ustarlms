<?php

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $SESSION, $USER;

header('Content-Type: application/json; charset=utf-8');

$response = [
    'active' => false,
    'ready' => false,
    // Backward-compatible marker used by the older SCORM return helper.
    'complete' => false,
    'confirmed' => false,
    'hasnext' => false,
    'targeturl' => '',
    'routeurl' => (
        new moodle_url('/local/ustar/route.php')
    )->out(false),
    'sesskey' => sesskey(),
];

try {
    $cmid = required_param('cmid', PARAM_INT);
    $confirm = optional_param('confirm', 0, PARAM_BOOL);

    $context =
        $SESSION->ustar_scorm_route ?? null;

    if (
        !$context
        ||
        (int)($context['cmid'] ?? 0) !== $cmid
    ) {
        echo json_encode($response);
        die;
    }

    $response['active'] = true;

    $pointid =
        (int)($context['pointid'] ?? 0);

    $scormid =
        (int)($context['scormid'] ?? 0);

    $attemptbefore =
        (int)($context['attemptbefore'] ?? 0);

    $startedat =
        (int)($context['startedat'] ?? 0);

    $remediationmode =
        (string)($context['mode'] ?? '') === 'assessment_remediation';

    $runtimeid =
        (int)($context['runtimeid'] ?? 0);

    if (
        $pointid <= 0
        ||
        $scormid <= 0
    ) {
        echo json_encode($response);
        die;
    }

    $position =
        \local_ustar\position_access::position_for_user(
            (int)$USER->id
        );

    $positionid =
        is_array($position)
            ? (string)($position['id'] ?? '')
            : '';

    if ($positionid === '') {
        echo json_encode($response);
        die;
    }

    $route = null;
    $current = null;

    if ($remediationmode) {
        \local_ustar\assessment_lifecycle::validate_remediation_context(
            (int)$USER->id,
            $context
        );
    } else {
        $route =
            \local_ustar\route_model::for_user(
                $positionid,
                (int)$USER->id
            );

        $current =
            $route['currentpoint'] ?? null;

        /*
         * Ordinary route confirmation is valid only while the SCORM point
         * is actually the employee's current route point. Assessment
         * remediation is separately authorised by its lifecycle runtime.
         */
        if (
            !$current
            ||
            (int)($current['id'] ?? 0) !== $pointid
        ) {
            echo json_encode($response);
            die;
        }
    }

    $attempt =
        $DB->get_record_sql(
            "SELECT id, attempt
               FROM {scorm_attempt}
              WHERE userid = :userid
                AND scormid = :scormid
           ORDER BY attempt DESC, id DESC",
            [
                'userid' => (int)$USER->id,
                'scormid' => $scormid,
            ],
            IGNORE_MULTIPLE
        );

    $freshattempt =
        $attempt
        && (int)$attempt->attempt > $attemptbefore;

    $freshstatus = false;
    $completedat = 0;

    if ($freshattempt) {
        $track =
            $DB->get_record_sql(
                "SELECT v.value, v.timemodified
                   FROM {scorm_scoes_value} v
                   JOIN {scorm_element} e
                     ON e.id = v.elementid
                  WHERE v.attemptid = :attemptid
                    AND e.element IN (
                        'cmi.core.lesson_status',
                        'cmi.completion_status'
                    )
                    AND LOWER(v.value) IN (
                        'completed',
                        'passed'
                    )
               ORDER BY v.timemodified DESC",
                ['attemptid' => (int)$attempt->id],
                IGNORE_MULTIPLE
            );

        if ($track) {
            $freshstatus = true;
            $completedat = (int)$track->timemodified;
        }
    }

    /*
     * Defensive Moodle-completion fallback.
     * It is accepted only if Moodle changed the completion
     * AFTER this USTAR launch, therefore old historical
     * completion cannot silently advance the route.
     */
    if (!$freshstatus) {
        $completion =
            $DB->get_record(
                'course_modules_completion',
                [
                    'coursemoduleid' => $cmid,
                    'userid' => (int)$USER->id,
                ],
                '*',
                IGNORE_MISSING
            );

        if (
            $completion
            &&
            in_array((int)$completion->completionstate, [1, 2], true)
            &&
            (int)$completion->timemodified >= $startedat
        ) {
            $freshstatus = true;
            $completedat =
                (int)$completion->timemodified;
        }
    }

    if (!$freshstatus) {
        echo json_encode($response);
        die;
    }

    $response['ready'] = true;
    if ($remediationmode) {
        // Older USTAR SCORM return JS watches `complete`; it will return to the
        // route, where lifecycle reconciliation atomically opens the next cycle.
        // Newer JS may still POST/GET confirm=1 and receive a direct target URL.
        $response['complete'] = true;
    }

    if (!$confirm) {
        echo json_encode($response);
        die;
    }

    require_sesskey();

    if ($remediationmode) {
        $lifecycle =
            \local_ustar\assessment_lifecycle::confirm_scorm_remediation(
                (int)$USER->id,
                $runtimeid,
                $completedat,
                $attempt ? (int)$attempt->attempt : 0
            );

        $response['confirmed'] = true;
        $response['complete'] = true;
        if (!empty($lifecycle['canlaunch']) && !empty($lifecycle['launchurl'])) {
            $response['hasnext'] = true;
            $response['targeturl'] = (string)$lifecycle['launchurl'];
        } else {
            $response['targeturl'] = $response['routeurl'];
        }

        unset($SESSION->ustar_scorm_route);
        echo json_encode($response);
        die;
    }

    $eventid =
        \local_ustar\native_learning::record(
            (int)$USER->id,
            \local_ustar\native_learning::PRODUCT_SCORM_ACK,
            [
                'cmid' => $cmid,
                'scormid' => $scormid,
                'attempt' =>
                    $attempt
                        ? (int)$attempt->attempt
                        : 0,
                'startedat' => $startedat,
                'completedat' => $completedat,
                'source' => 'scorm_explicit_finish',
            ]
        );

    if ($eventid <= 0) {
        throw new moodle_exception(
            'Не удалось подтвердить завершение товароведения.'
        );
    }

    $route =
        \local_ustar\route_model::for_user(
            $positionid,
            (int)$USER->id
        );

    $response['confirmed'] = true;

    $current =
        $route['currentpoint'] ?? null;

    if (
        $current
        &&
        (int)($current['id'] ?? 0) !== $pointid
        &&
        !empty($current['canlaunch'])
        &&
        !empty($current['launchurl'])
    ) {
        $response['hasnext'] = true;
        $response['targeturl'] =
            (string)$current['launchurl'];
    } else {
        $response['targeturl'] =
            $response['routeurl'];
    }

    unset($SESSION->ustar_scorm_route);

} catch (\Throwable $e) {
    $response['error'] = true;
    $response['message'] =
        'Не удалось подтвердить завершение. Попробуйте ещё раз.';
}

echo json_encode($response);
