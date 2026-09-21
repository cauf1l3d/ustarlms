<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Canonical business operations mapped to explicit Moodle capabilities. */
final class capabilities {
    public const LEARNING_USE = 'learning.use';
    public const TEAM_READ = 'team.read';
    public const TEAM_REMEDIATION = 'team.remediation';
    public const COMPANY_READ = 'company.read';
    public const HR_WRITE = 'hr.write';

    private const MAP = [
        self::LEARNING_USE => ['local/ustar:use'],
        self::TEAM_READ => ['local/ustar:viewteam'],
        self::TEAM_REMEDIATION => ['local/ustar:viewteam'],
        self::COMPANY_READ => [
            'local/ustar:admin', 'local/ustar:hr',
            'local/ustar:hrmanage', 'local/ustar:executive',
        ],
        self::HR_WRITE => ['local/ustar:admin', 'local/ustar:hrmanage'],
    ];

    public static function has(int $userid, string $operation): bool {
        if ($userid <= 1 || !employment::is_active($userid)) {
            return false;
        }
        if (is_siteadmin($userid)) {
            return true;
        }
        $required = self::MAP[$operation] ?? null;
        if ($required === null) {
            throw new \coding_exception('Unknown USTAR business capability: ' . $operation);
        }
        $context = \context_system::instance();
        foreach ($required as $capability) {
            if (has_capability($capability, $context, $userid)) {
                return true;
            }
        }
        return false;
    }
}
