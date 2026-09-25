<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Read-only resolution of the business position. Never repairs legacy data on read. */
final class organization_identity {
    public static function legacy_position_id(int $userid): string {
        global $DB;
        return trim((string)$DB->get_field_sql(
            "SELECT d.data FROM {user_info_data} d
               JOIN {user_info_field} f ON f.id = d.fieldid
              WHERE d.userid = :userid AND f.shortname = 'ustar_position'",
            ['userid' => $userid]
        ));
    }

    /**
     * Assignment history opts an identity into the normalized model permanently.
     * Expired/ended/invalid appointments must not resurrect an old profile value.
     * ACTING changes authority, never the employee's primary learning position.
     */
    public static function resolve(int $userid, ?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $legacy = self::legacy_position_id($userid);
        $result = ['userid' => $userid, 'source' => 'legacy', 'positionid' => '',
            'departmentid' => '', 'assignmentid' => 0, 'staffplaceid' => 0,
            'legacypositionid' => $legacy, 'conflicts' => [], 'warnings' => []];
        $positions = people::position_map(structure::get(structure::NAME_STRUCTURE));
        $hasassignments = $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_assignments'))
            && $DB->record_exists('local_ustar_assignments', ['userid' => $userid]);
        if (!$hasassignments) {
            if ($legacy !== '' && !isset($positions[$legacy])) {
                $result['conflicts'][] = 'unknown_legacy_position';
            } else {
                $result['positionid'] = $legacy;
                $result['departmentid'] = (string)($positions[$legacy]['department'] ?? '');
            }
            return $result;
        }
        $result['source'] = 'assignment';
        $primary = array_values(array_filter(organization_model::active_assignments($userid, $now),
            static fn($a) => $a->assignmenttype === 'primary'));
        if (count($primary) !== 1) {
            $result['conflicts'][] = $primary ? 'multiple_primary_assignments' : 'no_active_primary_assignment';
            return $result;
        }
        $assignment = $primary[0];
        $result['assignmentid'] = (int)$assignment->id;
        $result['staffplaceid'] = (int)$assignment->staffplaceid;
        $place = organization_model::staff_place((int)$assignment->staffplaceid, $now);
        if (!$place) {
            $result['conflicts'][] = 'inactive_or_missing_staff_place';
            return $result;
        }
        if (!organization_model::valid_place_chain((int)$place->id, $now)) {
            $result['conflicts'][] = 'invalid_staff_place_hierarchy';
            return $result;
        }
        $positionid = (string)$place->positionid;
        if (!isset($positions[$positionid])) {
            $result['conflicts'][] = 'unknown_assigned_position';
            return $result;
        }
        if ((string)$place->departmentid !== (string)($positions[$positionid]['department'] ?? '')) {
            $result['conflicts'][] = 'department_mismatch';
            return $result;
        }
        $result['positionid'] = $positionid;
        $result['departmentid'] = (string)$place->departmentid;
        if ($legacy !== $positionid) {
            $result['warnings'][] = 'legacy_position_mismatch';
        }
        return $result;
    }

    /** Stable, non-mutating input for an explicit reconciliation decision. */
    public static function reconciliation(): array {
        global $DB;
        $now = time();
        $rows = [];
        foreach ($DB->get_records_select('user', 'deleted = 0 AND id > 1', [], 'id', 'id') as $user) {
            if (!accounts::is_business_account((int)$user->id)) {
                continue;
            }
            $row = self::resolve((int)$user->id, $now);
            $row['managerid'] = org::manager_id((int)$user->id);
            $row['legacymanagerid'] = org::reporting_available()
                ? (int)$DB->get_field('local_ustar_reporting', 'managerid', ['userid' => $user->id]) : 0;
            if ($row['managerid'] !== $row['legacymanagerid']) {
                $row['warnings'][] = 'legacy_manager_mismatch';
            }
            $row['action'] = $row['conflicts'] ? 'review_conflicts'
                : ($row['source'] === 'legacy' ? 'review_assignment_migration'
                    : ($row['warnings'] ? 'review_legacy_projection' : 'none'));
            $rows[] = $row;
        }
        return ['dryrun' => true, 'asof' => $now, 'employees' => $rows];
    }
}
