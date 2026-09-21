<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Unified access context for employee-facing modules.
 *
 * Provides a migration point from direct role checks to capability checks.
 */
class access_context {

    public static function for_user(int $userid): array {
        return [
            'userid' => $userid,
            'employment_state' => employment::state_for_user($userid),
            'capabilities' => employee_capabilities::for_user($userid),
        ];
    }
}
