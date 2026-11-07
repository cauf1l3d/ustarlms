<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Direct entry into Moodle's SCORM runtime after the route has authorised the launch. */
final class scorm_route_launch {
    /** Supply the learner role for an already route-authorised SCORM launch. */
    public static function ensure_tracking(\stdClass $cm, \stdClass $user): void {
        global $CFG, $DB, $USER;
        $userid = (int)($user->id ?? 0);
        $context = \context_module::instance((int)$cm->id);
        $capability = 'mod/scorm:savetrack';
        if ($userid <= 0 || !empty($user->deleted) || !empty($user->suspended)) {
            throw new \moodle_exception('Учётная запись недоступна для прохождения SCORM.');
        }
        if (has_capability($capability, $context, $userid)) { return; }

        // An explicit deny is an access decision, not a missing technical role.
        $roleids = [(int)($CFG->defaultuserroleid ?? 0)];
        foreach (get_user_roles($context, $userid, true) as $role) { $roleids[] = (int)$role->roleid; }
        foreach (array_unique(array_filter($roleids)) as $roleid) {
            $caps = role_context_capabilities($roleid, $context, $capability);
            if ((int)($caps[$capability] ?? 0) < 0) {
                throw new \moodle_exception('Сохранение SCORM запрещено настройками роли. Обратитесь к администратору.');
            }
        }
        $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        $studentcaps = $studentroleid > 0 ? role_context_capabilities($studentroleid, $context, $capability) : [];
        if ((int)($studentcaps[$capability] ?? 0) !== CAP_ALLOW) {
            throw new \moodle_exception('Штатная роль Студент не разрешает сохранять SCORM. Обратитесь к администратору.');
        }
        require_once($CFG->libdir . '/enrollib.php');
        $manual = enrol_get_plugin('manual');
        if (!$manual) { throw new \moodle_exception('Ручное зачисление Moodle отключено.'); }
        $instance = null;
        $disabled = false;
        foreach (enrol_get_instances((int)$cm->course, false) as $candidate) {
            if ($candidate->enrol !== 'manual') { continue; }
            if ((int)$candidate->status === ENROL_INSTANCE_ENABLED) { $instance = $candidate; break; }
            $disabled = true;
        }
        if (!$instance && $disabled) {
            throw new \moodle_exception('Зачисление на этот курс отключено администратором.');
        }
        $enrolment = $instance ? $DB->get_record('user_enrolments', [
            'enrolid' => (int)$instance->id, 'userid' => $userid,
        ]) : null;
        $now = time();
        if ($enrolment && ((int)$enrolment->status !== ENROL_USER_ACTIVE
                || ((int)$enrolment->timestart > $now)
                || (!empty($enrolment->timeend) && (int)$enrolment->timeend <= $now))) {
            throw new \moodle_exception('Зачисление сотрудника приостановлено или срок доступа ещё не действует.');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            if (!$instance) {
                $course = $DB->get_record('course', ['id' => (int)$cm->course], '*', MUST_EXIST);
                $id = $manual->add_instance($course, [
                    'status' => ENROL_INSTANCE_ENABLED, 'roleid' => $studentroleid, 'enrolperiod' => 0,
                ]);
                $instance = $DB->get_record('enrol', ['id' => (int)$id], '*', MUST_EXIST);
            }
            $start = $enrolment ? (int)$enrolment->timestart : $now;
            $end = $enrolment ? (int)$enrolment->timeend
                : (!empty($instance->enrolperiod) ? $now + (int)$instance->enrolperiod : 0);
            $manual->enrol_user($instance, $userid, $studentroleid, $start, $end, ENROL_USER_ACTIVE);
            if ((int)($USER->id ?? 0) === $userid) { reload_all_capabilities(); }
            else { accesslib_clear_all_caches(true); }
            if (!has_capability($capability, $context, $userid)) {
                throw new \moodle_exception('Moodle не разрешает сохранять прохождение SCORM. Обратитесь к администратору.');
            }
            $transaction->allow_commit();
        } catch (\Throwable $error) {
            $transaction->rollback($error);
        }
    }

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
        self::ensure_tracking($cm, $user);
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
