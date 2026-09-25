<?php
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

$employee = $DB->get_record('user', ['username' => 'audit_employee'], '*', MUST_EXIST);
$admin = $DB->get_record('user', ['username' => 'audit_superadmin'], '*', MUST_EXIST);
$route = \local_ustar\route_model::get_route('retail_seller');
if (!$route) {
    cli_error('RETAIL_SELLER_ROUTE_MISSING');
}

$stamp = time() . '_' . random_int(1000, 9999);
$contentids = [];
$folderids = [];
$pointids = [];
$checks = 0;

$assert = static function(bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('ASSERT_FAILED=' . $label);
    }
    $checks++;
    echo $label . '=PASS' . PHP_EOL;
};

$createcontent = static function(
    string $title,
    string $sourcekind,
    bool $ackrequired,
    int $parentid = 0,
    string $type = 'article'
) use (&$contentids, &$folderids, $DB): array {
    $now = time();
    $contentid = (int)$DB->insert_record('local_ustar_content', (object)[
        'parentid' => $parentid > 0 ? $parentid : null,
        'type' => $type,
        'title' => $title,
        'summary' => 'Synthetic isolated Materials/Library test fixture',
        'category' => 'learning',
        'status' => 'published',
        'sourcekind' => $sourcekind,
        'courseid' => null,
        'cmid' => null,
        'externalurl' => $sourcekind === 'external' ? 'https://invalid.example/isolated-test' : null,
        'owneruserid' => null,
        'ackrequired' => $ackrequired ? 1 : 0,
        'publishedat' => $now,
        'sortorder' => 0,
        'timecreated' => $now,
        'timemodified' => $now,
        'usermodified' => 0,
    ]);
    $contentids[] = $contentid;
    if ($type === 'folder') {
        $folderids[] = $contentid;
        return ['contentid' => $contentid, 'versionid' => 0, 'timemodified' => $now];
    }
    $versionid = (int)$DB->insert_record('local_ustar_content_versions', (object)[
        'contentid' => $contentid,
        'versionno' => 1,
        'versionlabel' => 'v1-test',
        'changenote' => 'Synthetic isolated test only',
        'effectivedate' => $now,
        'iscurrent' => 1,
        'status' => 'published',
        'timecreated' => $now,
        'createdby' => 0,
    ]);
    $DB->insert_record('local_ustar_content_access', (object)[
        'contentid' => $contentid,
        'scopetype' => 'all',
        'scopeid' => null,
        'active' => 1,
        'timecreated' => $now,
        'createdby' => 0,
    ]);
    return ['contentid' => $contentid, 'versionid' => $versionid, 'timemodified' => $now];
};

try {
    $opencontent = $createcontent(
        'ZZ route open ' . $stamp,
        'external',
        false
    );
    $directcontent = $createcontent(
        'ZZ direct ACL only ' . $stamp,
        'external',
        false
    );
    $ackcontent = $createcontent(
        'ZZ route acknowledgement ' . $stamp,
        \local_ustar\content::SOURCE_FILE,
        true
    );

    $openpoint = \local_ustar\route_model::add_point(
        (int)$route->id,
        'zz_open_' . $stamp,
        \local_ustar\route_model::PHASE_ADAPTATION,
        -100000,
        [
            'title' => 'ZZ open route material',
            'requirements' => [[
                'type' => 'content',
                'sourceid' => (int)$opencontent['contentid'],
                'completionmode' => 'open',
                'required' => true,
            ]],
            'status' => \local_ustar\route_model::STATUS_PUBLISHED,
            'effectivedate' => 0,
        ],
        (int)$admin->id
    );
    $pointids[] = (int)$openpoint->id;
    $openversion = \local_ustar\route_model::current_published_version((int)$openpoint->id);
    $assert(!empty($openversion), 'OPEN_ROUTE_VERSION');

    $model = \local_ustar\route_model::for_user('retail_seller', (int)$employee->id);
    $view = null;
    foreach ($model['points'] ?? [] as $candidate) {
        if ((int)$candidate['id'] === (int)$openpoint->id) {
            $view = $candidate;
            break;
        }
    }
    $assert(!empty($view['canlaunch']), 'OPEN_ROUTE_IS_LAUNCHABLE');
    \local_ustar\route_model::assert_content_launch(
        (int)$employee->id,
        (int)$opencontent['contentid'],
        (int)$openpoint->id,
        (int)$openversion->id
    );
    $assert(true, 'GATEWAY_ACCEPTS_CURRENT_POINT');

    $before = $DB->count_records('local_ustar_content_events', [
        'userid' => (int)$employee->id,
        'contentid' => (int)$opencontent['contentid'],
    ]);
    $eventid = \local_ustar\learning_events::record_route_open(
        (int)$employee->id,
        (int)$opencontent['contentid'],
        (int)$openpoint->id,
        (int)$openversion->id
    );
    $repeatid = \local_ustar\learning_events::record_route_open(
        (int)$employee->id,
        (int)$opencontent['contentid'],
        (int)$openpoint->id,
        (int)$openversion->id
    );
    $after = $DB->count_records('local_ustar_content_events', [
        'userid' => (int)$employee->id,
        'contentid' => (int)$opencontent['contentid'],
    ]);
    $assert($eventid === $repeatid && $after === $before + 1, 'OPEN_EVENT_IDEMPOTENT');
    $assert($DB->record_exists('local_ustar_library', [
        'userid' => (int)$employee->id,
        'contentid' => (int)$opencontent['contentid'],
    ]), 'OPEN_EVENT_UNLOCKS_LIBRARY');

    $libraryids = array_map(
        static fn(array $item): int => (int)$item['id'],
        \local_ustar\learning_events::library_for_user((int)$employee->id)
    );
    $assert(in_array((int)$opencontent['contentid'], $libraryids, true), 'UNLOCKED_ITEM_VISIBLE');
    $assert(!in_array((int)$directcontent['contentid'], $libraryids, true), 'DIRECT_ACL_DOES_NOT_UNLOCK');

    $ackpoint = \local_ustar\route_model::add_point(
        (int)$route->id,
        'zz_ack_' . $stamp,
        \local_ustar\route_model::PHASE_ADAPTATION,
        -99990,
        [
            'title' => 'ZZ acknowledgement route material',
            'requirements' => [[
                'type' => 'content',
                'sourceid' => (int)$ackcontent['contentid'],
                'completionmode' => 'ack',
                'required' => true,
            ]],
            'status' => \local_ustar\route_model::STATUS_PUBLISHED,
            'effectivedate' => 0,
        ],
        (int)$admin->id
    );
    $pointids[] = (int)$ackpoint->id;
    $ackversion = \local_ustar\route_model::current_published_version((int)$ackpoint->id);
    $assert(!empty($ackversion), 'ACK_ROUTE_VERSION');
    \local_ustar\route_model::assert_content_launch(
        (int)$employee->id,
        (int)$ackcontent['contentid'],
        (int)$ackpoint->id,
        (int)$ackversion->id
    );
    \local_ustar\learning_events::record_route_open(
        (int)$employee->id,
        (int)$ackcontent['contentid'],
        (int)$ackpoint->id,
        (int)$ackversion->id
    );
    $assert(\local_ustar\learning_events::route_fact(
        (int)$employee->id,
        (int)$ackcontent['contentid'],
        (int)$ackpoint->id,
        (int)$ackversion->id,
        'ack'
    ) === null, 'ACK_OPEN_IS_NOT_STUDIED');
    \local_ustar\content::acknowledge((int)$ackcontent['contentid'], (int)$employee->id);
    \local_ustar\learning_events::record_route_studied(
        (int)$employee->id,
        (int)$ackcontent['contentid'],
        (int)$ackpoint->id,
        (int)$ackversion->id
    );
    $assert(\local_ustar\learning_events::route_fact(
        (int)$employee->id,
        (int)$ackcontent['contentid'],
        (int)$ackpoint->id,
        (int)$ackversion->id,
        'ack'
    ) !== null, 'ACK_STUDIED_EVENT_RECORDED');

    $foldera = $createcontent('ZZ folder A ' . $stamp, 'folder', false, 0, 'folder');
    $folderb = $createcontent('ZZ folder B ' . $stamp, 'folder', false, 0, 'folder');
    $moveme = $createcontent('ZZ move me ' . $stamp, 'external', false);

    try {
        \local_ustar\content_admin::move(
            (int)$moveme['contentid'],
            (int)$foldera['contentid'],
            (int)$moveme['timemodified'],
            (int)$employee->id
        );
        throw new RuntimeException('EMPLOYEE_MOVE_WAS_ALLOWED');
    } catch (required_capability_exception $e) {
        $assert(true, 'EMPLOYEE_MOVE_DENIED');
    }

    \local_ustar\content_admin::move(
        (int)$moveme['contentid'],
        (int)$foldera['contentid'],
        (int)$moveme['timemodified'],
        (int)$admin->id
    );
    $assert((int)$DB->get_field('local_ustar_content', 'parentid', [
        'id' => (int)$moveme['contentid'],
    ]) === (int)$foldera['contentid'], 'ADMIN_MOVE_APPLIED');
    $assert($DB->record_exists('local_ustar_content_events', [
        'contentid' => (int)$moveme['contentid'],
        'eventtype' => \local_ustar\learning_events::EVENT_MOVED,
    ]), 'MOVE_AUDIT_RECORDED');

    try {
        \local_ustar\content_admin::move(
            (int)$moveme['contentid'],
            (int)$folderb['contentid'],
            (int)$moveme['timemodified'],
            (int)$admin->id
        );
        throw new RuntimeException('STALE_MOVE_WAS_ALLOWED');
    } catch (moodle_exception $e) {
        $assert(true, 'STALE_MOVE_REJECTED');
    }

    \local_ustar\content_admin::move(
        (int)$foldera['contentid'],
        (int)$folderb['contentid'],
        (int)$foldera['timemodified'],
        (int)$admin->id
    );
    $freshb = (int)$DB->get_field('local_ustar_content', 'timemodified', [
        'id' => (int)$folderb['contentid'],
    ]);
    try {
        \local_ustar\content_admin::move(
            (int)$folderb['contentid'],
            (int)$foldera['contentid'],
            $freshb,
            (int)$admin->id
        );
        throw new RuntimeException('FOLDER_CYCLE_WAS_ALLOWED');
    } catch (invalid_parameter_exception $e) {
        $assert(true, 'FOLDER_CYCLE_REJECTED');
    }

    echo 'MATERIALS_LIBRARY_SMOKE=PASS CHECKS=' . $checks . PHP_EOL;
} finally {
    if ($pointids) {
        $DB->delete_records_list('local_ustar_route_progress', 'pointid', $pointids);
        $DB->delete_records_list('local_ustar_route_versions', 'pointid', $pointids);
        $DB->delete_records_list('local_ustar_route_points', 'id', $pointids);
    }
    if ($contentids) {
        $DB->delete_records_list('local_ustar_content_ack', 'contentid', $contentids);
        $DB->delete_records_list('local_ustar_library', 'contentid', $contentids);
        $DB->delete_records_list('local_ustar_content_events', 'contentid', $contentids);
        $DB->delete_records_list('local_ustar_content_access', 'contentid', $contentids);
        $DB->delete_records_list('local_ustar_content_versions', 'contentid', $contentids);
        $nonfolders = array_values(array_diff($contentids, $folderids));
        if ($nonfolders) {
            $DB->delete_records_list('local_ustar_content', 'id', $nonfolders);
        }
        foreach ($folderids as $folderid) {
            $DB->set_field('local_ustar_content', 'parentid', null, ['id' => $folderid]);
        }
        if ($folderids) {
            $DB->delete_records_list('local_ustar_content', 'id', $folderids);
        }
    }
}
