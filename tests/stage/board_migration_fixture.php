<?php
// Synthetic migration evidence. Never run against a real installation.
define('CLI_SCRIPT', true);
require('/stage/moodle/config.php');
if ($CFG->dbhost !== 'db' || $CFG->dbname !== 'ustar_stage1' || $CFG->prefix !== 'stage_'
        || realpath($CFG->dataroot) !== '/stage/data/stage_') {
    throw new RuntimeException('REFUSING_NON_ISOLATED_MIGRATION_FIXTURE');
}
$mode = $argv[1] ?? '';
$fixturepath = '/artifacts/board-migration-fixture.json';
if ($mode === 'seed') {
    if (is_file($fixturepath)) {
        throw new RuntimeException('Fixture must be seeded once before the candidate upgrade');
    }
    // The verified production baseline already retired Boards. Preserve the
    // old migration fixture for earlier baselines, but do not recreate a
    // retired working table in the current production-upgrade scenario.
    if ((int)get_config('local_ustar', 'version') >= 2026082744) {
        if ($DB->get_manager()->table_exists(new xmldb_table('local_ustar_boards'))
                || !$DB->get_manager()->table_exists(new xmldb_table('local_ustar_board_archive'))) {
            throw new RuntimeException('Retired baseline has an unexpected Boards schema');
        }
        if (file_put_contents($fixturepath, json_encode(['mode' => 'already_retired'],
                JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Cannot save retired-baseline evidence');
        }
        echo "BOARD_BASELINE_ALREADY_RETIRED=PASS\n";
        exit;
    }
    $ownerid = (int)get_admin()->id;
    $rows = [];
    foreach ([0, 1] as $deleted) {
        $row = (object)[
            'ownerid' => $ownerid, 'title' => 'Migration fixture ' . $deleted,
            'documentjson' => json_encode(['fixture' => true, 'deleted' => $deleted, 'text' => 'История'],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'version' => 7, 'sharedteam' => 1, 'deleted' => $deleted,
            'timecreated' => 1000, 'timemodified' => 2000,
        ];
        $row->id = (int)$DB->insert_record('local_ustar_boards', $row);
        $rows[] = $row;
    }
    if (file_put_contents($fixturepath, json_encode($rows, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Cannot save migration fixture evidence');
    }
    echo "BOARD_FIXTURE_SEEDED=2 (active and soft-deleted)\n";
} else if ($mode === 'verify') {
    $rows = json_decode(file_get_contents($fixturepath), true, 512, JSON_THROW_ON_ERROR);
    if (($rows['mode'] ?? '') === 'already_retired') {
        if ($DB->get_manager()->table_exists(new xmldb_table('local_ustar_boards'))
                || !$DB->get_manager()->table_exists(new xmldb_table('local_ustar_board_archive'))) {
            throw new RuntimeException('Boards were reactivated after candidate upgrade');
        }
        echo "BOARD_RETIRED_BASELINE_STILL_RETIRED=PASS\n";
        exit;
    }
    if (count($rows) !== 2 || $DB->get_manager()->table_exists(new xmldb_table('local_ustar_boards'))) {
        throw new RuntimeException('Board retirement did not complete');
    }
    foreach ($rows as $source) {
        $archive = $DB->get_record('local_ustar_board_archive', ['boardid' => $source['id']], '*', MUST_EXIST);
        foreach (['ownerid', 'title', 'documentjson', 'version', 'sharedteam'] as $field) {
            if ((string)$archive->$field !== (string)$source[$field]) {
                throw new RuntimeException('Board archive changed field: ' . $field);
            }
        }
        if (!hash_equals(hash('sha256', $source['documentjson']), $archive->checksum)
                || \local_ustar\board_retirement::get_for_owner((int)$archive->id, 0) !== null) {
            throw new RuntimeException('Archive checksum or owner isolation failed');
        }
    }
    echo "BOARD_MIGRATION_DATA_AND_OWNER_BOUNDARY=PASS\n";
} else {
    throw new RuntimeException('Expected seed or verify');
}
