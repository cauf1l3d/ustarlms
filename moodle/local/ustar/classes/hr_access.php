<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/**
 * HR role boundaries.
 *
 * USTAR HR (recruiter/HR assistant) works with people, materials,
 * checklists, learning/compliance signals and manual assessment grading.
 * Position architecture, organisation editing, grade configuration and
 * HRD-only escalations stay outside that role.
 */
final class hr_access {
    private const ROLE_HR = 'ustar_hr';
    private const ROLE_HRD = 'ustar_hrd';

    private static function has_system_role(int $userid, string $shortname): bool {
        global $DB;
        if ($userid <= 1) {
            return false;
        }
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
              WHERE ra.userid = :userid
                AND ra.contextid = :contextid
                AND r.shortname = :shortname",
            [
                'userid' => $userid,
                'contextid' => \context_system::instance()->id,
                'shortname' => $shortname,
            ]
        );
    }

    public static function is_recruiter(int $userid): bool {
        if (is_siteadmin($userid)) {
            return false;
        }
        return self::has_system_role($userid, self::ROLE_HR)
            && !self::has_system_role($userid, self::ROLE_HRD)
            && !has_capability('local/ustar:admin', \context_system::instance(), $userid);
    }

    public static function can_grade(int $userid): bool {
        return team_access::active_actor($userid) && (
            has_capability('local/ustar:gradeassessments', \context_system::instance(), $userid)
            || has_capability('local/ustar:hrmanage', \context_system::instance(), $userid)
        );
    }

    public static function require_grader(): void {
        global $USER;
        if (!self::can_grade((int)$USER->id)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/ustar:gradeassessments',
                'nopermissions',
                ''
            );
        }
    }

    public static function can_manage_structure(int $userid): bool {
        if (!team_access::active_actor($userid)) {
            return false;
        }
        if (is_siteadmin($userid)
                || has_capability('local/ustar:admin', \context_system::instance(), $userid)) {
            return true;
        }
        return !self::is_recruiter($userid)
            && has_capability('local/ustar:hrmanage', \context_system::instance(), $userid);
    }

    public static function require_structure_manager(): void {
        global $USER;
        if (!self::can_manage_structure((int)$USER->id)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/ustar:hrmanage',
                'nopermissions',
                ''
            );
        }
    }

    public static function can_view_hrd_escalations(int $userid): bool {
        if (!team_access::active_actor($userid)) {
            return false;
        }
        return is_siteadmin($userid)
            || has_capability('local/ustar:admin', \context_system::instance(), $userid)
            || self::has_system_role($userid, self::ROLE_HRD);
    }
}
