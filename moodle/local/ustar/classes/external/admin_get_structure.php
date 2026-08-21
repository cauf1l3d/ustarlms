<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class admin_get_structure extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        self::guard();
        require_capability('local/ustar:admin', \context_system::instance());

        return ['json' => json_encode([
            'structure' => structure::get(structure::NAME_STRUCTURE),
            'branding'  => structure::get(structure::NAME_BRANDING),
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW),
        ]);
    }
}
