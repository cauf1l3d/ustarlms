<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Read-only composition of one editing form; domain commands own every mutation. */
final class route_studio_presenter {
    public static function form(?\stdClass $version, array $choices, ?array $posted=null): array {
        $base=$version ? route_model::requirements_for_version($version) : [];
        $rows=route_studio_form::rows($base,$posted['rows']??null);
        $query=trim((string)($posted['sourceq']??''));
        foreach ($rows as &$row) {
            $row['choices']=[];
            if (!$row['existing']) {
                foreach ($choices as $key=>$r) {
                    $selected=($row['subject']??'')===$key;
                    if (!$selected && ($query!=='' && \core_text::strpos(\core_text::strtolower($r['label']),\core_text::strtolower($query))===false)) { continue; }
                    if (!$selected && count($row['choices'])>=200) { continue; }
                    $row['choices'][]=['value'=>$key,'label'=>$r['label'],'selected'=>$selected];
                }
            }
        }unset($row);
        $policy=$version ? route_point_access::policy($version) : route_point_access::normalize([]);
        $out=['sourceq'=>$query,'requirementrows'=>$rows,'baseversion'=>(int)($version->id??0),
            'formtitle'=>$version->title??'','formsummary'=>$version->summary??'',
            'formvaliddays'=>$version->validdays??0,'expectedmodified'=>$posted['expectedmodified']??null,
            'renewalvalue'=>$posted['renewalpolicy']??$version->renewalpolicy??route_model::RENEW_KEEP];
        foreach (['title'=>'formtitle','summary'=>'formsummary','validdays'=>'formvaliddays','baseversion'=>'baseversion'] as $key=>$target) {
            if ($posted!==null && isset($posted[$key])) { $out[$target]=$posted[$key]; }
        }
        foreach (['auto','manual','timed'] as $c) { $out['access'.$c]=($posted['advancepolicy']??$policy['advance'])===$c; }
        foreach (['close','keep'] as $c) { $out['access'.$c]=($posted['repeataccess']??$policy['repeat'])===$c; }
        foreach (['next','route','stay'] as $c) { $out['finish'.$c]=($posted['finishaction']??$policy['finish'])===$c; }
        $out['reviewduration']=$posted['reviewduration']??($policy['seconds']>0?$policy['seconds']/HOURSECS:24);
        $out['reviewdays']=($posted['reviewunit']??'hours')==='days';$out['reviewhours']=!$out['reviewdays'];
        $out['advancedopen']=$posted!==null;$out['accesssummary']=$version?route_point_access::description($version):'Стандартные правила';
        foreach (['keep','all','expiry','manual'] as $c) { $out[$c.'selected']=$out['renewalvalue']===$c; }
        return $out;
    }
    public static function error(\moodle_exception $e): string {
        if ($e instanceof \invalid_parameter_exception) {
            return trim(clean_param((string)($e->debuginfo??''),PARAM_TEXT)) ?: 'Проверьте параметры формы. Изменения не сохранены.';
        }
        return clean_param($e->getMessage(),PARAM_TEXT);
    }
}
