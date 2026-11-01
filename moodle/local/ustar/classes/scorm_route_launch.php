<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Direct entry into Moodle's SCORM runtime after the route has authorised the launch. */
final class scorm_route_launch {
    /** Old Studio imports contain one SCORM but did not configure whole-course completion. */
    public static function managed_course_completedat(int $courseid, int $userid): int {
        global $DB;
        $modules = $DB->get_records_sql('SELECT cm.id,cm.instance,cm.completion,m.name AS modname
            FROM {course_modules} cm JOIN {modules} m ON m.id=cm.module
            WHERE cm.course=:course AND cm.deletioninprogress=0',['course'=>$courseid]);
        // Moodle may create its administrative Announcements forum when the course menu is opened.
        // It is not another learning requirement; every other extra activity remains significant.
        foreach ($modules as $id=>$module) {
            if ($module->modname === 'forum' && (int)$module->completion === 0
                    && $DB->get_field('forum','type',['id'=>(int)$module->instance]) === 'news') { unset($modules[$id]); }
        }
        if (count($modules) !== 1) { return 0; }
        $cm = reset($modules);
        if ($cm->modname !== 'scorm') { return 0; }
        $events = $DB->get_records_select('local_ustar_workflow_events',
            'entitytype=:kind AND eventtype=:event AND ' . $DB->sql_like('detailsjson', ':coursejson'),
            ['kind'=>'studio_scorm_runtime','event'=>'scorm_runtime_created',
                'coursejson'=>'%"courseid":'.$courseid.',%']);
        $proven = false;
        foreach ($events as $event) {
            $data = json_decode($event->detailsjson,true);
            if ((int)($data['courseid'] ?? 0) === $courseid && (int)($data['cmid'] ?? 0) === (int)$cm->id) { $proven = true; }
        }
        if (!$proven) { return 0; }
        $completion = $DB->get_record('course_modules_completion',[
            'coursemoduleid'=>$cm->id,'userid'=>$userid],'completionstate,timemodified');
        if (!$completion || !in_array((int)$completion->completionstate,[1,2],true)) { return 0; }
        return (int)$completion->timemodified;
    }

    /** Resume the first unfinished visible SCORM in course order; completed courses can be reviewed. */
    public static function course_cmid(int $courseid, int $userid): int {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $modinfo = get_fast_modinfo($courseid, $userid);
        $completion = new \completion_info(get_course($courseid));
        $first = 0;
        foreach ($modinfo->get_sections() as $cmids) {
            foreach ($cmids as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if ($cm->modname !== 'scorm' || !$cm->uservisible) {
                    continue;
                }
                $first = $first ?: (int)$cm->id;
                $state = empty($cm->completion) ? 0
                    : (int)$completion->get_data($cm, false, $userid)->completionstate;
                if (!in_array($state, [1, 2], true)) {
                    return (int)$cm->id;
                }
            }
        }
        return $first;
    }

    /** Keep Moodle's SCO selection, resume and attempt policy; skip the two navigation pages. */
    public static function player_url(\stdClass $cm, \stdClass $user): \moodle_url {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scorm/lib.php');
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        $scorm = $DB->get_record('scorm', ['id' => (int)$cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);
        // SCORM has no mod/scorm:view capability. The launcher and native player
        // use require_login(course, false, cm), with tracking permissions enforced by Moodle.
        scorm_require_available($scorm);
        if ((int)$scorm->updatefreq === SCORM_UPDATE_EVERYTIME) {
            scorm_parse($scorm, false);
        }
        $toc = scorm_get_toc($user, $scorm, (int)$cm->id, TOCFULLURL);
        $scoid = (int)($toc->sco->id ?? 0);
        // For an always-new attempt Moodle must start at the first SCO, not the prior resume location.
        if ((int)$scorm->forcenewattempt === SCORM_FORCEATTEMPT_ALWAYS) {
            $scoes = $DB->get_records_select('scorm_scoes', 'scorm = :scorm AND launch <> :empty',
                ['scorm' => (int)$scorm->id, 'empty' => ''], 'sortorder, id', 'id', 0, 1);
            $scoid = $scoes ? (int)reset($scoes)->id : 0;
        }
        if ($scoid <= 0) {
            throw new \moodle_exception('В пакете SCORM не найдена страница запуска.');
        }
        scorm_view($scorm, get_course((int)$cm->course), $cm, $context);
        // player.php validates availability and owns normal/review/new-attempt handling.
        return new \moodle_url('/mod/scorm/player.php', ['cm' => (int)$cm->id, 'scoid' => $scoid]);
    }
}
