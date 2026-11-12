<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Permanent personal XP ranking inside the viewer's current department. */
final class department_leaderboard {
    public static function for_user(int $userid, int $currentxp): ?array {
        global $USER;
        if ((int)$USER->id !== $userid || view_as::active() || \core\session\manager::is_loggedinas()
                || !accounts::participates($userid)) {
            return null;
        }
        $identity = organization_identity::resolve($userid);
        $department = $identity['departmentid'];
        if ($identity['conflicts'] || $department === '') {
            return null;
        }
        $members = array_filter(organization_directory::users(true),
            static fn($employee) => $employee->departmentid === $department);
        if (!isset($members[$userid])) {
            return null;
        }
        $cache = \cache::make('local_ustar', 'departmentxp');
        $scores = $cache->get_many(array_keys($members));
        $fresh = [$userid => $currentxp];
        $rows = [];
        foreach ($members as $id => $employee) {
            $score = $scores[$id] ?? false;
            if ($id === $userid) {
                $score = $currentxp;
            } else if ($score === false) {
                $score = (int)reward_control::xp($id)['xp'];
                $fresh[$id] = $score;
            }
            $rows[] = ['id' => $id, 'displayname' => fullname($employee), 'xp' => (int)$score,
                'initials' => ui::initials($employee->firstname, $employee->lastname), 'current' => $id === $userid];
        }
        $cache->set_many($fresh);
        usort($rows, static fn($a, $b) => $b['xp'] <=> $a['xp']
            ?: strnatcasecmp($a['displayname'], $b['displayname']) ?: $a['id'] <=> $b['id']);
        $place = 0;
        $previous = null;
        $current = null;
        foreach ($rows as $index => &$row) {
            if ($row['xp'] !== $previous) {
                $place = $index + 1;
                $previous = $row['xp'];
            }
            $row['rank'] = $place;
            if ($row['current']) {
                $current = $row;
            }
        }
        unset($row);
        $names = people::department_map(structure::get(structure::NAME_STRUCTURE));
        return [
            'name' => $names[$department]['name'] ?? $department,
            'rows' => array_slice($rows, 0, 50),
            'current' => $current,
            'truncated' => count($rows) > 50,
        ];
    }
}
