<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\checklists;
use local_ustar\structure;

class hr_get_checklists extends base {
    public static function execute_parameters(): external_function_parameters { return new external_function_parameters([]); }
    public static function execute(): array {
        global $DB;
        self::guard();
        $ctx = \context_system::instance();
        if (!has_capability('local/ustar:hrmanage', $ctx) && !has_capability('local/ustar:admin', $ctx)) {
            throw new \required_capability_exception($ctx, 'local/ustar:hrmanage', 'nopermissions', '');
        }
        $defs = checklists::get();
        $st = structure::get(structure::NAME_STRUCTURE);
        $today = userdate(time(), '%Y-%m-%d');
        $todayruns = $DB->get_records('local_ustar_check_runs', ['datekey' => $today], 'timemodified DESC');
        $completed = 0;
        foreach ($todayruns as $r) { if ($r->status === 'completed') $completed++; }
        $recent = [];
        $sql = "SELECT r.*, u.firstname, u.lastname
                  FROM {local_ustar_check_runs} r
                  JOIN {user} u ON u.id = r.userid
              ORDER BY r.timemodified DESC";
        foreach (array_slice(array_values($DB->get_records_sql($sql, [], 0, 30)), 0, 30) as $r) {
            $recent[] = [
                'id' => (int)$r->id, 'checklistid' => $r->checklistkey, 'userid' => (int)$r->userid,
                'fullname' => trim($r->firstname . ' ' . $r->lastname), 'positionid' => $r->positionid,
                'date' => $r->datekey, 'status' => $r->status, 'done' => (int)$r->doneitems,
                'total' => (int)$r->totalitems, 'score' => (int)$r->score, 'comment' => (string)$r->comment,
                'completedAt' => (int)$r->completedat,
            ];
        }
        return ['json' => json_encode([
            'definitions' => $defs,
            'positions' => array_values($st['positions'] ?? []),
            'departments' => array_values($st['departments'] ?? []),
            'today' => ['date' => $today, 'runs' => count($todayruns), 'completed' => $completed],
            'recent' => $recent,
        ], JSON_UNESCAPED_UNICODE)];
    }
    public static function execute_returns() { return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'HR checklist editor and results')]); }
}
