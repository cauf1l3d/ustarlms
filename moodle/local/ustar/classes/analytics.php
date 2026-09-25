<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
final class analytics {
    public static function qualification_summary(int $limit=500): array {
        global $DB;
        $st=structure::get(structure::NAME_STRUCTURE); $pm=people::position_map($st); $skillmap=[];
        foreach($st['skills']??[] as $sk)$skillmap[(string)$sk['id']]=$sk;
        // Editable structure matrix is a draft. Count only explicit, published
        // nonempty position standards. Load once for the complete page.
        $codes=[];foreach($pm as $pid=>$position)$codes[standard_model::position_code((string)$pid)]=(string)$pid;
        $published=[];
        $versions=$DB->get_records_sql(
            'SELECT v.id,s.code,v.requirementsjson FROM {local_ustar_standards} s
               JOIN {local_ustar_standard_ver} v ON v.id=s.activeversionid
              WHERE s.status=:standardstatus AND v.status=:versionstatus AND v.effectivedate<=:now',
            ['standardstatus'=>standard_model::STATUS_PUBLISHED,
                'versionstatus'=>standard_model::STATUS_PUBLISHED,'now'=>time()]);
        foreach($versions as $version){
            if(!isset($codes[(string)$version->code]))continue;
            $requirements=json_decode((string)$version->requirementsjson,true);
            if(is_array($requirements)&&$requirements)$published[$codes[(string)$version->code]]=[
                'versionid'=>(int)$version->id,'requirements'=>$requirements];
        }
        $limit=max(1,$limit);
        $users=$DB->get_records_select('user','deleted=0 AND suspended=0 AND id>1', [], 'id ASC', 'id,firstname,lastname',0,$limit+1);
        $incomplete=count($users)>$limit;
        if ($incomplete) {array_pop($users);}
        $positionversions=[];
        foreach($published as $pid=>$standard)$positionversions[(string)$pid]=(int)$standard['versionid'];
        $assessedlevels=skill_assessment::current_for_users(array_keys($users),$positionversions);
        $qualified=0;$withgaps=0;$unassigned=0;$unconfigured=0;$unverified=0;$expired=0;$total=0;$gaps=[];
        foreach($users as $u){
            if(!accounts::participates((int)$u->id))continue; $total++; $pid=people::position_id((int)$u->id);
            if($pid===''||!isset($pm[$pid])){$unassigned++;continue;}
            $requirements=$published[$pid]['requirements']??[];
            if (!$requirements) {$unconfigured++;continue;}
            $ok=true;$configured=true;$unknown=false;$usergaps=[];
            foreach($requirements as $requirement){
                if(!is_array($requirement)||empty($requirement['required'])
                        ||(string)($requirement['sourcekind']??'')!=='ustar_skill'
                        ||!isset($skillmap[(string)($requirement['sourceid']??'')])){
                    $configured=false;break;
                }
                $skillid=(string)$requirement['sourceid'];
                $target=(int)($requirement['targetlevel']??0);
                if($target<1||$target>5){$configured=false;break;}
                // Course/activity completion cannot demonstrate a human skill level.
                // Until a separately reviewed and revocable level fact exists, the
                // person's qualification is unknown, not a passed standard or gap.
                $level=$assessedlevels[(int)$u->id.':'.$pid.':'.$skillid.':'
                    .(int)$published[$pid]['versionid']]??null;
                if($level===null){$unknown=true;continue;}
                if($level<$target){$ok=false;$usergaps[$skillid]=true;}
            }
            if(!$configured){$unconfigured++;continue;}
            if($unknown){$unverified++;continue;}
            foreach(array_keys($usergaps) as $skillid)$gaps[$skillid]=($gaps[$skillid]??0)+1;
            if($ok)$qualified++; else $withgaps++;
        }
        arsort($gaps);$top=[];foreach(array_slice($gaps,0,8,true) as $sid=>$count)$top[]=['name'=>(string)($skillmap[$sid]['name']??$sid),'count'=>(int)$count];
        return ['total'=>$total,'qualified'=>$qualified,'withgaps'=>$withgaps,'unassigned'=>$unassigned,
            'unconfigured'=>$unconfigured,'unverified'=>$unverified,
            'unknown'=>$unassigned+$unconfigured+$unverified,
            'assessable'=>$qualified+$withgaps,'expired'=>$expired,
            // The full employee denominator prevents unknown standards from
            // silently inflating the qualification percentage.
            'coverage'=>$total?round($qualified/$total*100):0,
            'incomplete'=>$incomplete,'topgaps'=>$top,'hastopgaps'=>!empty($top)];
    }

}
