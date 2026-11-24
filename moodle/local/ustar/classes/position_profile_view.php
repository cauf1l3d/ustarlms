<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Read data for the personal-model tab and the employee's development page. */
final class position_profile_view {
    public static function editor(string $positionid, int $selectedid, bool $creating, array $people,
            array $skills, array $baselevels): array {
        global $DB;
        $models = $DB->get_records(position_profiles::TABLE, ['positionid' => $positionid], 'name ASC, id ASC');
        $selected = $creating ? null : ($selectedid ? ($models[$selectedid] ?? null) : (reset($models) ?: null));
        $selectedid = (int)($selected->id ?? 0);
        $url = new \moodle_url('/local/ustar/positions.php', ['positionid' => $positionid, 'tab' => 'personal']);
        $rows = [];
        foreach ($models as $model) {
            $link = clone $url;
            $link->param('profileid', $model->id);
            $rows[] = ['id' => (int)$model->id, 'name' => $model->name, 'selected' => (int)$model->id === $selectedid,
                'url' => $link->out(false)];
        }
        $newurl = clone $url;
        $newurl->param('newprofile', 1);

        $targets = $selected ? position_profiles::targets($selected) : [];
        $skillrows = [];
        foreach ($skills as $skillid => $skill) {
            $levels = [];
            $baselevel = (int)($baselevels[$skillid] ?? 0);
            for ($level = 0; $level <= 5; ++$level) {
                if ($level && $level < $baselevel) {
                    continue;
                }
                $levels[] = ['value' => $level, 'label' => $level ?: 'По должности',
                    'selected' => $level === (int)($targets[$skillid] ?? 0)];
            }
            $skillrows[] = ['id' => $skillid, 'name' => $skill['name'],
                'base' => $baselevels[$skillid] ?? '', 'levels' => $levels];
        }

        $ladder = (int)(grade_ladders::binding($positionid)->ladderversionid ?? 0);
        $gradechanged = $selected && $selected->gradekey !== '' && (int)$selected->gradeversionid !== $ladder;
        $goals = [];
        foreach (position_profiles::grade_options($positionid) as $grade) {
            $goals[] = $grade + ['selected' => !$gradechanged && ($selected->gradekey ?? '') === $grade['id']];
        }

        $members = [];
        $bindings = [];
        $candidates = array_filter($people, static fn($person) => $person->positionid === $positionid);
        if ($candidates) {
            foreach ($DB->get_records_list(position_profiles::ASSIGNMENTS, 'userid', array_keys($candidates)) as $assignment) {
                $bindings[$assignment->userid] = $assignment;
            }
        }
        foreach ($candidates as $id => $person) {
            $identity = organization_identity::resolve((int)$id);
            if (!employment::is_active((int)$id) || $identity['conflicts'] || $identity['positionid'] !== $positionid) {
                continue;
            }
            $assignment = $bindings[$id] ?? null;
            $bindingvalid = $assignment && $assignment->positionid === $positionid
                && (int)$assignment->staffassignmentid === (int)$identity['assignmentid'];
            $effective = $bindingvalid ? (int)$assignment->profileid : 0;
            $options = [];
            foreach ($models as $model) {
                $options[] = ['id' => (int)$model->id, 'name' => $model->name, 'current' => (int)$model->id === $effective];
            }
            $grade = grade_promotion::current((int)$id);
            $members[] = ['id' => $id, 'name' => fullname($person), 'staffassignment' => $identity['assignmentid'],
                'revision' => (int)($assignment->revision ?? 0), 'grade' => ($grade['label'] ?? '') ?: 'Грейд не назначен',
                'options' => $options, 'hasmodel' => $effective !== 0];
        }
        $steps = [];
        $options = [];
        if ($selected) {
            $activepoints = route_model::points((int)$selected->routeid);
            $used = [];
            foreach ($activepoints as $i => $point) {
                $version = route_model::current_published_version((int)$point->id);
                $used[$point->pointkey] = true;
                if ($version) {
                    $steps[] = ['id' => (int)$point->id, 'number' => $i + 1, 'title' => $version->title,
                        'hasprev' => $i > 0, 'hasnext' => $i < count($activepoints) - 1];
                }
            }
            foreach (position_profiles::learning_options() as $module) {
                if (empty($used['personal_cm_' . $module->id])) {
                    $options[] = ['id' => (int)$module->id, 'name' => $module->fullname . ' / ' . $module->title
                        . ($module->modname === 'quiz' ? ' · Тест' : ' · SCORM')];
                }
            }
        }
        return ['models' => $rows, 'hasmodels' => (bool)$models, 'selectedid' => $selectedid, 'editname' => $selected->name ?? '',
            'revision' => (int)($selected->revision ?? 0), 'hasselected' => (bool)$selected, 'members' => $members,
            'hasmembers' => (bool)$members, 'newurl' => $newurl->out(false), 'skillrows' => $skillrows, 'goals' => $goals,
            'hasgoals' => (bool)$goals, 'gradechanged' => $gradechanged, 'oldgoal' => $gradechanged ? position_profiles::goal($selected) : '',
            'gradeurl' => (new \moodle_url('/local/ustar/grade_ladders.php', ['positionid' => $positionid]))->out(false),
            'steps' => $steps, 'hassteps' => (bool)$steps, 'sources' => $options, 'hassources' => (bool)$options,
            'routerevision' => $selected ? route_model::revision((int)$selected->routeid) : ''];
    }

    public static function for_user(string $positionid, int $userid, array $structure): ?array {
        $model = position_profiles::for_user($positionid, $userid);
        if (!$model) {
            return null;
        }
        $base = $structure['matrix'][$positionid] ?? [];
        $targets = [];
        $levels = position_profiles::targets($model);
        foreach ($structure['skills'] ?? [] as $skill) {
            $id = $skill['id'];
            if (isset($levels[$id])) {
                $targets[] = ['name' => $skill['name'], 'level' => max($levels[$id], (int)($base[$id] ?? 0)),
                    'base' => (int)($base[$id] ?? 0)];
            }
        }
        return ['name' => $model->name, 'goal' => position_profiles::goal($model), 'targets' => $targets,
            'routeurl' => (new \moodle_url('/local/ustar/route.php'))->out(false)];
    }
}
