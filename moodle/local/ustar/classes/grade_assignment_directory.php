<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Bounded HR directory for grade assignment UI.
 *
 * Mirrors organization_identity position semantics:
 * - normalized identities come from the active PRIMARY StaffPlace assignment;
 * - identities that have never entered Assignment history may still use the
 *   legacy ustar_position projection;
 * - once any Assignment history exists, legacy position data is never used as
 *   a fallback.
 */
final class grade_assignment_directory {
    public const MAX_RESULTS = 250;

    /** @return array<int,\stdClass> keyed by user id */
    public static function employees_for_position(
        string $departmentid,
        string $positionid,
        int $limit = self::MAX_RESULTS
    ): array {
        global $DB;

        $departmentid = trim($departmentid);
        $positionid = trim($positionid);
        $limit = max(1, min(self::MAX_RESULTS, $limit));

        if ($departmentid === '' || $positionid === '') {
            return [];
        }

        $positions = people::position_map(structure::get(structure::NAME_STRUCTURE));
        $position = $positions[$positionid] ?? null;
        if (!$position || (string)($position['department'] ?? '') !== $departmentid) {
            return [];
        }

        $now = time();
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                  FROM {user} u
             LEFT JOIN {local_ustar_employment} e
                    ON e.userid = u.id
             LEFT JOIN {user_info_field} accountfield
                    ON accountfield.shortname = :accountfield
             LEFT JOIN {user_info_data} accountdata
                    ON accountdata.userid = u.id
                   AND accountdata.fieldid = accountfield.id
                 WHERE u.deleted = 0
                   AND u.suspended = 0
                   AND u.id > 1
                   AND (e.id IS NULL OR e.status = :employmentactive)
                   AND (accountdata.data IS NULL OR accountdata.data = :employeetype)
                   AND (
                        EXISTS (
                            SELECT 1
                              FROM {local_ustar_assignments} a
                              JOIN {local_ustar_staff_places} sp
                                ON sp.id = a.staffplaceid
                             WHERE a.userid = u.id
                               AND a.assignmenttype = :primarytype
                               AND a.status = :assignmentactive
                               AND (a.effectivefrom = 0 OR a.effectivefrom <= :nowfrom)
                               AND (a.effectiveto IS NULL OR a.effectiveto = 0 OR a.effectiveto > :nowto)
                               AND sp.positionid = :positionid
                               AND sp.departmentid = :departmentid
                               AND sp.active = 1
                               AND (sp.effectivefrom = 0 OR sp.effectivefrom <= :placefrom)
                               AND (sp.effectiveto IS NULL OR sp.effectiveto = 0 OR sp.effectiveto > :placeto)
                        )
                        OR (
                            NOT EXISTS (
                                SELECT 1
                                  FROM {local_ustar_assignments} history
                                 WHERE history.userid = u.id
                            )
                            AND EXISTS (
                                SELECT 1
                                  FROM {user_info_data} posdata
                                  JOIN {user_info_field} posfield
                                    ON posfield.id = posdata.fieldid
                                 WHERE posdata.userid = u.id
                                   AND posfield.shortname = :positionfield
                                   AND posdata.data = :legacypositionid
                            )
                        )
                   )
              ORDER BY u.lastname, u.firstname, u.id";

        $rows = $DB->get_records_sql(
            $sql,
            [
                'accountfield' => accounts::FIELD,
                'employmentactive' => employment::ACTIVE,
                'employeetype' => accounts::TYPE_EMPLOYEE,
                'primarytype' => 'primary',
                'assignmentactive' => 'active',
                'nowfrom' => $now,
                'nowto' => $now,
                'positionid' => $positionid,
                'departmentid' => $departmentid,
                'placefrom' => $now,
                'placeto' => $now,
                'positionfield' => 'ustar_position',
                'legacypositionid' => $positionid,
            ],
            0,
            $limit
        );

        foreach ($rows as $id => $row) {
            if (is_siteadmin((int)$id)) {
                unset($rows[$id]);
            }
        }

        return $rows;
    }
}
