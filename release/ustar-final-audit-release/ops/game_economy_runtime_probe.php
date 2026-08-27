<?php
define('CLI_SCRIPT', true);
require('/var/www/html/public/config.php');

global $CFG, $DB, $USER;
if (!in_array($CFG->wwwroot, ['http://127.0.0.1:18080', 'http://127.0.0.1:18081', 'http://127.0.0.1:18082'], true)
        || empty($CFG->noemailever)) {
    fwrite(STDERR, "Refusing non-isolated Moodle instance\n");
    exit(2);
}

$employee = $DB->get_record('user', ['username' => 'audit_employee'], '*', MUST_EXIST);
$operator = $DB->get_record('user', ['username' => 'audit_superadmin'], '*', MUST_EXIST);
$originaluser = $USER;
$now = time();
$keyprefix = 'audit_economy_' . $now . '_';
$ledgerids = [];
$competitionid = 0;
$participantids = [];
$scoreeventids = [];
$resultids = [];
$balancebefore = $DB->get_record('local_ustar_coin_balance', ['userid' => $employee->id]);
$ledgerbefore = $DB->count_records('local_ustar_coin_ledger', ['userid' => $employee->id]);

$result = [
    'tables' => [], 'ledger' => [], 'competition' => [], 'baseline_restored' => false,
];

try {
    $result['tables'] = [
        'balance' => $DB->get_manager()->table_exists(new xmldb_table('local_ustar_coin_balance')),
        'competition' => $DB->get_manager()->table_exists(new xmldb_table('local_ustar_competitions')),
        'rules' => $DB->get_manager()->table_exists(new xmldb_table('local_ustar_comp_rules')),
        'participants' => $DB->get_manager()->table_exists(new xmldb_table('local_ustar_comp_participants')),
        'score_events' => $DB->get_manager()->table_exists(new xmldb_table('local_ustar_comp_score_events')),
        'results' => $DB->get_manager()->table_exists(new xmldb_table('local_ustar_comp_results')),
    ];
    if (in_array(false, $result['tables'], true)) {
        throw new moodle_exception('Game Economy tables are incomplete.');
    }

    $balance = \local_ustar\economy::balance((int)$employee->id);
    $creditkey = $keyprefix . 'credit';
    $result['ledger']['credit'] = \local_ustar\economy::post(
        (int)$employee->id, 50, 'manual_credit', $creditkey, 'audit', 'economy', 'audit credit', (int)$operator->id
    );
    $result['ledger']['credit_duplicate_noop'] = !\local_ustar\economy::post(
        (int)$employee->id, 50, 'manual_credit', $creditkey, 'audit', 'economy', 'audit duplicate', (int)$operator->id
    );
    $result['ledger']['after_credit'] = \local_ustar\economy::balance((int)$employee->id) === $balance + 50;

    $debitkey = $keyprefix . 'debit';
    $result['ledger']['spend'] = \local_ustar\economy::spend(
        (int)$employee->id, 30, 'test_spend', $debitkey, 'audit', 'economy', 'audit spend', (int)$operator->id
    );
    $result['ledger']['spend_duplicate_noop'] = !\local_ustar\economy::spend(
        (int)$employee->id, 30, 'test_spend', $debitkey, 'audit', 'economy', 'audit duplicate', (int)$operator->id
    );
    $result['ledger']['after_spend'] = \local_ustar\economy::balance((int)$employee->id) === $balance + 20;
    try {
        \local_ustar\economy::spend(
            (int)$employee->id, 100000, 'test_overspend', $keyprefix . 'overspend', 'audit', 'economy', 'audit overspend', (int)$operator->id
        );
        $result['ledger']['overspend_rejected'] = false;
    } catch (moodle_exception $e) {
        $result['ledger']['overspend_rejected'] = true;
    }
    $debit = $DB->get_record('local_ustar_coin_ledger', ['idempotencykey' => $debitkey], '*', MUST_EXIST);
    $ledgerids[] = (int)$debit->id;
    $result['ledger']['reversal'] = \local_ustar\economy::reverse(
        (int)$debit->id, $keyprefix . 'reversal', 'audit reversal', (int)$operator->id
    );
    $result['ledger']['reversal_duplicate_noop'] = !\local_ustar\economy::reverse(
        (int)$debit->id, $keyprefix . 'reversal-duplicate', 'audit duplicate reversal', (int)$operator->id
    );
    $result['ledger']['after_reversal'] = \local_ustar\economy::balance((int)$employee->id) === $balance + 50;
    foreach ($DB->get_records_select('local_ustar_coin_ledger', 'idempotencykey LIKE :prefix', ['prefix' => $keyprefix . '%']) as $row) {
        $ledgerids[] = (int)$row->id;
    }

    $resolved = \local_ustar\structure::resolve_user((int)$employee->id);
    $departmentid = (string)($resolved['position']['department'] ?? '');
    $competitionid = \local_ustar\competition::create_draft(
        'audit_comp_' . $now, 'Audit competition ' . $now, $departmentid, $now - 60, $now + 3600, 2, (int)$operator->id
    );
    \local_ustar\competition::publish($competitionid, (int)$operator->id);
    $participant = $DB->get_record('local_ustar_comp_participants', [
        'competitionid' => $competitionid, 'userid' => $employee->id,
    ], '*', MUST_EXIST);
    $participantids[] = (int)$participant->id;
    $result['competition']['published_snapshot'] = true;
    $result['competition']['participant_is_pseudonymous'] = (string)$participant->publiclabel !== '';
    $result['competition']['participant_label_has_no_name'] = strpos((string)$participant->publiclabel, (string)$employee->firstname) === false;

    \local_ustar\competition::record_game_mastery((int)$employee->id, 900001, 10, $now);
    \local_ustar\competition::record_game_mastery((int)$employee->id, 900001, 10, $now);
    $scoreevent = $DB->get_record('local_ustar_comp_score_events', [
        'competitionid' => $competitionid, 'sourceid' => '900001',
    ], '*', MUST_EXIST);
    $scoreeventids[] = (int)$scoreevent->id;
    $board = \local_ustar\competition::current_for_user((int)$employee->id);
    $employeeRows = array_values(array_filter($board['rows'] ?? [], static fn(array $row): bool => !empty($row['current'])));
    $result['competition']['score_is_separate'] = (int)($employeeRows[0]['points'] ?? 0) === 20
        && !array_key_exists('coin', $employeeRows[0] ?? []);
    $result['competition']['view_is_scoped'] = count($board['rows'] ?? []) > 0
        && count(array_filter($board['rows'], static fn(array $row): bool => !str_starts_with((string)$row['displayname'], 'Участник') && $row['displayname'] !== 'Вы')) === 0;

    $second = $DB->get_records_select('local_ustar_comp_participants', 'competitionid = :competitionid AND userid <> :userid',
        ['competitionid' => $competitionid, 'userid' => $employee->id], '', 'id,userid', 0, 1);
    if ($second) {
        $secondParticipant = reset($second);
        $participantids[] = (int)$secondParticipant->id;
        \local_ustar\competition::record_game_mastery((int)$secondParticipant->userid, 900002, 10, $now);
        $tieboard = \local_ustar\competition::current_for_user((int)$employee->id);
        $result['competition']['shared_place_policy'] = count(array_filter($tieboard['rows'] ?? [], static fn(array $row): bool => !empty($row['sharedplace']))) >= 2;
    } else {
        $result['competition']['shared_place_policy'] = false;
    }

    $DB->set_field('local_ustar_competitions', 'endat', $now - 1, ['id' => $competitionid]);
    \local_ustar\competition::close($competitionid, (int)$operator->id);
    $result['competition']['closed_results'] = $DB->count_records('local_ustar_comp_results', ['competitionid' => $competitionid]) > 0;
    $result['competition']['closed_status'] = (string)$DB->get_field('local_ustar_competitions', 'status', ['id' => $competitionid]) === 'closed';
    $result['competition']['rule_version'] = (int)$DB->get_field('local_ustar_comp_results', 'ruleversionid', ['competitionid' => $competitionid]) > 0;
    $resultids = array_map('intval', array_keys($DB->get_records('local_ustar_comp_results', ['competitionid' => $competitionid], '', 'id')));
} finally {
    \core\session\manager::set_user($originaluser);
    if ($competitionid > 0) {
        $DB->delete_records('local_ustar_comp_results', ['competitionid' => $competitionid]);
        $DB->delete_records('local_ustar_comp_score_events', ['competitionid' => $competitionid]);
        $DB->delete_records('local_ustar_comp_participants', ['competitionid' => $competitionid]);
        $DB->delete_records('local_ustar_comp_rules', ['competitionid' => $competitionid]);
        $DB->delete_records('local_ustar_competitions', ['id' => $competitionid]);
    }
    foreach (array_unique($ledgerids) as $ledgerid) {
        $DB->delete_records('local_ustar_coin_ledger', ['id' => $ledgerid]);
    }
    if ($balancebefore) {
        $DB->update_record('local_ustar_coin_balance', $balancebefore);
    } else {
        $DB->delete_records('local_ustar_coin_balance', ['userid' => $employee->id]);
    }
}

$result['baseline_restored'] = $ledgerbefore === $DB->count_records('local_ustar_coin_ledger', ['userid' => $employee->id])
    && (($balancebefore && $DB->record_exists('local_ustar_coin_balance', ['id' => $balancebefore->id]))
        || (!$balancebefore && !$DB->record_exists('local_ustar_coin_balance', ['userid' => $employee->id])));
$result['runtime_boundary_pass'] = !in_array(false, $result['tables'], true)
    && !in_array(false, $result['ledger'], true)
    && !in_array(false, $result['competition'], true)
    && $result['baseline_restored'];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['runtime_boundary_pass'] ? 0 : 1);
