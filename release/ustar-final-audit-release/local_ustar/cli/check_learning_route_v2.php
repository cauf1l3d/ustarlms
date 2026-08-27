<?php
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require_once($config);
require_once($CFG->libdir . '/clilib.php');

$tables = [
    'local_ustar_routes',
    'local_ustar_route_points',
    'local_ustar_route_versions',
    'local_ustar_route_progress',
];
foreach ($tables as $name) {
    if (!$DB->get_manager()->table_exists(new xmldb_table($name))) {
        cli_error('MISSING_TABLE=' . $name);
    }
}
echo "ROUTE_V2_TABLES=OK\n";

$route = \local_ustar\route_model::get_route('retail_seller');
if (!$route) {
    cli_error('TRADING_FLOOR_ROUTE_MISSING');
}
$expected = 'ТОРГОВЫЙ ЗАЛ: РАБОТНИК ТОРГОВОГО ЗАЛА';
if ((string)$route->name !== $expected) {
    cli_error('ROUTE_NAME_MISMATCH actual=' . (string)$route->name . ' expected=' . $expected);
}
echo 'ROUTE_NAME=' . (string)$route->name . PHP_EOL;

$points = \local_ustar\route_model::points((int)$route->id);
$publishedadaptation = 0;
$gate = 0;
$continuousdraft = 0;
$profilepoint = null;
foreach ($points as $point) {
    $published = \local_ustar\route_model::current_published_version((int)$point->id);
    if (in_array((string)$point->phase, [\local_ustar\route_model::PHASE_ADAPTATION, \local_ustar\route_model::PHASE_GATE], true) && $published) {
        $publishedadaptation++;
    }
    if ((string)$point->phase === \local_ustar\route_model::PHASE_GATE && $published) {
        $gate++;
    }
    if ((string)$point->phase === \local_ustar\route_model::PHASE_CONTINUOUS) {
        $latest = \local_ustar\route_model::latest_version((int)$point->id);
        if ($latest && (string)$latest->status === \local_ustar\route_model::STATUS_DRAFT) {
            $continuousdraft++;
        }
    }
    if ((string)$point->pointkey === 'team_profile_express') {
        $profilepoint = $point;
    }
}
if (!$profilepoint) {
    cli_error('TEAM_PROFILE_FIRST_STEP_MISSING');
}
$profileversion = \local_ustar\route_model::current_published_version((int)$profilepoint->id);
$profilerequirements = $profileversion ? \local_ustar\route_model::requirements_for_version($profileversion) : [];
$hasprofile = false;
foreach ($profilerequirements as $requirement) {
    $hasprofile = $hasprofile || (
        ($requirement['type'] ?? '') === 'assessment'
        && ($requirement['sourcekey'] ?? '') === \local_ustar\development_assessment::TEAM_PROFILE_KEY
        && !empty($requirement['required'])
    );
}
if (!$hasprofile || (int)$profilepoint->sortorder !== 1) {
    cli_error('TEAM_PROFILE_FIRST_STEP_INVALID');
}
if ($publishedadaptation < 2) {
    cli_error('NOT_ENOUGH_PUBLISHED_ADAPTATION_POINTS=' . $publishedadaptation);
}
if ($gate !== 1) {
    cli_error('ADMISSION_GATE_INVALID=' . $gate);
}
if ($continuousdraft < 3) {
    cli_error('CONTINUOUS_TEMPLATE_POINTS_INVALID=' . $continuousdraft);
}

echo 'PUBLISHED_ADAPTATION_POINTS=' . $publishedadaptation . PHP_EOL;
echo 'ADMISSION_GATE=' . $gate . PHP_EOL;
echo 'CONTINUOUS_DRAFT_POINTS=' . $continuousdraft . PHP_EOL;
echo "TEAM_PROFILE_FIRST_STEP=OK\n";
echo "POST_ATTESTATION_VIDEO_SCENARIOS=BLOCKED_OWNER_CONTENT_REQUIRED\n";
echo "LEARNING_ROUTE_V2_SMOKE=OK\n";
