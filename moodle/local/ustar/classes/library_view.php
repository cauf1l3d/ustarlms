<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Read composition of existing Library, catalog, saved feed files and mandatory route. */
final class library_view {
    public static function url(array $params=[]): string { return (new \moodle_url('/local/ustar/library.php',$params))->out(false); }
    public static function mine(int $userid,string $query='',int $page=0): array {
        global $DB;
        $items=[];$seen=[];
        foreach (library_grant::for_user($userid,500) as $grant) {
            $offer=library_offer::get((int)$grant->offerid);
            if (!$offer || !library_access::resolve((int)$offer->id,$userid)['canopen']) { continue; }
            $key=$offer->itemtype.':'.$offer->itemid;if (isset($seen[$key])) { continue; }$seen[$key]=true;
            $items[]=['title'=>$offer->title,'summary'=>$offer->summary,'typelabel'=>$grant->source==='purchase'?'Открыто за USCOIN':'Назначено',
                'url'=>self::url(['open'=>$offer->id]),'offerid'=>(int)$offer->id,'date'=>userdate($grant->timecreated,'%d.%m.%Y')];
        }
        foreach (learning_events::library_for_user($userid) as $item) {
            if (isset($seen['content:'.$item['id']])) { continue; }
            $url=content::open_url((int)$item['id'],$userid);if (!$url) { continue; }
            $items[]=['title'=>$item['title'],'summary'=>$item['summary']??'','typelabel'=>'Изученные материалы',
                'url'=>$url->out(false),'contentid'=>(int)$item['id'],'date'=>userdate((int)($item['library_lastaccessedat']??$item['timemodified']),'%d.%m.%Y')];
        }
        if ($query!=='') { $items=array_values(array_filter($items,static fn($i)=>\core_text::strpos(\core_text::strtolower($i['title'].' '.$i['summary']),\core_text::strtolower($query))!==false)); }
        $total=count($items);$slice=array_slice($items,$page*24,24);
        $saved=feed_files::saved_for_library($userid,$query,$page);
        return ['materials'=>$slice,'hasmaterials'=>(bool)$slice,'materialcount'=>$total,
            'savedfiles'=>$saved['items'],'hassavedfiles'=>(bool)$saved['items'],
            'hasmore'=>$total>($page+1)*24 || $saved['hasnext']];
    }
    public static function catalog(int $userid,int $parent,int $product,string $query='',int $page=0): array {
        $detail=null;$cards=[];$crumbs=[];
        if ($product) {
            if (!library_runtime::catalog_allowed($product,$userid)) { library_access::fail('Доступ к карточке можно открыть в Маркетплейсе.'); }
            $detail=catalog::view($product);if (!$detail) { library_access::fail('Карточка недоступна.'); }
            $parent=(int)$detail['parentid'];
        }
        if ($parent) {
            if (!library_access::catalog_active($parent)) { library_access::fail('Раздел недоступен.'); }
            $crumbs=catalog::ancestors($parent);$row=catalog::get($parent);
            $crumbs[]=['id'=>$parent,'title'=>$row->title];
            foreach ($crumbs as &$c) { $c['url']=self::url(['tab'=>'catalog','parent'=>$c['id']]); }unset($c);
        }
        $rows=$detail ? [] : catalog::browse($parent?:null,$query);
        foreach ($rows as $row) {
            if (!library_runtime::catalog_allowed((int)$row['id'],$userid,true)) { continue; }
            $open=library_runtime::catalog_allowed((int)$row['id'],$userid);
            $offer=library_offer::for_subject('catalog',(int)$row['id']);
            $row['url']=$open ? self::url(['tab'=>'catalog', $row['isfolder']?'parent':'product'=>$row['id']]) : self::url(['tab'=>'shop','open'=>$offer->id]);
            $row['locked']=!$open;$cards[]=$row;
        }
        $total=count($cards);$cards=array_slice($cards,$page*24,24);
        return ['cards'=>$cards,'hascards'=>(bool)$cards,'detail'=>$detail,'hasdetail'=>(bool)$detail,
            'breadcrumbs'=>$crumbs,'catalogroot'=>self::url(['tab'=>'catalog']),
            'hasmore'=>$total>($page+1)*24];
    }
    public static function checks(int $userid,string $query='',int $page=0): array {
        global $DB;$items=[];
        $names=['quick'=>'Товар','group'=>'Группа товаров','topic'=>'Тема','skill'=>'Навык'];
        $rows=$DB->get_records('local_ustar_library_assessment',['status'=>'published'],'title,id');
        foreach ($rows as $row) {
            if ($query!=='' && \core_text::strpos(\core_text::strtolower($row->title),\core_text::strtolower($query))===false) { continue; }
            $offer=library_offer::for_subject('assessment',(int)$row->id);
            if ($offer && !library_access::resolve((int)$offer->id,$userid)['canview']) { continue; }
            if (in_array($row->targettype,['quick','group'],true) && !library_runtime::catalog_allowed((int)$row->targetkey,$userid,true)) { continue; }
            $canopen=library_assessment::can_open($row,$userid);
            $best=library_assessment::best((int)$row->id,$userid);
            $items[]=['title'=>$row->title,'meta'=>($names[$row->targettype]??'Проверка').' · '.$row->questioncount.' вопросов · от '.$row->passpercent.'%',
                'result'=>$best ? ($best->passed?'Пройдено · ':'Последний лучший результат · ').$best->score.'%' : '',
                'url'=>$canopen ? (new \moodle_url('/local/ustar/library_check.php',['id'=>$row->id]))->out(false) : self::url(['tab'=>'shop','open'=>$offer->id??0]),
                'action'=>$canopen?'Пройти':'Открыть доступ'];
        }
        $total=count($items);$items=array_slice($items,$page*24,24);
        return ['assessments'=>$items,'hasassessments'=>(bool)$items,'assessmentcount'=>$total,'hasmore'=>$total>($page+1)*24];
    }
}
