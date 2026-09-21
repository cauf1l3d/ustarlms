<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Stage 2 R12 organization scope resolver.
 *
 * Centralizes visibility boundaries for organization consumers.
 */
class organization_scope {

    public const SCOPE_SELF = 'self';
    public const SCOPE_DEPARTMENT = 'department';
    public const SCOPE_COMPANY = 'company';

    /**
     * Resolve allowed organization scope from capabilities.
     */
    public static function for_user(int $userid): array {
        $scopes = [self::SCOPE_SELF];

        if (employee_capabilities::has($userid, 'team.read')) {
            $scopes[] = self::SCOPE_DEPARTMENT;
        }

        if (employee_capabilities::has($userid, 'company.read')) {
            $scopes[] = self::SCOPE_COMPANY;
        }

        return $scopes;
    }
}
