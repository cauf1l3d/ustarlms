<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Employee lifecycle state resolver.
 *
 * This is intentionally read-oriented. It separates workforce lifecycle
 * semantics from Moodle user suspension and from position assignment.
 */
final class employment {
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const TERMINATED = 'terminated';

    /** @return string[] */
    public static function states(): array {
        return [
            self::PENDING,
            self::ACTIVE,
            self::SUSPENDED,
            self::TERMINATED,
        ];
    }

    /**
     * Resolve current employment state without changing source data.
     */
    public static function state(int $userid): string {
        global $DB;

        if ($userid <= 0) {
            return self::TERMINATED;
        }

        $user = $DB->get_record('user',
            ['id' => $userid],
            'id,deleted,suspended',
            IGNORE_MISSING
        );

        if (!$user || !empty($user->deleted)) {
            return self::TERMINATED;
        }

        if (!empty($user->suspended)) {
            return self::SUSPENDED;
        }

        // Until the HR employment source is introduced, existing workforce
        // accounts remain active. The resolver is the future migration point.
        return self::ACTIVE;
    }

    public static function is_active(int $userid): bool {
        return self::state($userid) === self::ACTIVE;
    }

    public static function can_learn(int $userid): bool {
        return self::is_active($userid) && accounts::participates($userid);
    }
}
