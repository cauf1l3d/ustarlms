<?php
define('CLI_SCRIPT', true);
require('/stage/moodle/config.php');
if ($CFG->dbname !== 'ustar_stage1' || $CFG->dbhost !== 'db') { throw new RuntimeException('REFUSING_NON_ISOLATED_DB'); }
$tables = array_filter($DB->get_tables(), static fn($table) => str_starts_with($table, 'local_ustar_'));
sort($tables);
$snapshot = [];
foreach ($tables as $table) {
    $columns = [];
    foreach ($DB->get_columns($table, false) as $name => $column) {
        // Driver metadata includes names, types, lengths, nullability and defaults.
        $columns[$name] = (array)$column;
    }
    $indexes = array_values($DB->get_indexes($table));
    usort($indexes, static fn($a, $b) => strcmp(json_encode($a), json_encode($b)));
    $snapshot[$table] = ['columns' => $columns, 'indexes' => $indexes];
}
echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
