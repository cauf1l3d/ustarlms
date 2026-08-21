<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\people;
use local_ustar\structure;

class hr_save_learning extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'skillsjson' => new external_value(PARAM_RAW, 'Full skill array'),
            'matrixjson' => new external_value(PARAM_RAW, 'Position skill matrix'),
        ]);
    }
    public static function execute(string $skillsjson, string $matrixjson): array {
        global $USER;
        self::guard();
        \local_ustar\view_as::assert_writable();
        $ctx = \context_system::instance();
        if (!has_capability('local/ustar:hrmanage', $ctx) && !has_capability('local/ustar:admin', $ctx)) {
            throw new \required_capability_exception($ctx, 'local/ustar:hrmanage', 'nopermissions', '');
        }
        $params = self::validate_parameters(self::execute_parameters(), compact('skillsjson','matrixjson'));
        $skills = json_decode($params['skillsjson'], true);
        $matrix = json_decode($params['matrixjson'], true);
        if (!is_array($skills) || !is_array($matrix) || count($skills) > 500) throw new \invalid_parameter_exception('Invalid learning model');
        $seen = [];
        foreach ($skills as &$skill) {
            $id = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string)($skill['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) throw new \invalid_parameter_exception('Skill ids must be unique');
            $seen[$id] = true; $skill['id'] = $id;
            $skill['name'] = trim((string)($skill['name'] ?? ''));
            if ($skill['name'] === '') throw new \invalid_parameter_exception('Skill name is required');
            $skill['category'] = trim((string)($skill['category'] ?? 'Общее'));
            $skill['courses'] = array_values(array_unique(array_filter(array_map('trim', $skill['courses'] ?? []))));
        }
        unset($skill);
        $st = structure::get(structure::NAME_STRUCTURE);
        $posids = array_fill_keys(array_map(static fn($p) => $p['id'], $st['positions'] ?? []), true);
        $cleanmatrix = [];
        foreach ($matrix as $positionid => $row) {
            if (!isset($posids[$positionid]) || !is_array($row)) continue;
            foreach ($row as $skillid => $level) {
                if (isset($seen[$skillid])) $cleanmatrix[$positionid][$skillid] = max(1, min(5, (int)$level));
            }
        }
        $st['skills'] = array_values($skills);
        $st['matrix'] = $cleanmatrix;
        structure::save(structure::NAME_STRUCTURE, $st);
        people::log_action((int)$USER->id, null, 'learning_model_published', ['skills' => count($skills), 'matrixPositions' => count($cleanmatrix)]);
        return ['json' => json_encode(['ok' => true, 'skills' => count($skills), 'matrixPositions' => count($cleanmatrix)], JSON_UNESCAPED_UNICODE)];
    }
    public static function execute_returns() { return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Learning model publish result')]); }
}
