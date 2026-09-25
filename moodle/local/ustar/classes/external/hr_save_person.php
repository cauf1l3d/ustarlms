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

        $result = \local_ustar\hr_people::save($params, (int)$USER->id);

        return ['json' => json_encode([
            'ok' => true,
            'userid' => (int)$result['userid'],
            'assignment' => $result['assignment'],
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Save result JSON')]);
    }
}

