<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\checklists;
use local_ustar\structure;

class get_checklists extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB, $USER;
        self::guard();
        $resolved = structure::resolve_user($USER->id);
        $positionid = $resolved['position']['id'] ?? '';
        $today = userdate(time(), '%Y-%m-%d');
        $rows = [];
        foreach ((checklists::get()['items'] ?? []) as $checklist) {
            if (!checklists::applies_to($checklist, $positionid)) {
                continue;
            }
            $run = $DB->get_record('local_ustar_check_runs', [
                'checklistkey' => $checklist['id'], 'userid' => $USER->id, 'datekey' => $today,
            ]);
            $total = count(checklists::flat_items($checklist));
            $rows[] = $checklist + [
                'today' => $run ? [
                    'status' => $run->status,
                    'done' => (int)$run->doneitems,
                    'total' => (int)$run->totalitems,
                    'score' => (int)$run->score,
                    'comment' => (string)$run->comment,
                    'completedAt' => (int)$run->completedat,
                ] : ['status' => 'pending', 'done' => 0, 'total' => $total, 'score' => 0, 'comment' => '', 'completedAt' => 0],
            ];
        }
        return ['json' => json_encode(['date' => $today, 'positionid' => $positionid, 'checklists' => $rows], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Checklist JSON')]);
    }
}
