<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;

class save_prefs extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'prefs' => new external_value(PARAM_RAW, 'JSON: {accent, theme, cardStyle}'),
        ]);
    }

    public static function execute(string $prefs): array {
        self::guard();
        \local_ustar\view_as::assert_writable();
        $params = self::validate_parameters(self::execute_parameters(), ['prefs' => $prefs]);

        $decoded = json_decode($params['prefs'], true);
        if (!is_array($decoded)) {
            throw new \invalid_parameter_exception('prefs must be a JSON object');
        }
        // Whitelist keys to keep the preference safe and small.
        $allowed = ['accent', 'theme', 'cardStyle', 'compact'];
        $clean = array_intersect_key($decoded, array_flip($allowed));
        if (isset($clean['accent']) && !preg_match('/^#[0-9a-fA-F]{6}$/', $clean['accent'])) {
            unset($clean['accent']);
        }
        set_user_preference('local_ustar_prefs', json_encode($clean));

        return ['status' => 'ok'];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'status' => new external_value(PARAM_TEXT),
        ]);
    }
}
