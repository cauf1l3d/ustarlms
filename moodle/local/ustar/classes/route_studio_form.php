<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Lossless form codec. Existing sources come from the immutable server-side base, never hidden JSON. */
final class route_studio_form {
    public static function rows(array $base, ?array $posted = null): array {
        $rows=[];
        foreach ($base as $i=>$r) {
            $p=$posted[$i]??[];
            $rows[]=['index'=>$i,'base'=>(string)$i,'existing'=>true,
                'label'=>(string)($r['label']??(($r['type']??'').' · '.($r['sourceid']??$r['sourcekey']??''))),
                'required'=>$posted===null?!empty($r['required']):!empty($p['required']),
                'remove'=>!empty($p['remove']),'iscontent'=>($r['type']??'')==='content',
                'ack'=>($p['completionmode']??$r['completionmode']??'open')==='ack',
                'isskill'=>($r['type']??'')==='skill','primary'=>$posted===null?!empty($r['primary']):!empty($p['primary']),'open'=>($p['completionmode']??$r['completionmode']??'open')!=='ack'];
        }
        $extras=$posted===null?[]:array_slice($posted,count($base));
        if (!$extras) { $extras=[[],[]]; }
        foreach ($extras as $p) {
            $rows[]=['index'=>count($rows),'base'=>'','existing'=>false,'subject'=>(string)($p['subject']??''),
                'required'=>$posted===null?true:!empty($p['required']),'ack'=>($p['completionmode']??'open')==='ack',
                'open'=>($p['completionmode']??'open')!=='ack','remove'=>!empty($p['remove'])];
        }
        return $rows;
    }

    /** Exact base coverage prevents a truncated POST from silently deleting requirements. */
    public static function decode(array $input, array $base, array $choices): array {
        if (!isset($input['rows']) || !is_array($input['rows']) || count($input['rows'])>1000) {
            throw new \invalid_parameter_exception('Список обучения повреждён или слишком велик. Обновите форму.');
        }
        $seen=[];$out=[];
        foreach ($input['rows'] as $p) {
            if (!is_array($p)) { throw new \invalid_parameter_exception('Проверьте строку обучения.'); }
            foreach (['base','subject','required','remove','completionmode','primary'] as $field) {
                if (isset($p[$field]) && !is_scalar($p[$field])) { throw new \invalid_parameter_exception('Проверьте строку обучения.'); }
            }
            $index=(string)($p['base']??'');
            if ($index!=='') {
                if (!preg_match('/^(0|[1-9][0-9]*)$/D',$index) || !array_key_exists((int)$index,$base) || isset($seen[(int)$index])) {
                    throw new \invalid_parameter_exception('Исходный список обучения изменён. Обновите форму.');
                }
                $seen[(int)$index]=true;$r=$base[(int)$index];
            } else {
                $subject=(string)($p['subject']??'');
                if ($subject==='' || !empty($p['remove'])) { continue; }
                if (!isset($choices[$subject])) { throw new \invalid_parameter_exception('Выбранное обучение недоступно. Обновите список.'); }
                $r=$choices[$subject];
            }
            if (!empty($p['remove'])) { continue; }
            $r['required']=!empty($p['required']);
            if (($r['type']??'')==='content') {
                $mode=(string)($p['completionmode']??$r['completionmode']??'open');
                if (!in_array($mode,['open','ack'],true)) { throw new \invalid_parameter_exception('Выберите способ учёта материала.'); }
                $r['completionmode']=$mode;
            }
            // Skills describe outcomes; they never become completion gates through this editor.
            if (($r['type']??'')==='skill') { $r['required']=false;$r['primary']=!empty($p['primary']); }
            $out[]=$r;
        }
        if (count($seen)!==count($base)) { throw new \invalid_parameter_exception('Форма передана не полностью. Обучение не изменено; обновите страницу.'); }
        return $out;
    }

    /** Bounded, scalar-only values for redisplay after validation failure. */
    public static function posted(array $input): array {
        $out=[];
        foreach (['title','summary','renewalpolicy','validdays','status','advancepolicy','repeataccess','finishaction',
                'sourceq','reviewduration','reviewunit','expectedmodified','baseversion','pointid','phase','active','intent'] as $key) {
            if (isset($input[$key]) && is_scalar($input[$key])) { $out[$key]=clean_param(substr((string)$input[$key],0,20000),PARAM_TEXT); }
        }
        $out['rows']=[];
        foreach (array_slice(is_array($input['rows']??null)?$input['rows']:[],0,1000) as $row) {
            if (!is_array($row)) { continue; }$p=[];
            foreach (['base','subject','required','remove','completionmode','primary'] as $key) {
                if (isset($row[$key]) && is_scalar($row[$key])) { $p[$key]=clean_param(substr((string)$row[$key],0,255),PARAM_TEXT); }
            }
            $out['rows'][]=$p;
        }
        return $out;
    }
}
