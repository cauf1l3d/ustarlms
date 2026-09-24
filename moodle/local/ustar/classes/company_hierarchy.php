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
        $positions = people::position_map(structure::get(structure::NAME_STRUCTURE));
        $admin = $viewerid !== null && team_access::company($viewerid);
        $allowed = [];
        $mode = 'full';
        $ownid = '';
        $ownblock = '';
        if ($viewerid !== null && !$admin) {
            $me = org::person($viewerid);
            $access = access_context::for_user($viewerid);
            $ownid = (string)($me['departmentid'] ?? '');
            $configured = people::department_map(structure::get(structure::NAME_STRUCTURE));
            $ownblock = (string)($configured[$ownid]['block'] ?? '');
            if ($ownblock === '' && $ownid !== '') {
                $ownblock = self::business_block((string)($me['department'] ?? ''));
            }
            $mode = !empty($access['teamread']) ? 'leaders' : 'branch';
        }
        if ($viewerid !== null && !$admin) {
            $scope = team_access::learning_scope($viewerid);
            if (!empty($scope['allowed'])) {
                $allowed = array_fill_keys(array_map('intval', $scope['userids'] ?? []), true);
            }
        }
        $blocks = [];
        $blocknames = [
            'commercial' => 'Коммерческий блок',
            'operations' => 'Операционный блок',
            'finance' => 'Финансовый блок',
            'logistics' => 'Блок «Логистика»',
            'administrative' => 'Административный блок',
        ];
        $configuredblocks = organization_structure_editor::blocks();
        foreach ($configuredblocks as $key => $name) {
            if (isset(organization_structure_editor::BLOCKS[$key])
                    && $name === organization_structure_editor::BLOCKS[$key]) {
                $name = $blocknames[$key];
            }
            $blocks[$key] = ['key' => $key, 'name' => $name, 'leaders' => [],
                'members' => [], 'departments' => [], 'people' => 0,
                'islogistics' => $key === 'logistics',
                'isadministrative' => $key === 'administrative',
                'isdirect' => in_array($key, ['logistics', 'administrative'], true)];
        }
        $executives = [];
        $assistants = [];
        $seen = [];
        foreach ($departments as $department) {
            $name = (string)($department['name'] ?? 'Без подразделения');
            $key = (string)($department['block'] ?? '');
            if (!isset($blocks[$key])) { $key = self::business_block($name); }
            $row = ['id' => (string)($department['id'] ?? ''), 'name' => $name,
                'heads' => [], 'members' => [], 'groupsmap' => [], 'groups' => [], 'people' => 0];
            $members = $department['members'] ?? [];
            foreach ($department['groups'] ?? [] as $group) {
                foreach ($group['people'] ?? [] as $person) {
                    $person['_groupkey'] = (string)($group['id'] ?? $group['positionid'] ?? $group['name'] ?? 'group');
                    $person['_groupname'] = (string)($group['name'] ?? 'Команда');
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
                    $companyrole = (string)($positions[(string)($person['positionid'] ?? '')]['companyrole'] ?? '');
                    $role = self::business_label((string)($person['position'] ?? ''));
                    if ($companyrole === 'executive' || ($companyrole === '' && $role === 'генеральный директор')) {
                        $executives[] = $person;
                        continue;
                    }
                    $leaderblock = [
                        'commercial_director' => 'commercial',
                        'operations_director' => 'operations',
                        'finance_director' => 'finance',
                    ][$companyrole] ?? '';
                    if ($companyrole === '') { $leaderblock = [
                        'коммерческий директор' => 'commercial',
                        'операционный директор' => 'operations',
                        'финансовый директор' => 'finance',
                    ][$role] ?? ''; }
                    if ($leaderblock !== '') {
                        if ($mode === 'branch' && $leaderblock !== $ownblock) { continue; }
                        $blocks[$leaderblock]['leaders'][] = $person;
                        $blocks[$leaderblock]['people']++;
                        continue;
                    }
                    // A renamed leadership department must not recreate an Operations branch.
                    $leadership = in_array(self::business_label($name),
                        ['руководитель', 'руководство', 'администрация'], true);
                    if ($companyrole === 'assistant' || ($companyrole === '' && $leadership
                            && (str_contains($role, 'ассистент') || str_contains($role, 'помощник')))) {
                        if ($mode === 'full' || $ownblock === 'administrative') {
                            $person['ishead'] = false;
                            $blocks['administrative']['members'][] = $person;
                            $blocks['administrative']['people']++;
                        }
                        continue;
                    }
                    if ($mode === 'leaders' && $bucket !== 'heads') { continue; }
                    if ($mode === 'branch' && ($ownid === ''
                            || preg_replace('/^department:/', '', $row['id']) !== $ownid)) { continue; }
                    if ($bucket === 'members' && !empty($person['_groupname'])) {
                        $groupkey = (string)$person['_groupkey'];
                        if (!isset($row['groupsmap'][$groupkey])) {
                            $row['groupsmap'][$groupkey] = [
                                'name' => (string)$person['_groupname'], 'people' => [], 'count' => 0,
                            ];
                        }
                        unset($person['_groupkey'], $person['_groupname']);
                        $row['groupsmap'][$groupkey]['people'][] = $person;
                        $row['groupsmap'][$groupkey]['count']++;
                    } else {
                        unset($person['_groupkey'], $person['_groupname']);
                        $row[$bucket][] = $person;
                    }
                    $row['people']++;
                }
            }
            // A leadership department emptied by lifting its directors is not duplicated.
            if ($row['people'] === 0 && !($mode === 'branch' && $ownid !== ''
                    && preg_replace('/^department:/', '', $row['id']) === $ownid)
                    && !($mode === 'full' && str_starts_with(
                        preg_replace('/^department:/', '', (string)$row['id']), 'dept_'))) {
                continue;
            }
            foreach (['heads', 'members'] as $bucket) {
                usort($row[$bucket], static fn(array $a, array $b): int =>
                    strnatcasecmp((string)$a['fullname'], (string)$b['fullname']));
            }
            $row['groups'] = array_values($row['groupsmap']);
            unset($row['groupsmap']);
            foreach ($row['groups'] as &$group) {
                usort($group['people'], static fn(array $a, array $b): int =>
                    strnatcasecmp((string)$a['fullname'], (string)$b['fullname']));
            }
            unset($group);
            usort($row['groups'], static fn(array $a, array $b): int =>
                strnatcasecmp((string)$a['name'], (string)$b['name']));
            $row['hasheads'] = !empty($row['heads']);
            $row['hasmembers'] = !empty($row['members']);
            $row['hasgroups'] = !empty($row['groups']);
            $row['empty'] = $row['people'] === 0;
            $row['expanded'] = $mode === 'branch';
            $blocks[$key]['departments'][] = $row;
            $blocks[$key]['people'] += $row['people'];
        }
        foreach ($blocks as &$block) {
            usort($block['departments'], static fn(array $a, array $b): int =>
                strnatcasecmp($a['name'], $b['name']));
            usort($block['leaders'], static fn(array $a, array $b): int =>
                strnatcasecmp((string)$a['fullname'], (string)$b['fullname']));
            usort($block['members'], static fn(array $a, array $b): int =>
                strnatcasecmp((string)$a['fullname'], (string)$b['fullname']));
            $block['hasleaders'] = !empty($block['leaders']);
            $block['hasmembers'] = !empty($block['members']);
            $block['hasdepartments'] = !empty($block['departments']);
            $block['leadernames'] = implode(', ', array_column($block['leaders'], 'fullname'));
        }
        unset($block);
        $blocks = array_filter($blocks, static fn(array $b): bool =>
            $b['hasleaders'] || $b['hasmembers'] || $b['hasdepartments']
                || ($mode === 'full' && str_starts_with((string)$b['key'], 'block_')));
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
        if (in_array($name, ['руководитель', 'руководство', 'администрация'], true)) {
            return 'administrative';
        }
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
                'block' => (string)($department['block'] ?? ''),
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

        foreach (organization_directory::users(true) as $u) {
            $positionid = (string)$u->positionid;

            $position =
                $positionmap[$positionid]
                ?? null;

            if (!$position) {
                $departmentid = '__unassigned';
                if (!isset($rows[$departmentid])) {
                    $rows[$departmentid] = ['id' => $departmentid, 'name' => 'Не распределены по оргструктуре',
                        'heads' => [], 'hasheads' => false, 'groupsmap' => [], 'groups' => [], 'people' => 0];
                }
                $person = org::person((int)$u->id, fullname($u));
                $person['avatarurl'] = team_presenter::avatar_url((int)$u->id, 64);
                $rows[$departmentid]['groupsmap']['unassigned'] ??= [
                    'positionid' => '', 'name' => 'Требуется назначить должность', 'people' => [], 'count' => 0,
                ];
                $rows[$departmentid]['groupsmap']['unassigned']['people'][] = $person;
                $rows[$departmentid]['groupsmap']['unassigned']['count']++;
                $rows[$departmentid]['people']++;
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
                $departmentid = '__unassigned';
                if (!isset($rows[$departmentid])) {
                    $rows[$departmentid] = ['id' => $departmentid, 'name' => 'Не распределены по оргструктуре',
                        'heads' => [], 'hasheads' => false, 'groupsmap' => [], 'groups' => [], 'people' => 0];
                }
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
            $grouplabel = (string)($position['name'] ?? $positionid);
            $managerid = org::manager_id((int)$u->id);

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
                    'id' => $groupkey,
                    'name' => $grouplabel,
                    'people' => [],
                    'count' => 0,
                    'managers' => [],
                ];
            }

            if ($managerid > 0 && $managerid !== (int)$u->id) {
                $manager = org::person($managerid);
                $rows[$departmentid]['groupsmap'][$groupkey]['managers'][$managerid] =
                    (string)($manager['fullname'] ?? '');
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
                $managers = array_values(array_filter((array)($group['managers'] ?? [])));
                if (count($managers) === 1 && str_starts_with((string)$group['name'], 'МОП ')) {
                    $group['name'] .= ' · руководитель ' . $managers[0];
                }
                unset($group['managers']);
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
