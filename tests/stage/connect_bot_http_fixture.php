<?php
// Disposable native-session/CSRF test only. No production/provider input.
if(getenv('USTAR_STAGE_PREFIX')!=='stage_' || !is_file('/stage/moodle/config.php')){throw new RuntimeException('ISOLATED_STAGE_REQUIRED');}
define('CLI_SCRIPT',true);
require '/stage/moodle/config.php';
$mode=$argv[1]??'';$state='/stage/connect-role-http.json';
if($mode==='seed') {
    $USER=get_admin();\core\session\manager::set_user($USER);
    \local_ustar\accounts::ensure_profile_field();
    $sender=new \local_ustar\connect\identity('telegram','123456','999777');
    $code=\local_ustar\connect\identity_service::issue('telegram','123456')['code'];
    \local_ustar\connect\identity_service::claim_verified($code,$sender,hash('sha256','isolated-role-http'));
    \local_ustar\connect\identity_service::confirm($code);
    $uid=\local_ustar\registration_service::register(['username'=>'connect_http_pending','password'=>'Fixture-Only-Qx9!2026',
        'firstname'=>'Synthetic','lastname'=>'HTTP pending','email'=>'connect.http@example.invalid','departmentid'=>'retail']);
    $request=$DB->get_record('local_ustar_staff_requests',['employeeid'=>$uid],'*',MUST_EXIST);
    $link=\local_ustar\connect\bot_actor::link($sender);
    $dialog=\local_ustar\connect\bot_store::dialog($sender,(int)$USER->id,$link);
    $view=\local_ustar\connect\bot_confirmation::prepare($dialog,'execute.staffing',[
        'values'=>['reviewcomment'=>'Native cookie CSRF fixture'],
        'context'=>['id'=>(int)$request->id,'decision'=>'rejected','stamp'=>hash('sha256',json_encode($request))]],
        'Отклонить синтетическую заявку: HTTP pending');
    foreach($view['buttons'] as $button){if(isset($button['url'])){parse_str(parse_url($button['url'],PHP_URL_QUERY),$query);}}
    file_put_contents($state,json_encode(['id'=>(int)$request->id,'actor'=>(int)$USER->id]));chmod($state,0600);
    echo $query['action'];
} else if($mode==='status') {
    $saved=json_decode(file_get_contents($state),true,16,JSON_THROW_ON_ERROR);
    $request=$DB->get_record('local_ustar_staff_requests',['id'=>$saved['id']],'*',MUST_EXIST);
    if($request->status==='rejected' && ((int)$request->reviewedby!==$saved['actor'] || $request->reviewcomment!=='Native cookie CSRF fixture')){
        throw new RuntimeException('WRONG_CANONICAL_ACTOR_OR_COMMENT');
    }
    echo $request->status;
} else {throw new RuntimeException('FIXTURE_MODE');}
