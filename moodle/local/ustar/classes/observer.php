<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
final class observer {
    public static function course_completed(\core\event\course_completed $event): void {
        $userid=(int)$event->relateduserid; $courseid=(int)$event->courseid;
        if ($userid<=0 || $courseid<=0 || !accounts::participates($userid)) return;
        economy::post($userid,50,'course_complete','course_complete:'.$userid.':'.$courseid,'course',(string)$courseid,'Завершение курса');
    }
}
