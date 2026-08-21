<?php
// Idempotently seed USCOIN from already-completed learning/game events.
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
$would = 0; $posted = 0;
foreach ($DB->get_records_select('course_completions', 'timecompleted IS NOT NULL', [], 'id ASC', 'id,userid,course,timecompleted') as $c) {
    if (!\local_ustar\accounts::participates((int)$c->userid)) continue;
    $key = 'course_completed:' . (int)$c->userid . ':' . (int)$c->course;
    if ($DB->record_exists('local_ustar_coin_ledger', ['idempotencykey'=>$key])) continue;
    $would++;
    if (!$options['dry-run'] && \local_ustar\economy::post((int)$c->userid, $courseaward, 'course_completed', $key, 'course', (string)$c->course, 'Завершение курса')) $posted++;
}
if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_game_mastery'))) {
    foreach ($DB->get_records('local_ustar_game_mastery', [], 'id ASC') as $m) {
        if (!\local_ustar\accounts::participates((int)$m->userid)) continue;
        $key = 'game_mastery:' . (int)$m->userid . ':' . (int)$m->questionid;
        if ($DB->record_exists('local_ustar_coin_ledger', ['idempotencykey'=>$key])) continue;
        $award = max(1, (int)floor(((int)$m->xpearned) / 5));
        $would++;
        if (!$options['dry-run'] && \local_ustar\economy::post((int)$m->userid, $award, 'game_mastery', $key, 'question', (string)$m->questionid, 'Освоено игровое задание')) $posted++;
    }
}

echo 'Pending awards: ' . $would . PHP_EOL;
if ($options['dry-run']) echo "DRY_RUN=YES\nUSCOIN_SYNC=VALID\n";
else echo "Posted awards: {$posted}\nUSCOIN_SYNC=OK\n";
