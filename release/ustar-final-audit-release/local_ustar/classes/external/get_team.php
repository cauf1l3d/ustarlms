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

    /**
     * Head sees ONLY own department members (hierarchical visibility).
     * Superadmin sees everyone. Employees get 403 (capability check).
     */
    public static function execute(): array {
        global $USER, $DB;
        self::guard();
        require_capability('local/ustar:viewteam', \context_system::instance());

        $resolved = structure::resolve_user($USER->id);
        $st = $resolved['structure'];
        $role = $resolved['role'];
        $mydept = $resolved['position']['department'] ?? null;

        if ($role !== 'head' && $role !== 'superadmin') {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/ustar:viewteam',
                'nopermissions',
                ''
            );
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
        $records = $DB->get_records_sql($sql);

        $team = [];
        foreach ($records as $rec) {
            if (!\local_ustar\accounts::participates((int)$rec->userid)) {
                continue;
            }
            $pos = $positionsbyid[trim($rec->positionid)] ?? null;
            if (!$pos) {
                continue;
            }
            if ($role !== 'superadmin' && $pos['department'] !== $mydept) {
                continue; // Hierarchical visibility boundary.
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

        return ['json' => json_encode(['team' => $team, 'scope' => $role === 'superadmin' ? 'company' : 'department'],
            JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'json' => new external_value(PARAM_RAW, 'Team JSON'),
        ]);
    }
}
