<?php
namespace local_ustar\task_workspace;
use local_ustar\{access_context, organization_identity, people, structure, learning_tasks};
defined('MOODLE_INTERNAL') || die();
final class recipients {
    public static function options(int $actor, string $department = '', string $position = ''): array {
        global $DB;
        $scope = access_context::scope($actor);
        $out = ['departments'=>[], 'positions'=>[], 'people'=>[]];
        if (empty($scope['allowed'])) { return $out; }
        $st = structure::get(structure::NAME_STRUCTURE);
        $departments = people::department_map($st); $positions = people::position_map($st);
        foreach ($scope['userids'] as $id) {
            $id = (int)$id;
            if (!learning_tasks::can_assign($actor, $id)) { continue; }
            $identity = organization_identity::resolve($id);
            $d = $identity['departmentid']; $p = $identity['positionid'];
            if (!$d || !$p || $identity['conflicts']) { continue; }
            $out['departments'][$d] = $departments[$d]['name'] ?? $d;
            if ($d !== $department) { continue; }
            $out['positions'][$p] = $positions[$p]['name'] ?? $p;
            if ($p !== $position) { continue; }
            $u = $DB->get_record('user', ['id'=>$id,'deleted'=>0,'suspended'=>0]);
            if ($u) { $out['people'][$id] = fullname($u) . ' · №' . $id; }
        }
        foreach ($out as &$options) { asort($options, SORT_NATURAL | SORT_FLAG_CASE); }
        return $out;
    }
    public static function validate(int $actor, int $user, string $department, string $position): void {
        $i = organization_identity::resolve($user);
        if (!$department || !$position || $i['conflicts'] || $i['departmentid'] !== $department
                || $i['positionid'] !== $position || !learning_tasks::can_assign($actor, $user)) {
            throw new \invalid_parameter_exception('Назначение сотрудника изменилось. Повторно выберите отдел, должность и сотрудника.');
        }
    }
}
