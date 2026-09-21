<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 2 employee lifecycle resolver.
 *
 * Separates employee state from Moodle user state.
 *
 * This is intentionally read-oriented in R10:
 * - no automatic migrations;
 * - no destructive updates;
 * - legacy users can be reconciled first.
 */
class employment {

    public const STATE_PENDING = 'pending';
    public const STATE_ACTIVE = 'active';
    public const STATE_SUSPENDED = 'suspended';
    public const STATE_TERMINATED = 'terminated';

    /**
     * Supported lifecycle states.
     */
    public static function states(): array {
        return [
            self::STATE_PENDING,
            self::STATE_ACTIVE,
            self::STATE_SUSPENDED,
            self::STATE_TERMINATED,
        ];
    }

    /**
     * Resolve current employment state.
     *
     * R10 fallback keeps compatibility with existing Moodle accounts.
     * Dedicated employment storage will be introduced in migration phase.
     */
    public static function state_for_user(int $userid): string {
        global $DB;

        $user = $DB->get_record(
            'user',
            [
                'id' => $userid,
                'deleted' => 0,
            ],
            'id,suspended,confirmed',
            IGNORE_MISSING
        );

        if (!$user) {
            return self::STATE_TERMINATED;
        }

        if (!empty($user->suspended)) {
            return self::STATE_SUSPENDED;
        }

        if (empty($user->confirmed)) {
            return self::STATE_PENDING;
        }

        return self::STATE_ACTIVE;
    }

    /**
     * Check whether employee may receive active learning assignments.
     */
    public static function can_receive_learning(int $userid): bool {
        return self::state_for_user($userid) === self::STATE_ACTIVE;
    }
}
