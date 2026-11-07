<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Management read models. Canonical writers still recheck every POST independently. */
final class library_manage_reader {
    private const PAGE_SIZE=20;
    private const WINDOW=100;
    private static function allowed(int $actorid, string $right): bool {
        global $USER;
        return (int)($USER->id??0)===$actorid && !view_as::active() && match($right) {
            'author'=>library_access::is_authoring($actorid),
            'assessor'=>library_access::can_assess($actorid),
            default=>library_access::can_grant($actorid),
        };
    }
    private static function offset(int $page, int $size): int {
        if ($page>intdiv(PHP_INT_MAX,$size)) { throw new \invalid_parameter_exception('Страница недоступна.'); }
        return max(0,$page)*$size;
    }
    private static function search(string $query): string {
        global $DB;
        return '%'.$DB->sql_like_escape($query).'%';
    }
    private static function table(string $type): ?string {
        return ['content'=>'local_ustar_content','catalog'=>'local_ustar_catalog','assessment'=>'local_ustar_library_assessment'][$type]??null;
    }

    public static function subjects(int $actorid, string $type, string $query, int $page, string $selected=''): array {
        global $DB;
        $out=['items'=>[],'hasmore'=>false,'selectedlabel'=>''];
        if (!self::allowed($actorid,'author') || !($table=self::table($type))) { return $out; }
        $where=match($type) {'content'=>'type<>:folder','catalog'=>'itemtype<>:group AND itemtype<>:subgroup',default=>'1=1'};
        $params=match($type) {'content'=>['folder'=>'folder'],'catalog'=>['group'=>'group','subgroup'=>'subgroup'],default=>[]};
        if ($query!=='') { $where.=' AND '.$DB->sql_like('title',':sq',false);$params['sq']=self::search($query); }
        $rows=$DB->get_records_select($table,$where,$params,'title,id','id,title',self::offset($page,50),51);
        $out['hasmore']=count($rows)>50;
        $label=static fn($kind,$title)=>match($kind){'content'=>'Материал','catalog'=>'Товар',default=>'Проверка'}.' · '.$title;
        foreach (array_slice($rows,0,50) as $row) {
            $value=$type.':'.$row->id;$out['items'][]=['value'=>$value,'label'=>$label($type,$row->title),'selected'=>$selected===$value];
            if ($selected===$value) { $out['selectedlabel']=$label($type,$row->title); }
        }
        // A saved binding or rejected POST must survive any search/page boundary.
        if ($selected!=='' && $out['selectedlabel']==='') {
            $parts=explode(':',$selected,2);$source=self::table($parts[0]);
            $row=$source && preg_match('/^[1-9][0-9]*$/D',$parts[1]??'') ? $DB->get_record($source,['id'=>(int)$parts[1]],'id,title') : null;
            $out['selectedlabel']=$row ? $label($parts[0],$row->title) : 'Выбранный материал недоступен';
            array_unshift($out['items'],['value'=>$selected,'label'=>$out['selectedlabel'],'selected'=>true]);
        }
        return $out;
    }

    /** SQL scope is only a candidate filter; can_target remains the authoritative check. */
    private static function scope(int $actorid, string $column, string $prefix): array {
        global $DB;
        if (library_access::is_admin($actorid)) { return ['1=1',[]]; }
        $scope=access_context::scope($actorid);$ids=array_values(array_unique(array_map('intval',$scope['userids']??[])));
        if (empty($scope['allowed']) || !$ids) { return ['1=0',[]]; }
        [$in,$params]=$DB->get_in_or_equal($ids,SQL_PARAMS_NAMED,$prefix);
        return [$column.' '.$in,$params];
    }

    /** Keep only one bounded window and a visible page; denied rows never affect hasmore. */
    private static function visible_page(string $sql, array $params, int $page, callable $cansee): array {
        global $DB;
        $skip=self::offset($page,self::PAGE_SIZE);$seen=0;$items=[];$offset=0;
        do {
            $rows=$DB->get_records_sql($sql,$params,$offset,self::WINDOW);
            foreach ($rows as $row) {
                if (!$cansee($row)) { continue; }
                if ($seen++<$skip) { continue; }
                $items[]=$row;
                if (count($items)>self::PAGE_SIZE) { return ['items'=>array_slice($items,0,self::PAGE_SIZE),'hasmore'=>true]; }
            }
            $offset+=self::WINDOW;
        } while (count($rows)===self::WINDOW);
        return ['items'=>$items,'hasmore'=>false];
    }

    public static function targets(int $actorid, string $query, int $page): array {
        global $DB;
        if (!self::allowed($actorid,'grantor')) { return ['items'=>[],'hasmore'=>false]; }
        [$scope,$params]=self::scope($actorid,'u.id','target');
        $where='u.id>1 AND u.id<>:actor AND u.deleted=0 AND u.suspended=0 AND '.$scope;$params['actor']=$actorid;
        if ($query!=='') {
            $where.=' AND ('.$DB->sql_like('u.firstname',':pq1',false).' OR '.$DB->sql_like('u.lastname',':pq2',false).')';
            $params+=['pq1'=>self::search($query),'pq2'=>self::search($query)];
        }
        $out=self::visible_page('SELECT u.* FROM {user} u WHERE '.$where.' ORDER BY u.lastname,u.firstname,u.id',$params,$page,
            static fn($row)=>library_access::can_target($actorid,(int)$row->id));
        $out['items']=array_map(static fn($row)=>['value'=>(int)$row->id,'label'=>fullname($row)],$out['items']);
        return $out;
    }

    public static function grant_status(object $grant, int $now): array {
        $key=match(true) {
            (int)$grant->refundedat>0=>'refunded', (int)$grant->revokedat>0=>'revoked',
            (int)$grant->validto>0 && (int)$grant->validto<=$now=>'expired',
            (int)$grant->validfrom>$now=>'future', default=>'active',
        };
        return ['statuskey'=>$key,'status'=>match($key){'refunded'=>'Возвращён','revoked'=>'Отозван','expired'=>'Истёк','future'=>'Ещё не начался',default=>'Действует'}];
    }

    public static function history(int $actorid, string $query, int $page, int $now): array {
        global $DB;
        if (!self::allowed($actorid,'grantor')) { return ['items'=>[],'hasmore'=>false]; }
        [$where,$params]=self::scope($actorid,'g.userid','history');
        if ($query!=='') {
            $where.=' AND ('.$DB->sql_like('u.firstname',':hq1',false).' OR '.$DB->sql_like('u.lastname',':hq2',false).' OR '.$DB->sql_like('o.title',':hq3',false).')';
            $params+=['hq1'=>self::search($query),'hq2'=>self::search($query),'hq3'=>self::search($query)];
        }
        $allowed=[];
        $out=self::visible_page('SELECT g.* FROM {local_ustar_library_grant} g JOIN {user} u ON u.id=g.userid
            LEFT JOIN {local_ustar_library_offer} o ON o.id=g.offerid WHERE '.$where.' ORDER BY g.id DESC',$params,$page,
            static function($row)use($actorid,&$allowed){$id=(int)$row->userid;return $allowed[$id]??=library_access::can_target($actorid,$id);});
        if (!$out['items']) { return $out; }
        $users=$DB->get_records_list('user','id',array_values(array_unique(array_column($out['items'],'userid'))));
        $offers=$DB->get_records_list('local_ustar_library_offer','id',array_values(array_unique(array_column($out['items'],'offerid'))));
        $admin=library_access::is_admin($actorid);$hr=$admin || capabilities::has($actorid,capabilities::HR_WRITE);$rows=[];
        foreach ($out['items'] as $row) {
            $canrevoke=$admin || ($row->source!=='purchase' && ((int)$row->grantedby===$actorid || $hr));
            $rows[]=self::grant_status($row,$now)+['id'=>(int)$row->id,'title'=>$offers[$row->offerid]->title??'Архивный материал',
                'person'=>isset($users[$row->userid])?fullname($users[$row->userid]):'Сотрудник недоступен',
                'date'=>userdate($row->timecreated,'%d.%m.%Y'),'source'=>$row->source==='purchase'?'Покупка':'Назначение',
                'canrevoke'=>$canrevoke && !$row->revokedat,'canrefund'=>$admin && $row->source==='purchase' && !$row->refundedat];
        }
        $out['items']=$rows;return $out;
    }

    public static function checks(int $actorid, string $query, int $page): array {
        global $DB;
        if (!self::allowed($actorid,'assessor')) { return ['items'=>[],'hasmore'=>false]; }
        $where='targettype<>:quick';$params=['quick'=>'quick'];
        if ($query!=='') { $where.=' AND '.$DB->sql_like('title',':cq',false);$params['cq']=self::search($query); }
        $rows=$DB->get_records_select('local_ustar_library_assessment',$where,$params,'id DESC','id,title',self::offset($page,self::PAGE_SIZE),self::PAGE_SIZE+1);
        return ['items'=>array_slice($rows,0,self::PAGE_SIZE),'hasmore'=>count($rows)>self::PAGE_SIZE];
    }
}
