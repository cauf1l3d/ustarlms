<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Deterministic product checks; attempts and all other learning/economy tables are read-only. */
final class library_quick_sync {
    public const LOCK='library_quick_sync';
    public static function run(bool $dryrun=false,?int $onlyproduct=null): array {
        global $DB;
        $counts=['created'=>0,'refreshed'=>0,'archived'=>0,'noop'=>0,'skipped'=>0,'dryrun'=>$dryrun,'locked'=>false];
        if (!catalog::available() || !library_assessment::available()) { return $counts; }
        $lock=\core\lock\lock_config::get_lock_factory('local_ustar')->get_lock(self::LOCK,0);
        if (!$lock) { $counts['locked']=true;return $counts; }
        try {
            $all=$DB->get_records('local_ustar_catalog',[],'id');$products=[];$subgroups=[];$active=[];
            foreach ($all as $row) {
                $id=(int)$row->id;$seen=[];$node=$row;$valid=true;
                while ($node) {
                    if (!$node->active || isset($seen[(int)$node->id]) || count($seen)>=64) { $valid=false;break; }
                    $seen[(int)$node->id]=true;
                    if (!(int)$node->parentid) { break; }
                    $node=$all[(int)$node->parentid]??null;
                    if (!$node) { $valid=false; }
                }
                if (!$valid) { continue; }
                $active[$id]=true;
                if ($row->itemtype===catalog::TYPE_PRODUCT) { $products[$id]=$row; }
                if ($row->itemtype===catalog::TYPE_SUBGROUP) { $subgroups[$id]=$row; }
            }
            $pools=self::attribute_value_pools($products);$wanted=[];
            foreach ($products as $id=>$product) {
                if ($onlyproduct!==null && $id!==$onlyproduct) { continue; }
                $parent=$subgroups[(int)$product->parentid]??null;
                if (!$parent) { $counts['skipped']++;continue; }
                $others=[];foreach ($subgroups as $sg) { if ((int)$sg->id!==(int)$parent->id) { $others[]=(string)$sg->title; } }
                $questions=library_quick_generator::generate($product,$parent,$others,self::value_pool_for($product,$pools));
                if (!$questions) { $counts['skipped']++;continue; }
                $key='product:'.$id;$wanted[$key]=true;
                $title='Проверка: '.\core_text::substr($product->title,0,200);
                $json=json_encode($questions,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $hash=hash('sha256',$title.'|'.$json.'|100|3');
                $old=$DB->get_record('local_ustar_library_assessment',['sourcekey'=>$key]);
                if ($old && $old->inputhash===$hash && $old->status==='published') { $counts['noop']++;continue; }
                $counts[$old?'refreshed':'created']++;
                if ($dryrun) { continue; }
                $row=(object)['sourcekey'=>$key,'targettype'=>'quick','targetkey'=>(string)$id,'title'=>$title,
                    'questionsjson'=>$json,'questioncount'=>count($questions),'passpercent'=>100,'maxattempts'=>3,
                    'status'=>'published','inputhash'=>$hash,'revision'=>$old ? (int)$old->revision+1 : 1,
                    'timemodified'=>time(),'usermodified'=>0];
                if ($old) { $row->id=$old->id;$DB->update_record('local_ustar_library_assessment',$row); }
                else { $row->timecreated=time();$DB->insert_record('local_ustar_library_assessment',$row); }
            }
            $existing=$DB->get_records('local_ustar_library_assessment',['targettype'=>'quick','status'=>'published']);
            foreach ($existing as $row) {
                if (($onlyproduct===null || (int)$row->targetkey===$onlyproduct) && !isset($wanted[$row->sourcekey])) {
                    $counts['archived']++;
                    if (!$dryrun) { $DB->update_record('local_ustar_library_assessment',(object)['id'=>$row->id,'status'=>'archived',
                        'revision'=>(int)$row->revision+1,'timemodified'=>time()]); }
                }
            }
        } finally { $lock->release(); }
        return $counts;
    }
    public static function sync_product(int $id,bool $dryrun=false): array { return self::run($dryrun,$id); }
    private static function attribute_value_pools(array $products): array {
        $out=[];
        foreach ($products as $product) {
            $attrs=json_decode((string)$product->attributesjson,true);
            if (!is_array($attrs)) { continue; }
            foreach ($attrs as $name=>$value) {
                if (!str_starts_with((string)$name,'_') && is_scalar($value) && !is_bool($value) && trim((string)$value)!=='') {
                    $out[(string)$name][]=(string)$value;
                }
            }
        }
        return $out;
    }
    private static function value_pool_for(\stdClass $product,array $valuesbyname): array {
        $attribute=library_quick_generator::usable_attribute($product);
        return $attribute ? ($valuesbyname[$attribute['key']]??[]) : [];
    }
    /** Queued inside catalog's transaction: the core queue commits with the catalog, never before it. */
    public static function queue(int $productid): void {
        if (!library_assessment::available()) { return; }
        $task=new \local_ustar\task\sync_library_product();$task->set_custom_data(['productid'=>$productid]);
        \core\task\manager::queue_adhoc_task($task,true);
    }
}
