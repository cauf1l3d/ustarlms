<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

/**
 * Live HR workspace dataset. This endpoint intentionally reads from the same
 * Moodle/USTAR sources used by the rest of the product; there is no shadow HR database.
 */
class hr_get_workspace extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB;
        self::guard();
        require_capability('local/ustar:hr', \context_system::instance());

        $st = structure::get(structure::NAME_STRUCTURE);
        $positions = array_values($st['positions'] ?? []);
        $departments = array_values($st['departments'] ?? []);
        $skills = array_values($st['skills'] ?? []);
        $matrix = $st['matrix'] ?? [];

        $posmap = [];
        foreach ($positions as $p) {
            $posmap[$p['id']] = $p;
        }

        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.department AS profiledepartment,
                       u.suspended, u.lastaccess, TRIM(d.data) AS positionid
                  FROM {user} u
             LEFT JOIN {user_info_field} f ON f.shortname = 'ustar_position'
             LEFT JOIN {user_info_data} d ON d.userid = u.id AND d.fieldid = f.id
                 WHERE u.deleted = 0 AND u.id > 1
              ORDER BY u.lastname, u.firstname";
        $records = $DB->get_records_sql($sql);

        $people = [];
        $assigned = 0;
        $unassigned = 0;
        $occupancy = [];
        foreach ($positions as $p) {
            $occupancy[$p['id']] = 0;
        }
        foreach ($records as $u) {
            $positionid = trim((string)$u->positionid);
            $p = $posmap[$positionid] ?? null;
            if ($p) {
                $assigned++;
                $occupancy[$p['id']] = ($occupancy[$p['id']] ?? 0) + 1;
            } else {
                $unassigned++;
            }
            $people[] = [
                'id' => (int)$u->id,
                'username' => $u->username,
                'fullname' => trim($u->firstname . ' ' . $u->lastname),
                'firstname' => $u->firstname,
                'lastname' => $u->lastname,
                'email' => $u->email,
                'profileDepartment' => trim((string)$u->profiledepartment),
                'suspended' => (bool)$u->suspended,
                'lastaccess' => (int)$u->lastaccess,
                'positionid' => $p['id'] ?? '',
                'position' => $p['name'] ?? '',
                'department' => $p['department'] ?? '',
                'protected' => is_siteadmin($u) || has_capability('local/ustar:admin', \context_system::instance(), $u->id),
            ];
        }

        // Content coverage: all real Moodle courses are visible to HR; skills reference them by idnumber.
        $referenced = [];
        foreach ($skills as $skill) {
            foreach (($skill['courses'] ?? []) as $idnumber) {
                $idnumber = trim((string)$idnumber);
                if ($idnumber !== '') { $referenced[$idnumber] = true; }
            }
        }
        $coursemap = [];
        $courserows = [];
        $allcourses = $DB->get_records_select('course', 'id <> :siteid', ['siteid' => SITEID], 'fullname ASC', 'id,fullname,shortname,idnumber,visible');
        foreach ($allcourses as $course) {
            $modules = (int)$DB->count_records('course_modules', ['course' => $course->id]);
            $idnumber = trim((string)$course->idnumber);
            $row = [
                'id' => (int)$course->id,
                'idnumber' => $idnumber,
                'name' => $course->fullname,
                'shortname' => $course->shortname,
                'visible' => (bool)$course->visible,
                'modules' => $modules,
                'linked' => $idnumber !== '' && isset($referenced[$idnumber]),
            ];
            if ($idnumber !== '') { $coursemap[$idnumber] = $row; }
            $courserows[] = $row;
        }

        $skillrows = [];
        $skillswithcourses = 0;
        foreach ($skills as $skill) {
            $refs = array_values(array_filter(array_map('trim', $skill['courses'] ?? [])));
            if ($refs) {
                $skillswithcourses++;
            }
            $found = 0;
            foreach ($refs as $idnumber) {
                if (isset($coursemap[$idnumber])) {
                    $found++;
                }
            }
            $skillrows[] = [
                'id' => $skill['id'],
                'name' => $skill['name'],
                'category' => $skill['category'] ?? 'Общее',
                'courseRefs' => $refs,
                'coursesFound' => $found,
                'positions' => count(array_filter($matrix, static function($row) use ($skill) {
                    return isset($row[$skill['id']]);
                })),
            ];
        }

        $linkedmodules = 0;
        foreach ($courserows as $course) {
            if (!empty($course['linked'])) { $linkedmodules += $course['modules']; }
        }

        // Game Hub content is already persisted in USTAR tables.
        $games = [];
        $activegames = 0;
        $questioncount = 0;
        $attempts30 = 0;
        $accuracy = 0;
        $dbman = $DB->get_manager();
        if ($dbman->table_exists(new \xmldb_table('local_ustar_games'))) {
            $gamerecords = $DB->get_records('local_ustar_games', null, 'title ASC');
            foreach ($gamerecords as $game) {
                $qcount = (int)$DB->count_records('local_ustar_questions', ['gameid' => $game->id, 'active' => 1]);
                if ($game->active) {
                    $activegames++;
                }
                $questioncount += $qcount;
                $games[] = [
                    'id' => (int)$game->id,
                    'code' => $game->code,
                    'title' => $game->title,
                    'type' => $game->type,
                    'department' => (string)$game->department,
                    'active' => (bool)$game->active,
                    'questions' => $qcount,
                ];
            }
            if ($dbman->table_exists(new \xmldb_table('local_ustar_game_attempts'))) {
                $since = time() - 30 * DAYSECS;
                $attempts30 = (int)$DB->count_records_select('local_ustar_game_attempts', 'timecreated >= :since', ['since' => $since]);
                $correct30 = (int)$DB->count_records_select('local_ustar_game_attempts', 'timecreated >= :since AND iscorrect = 1', ['since' => $since]);
                $accuracy = $attempts30 ? (int)round($correct30 * 100 / $attempts30) : 0;
            }
        }

        $positionswithmatrix = 0;
        $heads = 0;
        $positionrows = [];
        foreach ($positions as $p) {
            $requirements = $matrix[$p['id']] ?? [];
            if ($requirements) {
                $positionswithmatrix++;
            }
            if (!empty($p['ishead'])) {
                $heads++;
            }
            $positionrows[] = $p + [
                'peopleCount' => (int)($occupancy[$p['id']] ?? 0),
                'skillCount' => count($requirements),
            ];
        }

        $missingcourses = [];
        foreach (array_keys($referenced) as $idnumber) {
            if (!isset($coursemap[$idnumber])) {
                $missingcourses[] = $idnumber;
            }
        }
        sort($missingcourses);

        $completeness = [
            'peopleAssigned' => $records ? (int)round($assigned * 100 / count($records)) : 100,
            'positionsMatrix' => $positions ? (int)round($positionswithmatrix * 100 / count($positions)) : 100,
            'skillsLearning' => $skills ? (int)round($skillswithcourses * 100 / count($skills)) : 100,
            'coursesResolved' => $referenced ? (int)round(count($coursemap) * 100 / count($referenced)) : 100,
            'gamesReady' => $games ? (int)round(count(array_filter($games, static fn($g) => $g['active'] && $g['questions'] > 0)) * 100 / count($games)) : 0,
        ];
        $completeness['academy'] = (int)round(array_sum($completeness) / count($completeness));

        return ['json' => json_encode([
            'people' => $people,
            'positions' => $positionrows,
            'departments' => $departments,
            'skills' => $skillrows,
            'matrix' => $matrix,
            'courses' => $courserows,
            'games' => $games,
            'stats' => [
                'people' => count($people),
                'assigned' => $assigned,
                'unassigned' => $unassigned,
                'positions' => count($positions),
                'heads' => $heads,
                'skills' => count($skills),
                'linkedCourses' => count(array_filter($courserows, static fn($c) => !empty($c['linked']))),
                'allCourses' => count($courserows),
                'linkedModules' => $linkedmodules,
                'games' => count($games),
                'activeGames' => $activegames,
                'questions' => $questioncount,
                'attempts30' => $attempts30,
                'gameAccuracy' => $accuracy,
            ],
            'completeness' => $completeness,
            'gaps' => [
                'unassignedPeople' => array_values(array_slice(array_filter($people, static fn($p) => $p['positionid'] === ''), 0, 40)),
                'positionsWithoutMatrix' => array_values(array_map(static fn($p) => ['id' => $p['id'], 'name' => $p['name']], array_filter($positionrows, static fn($p) => $p['skillCount'] === 0))),
                'skillsWithoutLearning' => array_values(array_map(static fn($s) => ['id' => $s['id'], 'name' => $s['name']], array_filter($skillrows, static fn($s) => count($s['courseRefs']) === 0))),
                'missingCourses' => $missingcourses,
                'gamesWithoutQuestions' => array_values(array_map(static fn($g) => ['id' => $g['id'], 'title' => $g['title']], array_filter($games, static fn($g) => $g['questions'] === 0))),
            ],
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Live USTAR HR workspace JSON'),
        ]);
    }
}
