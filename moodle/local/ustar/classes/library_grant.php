<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Historical entitlements. Never deletes a grant or writes a wallet projection. */
final class library_grant {
    public const SOURCE_PURCHASE='purchase';
    public const SOURCE_LEADER='leader';
    public const SOURCE_HRD='hrd';
    public const SOURCE_ADMIN='admin';

    public static function assign(int $offerid,int $userid,int $actorid,string $source='',string $comment='',string $nonce=''): int {
        library_access::require_actor($actorid);
        if (!library_access::can_target($actorid,$userid)) { library_access::fail('Сотрудник недоступен в вашей области управления.'); }
        // Source is derived from authority, never from a POST field.
        $source=library_access::is_admin($actorid) ? self::SOURCE_ADMIN
            : (capabilities::has($actorid,capabilities::HR_WRITE) ? self::SOURCE_HRD : self::SOURCE_LEADER);
        if (!preg_match('/^[a-f0-9]{32}$/D',$nonce)) { library_access::fail('Форма назначения устарела. Обновите страницу.'); }
        return library_offer::locked($offerid,static function($offer) use($offerid,$userid,$actorid,$source,$comment,$nonce): int {
            global $DB;
            if (!library_access::can_target($actorid,$userid) || $offer->visibility!=='published'
                    || !library_access::subject_accessible($offer,$userid)) {
                library_access::fail('Проверьте публикацию и аудиторию материала для этого сотрудника.');
            }
            $key=hash('sha256','assign:'.$offerid.':'.$userid.':'.$actorid.':'.$nonce);
            $existing=$DB->get_record('local_ustar_library_grant',['idempotencykey'=>$key]);
            if ($existing) { return (int)$existing->id; }
            $now=time();
            $active=$DB->get_records_select('local_ustar_library_grant',
                'offerid=:offer AND userid=:uid AND grantedby=:actor AND source=:source AND revokedat=0
                    AND validfrom<=:starts AND (validto=0 OR validto>:ends)',
                ['offer'=>$offerid,'uid'=>$userid,'actor'=>$actorid,'source'=>$source,'starts'=>$now,'ends'=>$now],'id DESC','id',0,1);
            if ($active) { return (int)reset($active)->id; }
            return self::insert($offerid,$userid,$actorid,$source,$key,0,$comment);
        });
    }

    /** Called inside the offer transaction; verify the exact canonical debit before recording its licence. */
    public static function purchase(int $offerid,int $userid,int $ledgerid,string $key): int {
        global $DB;
        library_access::require_actor($userid);
        $ledger=$DB->get_record('local_ustar_coin_ledger',['id'=>$ledgerid],'*',MUST_EXIST);
        if ((int)$ledger->userid!==$userid || (int)$ledger->amount>=0 || $ledger->txtype!=='library_purchase'
                || $ledger->sourcekind!=='library_offer' || (string)$ledger->sourceid!==(string)$offerid
                || $ledger->idempotencykey!==$key
                || $DB->record_exists('local_ustar_coin_ledger',['reversalofid'=>$ledgerid])) {
            library_access::fail('Не найдено подтверждённое списание за этот материал.');
        }
        $existing=$DB->get_record('local_ustar_library_grant',['idempotencykey'=>$key]);
        return $existing ? (int)$existing->id : self::insert($offerid,$userid,$userid,self::SOURCE_PURCHASE,$key,$ledgerid,'Покупка доступа');
    }

    private static function insert(int $offerid,int $userid,int $actorid,string $source,string $key,int $ledgerid,string $comment): int {
        global $DB;
        $now=time();
        $id=(int)$DB->insert_record('local_ustar_library_grant',(object)['offerid'=>$offerid,'userid'=>$userid,
            'source'=>$source,'grantedby'=>$actorid,'ledgerid'=>$ledgerid,'validfrom'=>$now,'validto'=>0,
            'revokedat'=>0,'revokerid'=>0,'refundedat'=>0,'comment'=>clean_param($comment,PARAM_TEXT),
            'idempotencykey'=>$key,'timecreated'=>$now]);
        library_offer::audit($offerid,'access_granted',$actorid,['grantid'=>$id,'userid'=>$userid,'source'=>$source,'ledgerid'=>$ledgerid]);
        return $id;
    }

    public static function revoke(int $grantid,int $actorid,string $comment=''): bool {
        global $DB;
        library_access::require_actor($actorid);
        $grant=$DB->get_record('local_ustar_library_grant',['id'=>$grantid],'*',MUST_EXIST);
        return library_offer::locked((int)$grant->offerid,static function() use($DB,$grantid,$actorid,$comment): bool {
            $grant=$DB->get_record_sql('SELECT * FROM {local_ustar_library_grant} WHERE id=:id FOR UPDATE',['id'=>$grantid],MUST_EXIST);
            $admin=library_access::is_admin($actorid);
            if ((!$admin && !library_access::can_target($actorid,(int)$grant->userid))
                    || (!$admin && ($grant->source===self::SOURCE_PURCHASE
                        || ((int)$grant->grantedby!==$actorid && !capabilities::has($actorid,capabilities::HR_WRITE))))) {
                library_access::fail('Недостаточно прав для отзыва этого доступа.');
            }
            if ((int)$grant->revokedat>0) { return false; }
            $DB->update_record('local_ustar_library_grant',(object)['id'=>$grantid,'revokedat'=>time(),'revokerid'=>$actorid]);
            library_offer::audit((int)$grant->offerid,'access_revoked',$actorid,
                ['grantid'=>$grantid,'userid'=>(int)$grant->userid,'reason'=>clean_param($comment,PARAM_TEXT)]);
            return true;
        });
    }

    public static function refund(int $grantid,int $actorid,string $reason=''): bool {
        global $DB;
        library_access::require_actor($actorid);
        if (!library_access::is_admin($actorid)) { library_access::fail('Возврат покупки доступен администратору.'); }
        $grant=$DB->get_record('local_ustar_library_grant',['id'=>$grantid],'*',MUST_EXIST);
        return library_offer::locked((int)$grant->offerid,static function() use($DB,$grantid,$actorid,$reason): bool {
            $grant=$DB->get_record_sql('SELECT * FROM {local_ustar_library_grant} WHERE id=:id FOR UPDATE',['id'=>$grantid],MUST_EXIST);
            if ((int)$grant->userid===$actorid) { library_access::fail('Возврат своей покупки выполняет другой администратор.'); }
            if ($grant->source!==self::SOURCE_PURCHASE || (int)$grant->ledgerid<=0) { library_access::fail('Это не покупка доступа.'); }
            if ((int)$grant->refundedat>0) { return false; }
            $ledger=$DB->get_record('local_ustar_coin_ledger',['id'=>(int)$grant->ledgerid],'*',MUST_EXIST);
            if ((int)$ledger->userid!==(int)$grant->userid || $ledger->txtype!=='library_purchase'
                    || $ledger->sourcekind!=='library_offer' || (string)$ledger->sourceid!==(string)$grant->offerid
                    || (int)$ledger->amount>=0 || $ledger->idempotencykey!==$grant->idempotencykey) {
                library_access::fail('Запись покупки не совпадает с правом доступа.');
            }
            economy::reverse((int)$ledger->id,'library-refund:'.$grantid,clean_param($reason,PARAM_TEXT),$actorid);
            $now=time();
            $DB->update_record('local_ustar_library_grant',(object)['id'=>$grantid,'refundedat'=>$now,
                'revokedat'=>(int)$grant->revokedat ?: $now,'revokerid'=>(int)$grant->revokerid ?: $actorid]);
            library_offer::audit((int)$grant->offerid,'purchase_refunded',$actorid,
                ['grantid'=>$grantid,'userid'=>(int)$grant->userid,'ledgerid'=>(int)$ledger->id]);
            return true;
        });
    }

    public static function for_user(int $userid,int $limit=100): array {
        global $DB;
        $now=time();
        return $DB->get_records_select('local_ustar_library_grant',
            'userid=:uid AND revokedat=0 AND validfrom<=:starts AND (validto=0 OR validto>:ends)',
            ['uid'=>$userid,'starts'=>$now,'ends'=>$now],'timecreated DESC,id DESC','*',0,max(1,min(500,$limit)));
    }
}
