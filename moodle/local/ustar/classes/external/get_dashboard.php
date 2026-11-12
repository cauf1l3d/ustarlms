<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\achievement_summary;

class get_dashboard extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER, $DB;
        self::guard();

        $courses = self::user_courses($USER->id);

        $avg = 0;
        if ($courses) {
            $sum = 0;
            foreach ($courses as $c) {
                $sum += $c['progress'];
            }
            $avg = (int) round($sum / count($courses));
        }

        $achievements = achievement_summary::read((int)$USER->id, $courses);

        // Goals.
        $goals = [];
        foreach ($DB->get_records('local_ustar_goals', ['userid' => $USER->id], 'timecreated DESC') as $g) {
            $goals[] = [
                'id' => (int)$g->id, 'title' => $g->title,
                'duedate' => (int)$g->duedate, 'completed' => (bool)$g->completed,
            ];
        }

        return ['json' => json_encode($achievements + [
            'courses' => $courses,
            'avgProgress' => $avg,
            'goals' => $goals,
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Dashboard JSON'),
        ]);
    }
}

