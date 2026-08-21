<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\assignment;
use local_ustar\people;
use local_ustar\structure;

/** Bulk assignment used by the HR workspace quick-fill tool. */
class hr_bulk_assign_positions extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignmentsjson' => new external_value(PARAM_RAW, 'JSON array: [{userid, positionid}]'),
        ]);
    }

    public static function execute(string $assignmentsjson): array {
        global $DB, $USER;
        self::guard();
        \local_ustar\view_as::assert_writable();
        $context = \context_system::instance();
        require_capability('local/ustar:hrmanage', $context);
        $params = self::validate_parameters(self::execute_parameters(), compact('assignmentsjson'));
        $items = json_decode($params['assignmentsjson'], true);
        if (!is_array($items)) {
            throw new \invalid_parameter_exception('assignmentsjson must contain a JSON array');
        }
        if (count($items) > 250) {
            throw new \invalid_parameter_exception('Maximum 250 assignments per request');
        }

        $st = structure::get(structure::NAME_STRUCTURE);
        $positions = [];
        foreach (($st['positions'] ?? []) as $position) {
            $positions[$position['id']] = true;
        }

        $updated = 0;
        $skipped = 0;
        $errors = [];
        $syncuserids = [];
        $transaction = $DB->start_delegated_transaction();
        foreach ($items as $index => $item) {
            $userid = (int)($item['userid'] ?? 0);
            $positionid = trim((string)($item['positionid'] ?? ''));
            if (!$userid || $positionid === '' || !isset($positions[$positionid])) {
                $errors[] = ['index' => $index, 'userid' => $userid, 'message' => 'Invalid user or position'];
                continue;
            }
            $target = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
            if (!$target) {
                $errors[] = ['index' => $index, 'userid' => $userid, 'message' => 'User not found'];
                continue;
            }
            if (is_siteadmin($target) || $target->id == $USER->id || has_capability('local/ustar:admin', $context, $target->id)) {
                $skipped++;
                continue;
            }
            people::set_position_id($userid, $positionid);
            people::log_action((int)$USER->id, $userid, 'position_bulk_assigned', ['positionid' => $positionid]);
            $syncuserids[$userid] = true;
            $updated++;
        }
        $transaction->allow_commit();

        $sync = [
            'users' => 0,
            'enrolled' => 0,
            'errors' => [],
        ];

        foreach (array_keys($syncuserids) as $syncuserid) {
            try {
                $result = assignment::sync_user((int)$syncuserid);

                $sync['users']++;
                $sync['enrolled'] += count($result['enrolled'] ?? []);

                people::log_action(
                    (int)$USER->id,
                    (int)$syncuserid,
                    'assignment_synced',
                    [
                        'status' => $result['status'] ?? '',
                        'enrolled' => array_values(array_map(
                            static fn($course) => (int)$course['id'],
                            $result['enrolled'] ?? []
                        )),
                    ]
                );
            } catch (\Throwable $e) {
                $sync['errors'][] = [
                    'userid' => (int)$syncuserid,
                    'message' => $e->getMessage(),
                ];

                people::log_action(
                    (int)$USER->id,
                    (int)$syncuserid,
                    'assignment_sync_failed',
                    ['message' => $e->getMessage()]
                );
            }
        }

        return ['json' => json_encode([
            'ok' => count($errors) === 0 && count($sync['errors']) === 0,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'sync' => $sync,
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Bulk assignment result JSON'),
        ]);
    }
}
