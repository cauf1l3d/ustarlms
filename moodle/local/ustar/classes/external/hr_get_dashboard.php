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

        $activeusers = 0;
        foreach ($DB->get_records_select('user', 'deleted = 0 AND suspended = 0 AND id > 1', [], '', 'id') as $u) {
            if (\local_ustar\accounts::participates((int)$u->id)) {
                $activeusers++;
            }
        }

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
            if (!\local_ustar\accounts::participates((int)$rec->userid)) {
                continue;
            }
            $p = $posmap[trim((string)$rec->positionid)] ?? null;
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

        $activelearners = 0;
        $learnerids = $DB->get_records_sql(
            "SELECT userid, MAX(timemodified) AS lastmodified
               FROM {course_modules_completion}
              WHERE timemodified >= :since
           GROUP BY userid",
            ['since' => $since]
        );
        foreach ($learnerids as $row) {
            if (\local_ustar\accounts::participates((int)$row->userid)) {
                $activelearners++;
            }
        }

        $coursecompletions30 = 0;
        foreach ($DB->get_records_select(
            'course_completions',
            'timecompleted IS NOT NULL AND timecompleted >= :since',
            ['since' => $since],
            '',
            'id,userid'
        ) as $row) {
            if (\local_ustar\accounts::participates((int)$row->userid)) {
                $coursecompletions30++;
            }
        }

        $gameattempts30 = 0;
        $gamecorrect30 = 0;
        $gameaccuracy = 0;
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_game_attempts'))) {
            foreach ($DB->get_records_select(
                'local_ustar_game_attempts',
                'timecreated >= :since',
                ['since' => $since],
                '',
                'id,userid,iscorrect'
            ) as $row) {
                if (!\local_ustar\accounts::participates((int)$row->userid)) {
                    continue;
                }
                $gameattempts30++;
                if (!empty($row->iscorrect)) {
                    $gamecorrect30++;
                }
            }
            if ($gameattempts30 > 0) {
                $gameaccuracy = (int)round($gamecorrect30 / $gameattempts30 * 100);
            }
        }

        $reviews30 = 0;
        $reviewsum = 0.0;
        $avgreviewscore = 0;
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_reviews'))) {
            foreach ($DB->get_records_select(
                'local_ustar_reviews',
                'timecreated >= :since',
                ['since' => $since],
                '',
                'id,userid,score'
            ) as $row) {
                if (!\local_ustar\accounts::participates((int)$row->userid)) {
                    continue;
                }
                $reviews30++;
                $reviewsum += (float)$row->score;
            }
            if ($reviews30 > 0) {
                $avgreviewscore = round($reviewsum / $reviews30, 1);
            }
        }

        $recentactions = [];
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_hr_actions'))) {
            $sql = "SELECT a.id, a.action, a.timecreated, a.actorid, a.targetuserid,
                           actor.firstname AS actorfirstname, actor.lastname AS actorlastname,
                           target.firstname AS targetfirstname, target.lastname AS targetlastname
                      FROM {local_ustar_hr_actions} a
                      JOIN {user} actor ON actor.id = a.actorid
                 LEFT JOIN {user} target ON target.id = a.targetuserid
                  ORDER BY a.timecreated DESC";
            foreach ($DB->get_records_sql($sql, [], 0, 80) as $action) {
                $targetid = (int)($action->targetuserid ?? 0);
                if ($targetid > 0 && !\local_ustar\accounts::is_business_account($targetid)) {
                    continue;
                }
                $actorid = (int)$action->actorid;
                $actor = \local_ustar\accounts::is_business_account($actorid)
                    ? trim($action->actorfirstname . ' ' . $action->actorlastname)
                    : 'Система USTAR';
                $recentactions[] = [
                    'id' => (int)$action->id,
                    'action' => $action->action,
                    'actor' => $actor,
                    'target' => trim((string)$action->targetfirstname . ' ' . (string)$action->targetlastname),
                    'timecreated' => (int)$action->timecreated,
                ];
                if (count($recentactions) >= 8) {
                    break;
                }
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
