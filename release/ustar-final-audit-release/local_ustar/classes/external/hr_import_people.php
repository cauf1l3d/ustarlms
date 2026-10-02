<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\assignment;
use local_ustar\people;
use local_ustar\structure;

/**
 * HR CSV/import bridge.
 * Existing usernames: only USTAR position is changed (identity fields are not overwritten in bulk).
 * New usernames: a manual Moodle account is created and forced to change the temporary password.
 */
class hr_import_people extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'json' => new external_value(PARAM_RAW, 'JSON array of import rows'),
        ]);
    }

    public static function execute(string $json): array {
        global $DB, $CFG, $USER;
        self::guard();
        \local_ustar\view_as::assert_writable();
        $context = \context_system::instance();
        require_capability('local/ustar:hrmanage', $context);
        ['json' => $json] = self::validate_parameters(self::execute_parameters(), ['json' => $json]);
        $rows = json_decode($json, true);
        if (!is_array($rows) || count($rows) > 250) {
            throw new \invalid_parameter_exception('Import must be an array with at most 250 rows');
        }

        $st = structure::get(structure::NAME_STRUCTURE);
        $positions = [];
        foreach ($st['positions'] ?? [] as $position) {
            $positions[$position['id']] = true;
        }
        require_once($CFG->dirroot . '/user/lib.php');

        $created = 0;
        $updated = 0;
        $errors = [];
        $credentials = [];

        $sync = [
            'users' => 0,
            'enrolled' => 0,
            'accessSynced' => 0,
            'accessErrors' => [],
            'errors' => [],
        ];
        foreach ($rows as $index => $row) {
            $line = $index + 2; // Header is line 1 in a normal CSV.
            try {
                if (!is_array($row)) {
                    throw new \invalid_parameter_exception('Invalid row');
                }
                $username = clean_param(trim((string)($row['username'] ?? '')), PARAM_USERNAME);
                $positionid = clean_param(trim((string)($row['positionid'] ?? '')), PARAM_ALPHANUMEXT);
                if ($username === '') {
                    throw new \invalid_parameter_exception('username is required');
                }
                if ($positionid !== '' && !isset($positions[$positionid])) {
                    throw new \invalid_parameter_exception('unknown positionid');
                }

                $existing = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
                if ($existing) {
                    if (is_siteadmin($existing) || (int)$existing->id === (int)$USER->id
                            || has_capability('local/ustar:admin', $context, $existing->id)) {
                        throw new \required_capability_exception($context, 'local/ustar:hrmanage', 'nopermissions', '');
                    }
                    people::set_position_id((int)$existing->id, $positionid);
                    people::log_action((int)$USER->id, (int)$existing->id, 'bulk_position_updated', ['positionid' => $positionid, 'line' => $line]);

                    try {
                        $access = \local_ustar\position_access::sync_user((int)$existing->id);
                        $sync['accessSynced']++;
                        people::log_action((int)$USER->id, (int)$existing->id, 'position_access_synced', [
                            'positionid' => $positionid,
                            'targetrole' => $access['targetrole'] ?? '',
                            'line' => $line,
                        ]);
                    } catch (\Throwable $e) {
                        $sync['accessErrors'][] = ['line' => $line, 'userid' => (int)$existing->id, 'message' => $e->getMessage()];
                        people::log_action((int)$USER->id, (int)$existing->id, 'position_access_sync_failed', [
                            'positionid' => $positionid, 'message' => $e->getMessage(), 'line' => $line,
                        ]);
                    }

                    try {
                        $result = assignment::sync_user((int)$existing->id);
                        $sync['users']++;
                        $sync['enrolled'] += count($result['enrolled'] ?? []);
                    } catch (\Throwable $e) {
                        $sync['errors'][] = [
                            'line' => $line,
                            'userid' => (int)$existing->id,
                            'username' => $username,
                            'message' => $e->getMessage(),
                        ];
                    }

                    $updated++;
                    continue;
                }

                $firstname = clean_param(trim((string)($row['firstname'] ?? '')), PARAM_NOTAGS);
                $lastname = clean_param(trim((string)($row['lastname'] ?? '')), PARAM_NOTAGS);
                $email = clean_param(trim((string)($row['email'] ?? '')), PARAM_EMAIL);
                if ($firstname === '' || $lastname === '' || $email === '') {
                    throw new \invalid_parameter_exception('firstname, lastname and email are required for a new user');
                }
                if ($DB->record_exists('user', ['email' => $email, 'deleted' => 0])) {
                    throw new \invalid_parameter_exception('email already exists');
                }
                $password = (string)($row['password'] ?? '');
                $generated = false;
                if ($password === '') {
                    $password = 'U!' . random_string(12) . '7a';
                    $generated = true;
                }
                $newuser = (object)[
                    'auth' => 'manual',
                    'confirmed' => 1,
                    'mnethostid' => $CFG->mnet_localhost_id,
                    'username' => $username,
                    'password' => $password,
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'email' => $email,
                    'suspended' => 0,
                ];
                $userid = (int)user_create_user($newuser, true, false);
                set_user_preference('auth_forcepasswordchange', 1, $userid);
                people::set_position_id($userid, $positionid);
                \local_ustar\accounts::set_type($userid, \local_ustar\accounts::TYPE_EMPLOYEE);
                people::log_action((int)$USER->id, $userid, 'person_imported', ['positionid' => $positionid, 'line' => $line]);

                try {
                    $access = \local_ustar\position_access::sync_user($userid);
                    $sync['accessSynced']++;
                    people::log_action((int)$USER->id, $userid, 'position_access_synced', [
                        'positionid' => $positionid,
                        'targetrole' => $access['targetrole'] ?? '',
                        'line' => $line,
                    ]);
                } catch (\Throwable $e) {
                    $sync['accessErrors'][] = ['line' => $line, 'userid' => $userid, 'message' => $e->getMessage()];
                    people::log_action((int)$USER->id, $userid, 'position_access_sync_failed', [
                        'positionid' => $positionid, 'message' => $e->getMessage(), 'line' => $line,
                    ]);
                }

                try {
                    $result = assignment::sync_user($userid);
                    $sync['users']++;
                    $sync['enrolled'] += count($result['enrolled'] ?? []);
                } catch (\Throwable $e) {
                    $sync['errors'][] = [
                        'line' => $line,
                        'userid' => $userid,
                        'username' => $username,
                        'message' => $e->getMessage(),
                    ];
                }

                if ($generated) {
                    $credentials[] = ['username' => $username, 'temporaryPassword' => $password];
                }
                $created++;
            } catch (\Throwable $e) {
                $errors[] = ['line' => $line, 'username' => (string)($row['username'] ?? ''), 'message' => $e->getMessage()];
            }
        }

        return ['json' => json_encode([
            'ok' => count($errors) === 0 && count($sync['errors']) === 0 && count($sync['accessErrors']) === 0,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
            'sync' => $sync,
            // Generated passwords are returned once and never logged by USTAR.
            'credentials' => $credentials,
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Import result JSON')]);
    }
}
