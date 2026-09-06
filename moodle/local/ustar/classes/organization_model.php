<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Canonical runtime bridge for StaffPlace/Assignment hierarchy.
 * PRIMARY is the employee's business position. ACTING temporarily grants
 * authority of another staff place without replacing PRIMARY.
 */
final class organization_model {
    private static function available(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists(new \xmldb_table('local_ustar_staff_places'))
            && $dbman->table_exists(new \xmldb_table('local_ustar_assignments'));
    }

    private static function active_sql(string $alias='a'): string {
        return "status='active'
            AND (effectivefrom=0 OR effectivefrom<=:nowfrom)
            AND (effectiveto IS NULL OR effectiveto=0 OR effectiveto>=:nowto)";
    }

    public static function active_assignments(int $userid, ?int $now=null): array {
        global $DB;
        if (!self::available()) return [];
        $now=$now??time();
        return array_values($DB->get_records_select(
            'local_ustar_assignments',
            'userid=:uid AND '.self::active_sql('a'),
            ['uid'=>$userid,'nowfrom'=>$now,'nowto'=>$now],
            "CASE assignmenttype WHEN 'acting' THEN 0 WHEN 'primary' THEN 1 ELSE 2 END,id ASC"
        ));
    }

    public static function primary_assignment(int $userid, ?int $now=null): ?\stdClass {
        foreach (self::active_assignments($userid,$now) as $a) {
            if ((string)$a->assignmenttype==='primary') return $a;
        }
        return null;
    }

    public static function staff_place(int $staffplaceid): ?\stdClass {
        global $DB;
        if (!self::available() || $staffplaceid<=0) return null;
        return $DB->get_record(
            'local_ustar_staff_places',
            ['id'=>$staffplaceid,'active'=>1],
            '*',
            IGNORE_MISSING
        ) ?: null;
    }

    public static function occupant_for_place(int $staffplaceid, ?int $now=null): int {
        global $DB;
        if (!self::available() || $staffplaceid<=0) return 0;
        $now=$now??time();
        $rows=$DB->get_records_select(
            'local_ustar_assignments',
            'staffplaceid=:sp AND '.self::active_sql('a'),
            ['sp'=>$staffplaceid,'nowfrom'=>$now,'nowto'=>$now],
            "CASE assignmenttype WHEN 'acting' THEN 0 WHEN 'primary' THEN 1 ELSE 2 END,id ASC",
            'id,userid,assignmenttype'
        );
        foreach ($rows as $row) {
            $uid=(int)$row->userid;
            $u=$DB->get_record('user',['id'=>$uid,'deleted'=>0,'suspended'=>0],'id',IGNORE_MISSING);
            if (!$u || is_siteadmin($uid) || !accounts::participates($uid)) continue;
            return $uid;
        }
        return 0;
    }

    private static function position_map(): array {
        return people::position_map(structure::get(structure::NAME_STRUCTURE));
    }

    private static function department_map(): array {
        return people::department_map(structure::get(structure::NAME_STRUCTURE));
    }

    private static function has_children(int $staffplaceid): bool {
        global $DB;
        return self::available() && $DB->record_exists('local_ustar_staff_places',[
            'managerplaceid'=>$staffplaceid,'active'=>1
        ]);
    }

    public static function manager_places(int $userid, ?int $now=null): array {
        if (is_siteadmin($userid)) return [];
        $positions=self::position_map();
        $out=[];
        foreach (self::active_assignments($userid,$now) as $a) {
            $place=self::staff_place((int)$a->staffplaceid);
            if (!$place) continue;
            $position=$positions[(string)$place->positionid]??null;
            if (self::has_children((int)$place->id) || ($position && !empty($position['ishead']))) {
                $out[(int)$place->id]=$place;
            }
        }
        return array_values($out);
    }

    public static function is_manager(int $userid, ?int $now=null): bool {
        return !empty(self::manager_places($userid,$now));
    }

    private static function descendant_place_ids(array $rootids): array {
        global $DB;
        if (!$rootids || !self::available()) return [];
        $seen=[]; $queue=[];
        foreach(array_unique(array_map('intval',$rootids)) as $id){$seen[$id]=true;$queue[]=$id;}
        $desc=[];
        for($i=0;$i<count($queue);$i++){
            $parent=(int)$queue[$i];
            foreach($DB->get_records('local_ustar_staff_places',['managerplaceid'=>$parent,'active'=>1],'id ASC') as $child){
                $cid=(int)$child->id;
                if(isset($seen[$cid])) continue;
                $seen[$cid]=true; $desc[$cid]=$cid; $queue[]=$cid;
            }
        }
        return array_values($desc);
    }

    public static function manager_scope(int $userid): array {
        global $DB;
        $managerplaces=self::manager_places($userid);
        if(!$managerplaces){
            return ['allowed'=>false,'departmentid'=>'','department'=>'','positions'=>[],
                'employees'=>[],'userids'=>[],'staffplaceids'=>[],'managerplaceids'=>[]];
        }

        $rootids=array_map(static fn($p)=>(int)$p->id,$managerplaces);
        $descids=self::descendant_place_ids($rootids);
        $positions=self::position_map();
        $departments=self::department_map();

        $positionoptions=[]; $employees=[]; $userids=[]; $departmentids=[];
        foreach($managerplaces as $root){
            if((string)$root->departmentid!=='')$departmentids[(string)$root->departmentid]=true;
        }

        foreach($descids as $placeid){
            $place=self::staff_place((int)$placeid);
            if(!$place)continue;
            $pid=(string)$place->positionid;
            $position=$positions[$pid]??null;
            if($position)$positionoptions[$pid]=['id'=>$pid,'name'=>(string)($position['name']??$pid)];
            if((string)$place->departmentid!=='')$departmentids[(string)$place->departmentid]=true;

            $occupant=self::occupant_for_place((int)$placeid);
            if($occupant<=0 || $occupant===$userid || isset($userids[$occupant]))continue;
            $u=$DB->get_record('user',['id'=>$occupant,'deleted'=>0],
                'id,username,firstname,lastname,suspended',IGNORE_MISSING);
            if(!$u || is_siteadmin((int)$u->id))continue;
            $primarypid=people::position_id((int)$u->id);
            $primarypos=$positions[$primarypid]??null;
            $employees[]=[
                'id'=>(int)$u->id,
                'fullname'=>trim((string)$u->lastname.' '.(string)$u->firstname),
                'positionid'=>$primarypid,
                'position'=>(string)($primarypos['name']??($primarypid?:'Без должности')),
                'suspended'=>!empty($u->suspended),
            ];
            $userids[$occupant]=$occupant;
        }

        $positionoptions=array_values($positionoptions);
        usort($positionoptions,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
        usort($employees,static fn($a,$b)=>strnatcasecmp($a['fullname'],$b['fullname']));

        $departmentkeys=array_keys($departmentids);
        $primarydepartmentid=(string)($managerplaces[0]->departmentid??'');
        $departmentlabel=count($departmentkeys)>1
            ? 'Вся подчинённая структура'
            : (string)($departments[$primarydepartmentid]['name']??$primarydepartmentid);

        return [
            'allowed'=>true,
            'departmentid'=>$primarydepartmentid,
            'department'=>$departmentlabel,
            'positions'=>$positionoptions,
            'employees'=>$employees,
            'userids'=>array_values($userids),
            'staffplaceids'=>$descids,
            'managerplaceids'=>$rootids,
        ];
    }

    public static function manager_user_for_place(int $staffplaceid): int {
        $place=self::staff_place($staffplaceid);
        $seen=[];
        for($i=0;$place && $i<100;$i++){
            $mid=(int)($place->managerplaceid??0);
            if($mid<=0 || isset($seen[$mid]))return 0;
            $seen[$mid]=true;
            $manager=self::occupant_for_place($mid);
            if($manager>0)return $manager;
            $place=self::staff_place($mid);
        }
        return 0;
    }

    public static function rebuild_reporting(): array {
        global $DB,$USER;
        if(!$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_reporting')))
            return ['ok'=>false,'count'=>0];

        $now=time(); $desired=[];
        foreach($DB->get_records_select('user','deleted=0 AND suspended=0 AND id>1',[],'id ASC','id') as $u){
            $uid=(int)$u->id;
            if(is_siteadmin($uid) || !accounts::participates($uid))continue;
            $primary=self::primary_assignment($uid,$now);
            if(!$primary)continue;
            $managerid=self::manager_user_for_place((int)$primary->staffplaceid);
            if($managerid>0 && $managerid!==$uid)$desired[$uid]=$managerid;
        }

        foreach($DB->get_records('local_ustar_reporting') as $row){
            $uid=(int)$row->userid;
            if(!isset($desired[$uid])){
                $DB->delete_records('local_ustar_reporting',['id'=>(int)$row->id]);
                continue;
            }
            if((int)$row->managerid!==(int)$desired[$uid] || (string)$row->source!=='staffplace'){
                $row->managerid=(int)$desired[$uid];
                $row->source='staffplace';
                $row->timemodified=$now;
                $row->usermodified=(int)($USER->id??0);
                $DB->update_record('local_ustar_reporting',$row);
            }
            unset($desired[$uid]);
        }
        foreach($desired as $uid=>$managerid){
            $DB->insert_record('local_ustar_reporting',(object)[
                'userid'=>(int)$uid,'managerid'=>(int)$managerid,'source'=>'staffplace',
                'timecreated'=>$now,'timemodified'=>$now,'usermodified'=>(int)($USER->id??0)
            ]);
        }
        return ['ok'=>true,'count'=>$DB->count_records('local_ustar_reporting')];
    }

    private static function active_assignment_exists(int $staffplaceid): bool {
        global $DB;
        $now=time();
        return $DB->record_exists_select(
            'local_ustar_assignments',
            'staffplaceid=:sp AND '.self::active_sql('a'),
            ['sp'=>$staffplaceid,'nowfrom'=>$now,'nowto'=>$now]
        );
    }

    public static function assign_hire(int $userid,string $positionid,int $requesterid): int {
        global $DB,$USER;
        $scope=self::manager_scope($requesterid);
        if(empty($scope['allowed'])){
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:viewteam','nopermissions','');
        }
        $allowed=array_fill_keys(array_map('intval',$scope['staffplaceids']??[]),true);
        $candidates=[];
        foreach($DB->get_records('local_ustar_staff_places',['positionid'=>$positionid,'active'=>1],'id ASC') as $place){
            if(isset($allowed[(int)$place->id]))$candidates[]=$place;
        }
        if(!$candidates)throw new \invalid_parameter_exception('В управленческом контуре нет штатного места для этой должности');

        $place=null;
        foreach($candidates as $candidate){
            if(!self::active_assignment_exists((int)$candidate->id)){$place=$candidate;break;}
        }
        if(!$place){
            $template=$candidates[0];$now=time();
            $place=(object)[
                'placecode'=>'staffing_'.$requesterid.'_'.$positionid.'_'.$now,
                'positionid'=>$positionid,'departmentid'=>(string)$template->departmentid,
                'managerplaceid'=>$template->managerplaceid?:null,'active'=>1,
                'effectivefrom'=>$now,'effectiveto'=>null,'timecreated'=>$now,
                'timemodified'=>$now,'usermodified'=>(int)($USER->id??0)
            ];
            $place->id=(int)$DB->insert_record('local_ustar_staff_places',$place);
        }
        $now=time();
        $DB->insert_record('local_ustar_assignments',(object)[
            'staffplaceid'=>(int)$place->id,'userid'=>$userid,'assignmenttype'=>'primary',
            'status'=>'active','effectivefrom'=>$now,'effectiveto'=>null,
            'timecreated'=>$now,'timemodified'=>$now,'usermodified'=>(int)($USER->id??0),
            'autorenew'=>0
        ]);
        self::rebuild_reporting();
        return (int)$place->id;
    }

    public static function end_user_assignments(int $userid,string $reason='ended'): void {
        global $DB,$USER;
        $now=time();
        foreach(self::active_assignments($userid,$now) as $a){
            $a->status='ended';$a->effectiveto=$now;$a->timemodified=$now;
            $a->usermodified=(int)($USER->id??0);
            if(property_exists($a,'autorenew'))$a->autorenew=0;
            $DB->update_record('local_ustar_assignments',$a);
        }
        self::rebuild_reporting();
    }
}
