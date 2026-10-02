<?php
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require_once($config);
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(['help' => false, 'userid' => 0], ['h' => 'help']);
if ($unrecognized) {
    cli_error('Неизвестные параметры: ' . implode(', ', $unrecognized));
}
if (!empty($options['help'])) {
    echo "Usage: php probe_development_assessment.php [--userid=N]\n";
    exit(0);
}
$userid = (int)$options['userid'];
if ($userid <= 0) {
    $userid = (int)$DB->get_field_select('user', 'id', 'id > 1 AND deleted = 0', [], IGNORE_MULTIPLE);
}
if ($userid <= 0) {
    cli_error('NO_TEST_EMPLOYEE');
}
$definition = \local_ustar\development_assessment::published(\local_ustar\development_assessment::TEAM_PROFILE_KEY);
if (!$definition) {
    cli_error('TEAM_PROFILE_NOT_PUBLISHED');
}
$answers = [];
foreach ($definition['questions'] as $question) {
    $answers[(string)$question['key']] = (string)$question['options'][0]['key'];
}
$prefix = 'audit_dev_profile_' . substr(sha1(uniqid('', true)), 0, 24);
$keys = [$prefix . '_one', $prefix . '_two'];
$attemptids = [];
try {
    $one = \local_ustar\development_assessment::submit(
        \local_ustar\development_assessment::TEAM_PROFILE_KEY, $userid, $answers, $keys[0], time() - 5
    );
    $again = \local_ustar\development_assessment::submit(
        \local_ustar\development_assessment::TEAM_PROFILE_KEY, $userid, $answers, $keys[0], time() - 5
    );
    if ((int)$one['attemptid'] !== (int)$again['attemptid']) {
        cli_error('IDEMPOTENCY_FAILED');
    }
    $attemptids[] = (int)$one['attemptid'];
    $two = \local_ustar\development_assessment::submit(
        \local_ustar\development_assessment::TEAM_PROFILE_KEY, $userid, $answers, $keys[1], time()
    );
    if ((int)$two['attemptid'] === (int)$one['attemptid']) {
        cli_error('HISTORY_FAILED');
    }
    $attemptids[] = (int)$two['attemptid'];
    $completion = \local_ustar\development_assessment::completion_for_user(
        \local_ustar\development_assessment::TEAM_PROFILE_KEY, $userid
    );
    if (!$completion || (int)$completion->id !== (int)$two['attemptid']) {
        cli_error('COMPLETION_LOOKUP_FAILED');
    }
    echo "DEVELOPMENT_ASSESSMENT_IDEMPOTENCY=OK\n";
    echo "DEVELOPMENT_ASSESSMENT_HISTORY=OK\n";
    echo "DEVELOPMENT_ASSESSMENT_COMPLETION=OK\n";
} finally {
    foreach ($attemptids as $attemptid) {
        $DB->delete_records('local_ustar_dev_assess_try', ['id' => $attemptid]);
    }
}
echo "DEVELOPMENT_ASSESSMENT_PROBE_CLEANUP=OK\n";
