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

    /**
     * User-approved business-block presentation (2026-09-07).
     * Filters directory data before rendering; never writes staffing/reporting data.
     * Both entry pages supply the real viewer ID. Directory visibility never grants learning access.
     */
    public static function business_view(array $departments, ?int $viewerid = null): array {
        $admin = $viewerid !== null && team_access::company($viewerid);
        $allowed = [];
        $mode = 'full';
        $ownid = '';
        $ownblock = '';
        if ($viewerid !== null && !$admin) {
            $me = org::person($viewerid);
            $positions = people::position_map(structure::get(structure::NAME_STRUCTURE));
            $position = $positions[(string)($me['positionid'] ?? '')] ?? [];
            $ownid = (string)($me['departmentid'] ?? '');
            $ownblock = $ownid !== '' ? self::business_block((string)($me['department'] ?? '')) : '';
            $mode = self::business_label((string)($me['position'] ?? '')) === 'генеральный директор'
                ? 'full' : ((!empty($position['ishead']) || organization_model::is_manager($viewerid))
                    ? 'leaders' : 'branch');
        }
        if ($viewerid !== null && !$admin) {
            $scope = organization_model::manager_scope($viewerid);
            if (!empty($scope['allowed'])) {
                $allowed = array_fill_keys(array_map('intval', $scope['userids'] ?? []), true);
            }
        }
        $blocks = [];
        foreach ([
            'commercial' => 'Коммерческий блок',
            'operations' => 'Операционный блок',
            'finance' => 'Финансовый блок',
            'logistics' => 'Блок «Логистика»',
        ] as $key => $name) {
            $blocks[$key] = ['key' => $key, 'name' => $name, 'leaders' => [],
                'departments' => [], 'people' => 0, 'islogistics' => $key === 'logistics'];
        }
        $executives = [];
        $assistants = [];
        $seen = [];
        foreach ($departments as $department) {
            $name = (string)($department['name'] ?? 'Без подразделения');
            $key = self::business_block($name);
            $row = ['id' => (string)($department['id'] ?? ''), 'name' => $name,
                'heads' => [], 'members' => [], 'people' => 0];
            $members = $department['members'] ?? [];
            foreach ($department['groups'] ?? [] as $group) {
                foreach ($group['people'] ?? [] as $person) {
                    $members[] = $person;
                }
            }
            $hadpeople = !empty($department['heads']) || !empty($members);
            foreach (['heads' => $department['heads'] ?? [], 'members' => $members] as $bucket => $list) {
                foreach ($list as $person) {
                    $id = (int)($person['id'] ?? 0);
                    if ($id <= 0 || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    if ($viewerid !== null) {
                        $person['canlearn'] = $admin || isset($allowed[$id]);
                        $person['detailurl'] = $person['canlearn']
                            ? (new \moodle_url('/local/ustar/team_learning.php', ['userid' => $id]))->out(false)
                            : '';
                        $person['isme'] = $id === $viewerid;
                    }
                    // Keep permission flags explicit in each Mustache person context.
                    $person['canlearn'] = !empty($person['canlearn']);
                    $person['detailurl'] = $person['canlearn'] ? (string)($person['detailurl'] ?? '') : '';
                    $person['canlearn'] = $person['canlearn'] && $person['detailurl'] !== '';
                    $person['ishead'] = $bucket === 'heads';
                    $role = self::business_label((string)($person['position'] ?? ''));
                    if ($role === 'генеральный директор') {
                        $executives[] = $person;
                        continue;
                    }
                    $leaderblock = [
                        'коммерческий директор' => 'commercial',
                        'операционный директор' => 'operations',
                        'финансовый директор' => 'finance',
                    ][$role] ?? '';
                    if ($leaderblock !== '') {
                        if ($mode === 'branch' && $leaderblock !== $ownblock) { continue; }
                        $blocks[$leaderblock]['leaders'][] = $person;
                        $blocks[$leaderblock]['people']++;
                        continue;
                    }
                    // A renamed leadership department must not recreate an Operations branch.
                    $leadership = in_array(self::business_label($name),
                        ['руководитель', 'руководство', 'администрация'], true);
                    if ($leadership && (str_contains($role, 'ассистент')
                            || str_contains($role, 'помощник'))) {
                        if ($mode === 'full') { $assistants[] = $person; }
                        continue;
                    }
                    if ($mode === 'leaders' && $bucket !== 'heads') { continue; }
                    if ($mode === 'branch' && ($ownid === ''
                            || preg_replace('/^department:/', '', $row['id']) !== $ownid)) { continue; }
                    $row[$bucket][] = $person;
                    $row['people']++;
                }
            }
            // A leadership department emptied by lifting its directors is not duplicated.
            if ($row['people'] === 0 && !($mode === 'branch' && $ownid !== ''
                    && preg_replace('/^department:/', '', $row['id']) === $ownid)) {
                continue;
            }
            foreach (['heads', 'members'] as $bucket) {
                usort($row[$bucket], static fn(array $a, array $b): int =>
                    strnatcasecmp((string)$a['fullname'], (string)$b['fullname']));
            }
            $row['hasheads'] = !empty($row['heads']);
            $row['hasmembers'] = !empty($row['members']);
            $row['empty'] = false;
            $row['expanded'] = $mode === 'branch';
            $blocks[$key]['departments'][] = $row;
            $blocks[$key]['people'] += $row['people'];
        }
        foreach ($blocks as &$block) {
            usort($block['departments'], static fn(array $a, array $b): int =>
                strnatcasecmp($a['name'], $b['name']));
            usort($block['leaders'], static fn(array $a, array $b): int =>
                strnatcasecmp((string)$a['fullname'], (string)$b['fullname']));
            $block['hasleaders'] = !empty($block['leaders']);
            $block['hasdepartments'] = !empty($block['departments']);
            $block['leadernames'] = implode(', ', array_column($block['leaders'], 'fullname'));
        }
        unset($block);
        $blocks = array_filter($blocks, static fn(array $b): bool =>
            $b['hasleaders'] || $b['hasdepartments']);
        $count = count($executives) + count($assistants) + array_sum(array_column($blocks, 'people'));
        return ['assistants' => $assistants, 'hasassistants' => !empty($assistants),
            'description' => $mode === 'full' ? 'Подразделения, руководители и сотрудники компании.'
                : ($mode === 'leaders' ? 'Руководители блоков и подразделений компании.'
                    : 'Ваше подразделение и руководство вашего блока.'),
            'executives' => $executives, 'hasexecutives' => !empty($executives),
            'blocks' => array_values($blocks), 'people' => $count];
    }

    private static function business_label(string $name): string {
        $name = str_replace('ё', 'е', \core_text::strtolower($name));
        return trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name));
    }

    /** Department labels are aliases of existing records, not new departments. */
    private static function business_block(string $name): string {
        $name = self::business_label($name);
        if (str_contains($name, 'логист') || str_contains($name, 'склад')) {
            return 'logistics';
        }
        if (str_contains($name, 'бухгалтер') || str_contains($name, 'контроля качества')
                || str_contains($name, 'контроль качества')) {
            return 'finance';
        }
        if (in_array($name, ['отдел продаж', 'отдел активных продаж', 'отдел оптовых продаж',
                'отдел корпоративных продаж', 'оптовые продажи', 'активные продажи'], true)) {
            return 'commercial';
        }
        // The requested operational block contains all remaining departments.
        return 'operations';
    }
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
