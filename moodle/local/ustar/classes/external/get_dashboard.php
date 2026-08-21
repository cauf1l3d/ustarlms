<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class get_dashboard extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER, $DB;
        self::guard();

        $courses = self::user_courses($USER->id);

        $avg = 0;
        $completedcourses = 0;
        if ($courses) {
            $sum = 0;
            foreach ($courses as $c) {
                $sum += $c['progress'];
                if ($c['progress'] >= 100) {
                    $completedcourses++;
                }
            }
            $avg = (int) round($sum / count($courses));
        }

        // XP: course/activity XP + server-awarded Game Hub XP.
        $activitydone = $DB->count_records_select('course_modules_completion',
            'userid = :uid AND completionstate IN (1,2)', ['uid' => $USER->id]);
        $gamexp = 0;
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_game_mastery'))) {
            $gamexp = (int)$DB->get_field_sql(
                'SELECT COALESCE(SUM(xpearned), 0) FROM {local_ustar_game_mastery} WHERE userid = :uid',
                ['uid' => $USER->id]
            );
        }
        $xp = $completedcourses * 100 + (int)$activitydone * 10 + $gamexp;
        $level = (int) floor(sqrt($xp / 50)) + 1;
        $nextlevelxp = 50 * $level * $level;

        // Badges from Moodle.
        $badges = [];
        if (function_exists('badges_get_user_badges')) {
            foreach (badges_get_user_badges($USER->id) as $b) {
                $badges[] = ['name' => $b->name, 'dateissued' => (int)$b->dateissued];
            }
        }

        // Goals.
        $goals = [];
        foreach ($DB->get_records('local_ustar_goals', ['userid' => $USER->id], 'timecreated DESC') as $g) {
            $goals[] = [
                'id' => (int)$g->id, 'title' => $g->title,
                'duedate' => (int)$g->duedate, 'completed' => (bool)$g->completed,
            ];
        }

        // Streak: distinct days with activity completion in last 30 days.
        $sql = "SELECT COUNT(DISTINCT " . $DB->sql_concat(
            "EXTRACT(YEAR FROM to_timestamp(timemodified))",
            "'-'",
            "EXTRACT(DOY FROM to_timestamp(timemodified))") . ")
                  FROM {course_modules_completion}
                 WHERE userid = :uid AND timemodified > :since";
        try {
            $streak = (int)$DB->count_records_sql($sql,
                ['uid' => $USER->id, 'since' => time() - 30 * DAYSECS]);
        } catch (\Throwable $e) {
            // MySQL fallback.
            $streak = 0;
        }

        return ['json' => json_encode([
            'courses'          => $courses,
            'avgProgress'      => $avg,
            'completedCourses' => $completedcourses,
            'xp'               => $xp,
            'gameXp'           => $gamexp,
            'level'            => $level,
            'nextLevelXp'      => $nextlevelxp,
            'badges'           => $badges,
            'goals'            => $goals,
            'activeDays30'     => $streak,
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Dashboard JSON'),
        ]);
    }
}
