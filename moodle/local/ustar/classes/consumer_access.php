<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 2 R12 consumer access adapter.
 *
 * Provides migration-safe access checks for application consumers.
 * Consumers should call this layer instead of checking positions directly.
 */
class consumer_access {

    /**
     * Check whether a user may view team information.
     */
    public static function can_read_team(int $userid): bool {
        return employee_capabilities::has($userid, 'team.read');
    }

    /**
     * Check whether a user may start remediation actions.
     */
    public static function can_remediate_team(int $userid): bool {
        return employee_capabilities::has($userid, 'team.remediation');
    }

    /**
     * Check company-wide visibility.
     */
    public static function can_read_company(int $userid): bool {
        return employee_capabilities::has($userid, 'company.read');
    }
}
