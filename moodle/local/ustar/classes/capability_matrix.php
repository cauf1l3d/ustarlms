<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 2-R11 capability matrix.
 *
 * Keeps business permissions independent from position names.
 *
 * Position is an input for assignment resolution only.
 * Access decisions are made through capabilities.
 */
class capability_matrix {

    /**
     * Capability definitions.
     */
    public static function definitions(): array {
        return [
            'learning.self' => [
                'scope' => 'self',
            ],
            'team.read' => [
                'scope' => 'department',
            ],
            'team.remediation' => [
                'scope' => 'department',
            ],
            'company.read' => [
                'scope' => 'company',
            ],
        ];
    }

    /**
     * Check whether capability exists.
     */
    public static function exists(string $capability): bool {
        return array_key_exists($capability, self::definitions());
    }

    /**
     * Return capability scope.
     */
    public static function scope(string $capability): ?string {
        if (!self::exists($capability)) {
            return null;
        }

        return self::definitions()[$capability]['scope'];
    }
}
