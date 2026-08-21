<?php
// Audited manual USCOIN correction. Requires a unique reason key.
define('CLI_SCRIPT', true);
require(dirname(__DIR__, 3) . '/config.php');
require_once($CFG->libdir . '/clilib.php');

[$o,$u] = cli_get_params(['username'=>null,'amount'=>null,'reason'=>null,'key'=>null,'help'=>false], ['h'=>'help']);
if ($o['help'] || !$o['username'] || $o['amount'] === null || !$o['reason'] || !$o['key']) {
    echo "php local/ustar/cli/adjust_uscoin.php --username=user --amount=25 --reason='...' --key=unique-key\n";
    exit($o['help'] ? 0 : 2);
}
$user = $DB->get_record('user', ['username'=>(string)$o['username'],'deleted'=>0], 'id,username', MUST_EXIST);
$amount = (int)$o['amount'];
if ($amount === 0) cli_error('amount must be non-zero');
$key = 'manual:' . clean_param((string)$o['key'], PARAM_ALPHANUMEXT);
$ok = \local_ustar\economy::post((int)$user->id, $amount, 'manual', $key, 'manual', '', (string)$o['reason']);
if (!$ok) cli_error('Transaction not posted (duplicate key or ledger unavailable)');
echo "USCOIN_ADJUST=OK\n";
