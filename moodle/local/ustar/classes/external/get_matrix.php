<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class get_matrix extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Visibility scoping:
     *  - employee   -> only own position row;
     *  - head       -> all rows of own department;
     *  - superadmin -> full matrix.
     */
    public static function execute(): array {
        global $USER;
        self::guard();

        $resolved = structure::resolve_user($USER->id);
        $st = $resolved['structure'];
        $role = $resolved['role'];
        $position = $resolved['position'];

        $visiblepositions = [];
        foreach ($st['positions'] as $p) {
            if ($role === 'superadmin') {
                $visiblepositions[] = $p;
            } else if ($role === 'head' && $position
                    && $p['department'] === $position['department']) {
                $visiblepositions[] = $p;
            } else if ($position && $p['id'] === $position['id']) {
                $visiblepositions[] = $p;
            }
        }

        $skillids = [];
        $matrix = [];
        foreach ($visiblepositions as $p) {
            $row = $st['matrix'][$p['id']] ?? [];
            $matrix[$p['id']] = $row;
            foreach (array_keys($row) as $sid) {
                $skillids[$sid] = true;
            }
        }
        $skills = array_values(array_filter($st['skills'],
            fn($s) => isset($skillids[$s['id']])));

        return ['json' => json_encode([
            'role'      => $role,
            'positions' => $visiblepositions,
            'skills'    => $skills,
            'matrix'    => $matrix,
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Matrix JSON'),
        ]);
    }
}
