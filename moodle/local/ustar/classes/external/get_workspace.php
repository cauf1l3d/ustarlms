<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class get_workspace extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        self::guard();
        $resolved = structure::resolve_user($USER->id);
        $branding = structure::get(structure::NAME_BRANDING);
        $prefs = json_decode(get_user_preferences('local_ustar_prefs', '{}'), true) ?: new \stdClass();
        $context = \context_system::instance();

        return ['json' => json_encode([
            'user' => [
                'id' => (int)$USER->id,
                'fullname' => fullname($USER),
                'firstname' => $USER->firstname,
                'email' => $USER->email,
            ],
            'role' => $resolved['role'],
            'position' => $resolved['position'],
            'department' => $resolved['department'],
            'branding' => $branding,
            'prefs' => $prefs,
            'capabilities' => [
                'admin' => has_capability('local/ustar:admin', $context),
                'hr' => has_capability('local/ustar:hr', $context),
                'hrManage' => has_capability('local/ustar:hrmanage', $context),
                'executive' => has_capability('local/ustar:executive', $context),
            ],
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Workspace bootstrap JSON'),
        ]);
    }
}
