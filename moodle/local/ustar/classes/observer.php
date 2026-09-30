<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
final class observer {
    public static function user_created(\core\event\user_created $event): void {
        registration_service::initialize((int)$event->objectid);
    }

    public static function course_completed(\core\event\course_completed $event): void {
        $userid=(int)$event->relateduserid; $courseid=(int)$event->courseid;
        if ($userid<=0 || $courseid<=0 || !accounts::participates($userid)) return;
        reward_control::course_completion($userid,$courseid);
        competition::record_course_completion($userid,$courseid);
    }
    public static function activity_completed(\core\event\course_module_completion_updated $event): void {
        reward_control::activity_completion((int)$event->objectid);
    }
}
