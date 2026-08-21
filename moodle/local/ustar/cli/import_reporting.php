<?php
// Import explicit reporting lines from CSV: employee_username,manager_username.
define('CLI_SCRIPT', true);
require(dirname(__DIR__, 3) . '/config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'file' => null,
    'dry-run' => false,
    'help' => false,
], ['h' => 'help']);

if ($options['help'] || empty($options['file'])) {
    echo "USTAR reporting-line import\n\n";
    echo "php local/ustar/cli/import_reporting.php --file=/path/reporting.csv [--dry-run]\n";
    echo "CSV columns: employee_username,manager_username\n";
    exit($options['help'] ? 0 : 2);
}

$file = (string)$options['file'];
if (!is_readable($file)) {
    cli_error('CSV file is not readable: ' . $file);
}

$handle = fopen($file, 'rb');
$header = fgetcsv($handle);
if (!$header) {
    cli_error('CSV is empty');
}
$header = array_map(static fn($v) => trim((string)$v), $header);
$empidx = array_search('employee_username', $header, true);
$manidx = array_search('manager_username', $header, true);
if ($empidx === false || $manidx === false) {
    cli_error('Required columns: employee_username,manager_username');
}

$planned = [];
$rows = 1;
while (($row = fgetcsv($handle)) !== false) {
    $rows++;
    $employee = trim((string)($row[$empidx] ?? ''));
    $manager = trim((string)($row[$manidx] ?? ''));
    if ($employee === '') {
        continue;
    }
    $eu = $DB->get_record('user', ['username' => $employee, 'deleted' => 0], 'id,username', IGNORE_MISSING);
    if (!$eu) {
        cli_error("Line {$rows}: employee not found: {$employee}");
    }
    $managerid = 0;
    if ($manager !== '') {
        $mu = $DB->get_record('user', ['username' => $manager, 'deleted' => 0], 'id,username', IGNORE_MISSING);
        if (!$mu) {
            cli_error("Line {$rows}: manager not found: {$manager}");
        }
        $managerid = (int)$mu->id;
    }
    if ((int)$eu->id === $managerid) {
        cli_error("Line {$rows}: employee cannot manage self: {$employee}");
    }
    $planned[(int)$eu->id] = ['managerid' => $managerid, 'employee' => $employee, 'manager' => $manager];
}
fclose($handle);

// Validate cycles against the complete proposed graph before any writes.
$current = [];
if (\local_ustar\org::reporting_available()) {
    foreach ($DB->get_records('local_ustar_reporting') as $r) {
        $current[(int)$r->userid] = (int)($r->managerid ?? 0);
    }
}
foreach ($planned as $uid => $change) {
    $current[$uid] = $change['managerid'];
}
foreach (array_keys($current) as $start) {
    $seen = [];
    $cursor = $start;
    for ($i = 0; $i < 200 && $cursor > 0; $i++) {
        if (isset($seen[$cursor])) {
            cli_error('Cycle detected in proposed reporting graph near user id ' . $cursor);
        }
        $seen[$cursor] = true;
        $cursor = (int)($current[$cursor] ?? 0);
    }
}

echo 'Validated lines: ' . count($planned) . PHP_EOL;
if ($options['dry-run']) {
    echo "DRY_RUN=YES\nREPORTING_IMPORT=VALID\n";
    exit(0);
}

$transaction = $DB->start_delegated_transaction();
foreach ($planned as $uid => $change) {
    \local_ustar\org::set_manager($uid, (int)$change['managerid'], 'csv');
}
$transaction->allow_commit();

echo 'Imported lines: ' . count($planned) . PHP_EOL;
echo "REPORTING_IMPORT=OK\n";
