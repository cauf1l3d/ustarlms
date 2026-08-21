<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class hr_get_person extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
        ]);
    }

    public static function execute(int $userid): array {
        global $DB;
        self::guard();
        require_capability('local/ustar:hr', \context_system::instance());
        ['userid' => $userid] = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);

        $u = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $resolved = structure::resolve_user($userid);
        $st = $resolved['structure'];
        $position = $resolved['position'];
        $courses = self::user_courses($userid);

        $progressbyidn = [];
        foreach ($courses as $c) {
            $progressbyidn[$c['idnumber']] = $c['progress'];
        }

        $skills = [];
        $required = $position ? ($st['matrix'][$position['id']] ?? []) : [];
        foreach ($st['skills'] as $skill) {
            if (!isset($required[$skill['id']])) {
                continue;
            }
            $linked = $skill['courses'];
            $sum = 0;
            foreach ($linked as $idnumber) {
                $sum += $progressbyidn[$idnumber] ?? 0;
            }
            $progress = $linked ? (int)round($sum / count($linked)) : 0;
            $requiredlevel = (int)$required[$skill['id']];
            $currentlevel = min($requiredlevel, (int)floor($progress / 100 * $requiredlevel + 0.001));
            $skills[] = [
                'id' => $skill['id'], 'name' => $skill['name'], 'category' => $skill['category'],
                'progress' => $progress, 'currentLevel' => $currentlevel, 'requiredLevel' => $requiredlevel,
            ];
        }

        $next = null;
        if ($position && !empty($position['next'])) {
            foreach ($st['positions'] as $p) {
                if ($p['id'] === $position['next']) {
                    $next = $p;
                    break;
                }
            }
        }
        $readiness = 0;
        $gaps = [];
        if ($next) {
            $nextreq = $st['matrix'][$next['id']] ?? [];
            $total = 0;
            $earned = 0;
            foreach ($nextreq as $skillid => $level) {
                $total += (int)$level;
                $current = 0;
                foreach ($st['skills'] as $skill) {
                    if ($skill['id'] !== $skillid) {
                        continue;
                    }
                    $sum = 0;
                    foreach ($skill['courses'] as $idnumber) {
                        $sum += $progressbyidn[$idnumber] ?? 0;
                    }
                    $avg = $skill['courses'] ? $sum / count($skill['courses']) : 0;
                    $current = min((int)$level, (int)floor($avg / 100 * (int)$level + 0.001));
                    $earned += $current;
                    if ($current < (int)$level) {
                        $gaps[] = ['id' => $skillid, 'name' => $skill['name'], 'current' => $current, 'required' => (int)$level];
                    }
                    break;
                }
            }
            $readiness = $total ? (int)round($earned / $total * 100) : 0;
        }

        $reviews = [];
        $sql = "SELECT r.id, r.score, r.category, r.period, r.summary, r.timecreated,
                       u.firstname, u.lastname
                  FROM {local_ustar_reviews} r
                  JOIN {user} u ON u.id = r.reviewerid
                 WHERE r.userid = :uid
              ORDER BY r.timecreated DESC";
        foreach ($DB->get_records_sql($sql, ['uid' => $userid], 0, 20) as $review) {
            $reviews[] = [
                'id' => (int)$review->id,
                'score' => (int)$review->score,
                'category' => $review->category,
                'period' => (string)$review->period,
                'summary' => (string)$review->summary,
                'reviewer' => trim($review->firstname . ' ' . $review->lastname),
                'timecreated' => (int)$review->timecreated,
            ];
        }

        $avg = $courses ? (int)round(array_sum(array_column($courses, 'progress')) / count($courses)) : 0;
        return ['json' => json_encode([
            'person' => [
                'id' => (int)$u->id, 'username' => $u->username,
                'firstname' => $u->firstname, 'lastname' => $u->lastname,
                'fullname' => fullname($u), 'email' => $u->email,
                'suspended' => (bool)$u->suspended, 'lastaccess' => (int)$u->lastaccess,
                'role' => $resolved['role'], 'position' => $position, 'department' => $resolved['department'],
                'protected' => is_siteadmin($u) || has_capability('local/ustar:admin', \context_system::instance(), $u->id),
            ],
            'courses' => $courses,
            'avgProgress' => $avg,
            'skills' => $skills,
            'nextPosition' => $next,
            'readiness' => $readiness,
            'gaps' => $gaps,
            'reviews' => $reviews,
            'positions' => array_values($st['positions']),
            'departments' => array_values($st['departments']),
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Person JSON')]);
    }
}
