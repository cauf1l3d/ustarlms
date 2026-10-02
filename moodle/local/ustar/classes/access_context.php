<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** One read-only access boundary for organization consumers. */
final class access_context {
    public static function for_user(int $userid): array {
        $identity = organization_identity::resolve($userid);
        $company = capabilities::has($userid, capabilities::COMPANY_READ);
        $teamscope = ['allowed' => false, 'userids' => []];
        if ($company) {
            $teamscope = self::company_scope();
        } else if (capabilities::has($userid, capabilities::TEAM_READ)) {
            $teamscope = organization_model::manager_scope($userid);
        }
        return [
            'userid' => $userid,
            'employment' => employment::resolve($userid),
            'identity' => $identity,
            'learning' => capabilities::has($userid, capabilities::LEARNING_USE)
                && employment::learning_allowed($userid),
            'companyread' => $company,
            'hrwrite' => capabilities::has($userid, capabilities::HR_WRITE),
            'teamread' => !empty($teamscope['allowed']),
            'teamremediation' => !empty($teamscope['allowed'])
                && ($company || capabilities::has($userid, capabilities::TEAM_REMEDIATION)),
            'scope' => $teamscope,
        ];
    }

    public static function scope(int $userid): array {
        return self::for_user($userid)['scope'];
    }

    private static function company_scope(): array {
        global $DB;
        $ids = [];
        foreach ($DB->get_records_select('user', 'deleted = 0 AND suspended = 0 AND id > 1', [], '', 'id') as $user) {
            $userid = (int)$user->id;
            if (accounts::participates($userid) && employment::is_active($userid)) {
                $ids[] = $userid;
            }
        }
        return ['allowed' => true, 'departmentid' => '', 'department' => 'Вся компания', 'userids' => $ids];
    }
}
