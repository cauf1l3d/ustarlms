<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class hr_get_dashboard extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB;
        self::guard();
        require_capability('local/ustar:hr', \context_system::instance());

        $st = structure::get(structure::NAME_STRUCTURE);
        $posmap = [];
        foreach ($st['positions'] as $p) {
            $posmap[$p['id']] = $p;
        }

        $activeusers = (int)$DB->count_records_select('user', 'deleted = 0 AND suspended = 0 AND id > 1');
        $sql = "SELECT d.userid, TRIM(d.data) AS positionid
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid AND f.shortname = 'ustar_position'
                  JOIN {user} u ON u.id = d.userid
                 WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 1";
        $assignedrecords = $DB->get_records_sql($sql);
        $assigned = 0;
        $heads = 0;
        $bydept = [];
        foreach ($assignedrecords as $rec) {
            $p = $posmap[trim($rec->positionid)] ?? null;
            if (!$p) {
                continue;
            }
            $assigned++;
            $bydept[$p['department']] = ($bydept[$p['department']] ?? 0) + 1;
            if (!empty($p['ishead'])) {
                $heads++;
            }
        }

        $since = time() - 30 * DAYSECS;
        $activelearners = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT userid) FROM {course_modules_completion} WHERE timemodified >= :since",
            ['since' => $since]
        );
        $coursecompletions30 = (int)$DB->count_records_select('course_completions', 'timecompleted IS NOT NULL AND timecompleted >= :since', ['since' => $since]);
        $gameattempts30 = 0;
        $gameaccuracy = 0;
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_game_attempts'))) {
            $gameattempts30 = (int)$DB->count_records_select('local_ustar_game_attempts', 'timecreated >= :since', ['since' => $since]);
            if ($gameattempts30 > 0) {
                $correct = (int)$DB->count_records_select('local_ustar_game_attempts', 'timecreated >= :since AND iscorrect = 1', ['since' => $since]);
                $gameaccuracy = (int)round($correct / $gameattempts30 * 100);
            }
        }

        $reviews30 = 0;
        $avgreviewscore = 0;
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_reviews'))) {
            $reviews30 = (int)$DB->count_records_select('local_ustar_reviews', 'timecreated >= :since', ['since' => $since]);
            $avgvalue = $DB->get_field_sql('SELECT AVG(score) FROM {local_ustar_reviews} WHERE timecreated >= :since', ['since' => $since]);
            $avgreviewscore = $avgvalue === false || $avgvalue === null ? 0 : round((float)$avgvalue, 1);
        }

        $recentactions = [];
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_hr_actions'))) {
            $sql = "SELECT a.id, a.action, a.timecreated,
                           actor.firstname AS actorfirstname, actor.lastname AS actorlastname,
                           target.firstname AS targetfirstname, target.lastname AS targetlastname
                      FROM {local_ustar_hr_actions} a
                      JOIN {user} actor ON actor.id = a.actorid
                 LEFT JOIN {user} target ON target.id = a.targetuserid
                  ORDER BY a.timecreated DESC";
            foreach ($DB->get_records_sql($sql, [], 0, 8) as $action) {
                $recentactions[] = [
                    'id' => (int)$action->id,
                    'action' => $action->action,
                    'actor' => trim($action->actorfirstname . ' ' . $action->actorlastname),
                    'target' => trim((string)$action->targetfirstname . ' ' . (string)$action->targetlastname),
                    'timecreated' => (int)$action->timecreated,
                ];
            }
        }

        $departments = [];
        foreach ($st['departments'] as $d) {
            $departments[] = [
                'id' => $d['id'],
                'name' => $d['name'],
                'people' => (int)($bydept[$d['id']] ?? 0),
            ];
        }

        return ['json' => json_encode([
            'totalPeople' => $activeusers,
            'assignedPeople' => $assigned,
            'unassignedPeople' => max(0, $activeusers - $assigned),
            'heads' => $heads,
            'activeLearners30' => $activelearners,
            'courseCompletions30' => $coursecompletions30,
            'gameAttempts30' => $gameattempts30,
            'gameAccuracy' => $gameaccuracy,
            'reviews30' => $reviews30,
            'avgReviewScore' => $avgreviewscore,
            'recentActions' => $recentactions,
            'departments' => $departments,
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW, 'HR dashboard JSON'),
        ]);
    }
}
