<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class get_skills extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;
        self::guard();

        $resolved = structure::resolve_user($USER->id);
        $st = $resolved['structure'];
        $position = $resolved['position'];

        $required = $position ? ($st['matrix'][$position['id']] ?? []) : [];
        $courses = self::user_courses($USER->id);
        $progressbyidn = [];
        foreach ($courses as $c) {
            $progressbyidn[$c['idnumber']] = $c['progress'];
        }

        $skills = [];
        foreach ($st['skills'] as $skill) {
            if (!isset($required[$skill['id']])) {
                continue;
            }
            // Current level: proportion of linked courses completed
            // scaled to the required level.
            $reqlevel = (int)$required[$skill['id']];
            $linked = $skill['courses'];
            $done = 0;
            $sum = 0;
            foreach ($linked as $idn) {
                $p = $progressbyidn[$idn] ?? 0;
                $sum += $p;
                if ($p >= 100) {
                    $done++;
                }
            }
            $avgp = $linked ? $sum / count($linked) : 0;
            $currentlevel = min($reqlevel, (int)floor($avgp / 100 * $reqlevel + 0.001));
            // Which other positions share this skill (shared course access).
            $sharedwith = [];
            foreach ($st['matrix'] as $posid => $skmap) {
                if ($posid !== $position['id'] && isset($skmap[$skill['id']])) {
                    foreach ($st['positions'] as $p2) {
                        if ($p2['id'] === $posid) {
                            $sharedwith[] = $p2['name'];
                        }
                    }
                }
            }
            $skills[] = [
                'id'            => $skill['id'],
                'name'          => $skill['name'],
                'category'      => $skill['category'],
                'requiredLevel' => $reqlevel,
                'currentLevel'  => $currentlevel,
                'progress'      => (int)round($avgp),
                'courses'       => $linked,
                'sharedWith'    => array_values(array_unique($sharedwith)),
            ];
        }

        return ['json' => json_encode(['skills' => $skills], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Skills JSON'),
        ]);
    }
}
