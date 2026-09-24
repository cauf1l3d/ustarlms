<?php

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

global $DB;

if (!\local_ustar\assessment_lifecycle::available()) {
    fwrite(STDERR, "ASSESSMENT_LIFECYCLE=UNAVAILABLE\n");
    exit(2);
}

$before = [];
$runtimes = $DB->get_records('local_ustar_assess_runtime', [], 'id ASC');
foreach ($runtimes as $runtime) {
    $key = (int)$runtime->userid . ':' . (int)$runtime->pointid . ':' . (int)$runtime->versionid;
    $before[$key] = [
        'lastattemptid' => (int)($runtime->lastattemptid ?? 0),
        'timemodified' => (int)$runtime->timemodified,
    ];

    $policy = $DB->get_record('local_ustar_assess_policy', ['id' => (int)$runtime->policyid]);
    if (!$policy) {
        echo "RUNTIME=" . (int)$runtime->id . " POLICY_MISSING\n";
        continue;
    }

    try {
        $synced = \local_ustar\assessment_lifecycle::sync_policy_user(
            $policy,
            (int)$runtime->userid,
            '',
            true
        );
        if ($synced) {
            echo "RUNTIME=" . (int)$synced->id
                . " USER=" . (int)$synced->userid
                . " STATUS=" . (string)$synced->status
                . " CYCLE=" . (int)$synced->cycle
                . " ATTEMPTS=" . (int)$synced->attemptsused
                . " LAST=" . (int)($synced->lastattemptid ?? 0)
                . "\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "RUNTIME=" . (int)$runtime->id . " RECONCILE_ERROR=" . $e->getMessage() . "\n");
        exit(3);
    }
}

/*
 * Repair only the narrow interrupted-write pattern: a newly-finalized manual
 * attempt exists beyond the runtime's previously known last attempt, but the
 * per-attempt USTAR grading audit is absent. Actor 0 explicitly means system
 * reconciliation; no human grader identity is fabricated.
 */
foreach (\local_ustar\route_quiz_grading::attempts() as $row) {
    if ((int)$row['manualcount'] <= 0 || (int)$row['pending'] > 0) {
        continue;
    }

    $key = (int)$row['userid'] . ':' . (int)$row['pointid'] . ':' . (int)$row['versionid'];
    if (!isset($before[$key])) {
        continue;
    }
    if ((int)$row['attemptid'] <= (int)$before[$key]['lastattemptid']) {
        continue;
    }

    $exists = $DB->record_exists('local_ustar_workflow_events', [
        'entitytype' => 'quiz_manual_grade',
        'entityid' => (int)$row['attemptid'],
        'eventtype' => 'route_quiz_manual_grade',
    ]);
    if ($exists) {
        continue;
    }

    $DB->insert_record('local_ustar_workflow_events', (object)[
        'entitytype' => 'quiz_manual_grade',
        'entityid' => (int)$row['attemptid'],
        'eventtype' => 'route_quiz_manual_grade',
        'actorid' => 0,
        'reason' => 'Системное восстановление аудита после прерванной ручной проверки',
        'detailsjson' => json_encode([
            'userid' => (int)$row['userid'],
            'quizid' => (int)$row['quizid'],
            'cmid' => (int)$row['cmid'],
            'pointid' => (int)$row['pointid'],
            'versionid' => (int)$row['versionid'],
            'attemptid' => (int)$row['attemptid'],
            'reconciled' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'timecreated' => time(),
    ]);

    echo "AUDIT_RECONCILED_ATTEMPT=" . (int)$row['attemptid'] . "\n";
}

echo "USTAR_GRADING_RECONCILE=OK\n";
