<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Company structure projection.
 *
 * Exact reporting lines are used when available elsewhere.
 * This class renders the approved staffing structure without
 * fabricating person-to-person direct reporting relations.
 */
final class company_hierarchy {

    public static function build(): array {
        global $DB;

        $structure =
            structure::get(
                structure::NAME_STRUCTURE
            );

        $positionmap =
            people::position_map($structure);

        $departmentmap =
            people::department_map($structure);

        $rows = [];

        foreach ($departmentmap as $id => $department) {
            $rows[$id] = [
                'id' => (string)$id,
                'name' =>
                    (string)(
                        $department['name']
                        ?? $id
                    ),
                'heads' => [],
                'hasheads' => false,
                'groupsmap' => [],
                'groups' => [],
                'people' => 0,
            ];
        }

        $sql =
            "SELECT u.id,u.firstname,u.lastname,
                    d.data AS positionid
               FROM {user} u
               JOIN {user_info_data} d
                 ON d.userid=u.id
               JOIN {user_info_field} f
                 ON f.id=d.fieldid
                AND f.shortname='ustar_position'
              WHERE u.deleted=0
                AND u.suspended=0
                AND u.id>1";

        foreach ($DB->get_records_sql($sql) as $u) {
            if (!accounts::participates((int)$u->id)) {
                continue;
            }

            $positionid =
                trim((string)$u->positionid);

            $position =
                $positionmap[$positionid]
                ?? null;

            if (!$position) {
                continue;
            }

            $departmentid =
                (string)(
                    $position['department']
                    ?? ''
                );

            if (
                $departmentid === ''
                ||
                !isset($rows[$departmentid])
            ) {
                continue;
            }

            $person =
                org::person(
                    (int)$u->id,
                    fullname($u)
                );

            $person['avatarurl'] =
                team_presenter::avatar_url(
                    (int)$u->id,
                    64
                );

            $rows[$departmentid]['people']++;

            if (!empty($position['ishead'])) {
                $rows[
                    $departmentid
                ]['heads'][] = $person;

                continue;
            }

            $groupkey = $positionid;

            if (
                !isset(
                    $rows[
                        $departmentid
                    ]['groupsmap'][$groupkey]
                )
            ) {
                $rows[
                    $departmentid
                ]['groupsmap'][$groupkey] = [
                    'positionid' => $positionid,
                    'name' =>
                        (string)(
                            $position['name']
                            ?? $positionid
                        ),
                    'people' => [],
                    'count' => 0,
                ];
            }

            $rows[
                $departmentid
            ]['groupsmap'][$groupkey]['people'][] =
                $person;

            $rows[
                $departmentid
            ]['groupsmap'][$groupkey]['count']++;
        }

        foreach ($rows as &$department) {
            usort(
                $department['heads'],
                static fn(array $a, array $b): int =>
                    strcasecmp(
                        (string)$a['fullname'],
                        (string)$b['fullname']
                    )
            );

            $department['hasheads'] =
                !empty($department['heads']);

            $department['groups'] =
                array_values(
                    $department['groupsmap']
                );

            unset($department['groupsmap']);

            usort(
                $department['groups'],
                static fn(array $a, array $b): int =>
                    strcasecmp(
                        (string)$a['name'],
                        (string)$b['name']
                    )
            );

            foreach ($department['groups'] as &$group) {
                usort(
                    $group['people'],
                    static fn(array $a, array $b): int =>
                        strcasecmp(
                            (string)$a['fullname'],
                            (string)$b['fullname']
                        )
                );
            }
            unset($group);

            $department['hasgroups'] =
                !empty($department['groups']);

            $department['empty'] =
                (int)$department['people'] === 0;
        }
        unset($department);

        $rows = array_values($rows);

        usort(
            $rows,
            static function(array $a, array $b): int {
                $priority = static function(string $name): int {
                    $n = \core_text::strtolower($name);

                    if (
                        str_contains($n, 'руковод')
                        ||
                        str_contains($n, 'дирекц')
                    ) {
                        return 0;
                    }

                    return 10;
                };

                return [
                    $priority((string)$a['name']),
                    (string)$a['name'],
                ] <=> [
                    $priority((string)$b['name']),
                    (string)$b['name'],
                ];
            }
        );

        return [
            'departments' => $rows,
            'hasdepartments' => !empty($rows),
            'exactreporting' =>
                org::reporting_configured(),
            'structuralmode' =>
                !org::reporting_configured(),
        ];
    }
}
