<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'plan' => '', 'apply' => false, 'confirm' => ''],
    ['h' => 'help']
);
if ($unrecognised) cli_error('Unknown options: ' . implode(', ', $unrecognised));
if ($options['help']) {
    echo "Dry-run: php local/ustar/cli/migrate_stage2_access.php\n";
    echo "Apply reviewed plan: --plan=/absolute/plan.json --apply --confirm=APPLY_STAGE2_ACCESS\n";
    exit(0);
}
if (!$options['apply']) {
    echo json_encode(\local_ustar\access_migration::report(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}
if ($options['confirm'] !== 'APPLY_STAGE2_ACCESS' || $options['plan'] === '') {
    cli_error('Apply requires --plan and --confirm=APPLY_STAGE2_ACCESS');
}
$path = (string)$options['plan'];
if (!is_file($path) || !is_readable($path)) cli_error('Plan is not readable.');
$plan = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$result = \local_ustar\access_migration::apply($plan);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
