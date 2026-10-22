<?php
// Disposable native2000→2200 history proof; no production config or provider call.
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
if ($CFG->dbhost !== 'db' || $CFG->dbname !== 'ustar_stage1' || $CFG->prefix !== 'stage_'
        || $CFG->dataroot !== '/stage/data/stage_' || is_dir('/opt/ustar')) {
    throw new RuntimeException('REFUSING_NON_ISOLATED_CON02_UPDATE');
}
const UPDATE_STATE = '/stage/con02-update-state.json';
function update_history(array $s): string {
    global $DB;
    return hash('sha256', json_encode([
        $DB->get_records_list('local_ustar_notifications', 'id', $s['notices'], 'id'),
        $DB->get_records_list('local_ustar_notify_delivery', 'notificationid', $s['notices'], 'id'),
        $DB->get_records('local_ustar_learning_tasks', ['id'=>$s['task']], 'id'),
        $DB->get_records('user_preferences', ['userid'=>$s['userid']], 'id'),
        $DB->get_records('local_ustar_conn_links', ['userid'=>$s['userid']], 'id'),
    ], JSON_THROW_ON_ERROR));
}
$phase = $argv[1] ?? '';
if ($phase === 'seed') {
    if ((int)get_config('local_ustar','version') !== 2026102000 || file_exists(UPDATE_STATE)) {
        throw new RuntimeException('INVALID_UPDATE_BASELINE');
    }
    // The accepted A.1 runner's private2000 pair already contains synthetic tasks,
    // preference, ACK and delivery rows. Reuse them without repeating that runner.
    $s = json_decode(file_get_contents('/stage/con02-ack-state.json'), true, 64, JSON_THROW_ON_ERROR);
    \core\session\manager::set_user(get_complete_user_data('id', $s['userid']));
    $links = $DB->get_records('local_ustar_conn_links', ['userid'=>$s['userid'],'state'=>'linked']);
    if (count($links) !== 1) { throw new RuntimeException('SYNTHETIC_LINK_REQUIRED'); }
    \local_ustar\connect\identity_service::revoke((int)reset($links)->id);
    $task = '\\local_ustar\\task\\prune_connect_identity';
    if (!$DB->record_exists('task_scheduled', ['classname'=>$task])) { throw new RuntimeException('PRUNE_TASK_REQUIRED'); }
    $DB->set_field('task_scheduled','disabled',1,['classname'=>$task]);
    $DB->set_field('task_scheduled','customised',1,['classname'=>$task]);
    $receipts = $DB->get_records_list('local_ustar_notify_delivery','notificationid',$s['notices']);
    $accepted = array_filter($receipts, fn($r)=>$r->status === 'provider_accepted');
    if (!$accepted) { throw new RuntimeException('SYNTHETIC_RECEIPT_REQUIRED'); }
    $DB->set_field('local_ustar_notify_delivery','providerref','con02-update-fixture-receipt',['id'=>(int)reset($accepted)->id]);
    if (!$DB->record_exists_select('local_ustar_notifications','userid=? AND ackat>0',[$s['userid']])) {
        throw new RuntimeException('NATIVE_ACK_HISTORY_REQUIRED');
    }
    $s['snapshot'] = update_history($s);
    file_put_contents(UPDATE_STATE,json_encode($s,JSON_THROW_ON_ERROR));chmod(UPDATE_STATE,0600);
    echo "CON02_UPDATE_BASELINE_SEEDED=PASS\n";
} else if (in_array($phase,['baseline','target','restored'],true)) {
    $s = json_decode(file_get_contents(UPDATE_STATE),true,64,JSON_THROW_ON_ERROR);
    $target = $phase === 'target';
    $count = count(array_filter($DB->get_tables(),fn($t)=>str_starts_with($t,'local_ustar_')));
    if ((int)get_config('local_ustar','version') !== ($target?2026102200:2026102000)
            || $count !== ($target?100:99) || update_history($s) !== $s['snapshot']
            || ($CFG->ustar_connect_ack_enabled??false) !== false
            || !$DB->record_exists('local_ustar_conn_links',['userid'=>$s['userid'],'state'=>'revoked'])) {
        throw new RuntimeException('CON02_UPDATE_HISTORY_VERSION_REVOKE_MISMATCH');
    }
    echo 'CON02_UPDATE_'.strtoupper($phase)."=PASS\n";
} else { throw new RuntimeException('CON02_UPDATE_PHASE_REQUIRED'); }
