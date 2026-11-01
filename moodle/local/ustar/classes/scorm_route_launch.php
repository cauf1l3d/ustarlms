<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Direct entry into Moodle's SCORM runtime after the route has authorised the launch. */
final class scorm_route_launch {
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
        require_capability('mod/scorm:view', $context);
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
