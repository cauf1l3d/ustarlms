<?php
// Audited manual USCOIN correction. Requires a unique reason key.
define('CLI_SCRIPT', true);
require(dirname(__DIR__, 3) . '/config.php');
require_once($CFG->libdir . '/clilib.php');

[$o,$u] = cli_get_params(['username'=>null,'amount'=>null,'reason'=>null,'key'=>null,'actorusername'=>null,'apply'=>false,'plan-sha256'=>'','backup-ref'=>'','help'=>false], ['h'=>'help']);
if ($o['help'] || !$o['username'] || $o['amount'] === null || !$o['reason'] || !$o['key'] || !$o['actorusername']) {
    echo "php local/ustar/cli/adjust_uscoin.php --username=user --amount=25 --reason='...' --key=unique-key --actorusername=operator [--apply --plan-sha256=<SHA> --backup-ref=<backup ID>]\n";
    exit($o['help'] ? 0 : 2);
}
$user = $DB->get_record('user', ['username'=>(string)$o['username'],'deleted'=>0], 'id,username', MUST_EXIST);
$actor = $DB->get_record('user', ['username'=>(string)$o['actorusername'],'deleted'=>0], 'id,username', MUST_EXIST);
if (!has_capability('local/ustar:adjustcoin', context_system::instance(), (int)$actor->id)) {
    cli_error('Actor lacks the USCOIN operator capability.');
}
$amount = (int)$o['amount'];
if ($amount === 0) cli_error('amount must be non-zero');
$key = 'manual:' . clean_param((string)$o['key'], PARAM_ALPHANUMEXT);
$reason = trim((string)$o['reason']);
if ($key === 'manual:' || $reason === '') cli_error('Nonempty key and reason required.');
$manifest = [
    'userid' => (int)$user->id, 'actorid' => (int)$actor->id,
    'amount' => $amount, 'key' => $key, 'reason' => $reason,
];
$planhash = hash('sha256', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo 'TARGET_USER=' . (int)$user->id . ' AMOUNT=' . $amount . ' KEY=' . $key . PHP_EOL;
echo 'PLAN_SHA256=' . $planhash . PHP_EOL;
if (empty($o['apply'])) {
    echo "DRY_RUN=YES\n";
    exit(0);
}
if (!preg_match('/^[a-f0-9]{64}$/', (string)$o['plan-sha256'])
        || !hash_equals($planhash, (string)$o['plan-sha256'])
        || trim((string)$o['backup-ref']) === '') {
    cli_error('Apply requires an exact --plan-sha256 and --backup-ref.');
}
$ok = $amount > 0
    ? \local_ustar\economy::post((int)$user->id, $amount, 'manual_credit', $key, 'manual', '', (string)$o['reason'], (int)$actor->id)
    : \local_ustar\economy::spend((int)$user->id, abs($amount), 'manual_debit', $key, 'manual', '', (string)$o['reason'], (int)$actor->id);
if (!$ok) cli_error('Transaction not posted (duplicate key or ledger unavailable)');
echo "USCOIN_ADJUST=OK\n";
