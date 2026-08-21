<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Position -> USTAR interface access bridge.
 *
 * Position remains the business source of truth. Moodle roles are only the
 * permission projection required by protected USTAR pages.
 */
final class position_access {
    private const COMPONENT = 'local_ustar';
    private const ROLE_MANAGER = 'ustar_manager';
    private const ROLE_HR = 'ustar_hr';

    /** Ensure the system roles used by automatic position projection exist. */
    public static function ensure_roles(): array {
        global $DB;

        $context = \context_system::instance();
        $definitions = [
            self::ROLE_MANAGER => [
                'name' => 'USTAR Manager',
                'description' => 'Position-derived manager access in USTAR Academy.',
                'caps' => [
                    'local/ustar:use',
                    'local/ustar:viewteam',
                ],
            ],
            self::ROLE_HR => [
                'name' => 'USTAR HR',
                'description' => 'Position-derived HR access in USTAR Academy.',
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
        $positionid = people::position_id($userid);
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

    /** Business classification used for automatic system-role projection. */
    public static function target_role_for_position(?array $position): string {
        if (!$position) {
            return '';
        }

        // HR positions get HR workspace permissions automatically.
        if ((string)($position['department'] ?? '') === 'hr') {
            return self::ROLE_HR;
        }

        // Department heads get team-management visibility. This deliberately
        // does NOT auto-grant executive access.
        if (!empty($position['ishead'])) {
            return self::ROLE_MANAGER;
        }

        return '';
    }

    /**
     * Synchronise only role assignments owned by local_ustar.
     * Manually assigned HR/executive/admin roles are never removed.
     */
    public static function sync_user(int $userid): array {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,username,suspended');
        if (!$user || is_siteadmin($userid)) {
            return ['ok' => true, 'userid' => $userid, 'status' => 'skipped'];
        }

        $roles = self::ensure_roles();
        $context = \context_system::instance();
        $position = self::position_for_user($userid);
        $target = self::target_role_for_position($position);

        foreach ([self::ROLE_MANAGER, self::ROLE_HR] as $shortname) {
            $roleid = (int)($roles[$shortname] ?? 0);
            if (!$roleid) {
                continue;
            }

            if ($shortname !== $target && $DB->record_exists('role_assignments', [
                'roleid' => $roleid,
                'userid' => $userid,
                'contextid' => $context->id,
                'component' => self::COMPONENT,
                'itemid' => 0,
            ])) {
                role_unassign($roleid, $userid, $context->id, self::COMPONENT, 0);
            }
        }

        if ($target !== '') {
            $roleid = (int)$roles[$target];

            // If the same role is already assigned manually, do not create a
            // duplicate local_ustar assignment. Manual ownership is preserved.
            $alreadyassigned = $DB->record_exists('role_assignments', [
                'roleid' => $roleid,
                'userid' => $userid,
                'contextid' => $context->id,
            ]);

            if (!$alreadyassigned) {
                role_assign($roleid, $userid, $context->id, self::COMPONENT, 0);
            }
        }

        return [
            'ok' => true,
            'userid' => $userid,
            'username' => (string)$user->username,
            'positionid' => (string)($position['id'] ?? ''),
            'position' => (string)($position['name'] ?? ''),
            'targetrole' => $target,
            'status' => 'synced',
        ];
    }

    /** Role-aware first page after a normal sign-in. */
    public static function landing_path(int $userid): string {
        $context = \context_system::instance();
        $position = self::position_for_user($userid);

        if (has_capability('local/ustar:executive', $context, $userid)) {
            return '/local/ustar/executive.php';
        }

        if (
            has_capability('local/ustar:hr', $context, $userid)
            || (string)($position['department'] ?? '') === 'hr'
        ) {
            return '/local/ustar/hr.php';
        }

        if (
            has_capability('local/ustar:viewteam', $context, $userid)
            || !empty($position['ishead'])
        ) {
            return '/local/ustar/team.php';
        }

        return '/local/ustar/home.php';
    }
}
