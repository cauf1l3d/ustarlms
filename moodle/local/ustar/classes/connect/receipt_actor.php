<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Current account guard only; callers must separately authenticate their transport/owner. */
final class receipt_actor {
    public static function require_active(int $userid): void {
        global $DB;
        if ($userid < 1 || isguestuser($userid)
                || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0, 'confirmed' => 1])
                || !\local_ustar\accounts::is_business_account($userid)
                || !\local_ustar\employment::is_active($userid)
                || !has_capability('local/ustar:use', \context_system::instance(), $userid)) {
            throw new \InvalidArgumentException('inactive_actor');
        }
    }
}
