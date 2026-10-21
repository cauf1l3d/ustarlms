<?php
// Test-only clock for isolated child requests. No production class has a fixture hook.
namespace local_ustar {
    function time(): int { return $GLOBALS['con02_ack_clock'] ?? \time(); }
}
namespace {
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
if ($CFG->dbhost !== 'db' || $CFG->dbname !== 'ustar_stage1' || $CFG->dbuser !== 'ustar_fixture'
        || $CFG->prefix !== 'stage_' || __DIR__ !== '/source/tests/stage') {
    throw new RuntimeException('REFUSING_NON_ISOLATED_ACK_FIXTURE');
}
if (($argv[1] ?? '') === 'child') {
    $input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $GLOBALS['con02_ack_clock'] = (int)$input['clock'];
    if ($input['legacy']) {
        foreach (['target_core', 'communication'] as $name) {
            if (class_exists('local_ustar\\'.$name, false)) { throw new RuntimeException('Legacy class loaded too early'); }
            require '/source/.stage-input/con02-ack-baseline/'.$name.'.php';
        }
    }
    $DB->get_field_sql("SELECT set_config('application_name', :name, false)", ['name' => $input['name']]);
    if ($input['operation'] === 'ack') {
        \local_ustar\target_core::acknowledge_notification((int)$input['id'], (int)$input['userid']);
    } else if ($input['operation'] === 'read') {
        \local_ustar\communication::mark_notification((int)$input['userid'], (int)$input['id']);
    } else { throw new RuntimeException('Unknown ACK fixture operation'); }
    echo json_encode(['completed' => $input['operation']]);
    exit;
}

function ack_child(array $input): array {
    $process = proc_open([PHP_BINARY, __FILE__, 'child'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start ACK child'); }
    fwrite($pipes[0], json_encode($input)); fclose($pipes[0]);
    return [$process, $pipes];
}
function ack_wait_for_lock(string $name): void {
    global $DB;
    $until = microtime(true) + 15;
    do {
        if ($DB->get_field_sql("SELECT COUNT(*) FROM pg_stat_activity
                WHERE application_name = :name AND wait_event_type = 'Lock'", ['name' => $name])) { return; }
        usleep(20000);
    } while (microtime(true) < $until);
    throw new RuntimeException('ACK barrier did not reach database lock: '.$name);
}
function ack_finish_child(array $worker): void {
    [$process, $pipes] = $worker;
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $err !== '' || !isset(json_decode($out, true)['completed'])) {
        throw new RuntimeException('ACK child failed: '.$err.' '.$out);
    }
}

$userid = (int)$DB->get_field('user', 'id', ['username' => 'admin'], MUST_EXIST);
$table = $CFG->prefix.'local_ustar_notifications';
// Moodle execute() intentionally rejects semicolons, including a PL/pgSQL body.
// Only disposable barrier DDL uses this separate native connection; writers stay Moodle DB.
$ddlconnection = pg_connect('host=db dbname=ustar_stage1 user=ustar_fixture', PGSQL_CONNECT_FORCE_NEW);
if (!$ddlconnection) { throw new RuntimeException('Cannot connect disposable ACK barrier'); }
function ack_ddl(string $sql): void {
    global $ddlconnection;
    if (!pg_query($ddlconnection, $sql)) { throw new RuntimeException('Cannot create/drop disposable ACK barrier'); }
}
$proof = [];
foreach ([true, false] as $legacy) {
    foreach (['read', 'ack'] as $secondop) {
        $now = time(); $firsttime = $now-120;
        $id = $DB->insert_record('local_ustar_notifications', (object)[
            'userid' => $userid, 'severity' => 'normal', 'eventtype' => 'general',
            'subject' => 'Synthetic ACK race', 'message' => 'No private data',
            'idempotencykey' => 'con02-ack-'.bin2hex(random_bytes(8)),
            'status' => 'unread', 'ackat' => null, 'timecreated' => $now, 'timemodified' => $now]);
        $suffix = bin2hex(random_bytes(6)); $name = 'con02_ack_'.$suffix;
        $lock = random_int(100000, 2000000000);
        $first = 'ustar_ack_first_'.$suffix; $second = 'ustar_ack_second_'.$suffix;
        $workers = []; $locked = false;
        try {
            // First UPDATE holds the row while waiting for this parent-owned advisory lock.
            // The second request has read the old row before blocking on its UPDATE.
            ack_ddl("CREATE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$
                BEGIN
                    IF NEW.id = $id AND current_setting('application_name') = '$first' THEN
                        PERFORM pg_advisory_xact_lock($lock);
                    END IF;
                    RETURN NEW;
                END \$\$");
            ack_ddl("CREATE TRIGGER $name BEFORE UPDATE ON $table
                FOR EACH ROW EXECUTE FUNCTION $name()");
            $DB->get_field_sql('SELECT pg_advisory_lock(:lock)', ['lock' => $lock]); $locked = true;
            $common = ['id' => $id, 'userid' => $userid, 'legacy' => $legacy];
            $workers[] = ack_child($common + ['operation' => 'ack', 'name' => $first, 'clock' => $firsttime]);
            ack_wait_for_lock($first);
            $workers[] = ack_child($common + ['operation' => $secondop, 'name' => $second, 'clock' => $now]);
            ack_wait_for_lock($second);
            $DB->get_field_sql('SELECT pg_advisory_unlock(:lock)', ['lock' => $lock]); $locked = false;
            foreach ($workers as $worker) { ack_finish_child($worker); }
            $workers = [];
            $row = $DB->get_record('local_ustar_notifications', ['id' => $id], '*', MUST_EXIST);
            $preserved = $row->status === 'acknowledged' && (int)$row->ackat === $firsttime;
            if ($legacy ? $preserved : !$preserved) {
                throw new RuntimeException('ACK differential contract mismatch: '.json_encode([$legacy, $secondop, $row->status, $row->ackat]));
            }
            $proof[] = ['source' => $legacy ? 'reviewed_baseline' : 'candidate',
                'second_request' => $secondop, 'first_receipt_preserved' => $preserved];
        } finally {
            if ($locked) { $DB->get_field_sql('SELECT pg_advisory_unlock(:lock)', ['lock' => $lock]); }
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                proc_close($process);
            }
            ack_ddl("DROP TRIGGER IF EXISTS $name ON $table");
            ack_ddl("DROP FUNCTION IF EXISTS $name()");
        }
    }
}
pg_close($ddlconnection);
file_put_contents('/artifacts/con02-ack-concurrency.json', json_encode($proof, JSON_PRETTY_PRINT)."\n");
echo "CON02_ACK_NATIVE_BASELINE_RED_CANDIDATE_GREEN=PASS cases=4\n";
}
