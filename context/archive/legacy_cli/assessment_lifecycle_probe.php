<?php
// Read/sync probe for one employee. Sync intentionally materialises lifecycle
// runtime from authoritative provider facts; it never resets learning data.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'userid' => 0,
        'help' => false,
    ],
    [
        'u' => 'userid',
        'h' => 'help',
    ]
);

if (!empty($options['help']) || (int)$options['userid'] <= 0) {
    echo "USTAR assessment lifecycle probe\n\n";
    echo "Usage: php cli/assessment_lifecycle_probe.php --userid=<id>\n";
    exit(!empty($options['help']) ? 0 : 2);
}

$userid = (int)$options['userid'];
global $DB;

if (!\local_ustar\assessment_lifecycle::available()) {
    cli_error('ASSESS_LIFECYCLE_TABLES_MISSING');
}

$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,username,firstname,lastname', MUST_EXIST);
$resolved = \local_ustar\structure::resolve_user($userid);
$positionid = (string)($resolved['position']['id'] ?? '');
if ($positionid === '') {
    cli_error('POSITION_NOT_RESOLVED');
}

echo 'USER=' . $userid . ' ' . fullname($user) . PHP_EOL;
echo 'POSITION=' . $positionid . PHP_EOL;

$policies = $DB->get_records('local_ustar_assess_policy', ['active' => 1], 'pointid ASC,versionid ASC');
$matched = 0;
foreach ($policies as $policy) {
    try {
        $runtime = \local_ustar\assessment_lifecycle::sync_policy_user(
            $policy,
            $userid,
            $positionid,
            false
        );
    } catch (Throwable $e) {
        echo 'POLICY=' . (int)$policy->id . ' ERROR=' . $e->getMessage() . PHP_EOL;
        continue;
    }
    if (!$runtime) {
        continue;
    }

    $matched++;
    $view = \local_ustar\assessment_lifecycle::route_view($runtime, $policy, $positionid);
    $point = $DB->get_record('local_ustar_route_points', ['id' => (int)$runtime->pointid], 'id,pointkey,routeid', MUST_EXIST);
    $version = $DB->get_record('local_ustar_route_versions', ['id' => (int)$runtime->versionid], 'id,versionno,title', MUST_EXIST);
    $remediation = null;
    if (!empty($runtime->remediationversionid)) {
        $remediation = $DB->get_record(
            'local_ustar_route_versions',
            ['id' => (int)$runtime->remediationversionid],
            'id,pointid,versionno,title',
            IGNORE_MISSING
        );
    }

    echo '=== ASSESSMENT ===' . PHP_EOL;
    echo 'POLICY=' . (int)$policy->id
        . ' POINT=' . (int)$point->id
        . ' KEY=' . (string)$point->pointkey
        . ' VERSION=' . (int)$version->id . '/v' . (int)$version->versionno
        . PHP_EOL;
    echo 'TITLE=' . (string)$version->title . PHP_EOL;
    echo 'PROVIDER=' . (string)$policy->providerkind . ':' . (string)$policy->providerref . PHP_EOL;
    echo 'RUNTIME=' . (int)$runtime->id
        . ' STATUS=' . (string)$runtime->status
        . ' CYCLE=' . (int)$runtime->cycle . '/' . (int)$policy->maxcycles
        . ' ATTEMPTS=' . (int)$runtime->attemptsused . '/' . (int)$runtime->unlockedattemptlimit
        . PHP_EOL;
    echo 'FAILURE_CUTOFF=' . (int)($runtime->failurecutoff ?? 0) . PHP_EOL;
    echo 'MANAGER=' . (int)($runtime->managerid ?? 0)
        . ' ESCALATED_AT=' . (int)($runtime->managerescalatedat ?? 0)
        . ' HRD_AT=' . (int)($runtime->hrdescalatedat ?? 0)
        . PHP_EOL;
    echo 'REMEDIATION=' . (int)($runtime->remediationpointid ?? 0)
        . '/' . (int)($runtime->remediationversionid ?? 0)
        . ($remediation ? ' ' . (string)$remediation->title : '')
        . PHP_EOL;
    echo 'VIEW_STATUS=' . (string)($view['statuslabel'] ?? '')
        . ' CAN_LAUNCH=' . (!empty($view['canlaunch']) ? 'YES' : 'NO')
        . PHP_EOL;

    if ((string)$policy->providerkind === 'moodle_quiz' && preg_match('/^cm:(\d+)$/', (string)$policy->providerref, $m)) {
        $cm = get_coursemodule_from_id('quiz', (int)$m[1], 0, false, MUST_EXIST);
        $override = $DB->get_record('quiz_overrides', [
            'quiz' => (int)$cm->instance,
            'userid' => $userid,
        ]);
        echo 'QUIZ_OVERRIDE_ATTEMPTS=' . ($override ? (int)$override->attempts : 'NONE') . PHP_EOL;
    }
}

if ($matched === 0) {
    echo "NO_RUNTIME_FOR_USER\n";
}
