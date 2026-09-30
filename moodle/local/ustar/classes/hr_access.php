<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
final class hr_access {
    public static function can_grade(int $userid): bool {
        return team_access::active_actor($userid) && (has_capability('local/ustar:gradeassessments', \context_system::instance(), $userid)
            || has_capability('local/ustar:hrmanage', \context_system::instance(), $userid));
    }
    public static function require_grader(): void {
        global $USER;
        if (!self::can_grade((int)$USER->id)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:gradeassessments', 'nopermissions', '');
        }
    }
}
