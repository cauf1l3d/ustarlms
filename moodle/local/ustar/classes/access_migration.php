<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Reviewed, idempotent migration away from position-derived access. */
final class access_migration {
    private const PROJECTED_COMPONENT = 'local_ustar';
    private const MIGRATION_COMPONENT = 'local_ustar_migration';
    private const ROLE_ALLOWLIST = ['ustar_manager', 'ustar_hr', 'ustar_executive'];

    public static function report(): array {
        global $DB;
        $context = \context_system::instance();
        $roles = self::roles(false);
        $rows = [];
        foreach ($DB->get_records_select('user', 'deleted = 0 AND id > 1', [], 'id ASC', 'id') as $user) {
            $userid = (int)$user->id;
            if (!accounts::is_business_account($userid)) {
                continue;
            }
            $projected = [];
            $explicit = [];
            foreach ($roles as $shortname => $roleid) {
                $assignments = $DB->get_records('role_assignments', [
                    'roleid' => $roleid, 'userid' => $userid, 'contextid' => $context->id,
                ]);
                foreach ($assignments as $assignment) {
                    if ((string)$assignment->component === self::PROJECTED_COMPONENT) {
                        $projected[$shortname] = $shortname;
                    } else {
                        $explicit[$shortname] = $shortname;
                    }
                }
            }
            $identity = organization_identity::resolve($userid);
            $rows[] = [
                'userid' => $userid,
                'positionid' => (string)$identity['positionid'],
                'employment' => employment::resolve($userid)['status'],
                'projectedroles' => array_values($projected),
                'explicitroles' => array_values($explicit),
                'action' => $projected ? 'review_access_mapping' : 'none',
            ];
        }
        return ['dryrun' => true, 'users' => $rows];
    }

    /**
     * Plan format: {users:[{userid, expectedpositionid, expectedemployment,
     * expectedprojectedroles:[], employment, roles:[]}]}
     */
    public static function apply(array $plan): array {
        global $DB;
        if (empty($plan['users']) || !is_array($plan['users'])) {
            throw new \invalid_parameter_exception('Migration plan must contain users.');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            position_access::ensure_roles();
            $roles = self::roles(false);
            $context = \context_system::instance();
            $rows = [];
            foreach (self::report()['users'] as $row) {
                $rows[(int)$row['userid']] = $row;
            }
            $prepared = [];
            $unchanged = [];
            // Validate every target before modifying any employee's access.
            foreach ($plan['users'] as $entry) {
                if (!is_array($entry) || !is_int($entry['userid'] ?? null)) {
                    throw new \invalid_parameter_exception('Invalid migration employee.');
                }
                $userid = $entry['userid'];
                if ($userid <= 1 || isset($prepared[$userid]) || !isset($rows[$userid])) {
                    throw new \invalid_parameter_exception('Duplicate or unknown migration employee.');
                }
                foreach (['expectedprojectedroles', 'roles'] as $field) {
                    if (!isset($entry[$field]) || !is_array($entry[$field])) {
                        throw new \invalid_parameter_exception('Missing migration role list: ' . $field);
                    }
                    foreach ($entry[$field] as $role) {
                        if (!is_string($role)) {
                            throw new \invalid_parameter_exception('Invalid migration role.');
                        }
                    }
                }
                $current = $rows[$userid];
                $expectedroles = array_values(array_unique($entry['expectedprojectedroles']));
                sort($expectedroles);
                $actualroles = $current['projectedroles'];
                sort($actualroles);
                $desired = array_values(array_unique($entry['roles']));
                sort($desired);
                foreach (array_merge($expectedroles, $desired) as $shortname) {
                    if (!in_array($shortname, self::ROLE_ALLOWLIST, true) || empty($roles[$shortname])) {
                        throw new \invalid_parameter_exception('Unsupported migration role: ' . $shortname);
                    }
                }
                $desiredemployment = (string)($entry['employment'] ?? $current['employment']);
                if (!in_array($desiredemployment, employment::statuses(), true)) {
                    throw new \invalid_parameter_exception('Unsupported employment state.');
                }
                $explicitroles = $current['explicitroles'];
                sort($explicitroles);
                $alreadyapplied = (string)($entry['expectedpositionid'] ?? '') === $current['positionid']
                    && $actualroles === []
                    && $current['employment'] === $desiredemployment
                    && !array_diff($desired, $explicitroles);
                if (!$alreadyapplied && ((string)($entry['expectedpositionid'] ?? '') !== $current['positionid']
                        || (string)($entry['expectedemployment'] ?? '') !== $current['employment']
                        || $expectedroles !== $actualroles)) {
                    throw new \moodle_exception('Migration plan is stale for user ' . $userid);
                }
                $prepared[$userid] = [$actualroles, $desired, $desiredemployment, $alreadyapplied];
                if ($alreadyapplied) {
                    $unchanged[] = $userid;
                }
            }
            $changed = [];
            foreach ($prepared as $userid => [$actualroles, $desired, $desiredemployment, $alreadyapplied]) {
                if ($alreadyapplied) {
                    continue;
                }
                foreach ($actualroles as $shortname) {
                    role_unassign($roles[$shortname], $userid, $context->id, self::PROJECTED_COMPONENT, 0);
                }
                foreach ($desired as $shortname) {
                    $roleid = $roles[$shortname];
                    if (!$DB->record_exists('role_assignments', [
                        'roleid' => $roleid, 'userid' => $userid, 'contextid' => $context->id,
                    ])) {
                        role_assign($roleid, $userid, $context->id, self::MIGRATION_COMPONENT, 0);
                    }
                }
                self::set_employment($userid, $desiredemployment);
                $changed[] = $userid;
            }
            $transaction->allow_commit();
            return ['applied' => true, 'changeduserids' => $changed, 'unchangeduserids' => $unchanged];
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    private static function roles(bool $ensure = false): array {
        global $DB;
        if ($ensure) {
            position_access::ensure_roles();
        }
        $out = [];
        foreach (self::ROLE_ALLOWLIST as $shortname) {
            $roleid = (int)$DB->get_field('role', 'id', ['shortname' => $shortname]);
            if ($roleid) $out[$shortname] = $roleid;
        }
        return $out;
    }

    private static function set_employment(int $userid, string $status): void {
        global $DB;
        if (!in_array($status, employment::statuses(), true)) {
            throw new \invalid_parameter_exception('Unsupported employment state.');
        }
        $now = time();
        $record = $DB->get_record('local_ustar_employment', ['userid' => $userid], '*', IGNORE_MISSING);
        $values = (object)['userid' => $userid, 'status' => $status, 'source' => 'reviewed_migration',
            'approvedby' => null, 'approvedat' => $status === employment::ACTIVE ? $now : null,
            'timemodified' => $now, 'usermodified' => 0];
        if ($record) {
            $values->id = (int)$record->id;
            $DB->update_record('local_ustar_employment', $values);
        } else {
            $values->timecreated = $now;
            $DB->insert_record('local_ustar_employment', $values);
        }
    }
}
