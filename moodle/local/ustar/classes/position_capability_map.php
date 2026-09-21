<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 2 R11 position capability mapping.
 *
 * Position names are translated into business capabilities through
 * an explicit mapping layer. This prevents direct permission decisions
 * from depending on position labels.
 */
class position_capability_map {

    public static function capabilities_for_position(string $position): array {
        $position = strtolower(trim($position));

        $map = [
            'консультант' => [
                capabilities::LEARNING_SELF,
            ],
            'руководитель' => [
                capabilities::LEARNING_SELF,
                capabilities::TEAM_READ,
                capabilities::TEAM_REMEDIATION,
            ],
            'hrd' => [
                capabilities::LEARNING_SELF,
                capabilities::TEAM_READ,
                capabilities::TEAM_REMEDIATION,
                capabilities::COMPANY_READ,
            ],
        ];

        return $map[$position] ?? [];
    }
}
