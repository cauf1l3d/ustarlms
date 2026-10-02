<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Compatibility bridge for access previously projected from positions.
 * New access decisions use explicit Moodle capabilities through access_context.
 */
final class position_access {
    private const COMPONENT = 'local_ustar';
    private const ROLE_MANAGER = 'ustar_manager';
    private const ROLE_HR = 'ustar_hr';

    /** Ensure the explicit system roles used by USTAR administrators exist. */
    public static function ensure_roles(): array {
        global $DB;

        $context = \context_system::instance();
        $definitions = [
            self::ROLE_MANAGER => [
                'name' => 'USTAR Manager',
                'description' => 'Explicit manager access in USTAR Academy.',
                'caps' => [
                    'local/ustar:use',
                    'local/ustar:viewteam',
                ],
            ],
            self::ROLE_HR => [
                'name' => 'USTAR HR',
                'description' => 'Explicit HR access in USTAR Academy.',
                'caps' => [
                    'local/ustar:use',
                    'local/ustar:hr',
                    'local/ustar:hrmanage',
                ],
            ],
        ];

        $result = [];
        foreach ($definitions as $shortname => $definition) {
            $roleid = (int)$DB->get_field('role', 'id', ['shortname' => $shortname]);
            if (!$roleid) {
                $roleid = (int)create_role(
                    $definition['name'],
                    $shortname,
                    $definition['description']
                );
                set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
            }

            foreach ($definition['caps'] as $capability) {
                $permission = $DB->get_field('role_capabilities', 'permission', [
                    'roleid' => $roleid,
                    'contextid' => $context->id,
                    'capability' => $capability,
                ]);
                if ((int)$permission !== CAP_ALLOW) {
                    assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
                }
            }

            $result[$shortname] = $roleid;
        }

        return $result;
    }

    /** Return the live USTAR position record for a user, if one is assigned. */
    public static function position_for_user(int $userid): ?array {
        $positionid = organization_identity::resolve($userid)['positionid'];
        if ($positionid === '') {
            return null;
        }

        $structure = structure::get(structure::NAME_STRUCTURE);
        foreach ($structure['positions'] ?? [] as $position) {
            if ((string)($position['id'] ?? '') === $positionid) {
                return $position;
            }
        }

        return null;
    }

    /** @deprecated Positions no longer grant access roles. */
    public static function target_role_for_position(?array $position): string {
        return '';
    }

    /**
     * Compatibility no-op. Position changes must never create or remove access.
     * Legacy projected assignments are handled only by the reviewed migration.
     */
    public static function sync_user(int $userid): array {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,username,suspended');
        if (!$user || is_siteadmin($userid)) {
            return ['ok' => true, 'userid' => $userid, 'status' => 'skipped'];
        }

        $position = self::position_for_user($userid);

        return [
            'ok' => true,
            'userid' => $userid,
            'username' => (string)$user->username,
            'positionid' => (string)($position['id'] ?? ''),
            'position' => (string)($position['name'] ?? ''),
            'targetrole' => '',
            'status' => 'explicit_access_required',
        ];
    }

    /** Role-aware first page after a normal sign-in. */
    public static function landing_path(int $userid): string {
        $context = \context_system::instance();
        $access = access_context::for_user($userid);
        if ($access['employment']['status'] !== employment::ACTIVE) {
            return '/local/ustar/profile.php';
        }
        if (has_capability('local/ustar:executive', $context, $userid)) {
            return '/local/ustar/executive.php';
        }
        if (has_capability('local/ustar:hr', $context, $userid)) {
            return '/local/ustar/hr.php';
        }
        if (!empty($access['teamread'])) {
            return '/local/ustar/team.php';
        }

        return '/local/ustar/home.php';
    }
}
