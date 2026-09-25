<?php
// Read-only: no --apply option until explicit migration decisions are implemented.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
[$options, $unrecognised] = cli_get_params(['help' => false], ['h' => 'help']);
if ($unrecognised) cli_error('Unknown options: ' . implode(', ', $unrecognised));
if ($options['help']) {
    echo "Read-only organizational reconciliation. Outputs IDs, sources and conflicts as JSON.\n";
    exit(0);
}
echo json_encode(\local_ustar\organization_identity::reconciliation(),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
