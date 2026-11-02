<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Commercial metadata only; content/catalog publication remains with its existing writer. */
final class library_offer {
    public const VIS_DRAFT='draft';
    public const VIS_PUBLISHED='published';
    public const VIS_ARCHIVED='archived';

    public static function available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_library_offer'));
    }
    public static function get(int $id): ?\stdClass {
        global $DB;
        return $id > 0 ? ($DB->get_record('local_ustar_library_offer',['id'=>$id]) ?: null) : null;
    }
    public static function for_subject(string $type,int $id): ?\stdClass {
        global $DB;
        return self::available() ? ($DB->get_record('local_ustar_library_offer',['itemtype'=>$type,'itemid'=>$id]) ?: null) : null;
    }

    /** All offer, grant, purchase and refund writes acquire this lock before the wallet lock. */
    public static function locked(int $id, callable $operation): mixed {
        global $DB;
        $lock=\core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('library-offer:'.$id,10);
        if (!$lock) { library_access::fail('Доступ сейчас изменяется. Повторите действие.'); }
        try {
            $tx=$DB->start_delegated_transaction();
            try {
                $offer=$DB->get_record_sql('SELECT * FROM {local_ustar_library_offer} WHERE id=:id FOR UPDATE',['id'=>$id],MUST_EXIST);
                $result=$operation($offer);
                $tx->allow_commit();
                return $result;
            } catch (\Throwable $e) { $tx->rollback($e); throw $e; }
        } finally { $lock->release(); }
    }

    public static function save(int $id,array $input,int $actorid,int $expectedmodified=0): \stdClass {
        global $DB;
        library_access::require_actor($actorid);
        if (!library_access::is_authoring($actorid)) { library_access::fail('Недостаточно прав для настройки предложений.'); }
        $type=(string)($input['itemtype']??'content');
        $itemid=(int)($input['itemid']??0);
        $policy=(string)($input['accesspolicy']??'free');
        $visibility=(string)($input['visibility']??'draft');
        if (!in_array($type,['content','catalog','assessment'],true) || $itemid<=0
                || !in_array($policy,['free','paid','gated','assigned'],true)
                || !in_array($visibility,['draft','published','archived'],true)) {
            library_access::fail('Проверьте материал и правила доступа.');
        }
        $title=trim(clean_param((string)($input['title']??''),PARAM_TEXT));
        if ($title==='' || \core_text::strlen($title)>255) { library_access::fail('Укажите название до255 символов.'); }
        $price=$policy==='paid' ? (int)($input['pricecoin']??0) : 0;
        $bounds=library_store::price_bounds();
        if ($policy==='paid' && (!$bounds['valid'] || $price<$bounds['min'] || $price>$bounds['max'])) {
            library_access::fail('Цена должна быть от '.$bounds['min'].' до '.$bounds['max'].' USCOIN.');
        }
        $gate=max(0,(int)($input['gatepointid']??0));
        if ($policy==='gated' && (!$gate || !$DB->record_exists('local_ustar_route_points',['id'=>$gate,'active'=>1]))) {
            library_access::fail('Выберите действующую точку, открывающую доступ.');
        }
        $table=match($type) {'content'=>'local_ustar_content','catalog'=>'local_ustar_catalog','assessment'=>'local_ustar_library_assessment'};
        $subject=$DB->get_record($table,['id'=>$itemid],'*',MUST_EXIST);
        if ($type==='content' && ($subject->type==='folder'
                || ($policy==='paid' && $subject->sourcekind===content::SOURCE_EXTERNAL))) {
            library_access::fail('Платный доступ доступен к материалам, хранящимся в Академии.');
        }
        if ($type==='catalog' && in_array($subject->itemtype,[catalog::TYPE_GROUP,catalog::TYPE_SUBGROUP],true)) {
            library_access::fail('Разделы справочника открыты; настраивайте доступ к конкретной карточке.');
        }
        $record=(object)['itemtype'=>$type,'itemid'=>$itemid,'title'=>$title,
            'summary'=>clean_param((string)($input['summary']??''),PARAM_TEXT),
            'pricecoin'=>$price,'accesspolicy'=>$policy,'gatepointid'=>$gate,'visibility'=>$visibility,
            'sortorder'=>max(0,(int)($input['sortorder']??0)),'usermodified'=>$actorid];
        $write=static function(?\stdClass $old) use($DB,$record,$actorid,$expectedmodified,$id): \stdClass {
            if ($old && ($expectedmodified<=0 || (int)$old->timemodified!==$expectedmodified
                    || $old->itemtype!==$record->itemtype || (int)$old->itemid!==$record->itemid)) {
                library_access::fail('Предложение изменилось. Обновите страницу.');
            }
            $record->timemodified=max(time(),$old ? (int)$old->timemodified+1 : 0);
            if ($old) { $record->id=$id; $DB->update_record('local_ustar_library_offer',$record); }
            else { $record->timecreated=time();$record->id=(int)$DB->insert_record('local_ustar_library_offer',$record); }
            self::audit((int)$record->id,'offer_saved',$actorid,['policy'=>$record->accesspolicy,'price'=>$record->pricecoin,'visibility'=>$record->visibility]);
            return self::get((int)$record->id);
        };
        if ($id>0) { return self::locked($id,$write); }
        $lock=\core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('library-subject:'.$type.':'.$itemid,10);
        if (!$lock) { library_access::fail('Материал сейчас добавляется. Повторите действие.'); }
        try {
            if (self::for_subject($type,$itemid)) { library_access::fail('Предложение для этого материала уже существует.'); }
            $tx=$DB->start_delegated_transaction();
            try { $result=$write(null);$tx->allow_commit();return $result; }
            catch (\Throwable $e) { $tx->rollback($e);throw $e; }
        } finally { $lock->release(); }
    }

    public static function seed(int $actorid,bool $dryrun=false): array {
        global $DB;
        library_access::require_actor($actorid);
        if (!library_access::is_authoring($actorid)) { library_access::fail('Недостаточно прав.'); }
        $result=['created'=>0,'skipped'=>0,'dryrun'=>$dryrun];
        $sources=[
            'content'=>$DB->get_records_select('local_ustar_content','status=:status AND type<>:folder',
                ['status'=>'published','folder'=>'folder'],'id','id,title,summary'),
            'catalog'=>$DB->get_records('local_ustar_catalog',['active'=>1,'itemtype'=>catalog::TYPE_PRODUCT],'id','id,title,summary'),
            'assessment'=>$DB->get_records('local_ustar_library_assessment',['status'=>'published'],'id','id,title'),
        ];
        foreach ($sources as $type=>$rows) {
            foreach ($rows as $row) {
                if (self::for_subject($type,(int)$row->id)) { $result['skipped']++;continue; }
                if (!$dryrun) {
                    self::save(0,['itemtype'=>$type,'itemid'=>$row->id,'title'=>$row->title,
                        'summary'=>$row->summary??'','accesspolicy'=>'free','visibility'=>'published'],$actorid);
                }
                $result['created']++;
            }
        }
        return $result;
    }

    public static function audit(int $id,string $event,int $actorid,array $details,string $entitytype='library_offer'): void {
        global $DB;
        $DB->insert_record('local_ustar_workflow_events',(object)['entitytype'=>$entitytype,'entityid'=>$id,
            'eventtype'=>$event,'actorid'=>$actorid,'reason'=>null,
            'detailsjson'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'timecreated'=>time()]);
    }
}
