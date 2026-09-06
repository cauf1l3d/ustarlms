<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Technical adapter that lets a USTAR route point launch a legacy Moodle CM
 * without exposing the legacy course as the navigation model.
 *
 * USTAR route state is the canonical availability gate. Moodle course/section
 * visibility and old availability restrictions are treated only as legacy
 * container metadata for a CM that has already been authorised by the current
 * route point.
 */
final class moodle_activity_bridge {

    /**
     * Prepare one already-authorised current-route activity for direct access.
     *
     * @return array<string,mixed>
     */
    public static function prepare(int $userid, int $cmid): array {
        global $CFG, $DB;

        if ($userid <= 0 || $cmid <= 0) {
            throw new \invalid_parameter_exception('Некорректный технический запуск активности USTAR.');
        }

        require_once($CFG->libdir . '/enrollib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $user = $DB->get_record('user', [
            'id' => $userid,
            'deleted' => 0,
        ], '*', MUST_EXIST);

        $cmrow = $DB->get_record('course_modules', [
            'id' => $cmid,
            'deletioninprogress' => 0,
        ], 'id,course,section,module,visible,visibleold,visibleoncoursepage,availability', MUST_EXIST);

        $course = $DB->get_record('course', [
            'id' => (int)$cmrow->course,
        ], '*', MUST_EXIST);

        $section = $DB->get_record('course_sections', [
            'id' => (int)$cmrow->section,
            'course' => (int)$course->id,
        ], 'id,course,section,visible,availability', MUST_EXIST);

        $modname = (string)$DB->get_field('modules', 'name', [
            'id' => (int)$cmrow->module,
        ], MUST_EXIST);

        $changed = [];

        // Technical enrolment. The route point has already been authorised.
        $coursecontext = \context_course::instance((int)$course->id);
        if (!is_enrolled($coursecontext, $user, '', true)) {
            enrol_try_internal_enrol((int)$course->id, $userid);
        }

        if (!is_enrolled($coursecontext, $user, '', true)) {
            $manual = enrol_get_plugin('manual');
            if (!$manual) {
                throw new \moodle_exception('Не найден технический способ открыть активность USTAR.');
            }

            $manualinstance = null;
            foreach (enrol_get_instances((int)$course->id, false) as $instance) {
                if ((string)$instance->enrol === 'manual') {
                    $manualinstance = $instance;
                    break;
                }
            }

            if (!$manualinstance) {
                $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
                if ($studentroleid <= 0) {
                    throw new \moodle_exception('Не найдена техническая роль обучающегося Moodle.');
                }
                $instanceid = $manual->add_instance($course, [
                    'status' => ENROL_INSTANCE_ENABLED,
                    'roleid' => $studentroleid,
                    'enrolperiod' => 0,
                ]);
                if ($instanceid) {
                    $manualinstance = $DB->get_record('enrol', ['id' => (int)$instanceid], '*', MUST_EXIST);
                    $changed[] = 'manual_instance_created';
                }
            }

            if ($manualinstance && (int)$manualinstance->status !== ENROL_INSTANCE_ENABLED) {
                $manual->update_status($manualinstance, ENROL_INSTANCE_ENABLED);
                $manualinstance = $DB->get_record('enrol', ['id' => (int)$manualinstance->id], '*', MUST_EXIST);
                $changed[] = 'manual_instance_enabled';
            }

            if (!$manualinstance) {
                throw new \moodle_exception('Не удалось подготовить техническое зачисление USTAR.');
            }

            $roleid = (int)($manualinstance->roleid ?? 0);
            if ($roleid <= 0) {
                $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
            }

            $manual->enrol_user(
                $manualinstance,
                $userid,
                $roleid,
                time(),
                0,
                ENROL_USER_ACTIVE
            );
            $changed[] = 'user_enrolled';
        }

        if (!is_enrolled($coursecontext, $user, '', true)) {
            throw new \moodle_exception('Техническое зачисление USTAR не вступило в силу.');
        }

        // Enrolment without a learner role is not sufficient for Moodle CM access.
        $viewcapability = 'mod/' . $modname . ':view';
        if (
            get_capability_info($viewcapability)
            && !has_capability(
                $viewcapability,
                \context_module::instance($cmid),
                $userid
            )
        ) {
            $manual = enrol_get_plugin('manual');
            if (!$manual) {
                throw new \moodle_exception('Не найден manual enrolment для технического доступа USTAR.');
            }

            $studentroleid = (int)$DB->get_field(
                'role',
                'id',
                ['shortname' => 'student'],
                MUST_EXIST
            );

            $routeinstance = null;
            foreach (enrol_get_instances((int)$course->id, false) as $instance) {
                if ((string)$instance->enrol === 'manual') {
                    $routeinstance = $instance;
                    break;
                }
            }

            if (!$routeinstance) {
                $instanceid = $manual->add_instance($course, [
                    'status' => ENROL_INSTANCE_ENABLED,
                    'roleid' => $studentroleid,
                    'enrolperiod' => 0,
                ]);
                if ($instanceid) {
                    $routeinstance = $DB->get_record(
                        'enrol',
                        ['id' => (int)$instanceid],
                        '*',
                        MUST_EXIST
                    );
                }
            }

            if (!$routeinstance) {
                throw new \moodle_exception('Не удалось создать техническое зачисление USTAR.');
            }

            if ((int)$routeinstance->status !== ENROL_INSTANCE_ENABLED) {
                $manual->update_status($routeinstance, ENROL_INSTANCE_ENABLED);
                $routeinstance = $DB->get_record(
                    'enrol',
                    ['id' => (int)$routeinstance->id],
                    '*',
                    MUST_EXIST
                );
            }

            $manual->enrol_user(
                $routeinstance,
                $userid,
                $studentroleid,
                time(),
                0,
                ENROL_USER_ACTIVE
            );
            $changed[] = 'student_enrolment_repaired';
        }

        /*
         * Legacy course metadata must not veto a route-authorised CM.
         * We only repair the exact course/section/CM needed by the current
         * route point. The CM remains hidden from the legacy course page using
         * visibleoncoursepage=0, but is direct-link accessible (stealth mode).
         */
        if (empty($course->visible)) {
            $DB->set_field('course', 'visible', 1, ['id' => (int)$course->id]);
            $changed[] = 'course_visible';
        }

        if (empty($section->visible)) {
            $DB->set_field('course_sections', 'visible', 1, ['id' => (int)$section->id]);
            $changed[] = 'section_visible';
        }
        if (!empty($section->availability)) {
            $DB->set_field('course_sections', 'availability', null, ['id' => (int)$section->id]);
            $changed[] = 'section_legacy_availability_removed';
        }

        if (empty($cmrow->visible)) {
            $DB->set_field('course_modules', 'visible', 1, ['id' => $cmid]);
            $changed[] = 'cm_visible';
        }
        if ((int)$cmrow->visibleold !== 1) {
            $DB->set_field('course_modules', 'visibleold', 1, ['id' => $cmid]);
            $changed[] = 'cm_visibleold';
        }
        if ((int)$cmrow->visibleoncoursepage !== 0) {
            $DB->set_field('course_modules', 'visibleoncoursepage', 0, ['id' => $cmid]);
            $changed[] = 'cm_stealth';
        }
        if (!empty($cmrow->availability)) {
            $DB->set_field('course_modules', 'availability', null, ['id' => $cmid]);
            $changed[] = 'cm_legacy_availability_removed';
        }

        if ($changed) {
            rebuild_course_cache((int)$course->id, true);
        }

        // This is the gate HF4 did not validate: direct Moodle user visibility.
        $modinfo = get_fast_modinfo((int)$course->id, $userid);
        $cm = $modinfo->get_cm($cmid);
        if (!$cm) {
            throw new \moodle_exception('Moodle не вернул подготовленную активность USTAR.');
        }

        if (empty($cm->uservisible)) {
            $diag = 'course=' . (int)$course->id
                . ', section=' . (int)$section->id
                . ', cm=' . $cmid
                . ', visible=' . (int)$cm->visible
                . ', visibleoncoursepage=' . (int)$cm->visibleoncoursepage
                . ', available=' . (!empty($cm->available) ? '1' : '0');
            throw new \moodle_exception('Активность USTAR всё ещё недоступна после подготовки: ' . $diag);
        }

        return [
            'courseid' => (int)$course->id,
            'sectionid' => (int)$section->id,
            'cmid' => $cmid,
            'modname' => $modname,
            'uservisible' => !empty($cm->uservisible),
            'available' => !empty($cm->available),
            'visible' => (int)$cm->visible,
            'visibleoncoursepage' => (int)$cm->visibleoncoursepage,
            'changed' => $changed,
            'targeturl' => (new \moodle_url('/mod/' . $modname . '/view.php', ['id' => $cmid]))->out(false),
        ];
    }
}
