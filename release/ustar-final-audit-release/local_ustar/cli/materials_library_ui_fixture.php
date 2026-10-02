<?php
// Reproducible isolated-only fixture for authenticated Materials/Library screenshots.
define('CLI_SCRIPT', true);

$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require_once($config);
require_once($CFG->libdir . '/clilib.php');

if (!in_array($CFG->wwwroot, [
    'http://127.0.0.1:18080',
    'http://127.0.0.1:18081',
    'http://127.0.0.1:18082',
], true) || empty($CFG->noemailever)) {
    cli_error('REFUSING_NON_ISOLATED_MOODLE');
}

[$options, $unrecognised] = cli_get_params([
    'action' => '',
    'key' => 'materials_ui',
], [
    'h' => 'help',
]);

if ($unrecognised) {
    cli_error('Unknown options: ' . implode(', ', $unrecognised));
}
if (!empty($options['help'])) {
    echo "Create, unlock or clean a synthetic isolated Materials/Library UI fixture.\n\n";
    echo "--action=create|unlock|cleanup\n";
    echo "--key=materials_ui\n";
    exit(0);
}

$action = (string)$options['action'];
$key = strtolower(trim((string)$options['key']));
if (!in_array($action, ['create', 'unlock', 'cleanup'], true)) {
    cli_error('INVALID_ACTION');
}
if (!preg_match('/^[a-z0-9_]{3,40}$/', $key)) {
    cli_error('INVALID_KEY');
}

$titleprefix = 'ZZ UI ' . $key . ' ';
$pointprefix = 'zz_ui_' . $key . '_';

$cleanup = static function() use ($DB, $titleprefix, $pointprefix): array {
    $pointids = $DB->get_fieldset_select(
        'local_ustar_route_points',
        'id',
        $DB->sql_like('pointkey', ':pointprefix'),
        ['pointprefix' => $pointprefix . '%']
    );
    $pointids = array_map('intval', $pointids);
    if ($pointids) {
        $DB->delete_records_list('local_ustar_route_progress', 'pointid', $pointids);
        $DB->delete_records_list('local_ustar_route_versions', 'pointid', $pointids);
        $DB->delete_records_list('local_ustar_route_points', 'id', $pointids);
    }

    $contentids = $DB->get_fieldset_select(
        'local_ustar_content',
        'id',
        $DB->sql_like('title', ':titleprefix'),
        ['titleprefix' => $titleprefix . '%']
    );
    $contentids = array_map('intval', $contentids);
    if ($contentids) {
        $DB->delete_records_list('local_ustar_content_ack', 'contentid', $contentids);
        $DB->delete_records_list('local_ustar_library', 'contentid', $contentids);
        $DB->delete_records_list('local_ustar_content_events', 'contentid', $contentids);
        $DB->delete_records_list('local_ustar_content_access', 'contentid', $contentids);
        $DB->delete_records_list('local_ustar_content_versions', 'contentid', $contentids);
        [$insql, $inparams] = $DB->get_in_or_equal($contentids, SQL_PARAMS_NAMED, 'cleanup');
        $DB->set_field_select(
            'local_ustar_content',
            'parentid',
            null,
            'id ' . $insql,
            $inparams
        );
        $DB->delete_records_list('local_ustar_content', 'id', $contentids);
    }

    return [
        'points' => count($pointids),
        'content' => count($contentids),
    ];
};

if ($action === 'cleanup') {
    $removed = $cleanup();
    echo 'UI_FIXTURE_CLEANUP=PASS points=' . $removed['points'] .
        ' content=' . $removed['content'] . PHP_EOL;
    exit(0);
}

$employee = $DB->get_record('user', ['username' => 'audit_employee'], '*', MUST_EXIST);
$admin = $DB->get_record('user', ['username' => 'audit_superadmin'], '*', MUST_EXIST);
$route = \local_ustar\route_model::get_route('retail_seller');
if (!$route) {
    cli_error('RETAIL_SELLER_ROUTE_MISSING');
}

if ($action === 'unlock') {
    $content = $DB->get_record('local_ustar_content', [
        'title' => $titleprefix . 'Route material',
    ], '*', MUST_EXIST);
    $point = $DB->get_record('local_ustar_route_points', [
        'pointkey' => $pointprefix . 'route_material',
    ], '*', MUST_EXIST);
    $version = \local_ustar\route_model::current_published_version((int)$point->id);
    if (!$version) {
        cli_error('ROUTE_VERSION_MISSING');
    }
    \local_ustar\route_model::assert_content_launch(
        (int)$employee->id,
        (int)$content->id,
        (int)$point->id,
        (int)$version->id
    );
    $eventid = \local_ustar\learning_events::record_route_open(
        (int)$employee->id,
        (int)$content->id,
        (int)$point->id,
        (int)$version->id
    );
    echo 'UI_FIXTURE_UNLOCK=PASS eventid=' . $eventid .
        ' contentid=' . $content->id . PHP_EOL;
    exit(0);
}

$cleanup();
$now = time();

$createcontent = static function(
    string $title,
    string $type,
    string $sourcekind,
    ?int $parentid = null
) use ($DB, $admin, $now): array {
    $contentid = (int)$DB->insert_record('local_ustar_content', (object)[
        'parentid' => $parentid,
        'type' => $type,
        'title' => $title,
        'summary' => 'Synthetic isolated screenshot fixture; safe to remove by key.',
        'category' => 'learning',
        'status' => 'published',
        'sourcekind' => $sourcekind,
        'courseid' => null,
        'cmid' => null,
        'externalurl' => $sourcekind === 'external' ? 'https://invalid.example/isolated-ui-fixture' : null,
        'owneruserid' => (int)$admin->id,
        'ackrequired' => 0,
        'publishedat' => $now,
        'sortorder' => 0,
        'timecreated' => $now,
        'timemodified' => $now,
        'usermodified' => (int)$admin->id,
    ]);

    $versionid = 0;
    if ($type !== 'folder') {
        $versionid = (int)$DB->insert_record('local_ustar_content_versions', (object)[
            'contentid' => $contentid,
            'versionno' => 1,
            'versionlabel' => 'v1-ui-fixture',
            'changenote' => 'Synthetic isolated screenshot fixture',
            'effectivedate' => $now,
            'iscurrent' => 1,
            'status' => 'published',
            'timecreated' => $now,
            'createdby' => (int)$admin->id,
        ]);
        $DB->insert_record('local_ustar_content_access', (object)[
            'contentid' => $contentid,
            'scopetype' => 'all',
            'scopeid' => null,
            'active' => 1,
            'timecreated' => $now,
            'createdby' => (int)$admin->id,
        ]);
    }
    return [
        'contentid' => $contentid,
        'versionid' => $versionid,
    ];
};

$foldera = $createcontent($titleprefix . 'Folder A', 'folder', 'folder');
$folderb = $createcontent($titleprefix . 'Folder B', 'folder', 'folder');
$moveme = $createcontent($titleprefix . 'Move me', 'article', 'external');
$routecontent = $createcontent($titleprefix . 'Route material', 'article', 'external');

$point = \local_ustar\route_model::add_point(
    (int)$route->id,
    $pointprefix . 'route_material',
    \local_ustar\route_model::PHASE_ADAPTATION,
    -100000,
    [
        'title' => 'UI fixture: route material',
        'requirements' => [[
            'type' => 'content',
            'sourceid' => (int)$routecontent['contentid'],
            'completionmode' => 'open',
            'required' => true,
        ]],
        'status' => \local_ustar\route_model::STATUS_PUBLISHED,
        'effectivedate' => 0,
    ],
    (int)$admin->id
);
$version = \local_ustar\route_model::current_published_version((int)$point->id);
if (!$version) {
    cli_error('ROUTE_VERSION_MISSING_AFTER_CREATE');
}

echo 'UI_FIXTURE_CREATE=PASS' . PHP_EOL;
echo 'KEY=' . $key . PHP_EOL;
echo 'FOLDER_A_ID=' . $foldera['contentid'] . PHP_EOL;
echo 'FOLDER_B_ID=' . $folderb['contentid'] . PHP_EOL;
echo 'MOVE_CONTENT_ID=' . $moveme['contentid'] . PHP_EOL;
echo 'ROUTE_CONTENT_ID=' . $routecontent['contentid'] . PHP_EOL;
echo 'ROUTE_POINT_ID=' . $point->id . PHP_EOL;
echo 'ROUTE_VERSION_ID=' . $version->id . PHP_EOL;
