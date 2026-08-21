<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
final class analytics {
    public static function qualification_summary(int $limit=500): array {
        global $DB;
        $st=structure::get(structure::NAME_STRUCTURE); $pm=people::position_map($st); $skillmap=[];
        foreach($st['skills']??[] as $sk)$skillmap[(string)$sk['id']]=$sk;
        $users=$DB->get_records_select('user','deleted=0 AND suspended=0 AND id>1', [], '', 'id,firstname,lastname',0,$limit);
        $qualified=0;$withgaps=0;$unassigned=0;$expired=0;$total=0;$gaps=[];
        foreach($users as $u){
            if(!accounts::participates((int)$u->id))continue; $total++; $pid=people::position_id((int)$u->id);
            if($pid===''||!isset($pm[$pid])){$unassigned++;continue;}
            $matrix=$st['matrix'][$pid]??[]; $ok=true;
            foreach($matrix as $skillid=>$target){
                $ev=evidence::evaluate_skill((string)$skillid,$pid,(int)$u->id);
                if(empty($ev['satisfied'])){$ok=false;$gaps[$skillid]=($gaps[$skillid]??0)+1;}
                foreach(($ev['bestpath']['items']??[]) as $item)if(!empty($item['expired']))$expired++;
            }
            if($ok)$qualified++; else $withgaps++;
        }
        arsort($gaps);$top=[];foreach(array_slice($gaps,0,8,true) as $sid=>$count)$top[]=['name'=>(string)($skillmap[$sid]['name']??$sid),'count'=>(int)$count];
        return ['total'=>$total,'qualified'=>$qualified,'withgaps'=>$withgaps,'unassigned'=>$unassigned,'expired'=>$expired,'coverage'=>$total?round($qualified/$total*100):0,'topgaps'=>$top,'hastopgaps'=>!empty($top)];
    }
}
