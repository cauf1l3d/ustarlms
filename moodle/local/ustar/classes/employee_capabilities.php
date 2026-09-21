<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 2-R11 employee capability resolver.
 *
 * Converts employee context into business permissions.
 */
class employee_capabilities {

    /**
     * Resolve capabilities from employee context.
     */
    public static function resolve(int $userid): array {
        $caps = [];

        if (!employment::can_receive_learning($userid)) {
            return $caps;
        }

        $caps[] = capabilities::LEARNING_SELF;

        return $caps;
    }

    public static function can(int $userid, string $capability): bool {
        return in_array(
            $capability,
            self::resolve($userid),
            true
        );
    }
}
