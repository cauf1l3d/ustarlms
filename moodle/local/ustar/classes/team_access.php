<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Company-wide read access. Does not confer staffing or assessment decision rights. */
final class team_access {
    public static function active_actor(int $userid): bool {
        global $DB;
        return $userid > 1
            && $DB->record_exists('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0])
            && employment::is_active($userid);
    }

    public static function company(int $userid): bool {
        return self::active_actor($userid)
            && capabilities::has($userid, capabilities::COMPANY_READ);
    }

    public static function learning_scope(int $userid): array {
        if (!self::active_actor($userid)) return ['allowed' => false, 'userids' => []];
        return access_context::scope($userid);
    }
}
