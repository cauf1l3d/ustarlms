<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Password-free chat onboarding around the canonical pending-account writer. */
final class bot_registration {
    public static function route(\stdClass $d,?\stdClass $link,string $intent,array $data,string $text): array {
        global $DB;
        if ($link) { return bot_service::menu($d,$link); }
        if($DB->record_exists('local_ustar_conn_links',['identityhash'=>$d->identityhash])){
            return bot_store::card('🔗 Восстановите привязку','Этот Telegram уже связывался с Академией. Получите новую персональную ссылку в «Мессенджерах». Повторная регистрация не нужна.');
        }
        if ($intent==='registration.start') {
            return bot_forms::start($d,'📝 Заявка в Академию',[
                ['key'=>'firstname','label'=>'Ваше имя','required'=>true],
                ['key'=>'lastname','label'=>'Ваша фамилия','required'=>true],
                ['key'=>'email','label'=>'Ваш email','type'=>'email','required'=>true],
                ['key'=>'username','label'=>'Логин для Академии','required'=>true],
                ['key'=>'departmentid','label'=>'Выберите подразделение','provider'=>'registration.departments','required'=>true],
            ],'registration.confirm');
        }
        if ($intent==='input' && $d->phase==='form.input') { return bot_forms::route($d,'form.input',[] ,['text'=>$text,'media'=>'']); }
        if (str_starts_with($intent,'form.')) { return bot_forms::route($d,$intent,$data,['text'=>$text,'media'=>'']); }
        return bot_service::menu($d,$link);
    }
    public static function commit(\stdClass $d,?\stdClass $link,identity $sender,array $data,
            string $event,string $payloadhash,?\stdClass $action): array {
        global $DB;
        if (!$action || $action->status!=='issued' || $link || (int)$d->userid) {
            return bot_service::menu($d,$link);
        }
        $existing=$DB->get_record('local_ustar_conn_signups',['identityhash'=>$sender->key()]);
        if ($existing) { return bot_service::menu($d,bot_actor::link($sender)); }
        $form=$data['values']??[];
        // No password ever enters Telegram, transport receipts or logs. The account is pending;
        // even after approval browser/password setup and bot native-policy checks remain mandatory.
        $form['password']='Aa9!'.bin2hex(random_bytes(32));
        $view=[];
        \local_ustar\registration_service::register($form,
            function(int $userid,int $requestid) use($d,$sender,$event,$payloadhash,$action,&$view):void {
                global $DB;
                $now=time();
                $linkid=(int)$DB->insert_record('local_ustar_conn_links',(object)[
                    'identityhash'=>$sender->key(),'userchannelhash'=>hash('sha256',$userid.':telegram'),
                    'userid'=>$userid,'provider'=>'telegram','tenant'=>$sender->tenant,'subject'=>$sender->subject,
                    'state'=>'linked','revision'=>1,'timecreated'=>$now,'timemodified'=>$now]);
                $DB->insert_record('local_ustar_conn_signups',(object)[
                    'identityhash'=>$sender->key(),'commandhash'=>$event,'payloadhash'=>$payloadhash,'keyid'=>'bot-role-v4',
                    'userid'=>$userid,'requestid'=>$requestid,'linkid'=>$linkid,'linkrevision'=>1,
                    'timecreated'=>$now,'expiresat'=>$now+30*DAYSECS]);
                set_user_preference('auth_forcepasswordchange',1,$userid);
                $d->linkid=$linkid; $d->linkrevision=1;
                $d->authority=bot_actor::authority($sender,0,(object)['id'=>$linkid,'revision'=>1]);
                bot_store::state($d,'menu');
                $view=bot_store::card('✓ Заявка отправлена','HRD проверит анкету. Первый пароль задаётся отдельно в Академии из офисной сети.',
                    [bot_store::button($d,'Обновить статус','menu')]);
                bot_store::done($action,$view);
                bot_service::remember($d,$event,$payloadhash,$view);
            });
        return $view;
    }
}
