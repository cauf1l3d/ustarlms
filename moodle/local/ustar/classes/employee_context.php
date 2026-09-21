<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 2 employee context facade.
 *
 * Provides one read model for consumers migrating away from direct
 * Moodle user/position checks.
 */
class employee_context {

    /**
     * Resolve stable employee context.
     */
    public static function resolve(int $userid): array {
        return [
            'userid' => $userid,
            'employment_state' => employment::state_for_user($userid),
            'can_receive_learning' => employment::can_receive_learning($userid),
            'capabilities' => capabilities::for_user($userid),
        ];
    }

    /**
     * Compatibility helper for migrated consumers.
     */
    public static function is_active(int $userid): bool {
        return employment::state_for_user($userid) === employment::STATE_ACTIVE;
    }
}
