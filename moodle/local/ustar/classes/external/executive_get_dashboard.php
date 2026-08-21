<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class executive_get_dashboard extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB;
        self::guard();
        require_capability('local/ustar:executive', \context_system::instance());

        $st = structure::get(structure::NAME_STRUCTURE);
        $posmap = [];
        foreach ($st['positions'] as $p) { $posmap[$p['id']] = $p; }
        $sql = "SELECT u.id, TRIM(d.data) AS positionid
                  FROM {user} u
             LEFT JOIN {user_info_field} f ON f.shortname = 'ustar_position'
             LEFT JOIN {user_info_data} d ON d.userid = u.id AND d.fieldid = f.id
                 WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 1";
        $rawusers = $DB->get_records_sql($sql);
        $users = [];
        foreach ($rawusers as $u) {
            if (\local_ustar\accounts::participates((int)$u->id)) {
                $users[(int)$u->id] = $u;
            }
        }
        $bydept = [];
        $assigned = 0;
        foreach ($users as $u) {
            $p = $posmap[trim((string)$u->positionid)] ?? null;
            if ($p) {
                $assigned++;
                $bydept[$p['department']] = ($bydept[$p['department']] ?? 0) + 1;
            }
        }
        $since = time() - 30 * DAYSECS;
        $activeids = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid FROM {course_modules_completion} WHERE timemodified >= :since",
            ['since' => $since]
        );
        $activelearners = count(array_filter(
            $activeids,
            static fn($id): bool => \local_ustar\accounts::participates((int)$id)
        ));

        $employeecompletionids = array_values(array_filter(
            $DB->get_fieldset_sql(
                "SELECT DISTINCT userid FROM {course_completions} WHERE timecompleted IS NOT NULL AND timecompleted >= :since",
                ['since' => $since]
            ),
            static fn($id): bool => \local_ustar\accounts::participates((int)$id)
        ));
        $completedcourses = 0;
        if ($employeecompletionids) {
            list($empsql, $empparams) = $DB->get_in_or_equal(
                array_map('intval', $employeecompletionids),
                SQL_PARAMS_NAMED,
                'empid'
            );
            $completedcourses = (int)$DB->count_records_select(
                'course_completions',
                'timecompleted IS NOT NULL AND timecompleted >= :since AND userid ' . $empsql,
                ['since' => $since] + $empparams
            );
        }
        $reviews30 = 0;
        $avgreviewscore = 0;
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_reviews'))) {
            $reviews30 = (int)$DB->count_records_select('local_ustar_reviews', 'timecreated >= :since', ['since' => $since]);
            $avgvalue = $DB->get_field_sql('SELECT AVG(score) FROM {local_ustar_reviews} WHERE timecreated >= :since', ['since' => $since]);
            $avgreviewscore = $avgvalue === false || $avgvalue === null ? 0 : round((float)$avgvalue, 1);
        }
        $departments = [];
        foreach ($st['departments'] as $d) {
            $departments[] = ['id' => $d['id'], 'name' => $d['name'], 'people' => (int)($bydept[$d['id']] ?? 0)];
        }
        return ['json' => json_encode([
            'totalPeople' => count($users),
            'assignedPeople' => $assigned,
            'unassignedPeople' => max(0, count($users) - $assigned),
            'activeLearners30' => $activelearners,
            'completedCourses30' => $completedcourses,
            'reviews30' => $reviews30,
            'avgReviewScore' => $avgreviewscore,
            'departments' => $departments,
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Executive dashboard JSON')]);
    }
}
