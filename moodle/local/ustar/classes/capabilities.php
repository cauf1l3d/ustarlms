<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 2 capability resolver foundation.
 *
 * Separates business capabilities from position names.
 */
class capabilities {

    public const LEARNING_SELF = 'learning.self';
    public const TEAM_READ = 'team.read';
    public const TEAM_REMEDIATION = 'team.remediation';
    public const COMPANY_READ = 'company.read';

    /**
     * Resolve capabilities for an employee.
     *
     * R10 provides a stable extension point.
     * Detailed mapping is added in R11 after migration inventory.
     */
    public static function for_user(int $userid): array {
        $result = [];

        if (employment::can_receive_learning($userid)) {
            $result[] = self::LEARNING_SELF;
        }

        return $result;
    }

    public static function has(int $userid, string $capability): bool {
        return in_array(
            $capability,
            self::for_user($userid),
            true
        );
    }
}
