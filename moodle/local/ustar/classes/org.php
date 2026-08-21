<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Explicit reporting-line and organizational visibility service. */
final class org {
    public static function reporting_available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_reporting'));
    }

    public static function manager_id(int $userid): int {
        global $DB;
        if (!self::reporting_available()) return 0;
        return (int)$DB->get_field('local_ustar_reporting','managerid',['userid'=>$userid]);
    }

    public static function set_manager(int $userid, int $managerid, string $source='manual'): void {
        global $DB, $USER;
        if ($userid === $managerid) throw new \invalid_parameter_exception('Сотрудник не может быть своим руководителем');
        if ($managerid > 0 && self::would_cycle($userid,$managerid)) throw new \invalid_parameter_exception('Цикл в оргструктуре');
        $now=time();
        $r=$DB->get_record('local_ustar_reporting',['userid'=>$userid]);
        if ($r) {
            $r->managerid=$managerid ?: null; $r->source=$source; $r->timemodified=$now; $r->usermodified=(int)$USER->id;
            $DB->update_record('local_ustar_reporting',$r);
        } else {
            $DB->insert_record('local_ustar_reporting',(object)[
                'userid'=>$userid,'managerid'=>$managerid ?: null,'source'=>$source,
                'timecreated'=>$now,'timemodified'=>$now,'usermodified'=>(int)$USER->id,
            ]);
        }
    }

    private static function would_cycle(int $userid, int $managerid): bool {
        $seen=[]; $cur=$managerid;
        for ($i=0;$i<100 && $cur>0;$i++) {
            if ($cur === $userid) return true;
            if (isset($seen[$cur])) return true;
            $seen[$cur]=true; $cur=self::manager_id($cur);
        }
        return false;
    }

    public static function chain(int $userid): array {
        global $DB;
        $out=[]; $seen=[]; $cur=$userid;
        for ($i=0;$i<50 && $cur>0;$i++) {
            if (isset($seen[$cur])) break;
            $seen[$cur]=true;
            $u=$DB->get_record('user',['id'=>$cur,'deleted'=>0],'id,firstname,lastname',IGNORE_MISSING);
            if (!$u) break;
            $out[] = self::person((int)$u->id, fullname($u));
            $cur=self::manager_id((int)$u->id);
        }
        return array_reverse($out);
    }

    public static function person(int $userid, string $fullname=''): array {
        global $DB;
        if ($fullname==='') {
            $u=$DB->get_record('user',['id'=>$userid],'id,firstname,lastname',MUST_EXIST); $fullname=fullname($u);
        }
        $st=structure::get(structure::NAME_STRUCTURE); $pm=people::position_map($st); $dm=people::department_map($st);
        $pid=people::position_id($userid); $pos=$pm[$pid]??[]; $did=(string)($pos['department']??'');
        $parts=preg_split('/\s+/u',trim($fullname),-1,PREG_SPLIT_NO_EMPTY) ?: [];
        return [
            'id'=>$userid,'fullname'=>$fullname,'initials'=>ui::initials((string)($parts[0]??''),(string)($parts[count($parts)-1]??'')),
            'position'=>(string)($pos['name']??'Без должности'),'positionid'=>$pid,
            'department'=>(string)($dm[$did]['name']??''),'departmentid'=>$did,
        ];
    }

    public static function horizon(int $userid): array {
        global $DB;
        $me=self::person($userid); $pid=$me['positionid']; $did=$me['departmentid'];
        $out=[];
        $sql="SELECT u.id,u.firstname,u.lastname,d.data positionid FROM {user} u JOIN {user_info_data} d ON d.userid=u.id JOIN {user_info_field} f ON f.id=d.fieldid AND f.shortname='ustar_position' WHERE u.deleted=0 AND u.suspended=0";
        foreach ($DB->get_records_sql($sql) as $u) {
            if (!accounts::participates((int)$u->id)) continue;
            $p=self::person((int)$u->id,$u->firstname.' '.$u->lastname);
            if ($p['positionid']===$pid || ($pid==='' && $p['departmentid']===$did)) $out[]=$p;
        }
        return $out;
    }

    public static function direct_reports(int $managerid): array {
        global $DB;
        if (!self::reporting_available()) return [];
        $out=[];
        foreach ($DB->get_records('local_ustar_reporting',['managerid'=>$managerid],'userid ASC') as $r) {
            if (accounts::participates((int)$r->userid)) $out[]=self::person((int)$r->userid);
        }
        return $out;
    }

    public static function company_tree(): array {
        global $DB;
        $people=[];
        foreach ($DB->get_records_select('user','deleted=0 AND suspended=0 AND id>1', [], '', 'id,firstname,lastname') as $u) {
            if (!accounts::participates((int)$u->id)) continue;
            $p=self::person((int)$u->id,fullname($u)); $p['managerid']=self::manager_id((int)$u->id); $p['children']=[]; $people[(int)$u->id]=$p;
        }
        $roots=[];
        foreach (array_keys($people) as $id) {
            $mid=$people[$id]['managerid'];
            if ($mid && isset($people[$mid]) && $mid!==$id) $people[$mid]['children'][]=&$people[$id];
            else $roots[]=&$people[$id];
        }
        return $roots;
    }
}
