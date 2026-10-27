<?php
// Controlled DB barriers in disposable Moodle; raw action travels only on private stdin.
namespace local_ustar { function time(): int { return $GLOBALS['callback_clock'] ?? \time(); } }
namespace {
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
if ($CFG->dbhost !== 'db' || $CFG->dbname !== 'ustar_stage1' || $CFG->prefix !== 'stage_'
        || __DIR__ !== '/source/tests/stage' || is_dir('/opt/ustar')) { throw new RuntimeException('ISOLATED_CALLBACK_RACE_REQUIRED'); }
use local_ustar\connect\{identity,identity_service,notification_action};
$s=json_decode(file_get_contents('/stage/con02-callback-state.json'),true,512,JSON_THROW_ON_ERROR);
$CFG->ustar_connect_ack_enabled=true;$CFG->ustar_connect_ack_since=time()-DAYSECS;
$CFG->ustar_connect_notify_userids=[$s['uid']];
if (($argv[1]??'')==='child') {
    $v=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    $GLOBALS['callback_clock']=$v['clock'];
    $DB->get_field_sql("SELECT set_config('application_name', :name, false)",['name'=>$v['name']]);
    try {
        if ($v['op']==='callback') { notification_action::acknowledge($v['action'],new identity('telegram','123456','77777'),'992',time()); }
        else if ($v['op']==='native') { \local_ustar\target_core::acknowledge_notification($v['id'],$s['uid']); }
        else if ($v['op']==='read') { \local_ustar\communication::mark_notification($s['uid'],$v['id']); }
        else if ($v['op']==='revoke') {
            \core\session\manager::set_user(get_complete_user_data('id',$s['uid']));identity_service::revoke($s['link']);
        } else { throw new RuntimeException('UNKNOWN_RACE_OPERATION'); }
        echo json_encode(['status'=>'done']);
    } catch (\InvalidArgumentException $e) { echo json_encode(['status'=>$e->getMessage()]); }
    exit;
}
function cb_child(array $v): array {
    $p=proc_open([PHP_BINARY,__FILE__,'child'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($p)) { throw new RuntimeException('CALLBACK_CHILD_UNAVAILABLE'); }
    fwrite($pipes[0],json_encode($v,JSON_THROW_ON_ERROR));fclose($pipes[0]);return [$p,$pipes];
}
function cb_wait(string $name, bool $rowlock = true): void {
    global $DB;$end=microtime(true)+8;
    do {
        if ($DB->get_field_sql("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name=:name".
                ($rowlock ? " AND wait_event_type='Lock'" : ''),['name'=>$name])) { return; }
        usleep(20000);
    } while (microtime(true)<$end);
    throw new RuntimeException('CALLBACK_RACE_BARRIER_TIMEOUT');
}
function cb_done(array $w,string $expected): void {
    [$p,$pipes]=$w;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
    if ($code!==0 || $err!=='' || json_decode($out,true)!==['status'=>$expected]) { throw new RuntimeException('CALLBACK_RACE_CHILD_FAILED: '.$err.' '.$out); }
}
$ddl=pg_connect('host=db dbname=ustar_stage1 user=ustar_fixture',PGSQL_CONNECT_FORCE_NEW);
if (!$ddl) { throw new RuntimeException('CALLBACK_RACE_DDL_UNAVAILABLE'); }
$proof=[];$taskbefore=$DB->get_record('local_ustar_learning_tasks',['id'=>$s['task']]);
foreach (['native','read','revoke'] as $other) {
    $now=time();$firsttime=$now-120;
    $id=\local_ustar\target_core::notify(['userid'=>$s['uid'],'eventtype'=>'task_assigned','severity'=>'action',
        'subject'=>'Synthetic race','message'=>'Private race','idempotencykey'=>'callback-race-'.bin2hex(random_bytes(8))]);
    $notice=$DB->get_record('local_ustar_notifications',['id'=>$id], '*',MUST_EXIST);
    $link=$DB->get_record('local_ustar_conn_links',['id'=>$s['link']], '*',MUST_EXIST);
    $did=$DB->insert_record('local_ustar_notify_delivery',(object)['notificationid'=>$id,'channel'=>'telegram','status'=>'sending',
        'attempts'=>1,'connectversion'=>1,'connlinkid'=>$link->id,'connrevision'=>$link->revision,
        'connexpires'=>$now+3600,'timecreated'=>$now,'timemodified'=>$now]);
    $delivery=$DB->get_record('local_ustar_notify_delivery',['id'=>$did], '*',MUST_EXIST);
    $action=identity_service::atomic(fn()=>notification_action::issue($notice,$delivery,$link,$now));
    if (!$action) { throw new RuntimeException('CALLBACK_RACE_ACTION_MISSING'); }
    $suffix=bin2hex(random_bytes(5));$name='cb_race_'.$suffix;$lock=random_int(100000,2000000000);
    $first='cb_first_'.$suffix;$second='cb_second_'.$suffix;
    $table=$CFG->prefix.($other==='revoke'?'local_ustar_conn_links':'local_ustar_notifications');
    $target=$other==='revoke'?$s['link']:$id;$workers=[];$locked=false;
    try {
        $sql="CREATE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
            IF NEW.id=$target AND current_setting('application_name')='$first' THEN PERFORM pg_advisory_xact_lock($lock); END IF;
            RETURN NEW; END; \$\$";
        if (!pg_query($ddl,$sql)||!pg_query($ddl,"CREATE TRIGGER $name BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION $name()")) {
            throw new RuntimeException('CALLBACK_RACE_DDL_FAILED');
        }
        $DB->get_field_sql('SELECT pg_advisory_lock(:key)',['key'=>$lock]);$locked=true;
        $common=['id'=>$id,'action'=>$action['action']];
        $workers[]=cb_child($common+['op'=>$other==='revoke'?'revoke':'callback','name'=>$first,'clock'=>$firsttime]);cb_wait($first);
        $workers[]=cb_child($common+['op'=>$other==='revoke'?'callback':$other,'name'=>$second,'clock'=>$now]);
        // Native/read must reach the held row. For revoke, prove the other DB request
        // starts before commit; Moodle's identity lock factory may poll nonblocking.
        cb_wait($second, $other!=='revoke');
        $DB->get_field_sql('SELECT pg_advisory_unlock(:key)',['key'=>$lock]);$locked=false;
        cb_done($workers[0],'done');cb_done($workers[1],$other==='revoke'?'ack_unavailable':'done');$workers=[];
        $row=$DB->get_record('local_ustar_notifications',['id'=>$id]);
        if ($other==='revoke' ? ($row->status!=='unread'||$row->ackat!==null)
                : ($row->status!=='acknowledged'||(int)$row->ackat!==$firsttime)) { throw new RuntimeException('CALLBACK_RACE_RECEIPT_CHANGED'); }
        if ($DB->get_record('local_ustar_notify_delivery',['id'=>$did])!=$delivery
                || $DB->get_record('local_ustar_learning_tasks',['id'=>$s['task']])!=$taskbefore) {
            throw new RuntimeException('CALLBACK_RACE_BUSINESS_OR_TRANSPORT_CHANGED');
        }
        $proof[]=['other'=>$other,'first_receipt_preserved'=>$other!=='revoke','revoke_denies_later_callback'=>$other==='revoke'];
    } finally {
        if ($locked) { $DB->get_field_sql('SELECT pg_advisory_unlock(:key)',['key'=>$lock]); }
        foreach ($workers as [$p,$pipes]) { proc_terminate($p);foreach($pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}}proc_close($p); }
        pg_query($ddl,"DROP TRIGGER IF EXISTS $name ON $table");pg_query($ddl,"DROP FUNCTION IF EXISTS $name()");
    }
}
pg_close($ddl);file_put_contents('/artifacts/con02-callback-concurrency.json',json_encode($proof,JSON_PRETTY_PRINT)."\n");
echo "CON02_CALLBACK_NATIVE_RACES=PASS cases=3\n";
}
