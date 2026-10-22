<?php
// Isolated actual Moodle/PG fixture only. All state/opaque capabilities stay on private tmpfs.
define('CLI_SCRIPT', true);
require('/stage/moodle/config.php');
if ($CFG->dbname !== 'ustar_stage1' || $CFG->dbhost !== 'db' || $CFG->prefix !== 'stage_'
        || $CFG->dataroot !== '/stage/data/stage_' || is_dir('/opt/ustar')) {
    throw new RuntimeException('REFUSING_NON_ISOLATED_CALLBACK_FIXTURE');
}
use local_ustar\connect\{identity,identity_service,notification_action,notification_delivery,notification_preferences,bridge_service};
const CALLBACK_STATE = '/stage/con02-callback-state.json';
const CALLBACK_WIRE = '/stage/con02-callback-wire.json';
function cb_save(array $state): void {
    file_put_contents(CALLBACK_STATE,json_encode($state,JSON_THROW_ON_ERROR));chmod(CALLBACK_STATE,0600);
}
function cb_state(): array { return json_decode(file_get_contents(CALLBACK_STATE),true,512,JSON_THROW_ON_ERROR); }
function cb_snapshot(array $s): string {
    global $DB;
    return hash('sha256',json_encode([
        $DB->get_record('local_ustar_notifications',['id'=>$s['notice']]),
        $DB->get_record('local_ustar_learning_tasks',['id'=>$s['task']]),
        $DB->get_record('local_ustar_conn_links',['id'=>$s['link']]),
    ],JSON_THROW_ON_ERROR));
}
function cb_config(array $s): void {
    global $CFG;
    $CFG->ustar_connect_channels=['telegram'=>['tenant'=>'123456','username'=>'ustarcorp_bot']];
    $CFG->ustar_connect_ack_enabled=true;$CFG->ustar_connect_ack_since=time()-60;
    $CFG->ustar_connect_notifications_enabled=true;$CFG->ustar_connect_notify_since=time()-60;
    $CFG->ustar_connect_notify_userids=[$s['uid']];
}
function cb_notice(array $s,string $suffix): int {
    global $DB;
    $key='task-revised:'.$s['task'].':'.$suffix;
    \local_ustar\workflow_notifications::enqueue($s['uid'],'task_revised','PRIVATE CALLBACK TITLE',
        'PRIVATE CALLBACK BODY','/local/ustar/tasks.php?tab=assigned',$key);
    return (int)$DB->get_field('local_ustar_notifications','id',['idempotencykey'=>$key],MUST_EXIST);
}
$phase=$argv[1]??'';
if ($phase==='seed') {
    if (file_exists(CALLBACK_STATE)) { throw new RuntimeException('CALLBACK_STATE_EXISTS'); }
    require_once($CFG->dirroot.'/user/lib.php');
    $uid=(int)user_create_user((object)['username'=>'con02_callback_employee','auth'=>'manual','confirmed'=>1,'mnethostid'=>$CFG->mnet_localhost_id,
        'firstname'=>'Synthetic','lastname'=>'Callback','email'=>'callback@example.invalid',
        'password'=>hash_internal_user_password('Callback-Fixture-Only-Password1!')],false,false);
    $role=create_role('Callback fixture','con02_callback_fixture','Synthetic only');
    assign_capability('local/ustar:use',CAP_ALLOW,$role,\context_system::instance()->id);
    role_assign($role,$uid,\context_system::instance()->id);
    \core\session\manager::set_user(get_admin());
    $task=\local_ustar\task_workspace\service::create((int)get_admin()->id,$uid,
        ['kind'=>'task','title'=>'PRIVATE TASK','date'=>gmdate('Y-m-d',time()+DAYSECS),'clock'=>'17:00',
         'policy'=>\local_ustar\task_workspace\policy::validate(['timezone'=>'UTC','mode'=>'calendar'])]);
    \core\session\manager::set_user(get_complete_user_data('id',$uid));
    $c=identity_service::issue('telegram','123456');
    identity_service::claim_verified($c['code'],new identity('telegram','123456','77777'),hash('sha256',$c['code']));
    $link=identity_service::confirm($c['code']);
    $state=['uid'=>$uid,'task'=>$task,'link'=>(int)$link['id']];
    $state['notice']=cb_notice($state,'baseline');$state['snapshot']=cb_snapshot($state);cb_save($state);
    echo "CALLBACK_BASELINE_SEEDED\n";
} else if ($phase==='verify'||$phase==='restored') {
    $s=cb_state();$version=$phase==='verify'?2026102200:2026102100;
    $count=count(array_filter($DB->get_tables(),fn($t)=>str_starts_with($t,'local_ustar_')));
    if ((int)get_config('local_ustar','version')!==$version||$count!==($phase==='verify'?100:99)
            || cb_snapshot($s)!==$s['snapshot'] || ($CFG->ustar_connect_ack_enabled??false)!==false) {
        throw new RuntimeException('CALLBACK_UPGRADE_HISTORY_OR_DEFAULTS_MISMATCH');
    }
    echo "CALLBACK_".strtoupper($phase)."_PASS\n";
} else if ($phase==='wire') {
    $s=cb_state();cb_config($s);\core\session\manager::set_user(get_complete_user_data('id',$s['uid']));
    notification_preferences::save_own(['enabled'=>true,'categories'=>['tasks'],'min_severity'=>'normal',
        'quiet_start'=>'00:00','quiet_end'=>'00:00','timezone'=>'UTC','interval_minutes'=>1]);
    $v1id=cb_notice($s,'v1');$now=time();
    $v1lease=notification_delivery::lease('tg_v1','telegram','123456',$now);
    if (!$v1lease) { throw new RuntimeException('CALLBACK_V1_LEASE_MISSING'); }
    $v1=notification_delivery::begin($v1lease['delivery'],$v1lease['lease'],'tg_v1','telegram','123456',$now);
    notification_delivery::finish($v1lease['delivery'],$v1lease['lease'],'provider_accepted','991','',0,'tg_v1','telegram','123456');
    $v2id=cb_notice($s,'v2');$v2now=$now+61;
    $v2lease=notification_delivery::lease('tg_v2','telegram','123456',$v2now);
    if (!$v2lease) { throw new RuntimeException('CALLBACK_V2_LEASE_MISSING'); }
    $v2=notification_delivery::begin_v2($v2lease['delivery'],$v2lease['lease'],'tg_v2','telegram','123456',$v2now);
    if (!$v2['ack']) { throw new RuntimeException('CALLBACK_ACTION_MISSING'); }
    $s['wire_notice']=$v2id;$s['wire_delivery']=$v2lease['delivery'];cb_save($s);
    file_put_contents(CALLBACK_WIRE,json_encode(['v1'=>['lease'=>$v1lease,'permit'=>$v1,'now'=>$now],
        'v2'=>['lease'=>$v2lease,'permit'=>$v2,'now'=>$v2now]],JSON_THROW_ON_ERROR));chmod(CALLBACK_WIRE,0600);
    echo "CALLBACK_PRIVATE_WIRE_READY\n";
} else if ($phase==='dispatch') {
    $s=cb_state();cb_config($s);
    // No native user session: only authenticated provider adapter may resolve the recipient.
    \core\session\manager::set_user((object)['id'=>0]);
    $keys=[];
    foreach (['tg_v1'=>['11',['notification.lease','notification.begin','notification.finish']],
              'tg_v2'=>['22',['notification.lease','notification.begin_v2','notification.finish']],
              'tg_ack'=>['33',['notification.ack']]] as $id=>[$secret,$ops]) {
        $keys[$id]=['secret'=>str_repeat($secret,32),'operations'=>$ops,'provider'=>'telegram','tenant'=>'123456'];
    }
    $input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
    try { echo json_encode(['ok'=>true,'result'=>bridge_service::dispatch($input['body'],$input['headers'],$keys)],JSON_THROW_ON_ERROR); }
    catch (\InvalidArgumentException $error) { echo json_encode(['ok'=>false,'reason'=>$error->getMessage()],JSON_THROW_ON_ERROR); }
} else if ($phase==='status') {
    $s=cb_state();$notice=$DB->get_record('local_ustar_notifications',['id'=>$s['wire_notice']], 'status,ackat',MUST_EXIST);
    $d=$DB->get_record('local_ustar_notify_delivery',['id'=>$s['wire_delivery']], 'status,providerref',MUST_EXIST);
    echo json_encode(['notice'=>$notice,'delivery'=>$d],JSON_THROW_ON_ERROR);
} else { throw new RuntimeException('INVALID_CALLBACK_PHASE'); }
