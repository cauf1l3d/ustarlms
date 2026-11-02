<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Purchase serialises offer -> canonical coin-event -> wallet; no second wallet exists. */
final class library_store {
    public static function enabled(): bool { return (bool)get_config('local_ustar','library_store_enabled'); }
    public static function price_bounds(): array {
        $min=(int)get_config('local_ustar','library_pricemin') ?: 1;
        $max=(int)get_config('local_ustar','library_pricemax') ?: 25;
        return ['min'=>$min,'max'=>$max,'valid'=>$min>0 && $max>=$min && $max<=1000000];
    }

    public static function purchase(int $offerid,int $userid,?int $expectedprice=null,int $expectedrevision=0,string $nonce=''): array {
        global $DB;
        library_access::require_actor($userid);
        $fail=static fn($reason,$balance=0)=>['ok'=>false,'reason'=>$reason,'grantid'=>0,'balance'=>$balance,'spent'=>0];
        if (!self::enabled()) { return $fail('store_disabled'); }
        if (!library_access::eligible($userid)) { return $fail('employment'); }
        if (!economy::available()) { return $fail('economy_unavailable'); }
        if ($expectedprice===null || $expectedrevision<=0 || !preg_match('/^[a-f0-9]{32}$/D',$nonce)) { return $fail('quote_required'); }
        return library_offer::locked($offerid,static function($offer) use($DB,$offerid,$userid,$expectedprice,$expectedrevision,$nonce,$fail): array {
            $balance=economy::balance($userid);
            $key='library:'.hash('sha256',$offerid.':'.$userid.':'.$nonce);
            $ledger=$DB->get_record('local_ustar_coin_ledger',['idempotencykey'=>$key]);
            if ($ledger) {
                $grant=$DB->get_record('local_ustar_library_grant',['idempotencykey'=>$key]);
                if (!$grant || (int)$grant->userid!==$userid || (int)$grant->offerid!==$offerid) { return $fail('purchase_incomplete',$balance); }
                if ($grant->revokedat || $DB->record_exists('local_ustar_coin_ledger',['reversalofid'=>(int)$ledger->id])) { return $fail('quote_expired',$balance); }
                return ['ok'=>true,'reason'=>'idempotent','grantid'=>(int)$grant->id,'balance'=>$balance,'spent'=>0];
            }
            if ($offer->visibility!=='published' || $offer->accesspolicy!=='paid'
                    || !library_access::subject_accessible($offer,$userid)) { return $fail('not_purchasable',$balance); }
            $existing=library_access::active_grant($offerid,$userid);
            if ($existing) { return ['ok'=>true,'reason'=>'already_open','grantid'=>(int)$existing->id,'balance'=>$balance,'spent'=>0]; }
            if ((int)$offer->timemodified!==$expectedrevision || (int)$offer->pricecoin!==$expectedprice) { return $fail('offer_changed',$balance); }
            $price=(int)$offer->pricecoin;$bounds=self::price_bounds();
            if (!$bounds['valid'] || $price<$bounds['min'] || $price>$bounds['max']) { return $fail('price_out_of_bounds',$balance); }
            if ($balance<$price) { return $fail('insufficient_balance',$balance); }
            economy::spend($userid,$price,'library_purchase',$key,'library_offer',(string)$offerid,
                'Библиотека: '.(string)$offer->title,$userid,$balance);
            $ledger=$DB->get_record('local_ustar_coin_ledger',['idempotencykey'=>$key],'id',MUST_EXIST);
            $grantid=library_grant::purchase($offerid,$userid,(int)$ledger->id,$key);
            return ['ok'=>true,'reason'=>'purchased','grantid'=>$grantid,'balance'=>economy::balance($userid),'spent'=>$price];
        });
    }

    public static function storefront(int $userid,string $q='',int $page=0,int $perpage=24): array {
        global $DB;
        $page=max(0,$page);$perpage=max(1,min(48,$perpage));
        $where='visibility=:vis';$params=['vis'=>'published'];
        if ($q!=='') {
            $where.=' AND ('.$DB->sql_like('title',':q1',false).' OR '.$DB->sql_like('summary',':q2',false).')';
            $params+=['q1'=>'%'.$DB->sql_like_escape($q).'%','q2'=>'%'.$DB->sql_like_escape($q).'%'];
        }
        // Filter source audiences before pagination/counts, so inaccessible offers never leak or produce empty pages.
        $offers=$DB->get_records_select('local_ustar_library_offer',$where,$params,'sortorder,id');
        $states=library_access::resolve_many(array_values($offers),$userid);
        $visible=array_filter($offers,static fn($o)=>$states[(int)$o->id]['canview']);
        $total=count($visible);$balance=economy::available() ? economy::balance($userid) : 0;
        $items=[];
        foreach (array_slice(array_values($visible),$page*$perpage,$perpage) as $offer) {
            $state=$states[(int)$offer->id];$price=(int)$offer->pricecoin;
            $items[]=['id'=>(int)$offer->id,'title'=>format_string($offer->title),'summary'=>format_text($offer->summary??'',FORMAT_PLAIN),
                'price'=>$price,'hasprice'=>$price>0,'affordable'=>$price>0 && $balance>=$price,'open'=>$state['canopen'],
                'reason'=>$state['reason'],'ispurchase'=>$state['reason']===library_access::REASON_PURCHASE,
                'isgate'=>$state['reason']===library_access::REASON_GATE,'isassign'=>$state['reason']===library_access::REASON_ASSIGN,
                'storeenabled'=>self::enabled(),'revision'=>(int)$offer->timemodified,
                'actionurl'=>(new \moodle_url('/local/ustar/library.php',['tab'=>'shop','open'=>(int)$offer->id]))->out(false)];
        }
        return ['items'=>$items,'total'=>$total,'balance'=>$balance,'storeenabled'=>self::enabled(),
            'hasmore'=>($page+1)*$perpage<$total,'page'=>$page];
    }
}
