<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Canonical employee directory projection backed by organization_identity. */
final class organization_directory {
    public static function users(bool $activeonly = false): array {
        global $DB;
        $select = 'deleted = 0 AND id > 1' . ($activeonly ? ' AND suspended = 0' : '');
        $rows = [];
        foreach ($DB->get_records_select('user', $select, [], 'lastname ASC, firstname ASC',
            'id,username,firstname,lastname,middlename,email,department,suspended,lastaccess,confirmed') as $user) {
            $userid = (int)$user->id;
            if (!accounts::is_business_account($userid)) continue;
            if ($activeonly && !accounts::participates($userid)) continue;
            $identity = organization_identity::resolve($userid);
            $user->positionid = (string)$identity['positionid'];
            $user->departmentid = (string)$identity['departmentid'];
            $user->employmentstatus = employment::resolve($userid)['status'];
            $rows[$userid] = $user;
        }
        return $rows;
    }
}
