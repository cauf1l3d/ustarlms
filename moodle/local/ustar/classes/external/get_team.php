<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class get_team extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /** Managers use the canonical managed subtree; authorized administrators see the company. */
    public static function execute(): array {
        global $USER, $DB;
        self::guard();
        require_capability('local/ustar:viewteam', \context_system::instance());

        $resolved = structure::resolve_user($USER->id);
        $st = $resolved['structure'];
        $companyaccess = is_siteadmin((int)$USER->id)
            || has_capability('local/ustar:admin', \context_system::instance(), (int)$USER->id);
        $allowedids = null;
        if (!$companyaccess) {
            $scope = \local_ustar\organization_model::manager_scope((int)$USER->id);
            $allowedids = !empty($scope['allowed'])
                ? array_fill_keys(array_map('intval', $scope['userids'] ?? []), true) : [];
            if (!$allowedids) {
                return ['json' => json_encode(['team' => [], 'scope' => 'managed_subtree'], JSON_UNESCAPED_UNICODE)];
            }
        }

        // Users whose profile field ustar_position belongs to visible departments.
        $positionsbyid = [];
        foreach ($st['positions'] as $p) {
            $positionsbyid[$p['id']] = $p;
        }

        $sql = "SELECT d.userid, d.data AS positionid,
                       u.firstname, u.lastname, u.email, u.suspended, u.deleted
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid AND f.shortname = 'ustar_position'
                  JOIN {user} u ON u.id = d.userid
                 WHERE u.deleted = 0 AND u.suspended = 0";
        $params = [];
        if (is_array($allowedids)) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($allowedids), SQL_PARAMS_NAMED, 'teamuser');
            $sql .= " AND u.id {$insql}";
        }
        $records = $DB->get_records_sql($sql, $params);

        $team = [];
        foreach ($records as $rec) {
            if (!\local_ustar\accounts::participates((int)$rec->userid)) {
                continue;
            }
            $pos = $positionsbyid[trim($rec->positionid)] ?? null;
            if (!$pos) {
                continue;
            }
            if (is_array($allowedids) && !isset($allowedids[(int)$rec->userid])) {
                continue;
            }
            $courses = self::user_courses((int)$rec->userid);
            $sum = 0;
            foreach ($courses as $c) {
                $sum += $c['progress'];
            }
            $team[] = [
                'id'         => (int)$rec->userid,
                'fullname'   => $rec->firstname . ' ' . $rec->lastname,
                'position'   => $pos['name'],
                'positionid' => $pos['id'],
                'department' => $pos['department'],
                'avgProgress'=> $courses ? (int)round($sum / count($courses)) : 0,
                'courseCount'=> count($courses),
            ];
        }
        usort($team, fn($a, $b) => $b['avgProgress'] <=> $a['avgProgress']);

        return ['json' => json_encode(['team' => $team, 'scope' => $companyaccess ? 'company' : 'managed_subtree'],
            JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Team JSON'),
        ]);
    }
}
