<?php
// One-off import of structure/branding JSON.
// Usage:
//   php local/ustar/cli/import_structure.php --name=structure --file=/path/structure.json
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognised) = cli_get_params(
    ['name' => 'structure', 'file' => '', 'help' => false],
    ['h' => 'help']
);

if ($options['help'] || empty($options['file'])) {
    cli_writeln("Import USTAR structure JSON.
Options:
  --name=structure|branding
  --file=/path/to.json");
    exit(0);
}

$json = file_get_contents($options['file']);
$data = json_decode($json, true);
if (!is_array($data)) {
    cli_error('Invalid JSON in file.');
}
\local_ustar\structure::save($options['name'], $data);
cli_writeln("Imported '{$options['name']}' OK (" . strlen($json) . " bytes).");
