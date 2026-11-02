<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Enforces licences at the existing content/file/native boundaries, not just on cards. */
final class library_runtime {
    public static function content_entitled(int $contentid,int $userid): bool {
        $offer=library_offer::for_subject('content',$contentid);
        return !$offer || library_access::resolve((int)$offer->id,$userid)['canopen']
            || self::route_entitled($userid,'content',$contentid);
    }

    /** An independent explicit Library licence may outlive a closed mandatory review window. */
    public static function independent_native_grant(int $cmid,int $userid): bool {
        global $DB;
        if (!library_offer::available() || !library_access::eligible($userid)) { return false; }
        $rows=$DB->get_records('local_ustar_content',['cmid'=>$cmid,'sourcekind'=>content::SOURCE_MOODLE]);
        foreach ($rows as $row) {
            $offer=library_offer::for_subject('content',(int)$row->id);
            if ($offer && library_access::active_grant((int)$offer->id,$userid)
                    && library_access::resolve((int)$offer->id,$userid)['canopen']) { return true; }
        }
        return false;
    }

    public static function native_allowed(int $cmid,int $userid): bool {
        global $DB;
        if (!library_offer::available()) { return true; }
        $rows=$DB->get_records_sql('SELECT o.* FROM {local_ustar_library_offer} o
            JOIN {local_ustar_content} c ON c.id=o.itemid
            WHERE o.itemtype=:type AND c.sourcekind=:source AND c.cmid=:cm',
            ['type'=>'content','source'=>content::SOURCE_MOODLE,'cm'=>$cmid]);
        if (!$rows) { return true; }
        if (!library_access::eligible($userid)) { return false; }
        foreach (library_access::resolve_many(array_values($rows),$userid) as $state) {
            if ($state['canopen']) { return true; }
        }
        return self::route_entitled($userid,'cm',$cmid);
    }

    /** No reconciliation/writes. A licence never completes/unlocks a mandatory route point. */
    public static function route_entitled(int $userid,string $type,int $id): bool {
        if (!employment::learning_allowed($userid) || adaptation_service::learning_blocked($userid)) { return false; }
        $scope=content::user_scope($userid);$position=(string)($scope['positionid']??'');
        if ($position==='') { return false; }
        $cm=$type==='cm' ? get_coursemodule_from_id(null,$id,0,false,IGNORE_MISSING) : null;
        $snapshot=route_model::read_only_snapshot($position,$userid);
        foreach ($snapshot['points']??[] as $point) {
            if (!empty($point['locked'])) { continue; }
            $version=route_model::current_published_version((int)$point['id']);
            if (!$version) { continue; }
            foreach (route_model::requirements_for_version($version) as $r) {
                if (($r['type']===$type && (int)$r['sourceid']===$id)
                        || ($cm && $r['type']==='course' && (int)$r['sourceid']===(int)$cm->course)) { return true; }
                if ($cm && $r['type']==='content') {
                    global $DB;
                    $content=$DB->get_record('local_ustar_content',['id'=>(int)$r['sourceid']]);
                    if ($content && (int)$content->cmid===$id) { return true; }
                }
            }
        }
        return false;
    }

    public static function launch_content(int $contentid,int $userid): \moodle_url {
        global $DB,$SESSION,$USER;
        library_access::require_actor($userid);
        $record=$DB->get_record('local_ustar_content',['id'=>$contentid],'*',MUST_EXIST);
        if (!content::can_access_record($record,$userid)) { library_access::fail('Доступ к материалу закрыт.'); }
        if ($record->sourcekind===content::SOURCE_MOODLE) {
            assignment::enrol_library_content($contentid,$userid);
            $cm=get_coursemodule_from_id(null,(int)$record->cmid,0,false,MUST_EXIST);
            require_login(get_course((int)$cm->course),false,$cm);
            if (!get_fast_modinfo((int)$cm->course,$userid)->get_cm((int)$cm->id)->uservisible) { library_access::fail('Материал сейчас недоступен в курсе.'); }
            if ($cm->modname==='scorm') {
                unset($SESSION->ustar_scorm_route);
                return scorm_route_launch::player_url($cm,$USER);
            }
        }
        $url=content::open_url($contentid,$userid);
        if (!$url) { library_access::fail('Материал ещё не подготовлен.'); }
        return $url;
    }

    public static function catalog_allowed(int $itemid,int $userid,bool $teaser=false): bool {
        if (is_siteadmin($userid) || catalog::can_manage($userid)) { return true; }
        if (!library_access::eligible($userid) || !library_access::catalog_active($itemid)) { return false; }
        $offer=library_offer::for_subject('catalog',$itemid);
        if (!$offer) { return true; }
        $state=library_access::resolve((int)$offer->id,$userid);
        return $teaser ? $state['canview'] : $state['canopen'];
    }
}
