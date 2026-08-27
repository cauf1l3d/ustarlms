<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
final class observer {
    public static function course_completed(\core\event\course_completed $event): void {
        $userid=(int)$event->relateduserid; $courseid=(int)$event->courseid;
        if ($userid<=0 || $courseid<=0 || !accounts::participates($userid)) return;
        // Course completion remains a learning signal. It must not silently
        // mint spendable USCOIN or enter a competition without its published
        // rule version explicitly allowing that event type.
    }
}
