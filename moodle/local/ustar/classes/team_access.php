<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Company-wide read access. Does not confer staffing or assessment decision rights. */
final class team_access {
    public static function company(int $userid): bool {
        $context = \context_system::instance();
        return is_siteadmin($userid)
            || has_capability('local/ustar:admin', $context, $userid)
            || has_capability('local/ustar:hrmanage', $context, $userid)
            || has_capability('local/ustar:executive', $context, $userid);
    }

    public static function learning_scope(int $userid): array {
        global $DB;
        if (!self::company($userid)) {
            return organization_model::manager_scope($userid);
        }
        $ids = [];
        foreach ($DB->get_records_select('user', 'deleted = 0 AND suspended = 0 AND id > 1', [], '', 'id') as $u) {
            if (accounts::participates((int)$u->id)) {$ids[] = (int)$u->id;}
        }
        return ['allowed' => true, 'departmentid' => '', 'department' => 'Вся компания', 'userids' => $ids];
    }
}
