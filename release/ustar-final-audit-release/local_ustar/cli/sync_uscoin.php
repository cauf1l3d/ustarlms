<?php
// Legacy reconciliation report. TARGET USCOIN is never minted implicitly
// from learning or game events; rewards require an explicit approved ledger
// command and an accountable actor.
define('CLI_SCRIPT', true);
require(dirname(__DIR__, 3) . '/config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(['dry-run'=>false,'help'=>false], ['h'=>'help']);
if ($options['help']) {
    echo "php local/ustar/cli/sync_uscoin.php [--dry-run]\n";
    exit(0);
}
if (!\local_ustar\economy::available()) cli_error('USCOIN ledger unavailable. Run Moodle upgrade first.');

$courseaward = 50;
$would = 0;
foreach ($DB->get_records_select('course_completions', 'timecompleted IS NOT NULL', [], 'id ASC', 'id,userid,course,timecompleted') as $c) {
    if (!\local_ustar\accounts::participates((int)$c->userid)) continue;
    $key = 'course_completed:' . (int)$c->userid . ':' . (int)$c->course;
    if ($DB->record_exists('local_ustar_coin_ledger', ['idempotencykey'=>$key])) continue;
    $would++;
    // Conversion is intentionally disabled: this command is report-only.
}
if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_game_mastery'))) {
    foreach ($DB->get_records('local_ustar_game_mastery', [], 'id ASC') as $m) {
        if (!\local_ustar\accounts::participates((int)$m->userid)) continue;
        $key = 'game_mastery:' . (int)$m->userid . ':' . (int)$m->questionid;
        if ($DB->record_exists('local_ustar_coin_ledger', ['idempotencykey'=>$key])) continue;
        $would++;
        // Conversion is intentionally disabled: this command is report-only.
    }
}

echo 'Pending awards: ' . $would . PHP_EOL;
echo "DRY_RUN=YES\nUSCOIN_SYNC=REPORT_ONLY\n";
