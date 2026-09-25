<?php
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require_once($config);
require_once($CFG->libdir . '/clilib.php');

$tables = ['local_ustar_dev_assess', 'local_ustar_dev_assess_ver', 'local_ustar_dev_assess_try'];
foreach ($tables as $table) {
    if (!$DB->get_manager()->table_exists(new xmldb_table($table))) {
        cli_error('MISSING_TABLE=' . $table);
    }
}
$definition = \local_ustar\development_assessment::published(
    \local_ustar\development_assessment::TEAM_PROFILE_KEY
);
if (!$definition) {
    cli_error('TEAM_PROFILE_NOT_PUBLISHED');
}
$questions = $definition['questions'];
$results = $definition['results'];
if (count($questions) !== 12 || count($results) !== 4) {
    cli_error('TEAM_PROFILE_DEFINITION_INVALID questions=' . count($questions) . ' profiles=' . count($results));
}
$hrdroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'ustar_hrd']);
$hrroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'ustar_hr']);
if (!$hrdroleid || !$hrroleid) {
    cli_error('DEVELOPMENT_ROLE_MISSING');
}
$capability = 'local/ustar:developmentanalytics';
$systemcontextid = (int)context_system::instance()->id;
$hrdpermission = (int)$DB->get_field('role_capabilities', 'permission', [
    'roleid' => $hrdroleid,
    'capability' => $capability,
    'contextid' => $systemcontextid,
]);
$hrpermission = (int)$DB->get_field('role_capabilities', 'permission', [
    'roleid' => $hrroleid,
    'capability' => $capability,
    'contextid' => $systemcontextid,
]);
echo 'LOCAL_USTAR_VERSION=' . (string)get_config('local_ustar', 'version') . PHP_EOL;
if ($hrdpermission !== CAP_ALLOW || $hrpermission === CAP_ALLOW) {
    $hrdrows = $DB->get_records('role_capabilities', ['roleid' => $hrdroleid], '', 'id,contextid,capability,permission');
    $matches = [];
    foreach ($hrdrows as $row) {
        if ((string)$row->capability === $capability) {
            $matches[] = (int)$row->contextid . ':' . (int)$row->permission;
        }
    }
    cli_error('DEVELOPMENT_ROLE_BOUNDARY_INVALID hrd=' . $hrdpermission . ' hr=' . $hrpermission . ' sys=' . $systemcontextid . ' hrdmatches=' . implode(',', $matches));
}
echo 'DEVELOPMENT_ASSESSMENT_TABLES=OK' . PHP_EOL;
echo 'TEAM_PROFILE_TITLE=' . format_string((string)$definition['assessment']->title) . PHP_EOL;
echo 'TEAM_PROFILE_QUESTIONS=' . count($questions) . PHP_EOL;
echo 'TEAM_PROFILE_PROFILES=' . count($results) . PHP_EOL;
echo 'DEVELOPMENT_ROLE_BOUNDARY=OK' . PHP_EOL;
echo 'DEVELOPMENT_ASSESSMENT_SMOKE=OK' . PHP_EOL;
