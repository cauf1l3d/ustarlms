<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\assignment;
use local_ustar\people;
use local_ustar\structure;

class hr_save_person extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, '0 to create a user', VALUE_DEFAULT, 0),
            'username' => new external_value(PARAM_USERNAME, 'Username'),
            'firstname' => new external_value(PARAM_NOTAGS, 'First name'),
            'lastname' => new external_value(PARAM_NOTAGS, 'Last name'),
            'email' => new external_value(PARAM_EMAIL, 'Email'),
            'positionid' => new external_value(PARAM_ALPHANUMEXT, 'USTAR position id', VALUE_DEFAULT, ''),
            'suspended' => new external_value(PARAM_BOOL, 'Suspend account', VALUE_DEFAULT, false),
            'password' => new external_value(PARAM_RAW, 'Initial password for new account', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $userid, string $username, string $firstname, string $lastname, string $email,
            string $positionid = '', bool $suspended = false, string $password = ''): array {
        global $DB, $CFG, $USER;
        self::guard();
        \local_ustar\view_as::assert_writable();
        require_capability('local/ustar:hrmanage', \context_system::instance());
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'userid', 'username', 'firstname', 'lastname', 'email', 'positionid', 'suspended', 'password'
        ));
        extract($params);

        $st = structure::get(structure::NAME_STRUCTURE);
        if ($positionid !== '') {
            $valid = false;
            foreach ($st['positions'] as $position) {
                if ($position['id'] === $positionid) {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                throw new \invalid_parameter_exception('Unknown USTAR position id');
            }
        }

        require_once($CFG->dirroot . '/user/lib.php');
        if ($userid > 0) {
            $target = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
            $context = \context_system::instance();
            $targetisustaradmin = has_capability('local/ustar:admin', $context, $target->id);
            if (is_siteadmin($target) || $target->id == $USER->id || $targetisustaradmin) {
                // HR is intentionally isolated from platform administration. USTAR superadmins are managed outside HR.
                throw new \required_capability_exception($context, 'local/ustar:hrmanage', 'nopermissions', '');
            }
            $update = (object)[
                'id' => $target->id,
                'username' => $username,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => $email,
                'suspended' => $suspended ? 1 : 0,
            ];
            user_update_user($update, false, false);
            people::set_position_id((int)$target->id, $positionid);
            people::log_action((int)$USER->id, (int)$target->id, 'person_updated', [
                'positionid' => $positionid, 'suspended' => (bool)$suspended,
            ]);
            $savedid = (int)$target->id;
        } else {
            if ($password === '') {
                throw new \invalid_parameter_exception('Initial password is required for a new manual account');
            }
            $user = (object)[
                'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
                'username' => $username, 'password' => $password,
                'firstname' => $firstname, 'lastname' => $lastname, 'email' => $email,
                'suspended' => $suspended ? 1 : 0,
            ];
            $savedid = (int)user_create_user($user, true, false);
            set_user_preference('auth_forcepasswordchange', 1, $savedid);
            people::set_position_id($savedid, $positionid);
            people::log_action((int)$USER->id, $savedid, 'person_created', ['positionid' => $positionid]);
        }

        // Project the selected USTAR position into protected workspace access.
        try {
            $accesssync = \local_ustar\position_access::sync_user($savedid);
            people::log_action((int)$USER->id, $savedid, 'position_access_synced', [
                'positionid' => $positionid,
                'targetrole' => $accesssync['targetrole'] ?? '',
            ]);
        } catch (\Throwable $e) {
            people::log_action((int)$USER->id, $savedid, 'position_access_sync_failed', [
                'positionid' => $positionid,
                'message' => $e->getMessage(),
            ]);
        }

        /*
         * Apply position-derived Moodle access immediately.
         *
         * User creation/update itself must remain valid even if an
         * enrolment source is temporarily broken. The scheduled
         * reconciliation task will retry later.
         */
        try {
            $assignmentsync = assignment::sync_user($savedid);

            people::log_action(
                (int)$USER->id,
                $savedid,
                'assignment_synced',
                [
                    'positionid' => $positionid,
                    'status' => $assignmentsync['status'] ?? '',
                    'enrolled' => array_values(array_map(
                        static fn($course) => (int)$course['id'],
                        $assignmentsync['enrolled'] ?? []
                    )),
                    'missingManualInstance' => array_values(array_map(
                        static fn($course) => (int)$course['id'],
                        $assignmentsync['missingManualInstance'] ?? []
                    )),
                ]
            );
        } catch (\Throwable $e) {
            $assignmentsync = [
                'ok' => false,
                'status' => 'sync_error',
                'enrolled' => [],
                'message' => $e->getMessage(),
            ];

            people::log_action(
                (int)$USER->id,
                $savedid,
                'assignment_sync_failed',
                [
                    'positionid' => $positionid,
                    'message' => $e->getMessage(),
                ]
            );
        }

        return ['json' => json_encode([
            'ok' => true,
            'userid' => $savedid,
            'assignment' => $assignmentsync,
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Save result JSON')]);
    }
}
