<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Bounded HR directory and bulk-initialization helper for grade assignments.
 *
 * Identity semantics deliberately mirror organization_identity:
 * - normalized identities come from the active PRIMARY StaffPlace assignment;
 * - identities that have never entered Assignment history may still use the
 *   legacy ustar_position projection;
 * - once any Assignment history exists, legacy position data is never used as
 *   a fallback.
 *
 * Bulk assignment never changes an existing grade. It only initializes the
 * first grade of the ladder already bound to each employee's current position.
 */
final class grade_assignment_directory {
    public const MAX_RESULTS = 500;

    /**
     * @return array{employees:array<int,\stdClass>,truncated:bool}
     */
    public static function employees_for_scope(
        string $departmentid,
        string $positionid = '',
        int $limit = self::MAX_RESULTS
    ): array {
        global $DB;

        $departmentid = trim($departmentid);
        $positionid = trim($positionid);
        $limit = max(1, min(self::MAX_RESULTS, $limit));

        if ($departmentid === '') {
            return ['employees' => [], 'truncated' => false];
        }

        $structure = structure::get(structure::NAME_STRUCTURE);
        $departments = people::department_map($structure);
        $positions = people::position_map($structure);

        if (!isset($departments[$departmentid])) {
            return ['employees' => [], 'truncated' => false];
        }

        $scopepositions = [];
        foreach ($positions as $id => $position) {
            if ((string)($position['department'] ?? '') !== $departmentid) {
                continue;
            }
            if ($positionid !== '' && (string)$id !== $positionid) {
                continue;
            }
            $scopepositions[(string)$id] = true;
        }

        if ($positionid !== '' && !isset($scopepositions[$positionid])) {
            return ['employees' => [], 'truncated' => false];
        }
        if (!$scopepositions) {
            return ['employees' => [], 'truncated' => false];
        }

        [$canonicalpositionsql, $canonicalpositionparams] = $DB->get_in_or_equal(
            array_keys($scopepositions),
            SQL_PARAMS_NAMED,
            'canonicalpos'
        );
        [$legacypositionsql, $legacypositionparams] = $DB->get_in_or_equal(
            array_keys($scopepositions),
            SQL_PARAMS_NAMED,
            'legacypos'
        );

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
                   AND (
                        accountdata.data IS NULL
                        OR accountdata.data NOT IN (:servicetype, :testtype)
                   )
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
                               AND sp.positionid {$canonicalpositionsql}
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
                                   AND posdata.data {$legacypositionsql}
                            )
                        )
                   )
              ORDER BY u.lastname, u.firstname, u.id";

        $params = array_merge(
            [
                'accountfield' => accounts::FIELD,
                'employmentactive' => employment::ACTIVE,
                'servicetype' => accounts::TYPE_SERVICE,
                'testtype' => accounts::TYPE_TEST,
                'primarytype' => 'primary',
                'assignmentactive' => 'active',
                'nowfrom' => $now,
                'nowto' => $now,
                'departmentid' => $departmentid,
                'placefrom' => $now,
                'placeto' => $now,
                'positionfield' => 'ustar_position',
            ],
            $canonicalpositionparams,
            $legacypositionparams
        );

        // Ask for one extra row so bulk actions never silently omit employees.
        $rows = array_values($DB->get_records_sql($sql, $params, 0, $limit + 1));
        $truncated = count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_siteadmin((int)$row->id)) {
                continue;
            }
            $out[(int)$row->id] = $row;
        }

        return ['employees' => $out, 'truncated' => $truncated];
    }

    /** @return array<int,\stdClass> keyed by user id */
    public static function employees_for_position(
        string $departmentid,
        string $positionid,
        int $limit = self::MAX_RESULTS
    ): array {
        return self::employees_for_scope($departmentid, $positionid, $limit)['employees'];
    }

    /**
     * Preview the exact employees a bulk initial-grade assignment would affect.
     *
     * @return array{
     *   rows:array<int,array<string,mixed>>,
     *   targets:array<int,array<string,mixed>>,
     *   total:int,assignable:int,already:int,noladder:int,review:int,truncated:bool
     * }
     */
    public static function initial_assignment_preview(
        string $departmentid,
        string $positionid = ''
    ): array {
        global $DB;

        $scope = self::employees_for_scope($departmentid, $positionid);
        $employees = $scope['employees'];
        $structure = structure::get(structure::NAME_STRUCTURE);
        $positions = people::position_map($structure);

        $existingbyuser = [];
        if ($employees) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_map('intval', array_keys($employees)),
                SQL_PARAMS_NAMED,
                'gradeuser'
            );
            foreach ($DB->get_records_select(
                'local_ustar_employee_grades',
                "userid {$insql}",
                $inparams
            ) as $record) {
                $existingbyuser[(int)$record->userid] = $record;
            }
        }

        $rows = [];
        $targets = [];
        $counts = ['already' => 0, 'noladder' => 0, 'review' => 0];

        foreach ($employees as $employee) {
            $userid = (int)$employee->id;
            $resolved = organization_identity::resolve($userid);
            $currentpositionid = (string)($resolved['positionid'] ?? '');
            $positionlabel = (string)($positions[$currentpositionid]['name'] ?? $currentpositionid);
            $existing = $existingbyuser[$userid] ?? null;
            $binding = $currentpositionid !== '' ? grade_ladders::binding($currentpositionid) : null;
            $grades = $binding && !empty($binding->ladderversionid)
                ? grade_ladders::grades_for_version((int)$binding->ladderversionid)
                : null;

            $row = [
                'userid' => $userid,
                'fullname' => fullname($employee),
                'email' => (string)$employee->email,
                'positionid' => $currentpositionid,
                'position' => $positionlabel !== '' ? $positionlabel : 'Без должности',
                'gradelabel' => '',
                'status' => '',
                'statuslabel' => '',
                'willassign' => false,
            ];

            if ($resolved['conflicts'] ?? []) {
                $row['status'] = 'review';
                $row['statuslabel'] = 'Нужна проверка оргструктуры';
                $counts['review']++;
            } else if ($existing) {
                if ((string)$existing->positionid === $currentpositionid) {
                    $currentgrades = $grades ?: [];
                    $labels = array_column($currentgrades, 'name', 'id');
                    $row['gradelabel'] = (string)($labels[(string)$existing->gradekey] ?? $existing->gradekey);
                    $row['status'] = 'already';
                    $row['statuslabel'] = 'Грейд уже назначен';
                    $counts['already']++;
                } else {
                    $row['status'] = 'review';
                    $row['statuslabel'] = 'Грейд прежней должности — нужна ручная коррекция';
                    $counts['review']++;
                }
            } else if (!$binding || empty($binding->ladderversionid) || !$grades) {
                $row['status'] = 'noladder';
                $row['statuslabel'] = 'Для должности нет опубликованной лестницы';
                $counts['noladder']++;
            } else {
                $first = reset($grades);
                $row['gradelabel'] = (string)($first['name'] ?? $first['id'] ?? '');
                $row['status'] = 'assign';
                $row['statuslabel'] = 'Будет назначен стартовый грейд';
                $row['willassign'] = true;
                $targets[$userid] = $row;
            }

            $rows[$userid] = $row;
        }

        return [
            'rows' => array_values($rows),
            'targets' => array_values($targets),
            'total' => count($rows),
            'assignable' => count($targets),
            'already' => $counts['already'],
            'noladder' => $counts['noladder'],
            'review' => $counts['review'],
            'truncated' => (bool)$scope['truncated'],
        ];
    }

    /**
     * Assign the first bound grade to the exact server-side scope preview.
     *
     * Existing grades are never overwritten. A scope that exceeds the bounded
     * preview is rejected rather than partially processed.
     */
    public static function bulk_assign_initial(
        string $departmentid,
        string $positionid,
        int $actorid,
        string $reason
    ): array {
        global $DB, $USER;

        if ($actorid <= 0 || (int)$USER->id !== $actorid
                || !has_capability('local/ustar:hrmanage', \context_system::instance(), $actorid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/ustar:hrmanage',
                'nopermissions',
                ''
            );
        }
        view_as::assert_writable();

        $reason = trim(clean_param($reason, PARAM_TEXT));
        if ($reason === '') {
            throw new \invalid_parameter_exception('Укажите основание массового назначения.');
        }

        $preview = self::initial_assignment_preview($departmentid, $positionid);
        if ($preview['truncated']) {
            throw new \invalid_parameter_exception(
                'В выбранном контуре больше ' . self::MAX_RESULTS
                    . ' сотрудников. Уточните должность, чтобы назначение было полным и проверяемым.'
            );
        }
        if (!$preview['targets']) {
            return ['assigned' => 0, 'preview' => $preview];
        }

        $tx = $DB->start_delegated_transaction();
        try {
            $assigned = 0;
            foreach ($preview['targets'] as $target) {
                $userid = (int)$target['userid'];

                // Recheck inside the transaction so a stale browser preview
                // can never overwrite or double-count an existing assignment.
                if ($DB->record_exists('local_ustar_employee_grades', ['userid' => $userid])) {
                    continue;
                }

                $record = grade_promotion::assign_initial_if_bound(
                    $userid,
                    $actorid,
                    $reason
                );
                if ($record) {
                    $assigned++;
                }
            }

            $tx->allow_commit();
            return ['assigned' => $assigned, 'preview' => $preview];
        } catch (\Throwable $e) {
            $tx->rollback($e);
        }
    }
}
