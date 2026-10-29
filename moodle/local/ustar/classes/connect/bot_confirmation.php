<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Independent native-session confirmation for sensitive bot-prepared commands. */
final class bot_confirmation {
    public static function prepare(\stdClass $d,string $intent,array $payload,string $summary): array {
        $token=bot_store::action($d,$intent,$payload,$summary,true);
        return self::pending($d,$token,$summary);
    }
    private static function pending(\stdClass $d,string $token,string $summary): array {
        return bot_store::card('🔐 Подтвердите решение',$summary."\n\nОткройте защищённую Академию из офисной сети. Проверьте карточку и подтвердите действие своей учёткой. Срок — 15 минут.",[
            ['label'=>'Подтвердить в Академии','url'=>(new \moodle_url('/local/ustar/connect_bot_confirm.php',['action'=>$token]))->out(false)],
            bot_store::button($d,'Проверить результат','people.confirmation.status',['token'=>$token]),bot_service::home($d)]);
    }
    public static function lookup(string $token,int $userid): array {
        global $DB;
        $a=$DB->get_record('local_ustar_conn_actions',['tokenhash'=>bot_request::digest($token),'userid'=>$userid]);
        if (!$a || !in_array($a->status,['confirmation','done'],true) || $a->expiresat<=time()
                || !str_starts_with($a->intent,'execute.')) { throw new \InvalidArgumentException('expired_bot_action'); }
        $d=$DB->get_record('local_ustar_conn_dialogs',['id'=>$a->dialogid], '*', MUST_EXIST);
        $link=$DB->get_record('local_ustar_conn_links',['id'=>$d->linkid,'userid'=>$userid,'state'=>'linked']);
        if (!$link) { throw new \InvalidArgumentException('actor_unavailable'); }
        $sender=new identity($link->provider,$link->tenant,$link->subject);
        if (bot_actor::active($sender)!==$userid
                || !hash_equals($a->authority,bot_actor::authority($sender,$userid,$link))) {
            throw new \InvalidArgumentException('actor_unavailable');
        }
        return [$a,$d];
    }
    public static function status(\stdClass $d,string $token): array {
        [$a]=self::lookup($token,(int)$d->userid);
        return $a->status==='done'?json_decode($a->resultjson,true,32,JSON_THROW_ON_ERROR):self::pending($d,$token,$a->summary);
    }
    public static function confirm(string $token,int $userid): array {
        global $USER;
        if (!isloggedin() || isguestuser() || (int)$USER->id!==$userid
                || (defined('NO_MOODLE_COOKIES') && NO_MOODLE_COOKIES)
                || \core\session\manager::is_loggedinas() || \local_ustar\view_as::active()) {
            throw new \InvalidArgumentException('native_auth_required');
        }
        return identity_service::locked(function()use($token,$userid):array {
            global $DB;
            [$a,$d]=self::lookup($token,$userid);
            if ($a->status==='done') { return json_decode($a->resultjson,true,32,JSON_THROW_ON_ERROR); }
            $tx=$DB->start_delegated_transaction();
            try {
                bot_people::execute($a->intent,json_decode($a->payload,true,32,JSON_THROW_ON_ERROR),$userid);
                bot_store::state($d,'menu');
                $view=bot_store::card('✓ Решение сохранено','Действие выполнено в Академии. История и автор сохранены.',[bot_service::home($d)]);
                bot_store::done($a,$view);$tx->allow_commit();return $view;
            } catch(\Throwable $e) { $tx->rollback($e); }
        });
    }
}
