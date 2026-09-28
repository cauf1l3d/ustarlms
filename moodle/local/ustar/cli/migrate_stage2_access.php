<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'plan' => '', 'sha256' => '', 'backup-ref' => '',
        'apply' => false, 'confirm' => ''],
    ['h' => 'help']
);
if ($unrecognised) cli_error('Unknown options: ' . implode(', ', $unrecognised));
if ($options['help']) {
    echo "Dry-run: php local/ustar/cli/migrate_stage2_access.php\n";
    echo "Apply reviewed plan: --plan=/absolute/plan.json --sha256=<64 hex> " .
        "--backup-ref=<verified backup ID> --apply --confirm=APPLY_STAGE2_ACCESS\n";
    exit(0);
}
if (!$options['apply']) {
    echo json_encode(\local_ustar\access_migration::report(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}
if ($options['confirm'] !== 'APPLY_STAGE2_ACCESS' || $options['plan'] === ''
        || !preg_match('/^[a-f0-9]{64}$/i', (string)$options['sha256'])
        || trim((string)$options['backup-ref']) === '') {
    cli_error('Apply requires --plan, --sha256, --backup-ref and --confirm=APPLY_STAGE2_ACCESS');
}
$path = (string)$options['plan'];
if ($path[0] !== '/' || !is_file($path) || !is_readable($path)) {
    cli_error('Plan must be a readable absolute path.');
}
$contents = file_get_contents($path);
if ($contents === false || !hash_equals(strtolower((string)$options['sha256']), hash('sha256', $contents))) {
    cli_error('Plan SHA-256 does not match the reviewed file.');
}
$plan = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
$result = \local_ustar\access_migration::apply($plan);
$result['plan_sha256'] = hash('sha256', $contents);
$result['backup_ref'] = trim((string)$options['backup-ref']);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
