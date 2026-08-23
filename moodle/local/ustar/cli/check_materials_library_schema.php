<?php
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require_once($config);
require_once($CFG->libdir . '/clilib.php');

$expectedversion = 2026082301;
$actualversion = (int)get_config('local_ustar', 'version');
if ($actualversion !== $expectedversion) {
    cli_error('LOCAL_USTAR_VERSION_MISMATCH actual=' . $actualversion . ' expected=' . $expectedversion);
}

$required = [
    'local_ustar_content_events' => [
        'actorid', 'userid', 'contentid', 'contentversionid', 'routepointid',
        'routeversionid', 'eventtype', 'idempotencykey', 'detailsjson', 'timecreated',
    ],
    'local_ustar_library' => [
        'userid', 'contentid', 'unlockedversionid', 'firsteventid', 'routepointid',
        'routeversionid', 'unlockedat', 'lastaccessedat', 'timecreated', 'timemodified',
    ],
];

$dbman = $DB->get_manager();
foreach ($required as $tablename => $fields) {
    $table = new xmldb_table($tablename);
    if (!$dbman->table_exists($table)) {
        cli_error('MISSING_TABLE=' . $tablename);
    }
    foreach ($fields as $fieldname) {
        if (!$dbman->field_exists($table, new xmldb_field($fieldname))) {
            cli_error('MISSING_FIELD=' . $tablename . '.' . $fieldname);
        }
    }
}

$open = \local_ustar\route_model::normalize_requirements([[
    'type' => 'content',
    'sourceid' => 42,
    'completionmode' => 'open',
    'required' => true,
]]);
$ack = \local_ustar\route_model::normalize_requirements([[
    'type' => 'content',
    'sourceid' => 42,
    'completionmode' => 'ack',
    'required' => true,
]]);
if (($open[0]['completionmode'] ?? '') !== 'open' || ($ack[0]['completionmode'] ?? '') !== 'ack') {
    cli_error('CONTENT_REQUIREMENT_NORMALIZATION_FAILED');
}

$eventcount = $DB->count_records('local_ustar_content_events');
$librarycount = $DB->count_records('local_ustar_library');
echo 'LOCAL_USTAR_VERSION=' . $actualversion . PHP_EOL;
echo 'CONTENT_EVENTS=' . $eventcount . PHP_EOL;
echo 'PERSONAL_LIBRARY_ROWS=' . $librarycount . PHP_EOL;
echo "MATERIALS_LIBRARY_SCHEMA=OK\n";
