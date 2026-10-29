<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Short-lived dialogs and action/update receipts only. Domain state stays in its canonical tables. */
final class bot_store {
    public const TTL=900;
    public static function json(array $value): string {
        return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    public static function dialog(identity $sender, int $userid, ?\stdClass $link): \stdClass {
        global $DB;
        $authority=bot_actor::authority($sender,$userid,$link);
        $d=$DB->get_record('local_ustar_conn_dialogs',['identityhash'=>$sender->key()]);
        if (!$d) {
            $d=(object)['identityhash'=>$sender->key(),'userid'=>$userid,'linkid'=>(int)($link->id??0),
                'linkrevision'=>(int)($link->revision??0),'authority'=>$authority,'revision'=>1,'phase'=>'menu','payload'=>'{}',
                'expiresat'=>time()+self::TTL,'ratewindow'=>time(),'ratecount'=>0,'timemodified'=>time()];
            $d->id=$DB->insert_record('local_ustar_conn_dialogs',$d);
        }
        if ((int)$d->userid!==$userid || (int)$d->linkid!==(int)($link->id??0)
                || (int)$d->linkrevision!==(int)($link->revision??0) || !hash_equals($d->authority,$authority)
                || (int)$d->expiresat<=time()) {
            $d->userid=$userid; $d->linkid=(int)($link->id??0); $d->linkrevision=(int)($link->revision??0);
            $d->authority=$authority; $d->phase='menu'; $d->payload='{}'; $d->revision++;
        }
        return $d;
    }
    public static function save(\stdClass $d): void {
        global $DB;
        $d->expiresat=time()+self::TTL; $d->timemodified=time();
        $DB->update_record('local_ustar_conn_dialogs',$d);
    }
    public static function state(\stdClass $d, string $phase, array $value=[]): void {
        if($phase==='menu'){self::clear_drafts($d);}
        $d->phase=$phase; $d->payload=self::json($value); $d->revision++; self::save($d);
    }
    public static function clear_drafts(\stdClass $d): void {
        if((int)$d->userid>0) {
            get_file_storage()->delete_area_files(\context_user::instance((int)$d->userid)->id,
                'local_ustar','connectbotdraft',(int)$d->id);
        }
    }
    public static function data(\stdClass $d): array { return json_decode($d->payload,true,32,JSON_THROW_ON_ERROR); }
    public static function action(\stdClass $d, string $intent, array $payload=[], string $summary='', bool $native=false): string {
        global $DB;
        $token=bot_request::token();
        $DB->insert_record('local_ustar_conn_actions',(object)['tokenhash'=>bot_request::digest($token),
            'dialogid'=>$d->id,'userid'=>$d->userid,'authority'=>$d->authority,'revision'=>$d->revision,'intent'=>$intent,
            'payload'=>self::json($payload),'summary'=>$summary,'status'=>$native?'confirmation':'issued',
            'resultjson'=>'{}','expiresat'=>time()+self::TTL,'timecreated'=>time()]);
        return $token;
    }
    public static function resolve(\stdClass $d, string $token): \stdClass {
        global $DB;
        $a=$DB->get_record('local_ustar_conn_actions',['tokenhash'=>bot_request::digest($token)]);
        if (!$a || (int)$a->dialogid!==(int)$d->id || (int)$a->userid!==(int)$d->userid
                || !hash_equals($a->authority,$d->authority) || (int)$a->expiresat<=time()
                || ($a->status!=='done' && (int)$a->revision!==(int)$d->revision)) {
            throw new \InvalidArgumentException('expired_bot_action');
        }
        return $a;
    }
    public static function button(\stdClass $d, string $label, string $intent, array $data=[]): array {
        return ['label'=>$label,'callback'=>self::action($d,$intent,$data)];
    }
    public static function card(string $title, string $text='', array $buttons=[], array $metrics=[], array $rows=[]): array {
        $title=\core_text::substr($title,0,120);$text=\core_text::substr($text,0,2400);
        foreach($buttons as &$button){$button['label']=\core_text::substr($button['label'],0,52);}unset($button);
        $rows=array_slice($rows,0,12);
        foreach($rows as &$row){$row=[\core_text::substr((string)$row[0],0,60),\core_text::substr((string)$row[1],0,160)];}unset($row);
        $view=['title'=>$title,'text'=>$text,'buttons'=>$buttons,'metrics'=>$metrics,'rows'=>$rows];
        // Match the existing16KiB private bridge. Optional table/detail text gives way before actions.
        while(strlen(self::json($view))>14000 && $view['rows']){array_pop($view['rows']);}
        if(strlen(self::json($view))>14000){$view['text']=\core_text::substr($text,0,800)."\nТекст сокращён. Полная запись доступна в Академии.";}
        if(strlen(self::json($view))>14000){throw new \InvalidArgumentException('invalid_bot_action');}
        return $view;
    }
    public static function guarded(array $view,array $guard): array { return $view+['_guard'=>$guard]; }
    public static function done(\stdClass $action, array $view): void {
        global $DB;
        $action->status='done'; $action->resultjson=self::json($view);
        $DB->update_record('local_ustar_conn_actions',$action);
    }
    public static function event_key(identity $sender, string $event): string {
        return hash('sha256',"ustar-connect-bot-update-v4\0".$sender->key().':'.$event);
    }
    public static function prune(int $limit=500): int {
        global $DB;
        $count=0;
        foreach (['local_ustar_conn_updates'=>'timecreated','local_ustar_conn_actions'=>'expiresat',
                'local_ustar_conn_dialogs'=>'expiresat'] as $table=>$field) {
            $cutoff=$table==='local_ustar_conn_updates'?time()-7*DAYSECS:time()-DAYSECS;
            $rows=$DB->get_records_select($table,$field.' < :cutoff',['cutoff'=>$cutoff],'id','id',0,$limit);
            if ($rows) { $DB->delete_records_list($table,'id',array_keys($rows)); $count+=count($rows); }
        }
        return $count;
    }
}
