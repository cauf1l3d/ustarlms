<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
/** Presentation guidance only; does not grant access or learning evidence. */
final class academy_guide {
    public static function role(): string {
        global $USER;
        if (!isloggedin() || isguestuser() || view_as::active()) { return ''; }
        $c = \context_system::instance();
        if (is_siteadmin($USER) || has_capability('local/ustar:admin', $c) || has_capability('local/ustar:hr', $c)) { return 'hrd'; }
        if (has_capability('local/ustar:executive', $c)) { return 'executive'; }
        if (has_capability('local/ustar:viewteam', $c)) { return 'manager'; }
        return has_capability('local/ustar:use', $c) ? 'employee' : '';
    }
    public static function chapters(string $role): array {
        $catalog = json_decode(file_get_contents(__DIR__ . '/../guide_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
        return array_values(array_filter($catalog, static fn(array $item): bool => in_array($role, $item['roles'], true)));
    }
}
