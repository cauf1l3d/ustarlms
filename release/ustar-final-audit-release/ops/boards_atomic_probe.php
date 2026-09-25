<?php
define('CLI_SCRIPT', true);
require('/var/www/html/public/config.php');

global $CFG, $DB;

if (!in_array($CFG->wwwroot, [
    'http://127.0.0.1:18080',
    'http://127.0.0.1:18081',
    'http://127.0.0.1:18082',
], true) || empty($CFG->noemailever)) {
    fwrite(STDERR, "Refusing non-isolated Moodle instance\n");
    exit(2);
}

$mode = $argv[1] ?? 'snapshot';
$worker = (int)($argv[2] ?? 0);
$raceTitle = '__audit_board_atomic_race_20260823__';
$aclTitle = '__audit_board_atomic_acl_20260823__';
$validationTitle = '__audit_board_atomic_validation_20260823__';
$employee = $DB->get_record('user', ['username' => 'audit_employee'], 'id', MUST_EXIST);
$peer = $DB->get_record('user', ['username' => 'audit_retail_head'], 'id', MUST_EXIST);
$hr = $DB->get_record('user', ['username' => 'audit_hr'], 'id', MUST_EXIST);

function fixture_delete(int $ownerid, array $titles): int {
    global $DB;
    $deleted = 0;
    foreach ($titles as $title) {
        foreach ($DB->get_records('local_ustar_boards', ['ownerid' => $ownerid, 'title' => $title]) as $record) {
            $DB->delete_records('local_ustar_boards', ['id' => (int)$record->id, 'ownerid' => $ownerid]);
            $deleted++;
        }
    }
    return $deleted;
}

function row_count(): int {
    global $DB;
    return $DB->count_records('local_ustar_boards');
}

if ($mode === 'snapshot') {
    echo 'rows=' . row_count() . PHP_EOL;
    exit(0);
}

if ($mode === 'validation') {
    fixture_delete((int)$employee->id, [$validationTitle]);
    $id = \local_ustar\boards::create((int)$employee->id, $validationTitle);
    $invalidblocked = false;
    $oversizeblocked = false;
    $validversion = 0;
    try {
        try {
            \local_ustar\boards::save($id, (int)$employee->id, '{invalid', 1);
        } catch (\invalid_parameter_exception $e) {
            $invalidblocked = true;
        }
        try {
            $oversize = json_encode(['blob' => str_repeat('x', 10 * 1024 * 1024 + 1)]);
            \local_ustar\boards::save($id, (int)$employee->id, $oversize, 1);
        } catch (\invalid_parameter_exception $e) {
            $oversizeblocked = true;
        }
        $validversion = \local_ustar\boards::save($id, (int)$employee->id, '{"ok":true}', 1);
    } finally {
        fixture_delete((int)$employee->id, [$validationTitle]);
    }
    $pass = $invalidblocked && $oversizeblocked && $validversion === 2;
    echo 'invalid_json_blocked=' . ($invalidblocked ? '1' : '0') . PHP_EOL;
    echo 'oversize_blocked=' . ($oversizeblocked ? '1' : '0') . PHP_EOL;
    echo 'valid_after_rollbacks=' . ($validversion === 2 ? '1' : '0') . PHP_EOL;
    echo 'validation=' . ($pass ? 'PASS' : 'FAIL') . PHP_EOL;
    exit($pass ? 0 : 1);
}

if ($mode === 'acl') {
    fixture_delete((int)$employee->id, [$aclTitle]);
    $id = \local_ustar\boards::create((int)$employee->id, $aclTitle);
    $exitcode = 1;
    try {
        $privatepeer = \local_ustar\boards::get_for_user($id, (int)$peer->id) !== null;
        $DB->set_field('local_ustar_boards', 'sharedteam', 1, ['id' => $id, 'ownerid' => (int)$employee->id]);
        $sharedpeer = \local_ustar\boards::get_for_user($id, (int)$peer->id) !== null;
        $crosshr = \local_ustar\boards::get_for_user($id, (int)$hr->id) !== null;
        $peerwriteblocked = false;
        try {
            \local_ustar\boards::save($id, (int)$peer->id, '{"peer":true}', 1);
        } catch (\dml_missing_record_exception $e) {
            $peerwriteblocked = true;
        }
        $pass = !$privatepeer && $sharedpeer && !$crosshr && $peerwriteblocked;
        echo 'private_peer_denied=' . (!$privatepeer ? '1' : '0') . PHP_EOL;
        echo 'shared_peer_allowed=' . ($sharedpeer ? '1' : '0') . PHP_EOL;
        echo 'cross_department_denied=' . (!$crosshr ? '1' : '0') . PHP_EOL;
        echo 'shared_peer_write_denied=' . ($peerwriteblocked ? '1' : '0') . PHP_EOL;
        echo 'acl=' . ($pass ? 'PASS' : 'FAIL') . PHP_EOL;
        $exitcode = $pass ? 0 : 1;
    } finally {
        fixture_delete((int)$employee->id, [$aclTitle]);
    }
    exit($exitcode);
}

if ($mode === 'setup') {
    fixture_delete((int)$employee->id, [$raceTitle]);
    echo 'baseline_rows=' . row_count() . PHP_EOL;
    $id = \local_ustar\boards::create((int)$employee->id, $raceTitle);
    echo 'fixture_id=' . $id . PHP_EOL;
    exit(0);
}

if ($mode === 'race') {
    $record = $DB->get_record('local_ustar_boards', [
        'ownerid' => (int)$employee->id,
        'title' => $raceTitle,
        'deleted' => 0,
    ], '*', MUST_EXIST);
    try {
        $version = \local_ustar\boards::save(
            (int)$record->id,
            (int)$employee->id,
            json_encode(['worker' => $worker]),
            1
        );
        echo "posted,$worker,$version\n";
    } catch (\Throwable $e) {
        echo "conflict,$worker\n";
    }
    exit(0);
}

if ($mode === 'result') {
    $record = $DB->get_record('local_ustar_boards', [
        'ownerid' => (int)$employee->id,
        'title' => $raceTitle,
        'deleted' => 0,
    ], '*', MUST_EXIST);
    $document = json_decode((string)$record->documentjson, true);
    echo 'persisted_rows=' . $DB->count_records('local_ustar_boards', [
        'ownerid' => (int)$employee->id,
        'title' => $raceTitle,
    ]) . PHP_EOL;
    echo 'final_version=' . (int)$record->version . PHP_EOL;
    echo 'single_document=' . (isset($document['worker']) && count($document) === 1 ? '1' : '0') . PHP_EOL;
    exit(0);
}

if ($mode === 'cleanup') {
    echo 'deleted=' . fixture_delete((int)$employee->id, [$raceTitle, $aclTitle, $validationTitle]) . PHP_EOL;
    echo 'final_rows=' . row_count() . PHP_EOL;
    exit(0);
}

fwrite(STDERR, "Unknown mode\n");
exit(2);
