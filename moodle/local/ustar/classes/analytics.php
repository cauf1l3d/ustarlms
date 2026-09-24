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
            if(is_array($requirements)&&$requirements)$published[$codes[(string)$version->code]]=$requirements;
        }
        $limit=max(1,$limit);
        $users=$DB->get_records_select('user','deleted=0 AND suspended=0 AND id>1', [], 'id ASC', 'id,firstname,lastname',0,$limit+1);
        $incomplete=count($users)>$limit;
        if ($incomplete) {array_pop($users);}
        $qualified=0;$withgaps=0;$unassigned=0;$unconfigured=0;$unverified=0;$expired=0;$total=0;$gaps=[];
        foreach($users as $u){
            if(!accounts::participates((int)$u->id))continue; $total++; $pid=people::position_id((int)$u->id);
            if($pid===''||!isset($pm[$pid])){$unassigned++;continue;}
            $requirements=$published[$pid]??[];
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
                $level=self::verified_skill_level((int)$u->id,$pid,$skillid);
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

    /** Only a human-reviewed, still-valid level fact can demonstrate competence. */
    private static function verified_skill_level(int $userid,string $positionid,string $skillid): ?int {
        global $DB;
        $fact=$DB->get_record_sql(
            'SELECT * FROM {local_ustar_evidence_rec}
              WHERE userid=:userid AND positionid=:positionid AND skillid=:skillid
                AND sourcekind=:sourcekind AND evidencetype=:evidencetype AND outcome=:outcome
           ORDER BY validfrom DESC, id DESC',
            ['userid'=>$userid,'positionid'=>$positionid,'skillid'=>$skillid,
                'sourcekind'=>'human_skill_level','evidencetype'=>'manager_review','outcome'=>'passed'],
            IGNORE_MULTIPLE
        );
        if(!$fact||(int)$fact->recordedby<=0||(int)$fact->recordedby===$userid
                ||!target_core::evidence_is_valid((int)$fact->id))return null;
        $details=json_decode((string)$fact->detailsjson,true);
        $level=is_array($details)?($details['level']??null):null;
        return is_int($level)&&$level>=1&&$level<=5?$level:null;
    }
}
