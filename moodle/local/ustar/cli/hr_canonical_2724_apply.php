<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
require_once($CFG->dirroot . '/user/lib.php');

global $DB,$CFG,$USER;
$USER=get_admin();

$target=json_decode(
    file_get_contents('/var/www/html/public/local/ustar/data/hr_target_2724.json'),
    true,512,JSON_THROW_ON_ERROR
);

function c2724($v){
    if(is_array($v)){
        if(array_keys($v)!==range(0,count($v)-1))ksort($v);
        foreach($v as $k=>$x)$v[$k]=c2724($x);
    }
    return $v;
}
function h2724($v){
    return hash('sha256',json_encode(c2724($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function manifest2724(){
    global $DB;
    $st=\local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
    $r=[];foreach($DB->get_records('local_ustar_routes',null,'id ASC') as $x)$r[]=[(int)$x->id,(string)$x->positionid,(string)$x->departmentid,(int)$x->active,(string)$x->name];
    $p=[];foreach($DB->get_records('local_ustar_route_points',null,'id ASC') as $x)$p[]=[(int)$x->id,(int)$x->routeid,(string)$x->pointkey,(string)$x->phase,(int)$x->sortorder,(int)$x->active];
    $v=[];foreach($DB->get_records('local_ustar_route_versions',null,'id ASC') as $x)$v[]=[(int)$x->id,(int)$x->pointid,(int)$x->versionno,(string)$x->status,(string)$x->title,(string)$x->requirementsjson,(string)$x->renewalpolicy];
    $s=[];foreach($DB->get_records('local_ustar_route_scope',null,'id ASC') as $x)$s[]=[(int)$x->id,(int)$x->pointid,(string)$x->scopeid,(string)$x->state,(int)$x->active];
    return [
        'skills'=>h2724($st['skills']??[]),
        'matrix'=>h2724($st['matrix']??[]),
        'routes'=>h2724($r),
        'points'=>h2724($p),
        'versions'=>h2724($v),
        'scopes'=>h2724($s),
    ];
}
function assertmanifest2724(array $target,string $phase){
    $actual=manifest2724();
    foreach($target['protected_hashes'] as $k=>$expected){
        if(!isset($actual[$k])||!hash_equals($expected,$actual[$k])){
            throw new \RuntimeException($phase.'_PROTECTED_HASH_MISMATCH_'.strtoupper($k).' actual='.($actual[$k]??''));
        }
    }
}
function monthend2724(){
    $dt=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Moscow'));
    return $dt->modify('last day of this month')->setTime(23,59,59)->getTimestamp();
}
function password2724(){
    return 'Ustar!'.strtoupper(substr(bin2hex(random_bytes(8)),0,12));
}
function purge2724(int $uid){
    global $DB;

    $runids=$DB->get_fieldset_select('local_ustar_check_runs','id','userid=:u',['u'=>$uid]);
    if($runids){[$in,$p]=$DB->get_in_or_equal(array_map('intval',$runids),SQL_PARAMS_NAMED,'r');$DB->delete_records_select('local_ustar_check_answers','runid '.$in,$p);}
    $DB->delete_records('local_ustar_check_submits',['userid'=>$uid]);
    $DB->delete_records('local_ustar_check_runs',['userid'=>$uid]);

    $notids=$DB->get_fieldset_select('local_ustar_notifications','id','userid=:u',['u'=>$uid]);
    if($notids){[$in,$p]=$DB->get_in_or_equal(array_map('intval',$notids),SQL_PARAMS_NAMED,'n');$DB->delete_records_select('local_ustar_notify_delivery','notificationid '.$in,$p);}
    $DB->delete_records('local_ustar_notifications',['userid'=>$uid]);

    $evids=$DB->get_fieldset_select('local_ustar_evidence_rec','id','userid=:u',['u'=>$uid]);
    if($evids){[$in,$p]=$DB->get_in_or_equal(array_map('intval',$evids),SQL_PARAMS_NAMED,'e');$DB->delete_records_select('local_ustar_evidence_evt','evidenceid '.$in,$p);}
    $DB->delete_records('local_ustar_evidence_rec',['userid'=>$uid]);

    $ta=$DB->get_fieldset_select('local_ustar_test_attempts','id','userid=:u',['u'=>$uid]);
    if($ta){[$in,$p]=$DB->get_in_or_equal(array_map('intval',$ta),SQL_PARAMS_NAMED,'t');$DB->delete_records_select('local_ustar_test_answers','attemptid '.$in,$p);$DB->delete_records_select('local_ustar_test_results','attemptid '.$in,$p);}
    $DB->delete_records('local_ustar_test_results',['userid'=>$uid]);
    $DB->delete_records('local_ustar_test_attempts',['userid'=>$uid]);

    foreach([
        'local_ustar_route_progress','local_ustar_dev_assess_try','local_ustar_content_ack',
        'local_ustar_content_events','local_ustar_game_attempts','local_ustar_game_mastery',
        'local_ustar_coin_accounts','local_ustar_coin_balance','local_ustar_comp_participants',
        'local_ustar_goals','local_ustar_library','local_ustar_official_tasks',
        'local_ustar_personal_tasks','local_ustar_reviews','local_ustar_gate_decisions'
    ] as $t){
        if($DB->get_manager()->table_exists(new \xmldb_table($t)))$DB->delete_records($t,['userid'=>$uid]);
    }
    if($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_coin_ledger')))
        $DB->delete_records_select('local_ustar_coin_ledger','userid=:u OR actorid=:a',['u'=>$uid,'a'=>$uid]);
    if($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_comp_scores')))
        $DB->delete_records_select('local_ustar_comp_scores','userid=:u OR actorid=:a',['u'=>$uid,'a'=>$uid]);
    if($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_hr_actions')))
        $DB->delete_records_select('local_ustar_hr_actions','targetuserid=:u OR actorid=:a',['u'=>$uid,'a'=>$uid]);
    if($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_workflow_events')))
        $DB->delete_records('local_ustar_workflow_events',['actorid'=>$uid]);
    if($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_reporting')))
        $DB->delete_records_select('local_ustar_reporting','userid=:u OR managerid=:m',['u'=>$uid,'m'=>$uid]);

    $qu=$DB->get_fieldset_select('quiz_attempts','uniqueid','userid=:u',['u'=>$uid]);
    if($qu){
        $qu=array_values(array_unique(array_map('intval',$qu)));
        [$in,$p]=$DB->get_in_or_equal($qu,SQL_PARAMS_NAMED,'qu');
        $qaids=$DB->get_fieldset_select('question_attempts','id','questionusageid '.$in,$p);
        if($qaids){
            [$qain,$qap]=$DB->get_in_or_equal(array_map('intval',$qaids),SQL_PARAMS_NAMED,'qa');
            $steps=$DB->get_fieldset_select('question_attempt_steps','id','questionattemptid '.$qain,$qap);
            if($steps){[$sin,$sp]=$DB->get_in_or_equal(array_map('intval',$steps),SQL_PARAMS_NAMED,'qs');$DB->delete_records_select('question_attempt_step_data','attemptstepid '.$sin,$sp);}
            $DB->delete_records_select('question_attempt_steps','questionattemptid '.$qain,$qap);
            $DB->delete_records_select('question_attempts','questionusageid '.$in,$p);
        }
        $DB->delete_records_select('question_usages','id '.$in,$p);
    }
    $DB->delete_records('quiz_attempts',['userid'=>$uid]);
    $DB->delete_records('quiz_grades',['userid'=>$uid]);

    $sa=$DB->get_fieldset_select('scorm_attempt','id','userid=:u',['u'=>$uid]);
    if($sa){[$in,$p]=$DB->get_in_or_equal(array_map('intval',$sa),SQL_PARAMS_NAMED,'sa');$DB->delete_records_select('scorm_scoes_value','attemptid '.$in,$p);}
    $DB->delete_records('scorm_attempt',['userid'=>$uid]);

    foreach(['course_modules_completion','course_completions','course_completion_crit_compl','grade_grades_history','grade_grades','badge_issued','user_lastaccess','sessions'] as $t)
        $DB->delete_records($t,['userid'=>$uid]);

    $DB->delete_records_select('logstore_standard_log','userid=:u OR relateduserid=:r OR realuserid=:x',['u'=>$uid,'r'=>$uid,'x'=>$uid]);
    $DB->delete_records('local_ustar_assignments',['userid'=>$uid]);
}

echo "=== USTAR HR CANONICAL 2724 APPLY ===\n";
assertmanifest2724($target,'PRE');

$u2=$DB->get_record('user',['id'=>2],'id,username,deleted,suspended',MUST_EXIST);
$u87=$DB->get_record('user',['id'=>87],'id,username,deleted,suspended',MUST_EXIST);
if(strtolower((string)$u2->username)!=='emheadabdurahman')throw new \RuntimeException('U2_PROTECTION_MISMATCH');
if(strtolower((string)$u87->username)!=='ustar.academ')throw new \RuntimeException('U87_PROTECTION_MISMATCH');

foreach($target['delete_users'] as $d){
    $u=$DB->get_record('user',['id'=>(int)$d['id']],'id,username,deleted',MUST_EXIST);
    if(!empty($u->deleted)||strtolower((string)$u->username)!==strtolower((string)$d['username']))
        throw new \RuntimeException('DELETE_GUARD_MISMATCH_U'.$d['id']);
}
foreach($target['new_users'] as $n){
    if($DB->record_exists('user',['username'=>$n['username'],'deleted'=>0]))
        throw new \RuntimeException('NEW_USERNAME_CONFLICT_'.$n['username']);
}

$tx=$DB->start_delegated_transaction();
$credentials=[];
try{
    // Add only genuinely new positions. Existing route/skill position IDs remain untouched.
    $st=\local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
    $pm=\local_ustar\people::position_map($st);
    foreach($target['new_positions'] as $p){
        if(isset($pm[$p['id']]))throw new \RuntimeException('NEW_POSITION_CONFLICT_'.$p['id']);
        $st['positions'][]=[
            'id'=>$p['id'],'department'=>$p['departmentid'],'name'=>$p['name'],
            'level'=>1,'next'=>null,'ishead'=>!empty($p['ishead']),
            'accessprofile'=>$p['accessprofile']
        ];
    }
    \local_ustar\structure::save(\local_ustar\structure::NAME_STRUCTURE,$st);

    // New accounts. Temporary credentials are printed once after commit.
    $newids=[];
    foreach($target['new_users'] as $key=>$n){
        $password=password2724();
        $result=\local_ustar\hr_people::save([
            'userid'=>0,'username'=>$n['username'],'firstname'=>$n['firstname'],
            'lastname'=>$n['lastname'],'email'=>$n['username'].'@lms.ustar.local',
            'positionid'=>'','accounttype'=>\local_ustar\accounts::TYPE_EMPLOYEE,
            'suspended'=>0,'password'=>$password
        ],(int)$USER->id);
        $newids[$key]=(int)$result['userid'];
        $credentials[]=['userid'=>(int)$result['userid'],'username'=>$n['username'],
            'fullname'=>$n['lastname'].' '.$n['firstname'],'password'=>$password];
    }

    // Approved obsolete Academy accounts.
    foreach($target['delete_users'] as $d){
        $uid=(int)$d['id'];
        purge2724($uid);
        $u=$DB->get_record('user',['id'=>$uid,'deleted'=>0],'*',MUST_EXIST);
        if(!delete_user($u))throw new \RuntimeException('DELETE_USER_FAILED_U'.$uid);
    }

    // Ensure all target StaffPlaces exist.
    $seatids=[];
    foreach($target['seats'] as $s){
        if(!empty($s['staffplaceid'])){
            $place=$DB->get_record('local_ustar_staff_places',['id'=>(int)$s['staffplaceid']],'*',MUST_EXIST);
            $place->positionid=$s['positionid'];$place->departmentid=$s['departmentid'];
            $place->active=1;$place->timemodified=time();$place->usermodified=(int)$USER->id;
            $DB->update_record('local_ustar_staff_places',$place);
            $seatids[$s['seatkey']]=(int)$place->id;
        }else{
            $now=time();
            $id=(int)$DB->insert_record('local_ustar_staff_places',(object)[
                'placecode'=>'hr2724_'.$s['seatkey'],'positionid'=>$s['positionid'],
                'departmentid'=>$s['departmentid'],'managerplaceid'=>null,'active'=>1,
                'effectivefrom'=>$now,'effectiveto'=>null,'timecreated'=>$now,
                'timemodified'=>$now,'usermodified'=>(int)$USER->id
            ]);
            $seatids[$s['seatkey']]=$id;
        }
    }
    foreach($target['seats'] as $s){
        $place=$DB->get_record('local_ustar_staff_places',['id'=>$seatids[$s['seatkey']]],'*',MUST_EXIST);
        $place->managerplaceid=$s['managerseat']===null?null:(int)$seatids[$s['managerseat']];
        $place->timemodified=time();$place->usermodified=(int)$USER->id;
        $DB->update_record('local_ustar_staff_places',$place);
    }

    // Close old active assignment projections, preserve as historical rows.
    $now=time();
    foreach($DB->get_records('local_ustar_assignments',['status'=>'active']) as $a){
        $a->status='ended';$a->effectiveto=$now;$a->timemodified=$now;
        $a->usermodified=(int)$USER->id;
        if(property_exists($a,'autorenew'))$a->autorenew=0;
        $DB->update_record('local_ustar_assignments',$a);
    }

    // Canonical PRIMARY/ACTING assignments.
    $primary=[];
    foreach($target['seats'] as $s){
        if($s['occupant']===null)continue;
        $uid=is_int($s['occupant'])?(int)$s['occupant']:(int)$newids[$s['occupant']];
        $acting=(string)$s['assignmenttype']==='acting';
        $DB->insert_record('local_ustar_assignments',(object)[
            'staffplaceid'=>(int)$seatids[$s['seatkey']],'userid'=>$uid,
            'assignmenttype'=>$acting?'acting':'primary','status'=>'active',
            'effectivefrom'=>$now,'effectiveto'=>$acting?monthend2724():null,
            'timecreated'=>$now,'timemodified'=>$now,'usermodified'=>(int)$USER->id,
            'autorenew'=>$acting?1:0
        ]);
        if(!$acting)$primary[$uid]=(string)$s['positionid'];
    }

    foreach($primary as $uid=>$pid){
        if((int)$uid===2)continue; // protect U2 profile/credentials/admin projection.
        \local_ustar\people::set_position_id((int)$uid,$pid);
    }

    $report=\local_ustar\organization_model::rebuild_reporting();

    foreach($primary as $uid=>$pid){
        if((int)$uid===2)continue;
        \local_ustar\position_access::sync_user((int)$uid);
        try{\local_ustar\assignment::sync_user((int)$uid);}
        catch(\Throwable $e){
            \local_ustar\people::log_action((int)$USER->id,(int)$uid,
                'hr2724_assignment_sync_failed',['message'=>$e->getMessage()]);
        }
    }
    foreach($target['seats'] as $s){
        if((string)$s['assignmenttype']!=='acting'||$s['occupant']===null)continue;
        $uid=is_int($s['occupant'])?(int)$s['occupant']:(int)$newids[$s['occupant']];
        \local_ustar\position_access::sync_user($uid);
    }

    assertmanifest2724($target,'POST');

    $places=$DB->count_records('local_ustar_staff_places',['active'=>1]);
    $assign=$DB->count_records('local_ustar_assignments',['status'=>'active']);
    $unique=(int)$DB->get_field_sql("SELECT COUNT(DISTINCT userid) FROM {local_ustar_assignments} WHERE status='active'");
    $acting=(int)$DB->count_records('local_ustar_assignments',['status'=>'active','assignmenttype'=>'acting']);

    if($places!==90)throw new \RuntimeException('ACTIVE_STAFF_PLACES='.$places);
    if($assign!==76)throw new \RuntimeException('ACTIVE_ASSIGNMENTS='.$assign);
    if($unique!==75)throw new \RuntimeException('UNIQUE_ACTIVE_PEOPLE='.$unique);
    if($acting!==1)throw new \RuntimeException('ACTING_ASSIGNMENTS='.$acting);

    if(\local_ustar\people::position_id(31)!=='pos_8dd9749f07a5bc80')throw new \RuntimeException('KALINA_POSITION_CHANGED');
    if(\local_ustar\people::position_id(88)!=='pos_4551dd63496af62f')throw new \RuntimeException('U88_POSITION_BAD');
    if(\local_ustar\people::position_id(62)!=='pos_aef1f0c3fddd2fa5')throw new \RuntimeException('YUSUP_PRIMARY_BAD');
    if(\local_ustar\people::position_id(66)!=='pos_0c43f1c98cfc4351')throw new \RuntimeException('LOGISTICS_HEAD_BAD');

    $ys=\local_ustar\organization_model::manager_scope(62);
    $required=[51,52,53,54,55,56];
    $actual=array_values(array_intersect($required,array_map('intval',$ys['userids']??[])));
    sort($actual);
    if($actual!==$required)throw new \RuntimeException('YUSUP_SCOPE_BAD='.json_encode($actual));

    $u2a=$DB->get_record('user',['id'=>2],'id,username,deleted,suspended',MUST_EXIST);
    $u87a=$DB->get_record('user',['id'=>87],'id,username,deleted,suspended',MUST_EXIST);
    if(strtolower((string)$u2a->username)!=='emheadabdurahman'||!empty($u2a->deleted))throw new \RuntimeException('PROTECTED_U2_CHANGED');
    if(strtolower((string)$u87a->username)!=='ustar.academ'||!empty($u87a->deleted))throw new \RuntimeException('PROTECTED_U87_CHANGED');

    $tx->allow_commit();

    echo "HR2724_COMMITTED\n";
    echo "ACTIVE_STAFF_PLACES={$places}\nACTIVE_ASSIGNMENTS={$assign}\nUNIQUE_ACTIVE_PEOPLE={$unique}\nACTING_ASSIGNMENTS={$acting}\n";
    echo "REPORTING_LINES=".($report['count']??0)."\n";
    echo "YUSUP_SCOPE=".implode(',',array_map('intval',$ys['userids']??[]))."\n";
    echo "=== NEW USER CREDENTIALS ===\n";
    foreach($credentials as $c)
        echo "U".$c['userid']." | ".$c['username']." | ".$c['fullname']." | TEMP_PASSWORD=".$c['password']."\n";
    echo "PROTECTED_HASHES=UNCHANGED\nPROTECTED_U2=UNCHANGED\nPROTECTED_U87=UNCHANGED\nHR_CANONICAL_2724_ACTIVE\n";
}catch(\Throwable $e){
    try{$tx->rollback($e);}catch(\Throwable $ignored){}
    fwrite(STDERR,"HR2724_ROLLED_BACK | ".get_class($e)." | ".$e->getMessage()."\n");
    exit(10);
}
