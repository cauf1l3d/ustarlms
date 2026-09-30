<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Predicates on supported, verified source events; no manual minting endpoint. */
final class reward_conditions {
    public static function options(): array {
        global $DB;
        $structure=structure::get(structure::NAME_STRUCTURE);$out=['departments'=>[],'positions'=>[],'people'=>[],'resources'=>[]];
        foreach (people::department_map($structure) as $id=>$d) { $out['departments'][$id]=$d['name']; }
        foreach (people::position_map($structure) as $id=>$p) { $out['positions'][$id]=$p['name']; }
        foreach ($DB->get_records_select('user','deleted=0 AND suspended=0 AND id>1',[],'lastname,firstname','id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename') as $u) {
            if (accounts::participates((int)$u->id)) { $out['people'][(string)$u->id]=fullname($u).' · №'.$u->id; }
        }
        foreach (['course'=>['course','fullname'],'activity'=>['course_modules','id'],
            'route'=>['local_ustar_route_points','id'],'game'=>['local_ustar_questions','question'],
            'task'=>['local_ustar_task_templates','title'],'checklist'=>['local_ustar_task_templates','title']] as $kind=>[$table,$field]) {
            $out['resources'][$kind]=[];
            foreach ($DB->get_records($table,[],'id',implode(',',array_unique(['id',$field]))) as $r) {
                $out['resources'][$kind][(string)$r->id]=$kind==='route'?'Точка маршрута №'.$r->id:
                    ($kind==='activity'?'Активность №'.$r->id:(string)$r->$field);
            }
        }
        $titles=[];
        foreach ($DB->get_records('local_ustar_route_versions',[],'id DESC','id,pointid,title') as $v) {
            if (!isset($titles[$v->pointid])) { $titles[$v->pointid]=trim(strip_tags($v->title)); }
        }
        foreach ($out['resources']['route'] as $id=>&$label) { $label.=' · '.($titles[$id]??'Без опубликованного названия'); }
        unset($label);
        foreach ($out['resources']['activity'] as $id=>&$label) {
            $course=(int)$DB->get_field('course_modules','course',['id'=>$id]);
            $info=get_fast_modinfo($course);$cm=$info->get_cm((int)$id);
            $label=$cm->name.' · №'.$id;
        }
        unset($label);
        foreach ($out['resources']['game'] as &$label) { $label=\core_text::substr(trim(strip_tags($label)),0,120); }
        unset($label);
        return $out;
    }
    public static function validate(array $input): array {
        $options=self::options();$kind=$input['kind']??'';$scope=$input['scope']??'';
        if (!isset(reward_control::defaults()[$kind]) || !in_array($scope,['company','department','position','person'],true)
                || trim($input['title']??'')==='' || \core_text::strlen($input['title'])>100) {
            throw new \invalid_parameter_exception('Укажите название, событие и получателей условия.');
        }
        $maps=['department'=>'departments','position'=>'positions','person'=>'people'];
        $scopeid=$scope==='company'?'':(string)($input['scopeid']??'');
        if ($scope!=='company' && !isset($options[$maps[$scope]][$scopeid])) { throw new \invalid_parameter_exception('Выберите действующих получателей.'); }
        $resource=(string)($input['resource']??'');
        if ($resource!=='' && !isset($options['resources'][$kind][$resource])) { throw new \invalid_parameter_exception('Источник события недоступен.'); }
        foreach (['xp','coins'] as $unit) {
            if (!is_int($input[$unit]??null) || $input[$unit]<0 || $input[$unit]>10000) {
                throw new \invalid_parameter_exception('XP и USCOIN: целые числа от 0 до 10000.');
            }
        }
        if (in_array($kind,['course','activity','game'],true) && $input['coins']!==0) {
            throw new \invalid_parameter_exception('Для USCOIN выберите подтверждение точки маршрута или принятую задачу.');
        }
        if (!is_int($input['from']??null) || !is_int($input['until']??null) || $input['from']<=time()
                || ($input['until']!==0 && $input['until']<=$input['from'])) {
            throw new \invalid_parameter_exception('Начало условия должно быть в будущем, окончание — позже начала.');
        }
        return ['id'=>bin2hex(random_bytes(8)),'title'=>clean_param($input['title'],PARAM_TEXT),'kind'=>$kind,
            'scope'=>$scope,'scopeid'=>$scopeid,'resource'=>$resource,'from'=>$input['from'],'until'=>$input['until'],
            'xp'=>$input['xp'],'coins'=>$input['coins'],'active'=>true];
    }
    public static function resource(string $kind,string $source): string {
        global $DB;
        if ($kind==='route') { return (string)$DB->get_field('local_ustar_completion_cycle','pointid',['id'=>(int)$source]); }
        if ($kind==='course') { return (string)$DB->get_field('course_completions','course',['id'=>(int)$source]); }
        if ($kind==='activity') { return (string)$DB->get_field('course_modules_completion','coursemoduleid',['id'=>(int)$source]); }
        if (in_array($kind,['task','checklist'],true)) { return (string)$DB->get_field_sql('SELECT v.templateid FROM {local_ustar_task_meta} m JOIN {local_ustar_task_tpl_versions} v ON v.id=m.templateversionid WHERE m.taskid=:id',['id'=>(int)$source]); }
        return $source;
    }
    public static function matches(array $rule,int $userid,string $kind,string $source,int $at): bool {
        if (!$rule['active'] || $rule['kind']!==$kind || $at<$rule['from'] || ($rule['until'] && $at>$rule['until'])) { return false; }
        if ($rule['resource']!=='' && $rule['resource']!==self::resource($kind,$source)) { return false; }
        if ($rule['scope']==='company') { return true; }
        if ($rule['scope']==='person') { return (string)$userid===$rule['scopeid']; }
        $identity=organization_identity::resolve($userid,$at);
        return !$identity['conflicts'] && (string)$identity[$rule['scope']==='department'?'departmentid':'positionid']===$rule['scopeid'];
    }
}
