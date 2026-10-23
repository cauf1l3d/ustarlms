<?php
// Disposable native lifecycle only. This file refuses every production-shaped runtime.
define('CLI_SCRIPT', true);
require '/stage/moodle/config.php';
require_once($CFG->dirroot.'/user/lib.php');
if ($CFG->dbhost !== 'db' || $CFG->dbname !== 'ustar_stage1' || $CFG->prefix !== 'stage_'
        || $CFG->dataroot !== '/stage/data/stage_' || is_dir('/opt/ustar')) {
    throw new RuntimeException('REFUSING_NON_ISOLATED_PRESENTATION_FIXTURE');
}
use local_ustar\connect\{identity,identity_service,notification_preferences};
const TG_STATE = '/stage/telegram-presentation-state.json';
function tg_snapshot(array $s): string {
    global $DB;
    return hash('sha256', json_encode([
        $DB->get_record('local_ustar_learning_tasks',['id'=>$s['task']]),
        $DB->get_record('local_ustar_notifications',['id'=>$s['notice']]),
        $DB->get_records('local_ustar_notify_delivery',['notificationid'=>$s['notice']],'id'),
        $DB->get_records('local_ustar_conn_links',['userid'=>$s['uid']],'id'),
        $DB->get_record('user_preferences',['userid'=>$s['uid'],'name'=>notification_preferences::NAME]),
    ], JSON_THROW_ON_ERROR));
}
$mode=$argv[1]??'';
if ($mode==='seed') {
    if (file_exists(TG_STATE) || (int)get_config('local_ustar','version')!==2026102200) {
        throw new RuntimeException('PRESENTATION_BASELINE_REQUIRED');
    }
    $uid=(int)user_create_user((object)['username'=>'presentation_employee','auth'=>'manual','confirmed'=>1,
        'mnethostid'=>$CFG->mnet_localhost_id,'firstname'=>'Synthetic','lastname'=>'Presentation',
        'email'=>'presentation@example.invalid'],false,false);
    $role=create_role('Presentation fixture','presentation_fixture','Synthetic only');
    assign_capability('local/ustar:use',CAP_ALLOW,$role,\context_system::instance()->id);
    role_assign($role,$uid,\context_system::instance()->id);
    \core\session\manager::set_user(get_admin());
    $task=\local_ustar\task_workspace\service::create((int)get_admin()->id,$uid,
        ['kind'=>'task','title'=>'Private retained presentation task','date'=>gmdate('Y-m-d',time()+DAYSECS),
         'clock'=>'17:00','policy'=>\local_ustar\task_workspace\policy::validate(['timezone'=>'UTC','mode'=>'calendar'])]);
    \core\session\manager::set_user(get_complete_user_data('id',$uid));
    $c=identity_service::issue('telegram','123456');
    identity_service::claim_verified($c['code'],new identity('telegram','123456','77990001'),hash('sha256',$c['code']));
    identity_service::confirm($c['code']);
    notification_preferences::save_own(['enabled'=>true,'categories'=>['tasks'],'min_severity'=>'normal',
        'quiet_start'=>'22:00','quiet_end'=>'08:00','timezone'=>'Europe/Moscow','interval_minutes'=>5]);
    $notice=\local_ustar\target_core::notify(['userid'=>$uid,'severity'=>'normal','eventtype'=>'task_assigned',
        'subject'=>'Private presentation fixture','message'=>'Never sent','idempotencykey'=>'presentation-retained']);
    $DB->insert_record('local_ustar_notify_delivery',(object)['notificationid'=>$notice,'channel'=>'telegram',
        'status'=>'provider_accepted','attempts'=>1,'providerref'=>'998','connectversion'=>1,'timecreated'=>time(),'timemodified'=>time()]);
    \local_ustar\target_core::acknowledge_notification($notice,$uid);
    $s=['uid'=>$uid,'task'=>$task,'notice'=>$notice];$s['hash']=tg_snapshot($s);
    file_put_contents(TG_STATE,json_encode($s,JSON_THROW_ON_ERROR));chmod(TG_STATE,0600);
    echo "PRESENTATION_BASELINE_2200_SEEDED\n";
} else if (in_array($mode,['target','restored'],true)) {
    $s=json_decode(file_get_contents(TG_STATE),true,512,JSON_THROW_ON_ERROR);
    $expected=$mode==='target'?2026102300:2026102200;
    if ((int)get_config('local_ustar','version')!==$expected || tg_snapshot($s)!==$s['hash']
            || count(array_filter($DB->get_tables(),fn($t)=>str_starts_with($t,'local_ustar_')))!==100
            || ($CFG->ustar_connect_notification_presentation_enabled??false)!==false) {
        throw new RuntimeException('PRESENTATION_HISTORY_OR_DEFAULT_CHANGED');
    }
    \core\session\manager::set_user(get_complete_user_data('id',$s['uid']));
    $prefs=notification_preferences::for_user($s['uid']);
    if (!$prefs['enabled']||$prefs['timezone']!=='Europe/Moscow'||$prefs['interval_minutes']!==5) {
        throw new RuntimeException('PRESENTATION_PREFERENCES_CHANGED');
    }
    if ($mode==='target') {
        $own=\local_ustar\connect\notification_delivery::own_summary();
        if ($own[0]['notification_status']!=='acknowledged'||$own[0]['acknowledged_at']<=0
                ||$own[0]['status']!=='provider_accepted') { throw new RuntimeException('PRESENTATION_RECEIPTS_MERGED'); }
    }
    echo 'PRESENTATION_'.strtoupper($mode).'_HISTORY_VERIFIED'."\n";
} else { throw new RuntimeException('PRESENTATION_MODE_INVALID'); }
