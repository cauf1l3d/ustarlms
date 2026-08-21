<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\checklists;
use local_ustar\people;
use local_ustar\structure;

class submit_checklist extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'checklistid' => new external_value(PARAM_ALPHANUMEXT, 'Checklist id'),
            'answersjson' => new external_value(PARAM_RAW, 'JSON object itemid => {done,comment}'),
            'comment' => new external_value(PARAM_TEXT, 'Overall comment', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $checklistid, string $answersjson, string $comment = ''): array {
        global $DB, $USER;
        self::guard();
        \local_ustar\view_as::assert_writable();
        $params = self::validate_parameters(self::execute_parameters(), compact('checklistid', 'answersjson', 'comment'));
        $checklist = checklists::find($params['checklistid']);
        if (!$checklist) {
            throw new \invalid_parameter_exception('Unknown checklist');
        }
        $resolved = structure::resolve_user($USER->id);
        $positionid = $resolved['position']['id'] ?? '';
        if (!checklists::applies_to($checklist, $positionid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
        }
        $answers = json_decode($params['answersjson'], true);
        if (!is_array($answers)) {
            throw new \invalid_parameter_exception('answersjson must be an object');
        }
        $items = checklists::flat_items($checklist);
        $done = 0;
        foreach ($items as $id => $item) {
            if (!empty($answers[$id]['done'])) {
                $done++;
            }
        }
        $total = count($items);
        $score = $total ? (int)round($done * 100 / $total) : 100;
        $today = userdate(time(), '%Y-%m-%d');
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $run = $DB->get_record('local_ustar_check_runs', ['checklistkey' => $checklist['id'], 'userid' => $USER->id, 'datekey' => $today]);
        if (!$run) {
            $run = (object)[
                'checklistkey' => $checklist['id'], 'userid' => $USER->id, 'positionid' => $positionid,
                'datekey' => $today, 'status' => $done === $total ? 'completed' : 'partial',
                'doneitems' => $done, 'totalitems' => $total, 'score' => $score, 'comment' => $params['comment'],
                'startedat' => $now, 'completedat' => $done === $total ? $now : 0, 'timemodified' => $now,
            ];
            $run->id = $DB->insert_record('local_ustar_check_runs', $run);
        } else {
            $run->positionid = $positionid;
            $run->status = $done === $total ? 'completed' : 'partial';
            $run->doneitems = $done; $run->totalitems = $total; $run->score = $score; $run->comment = $params['comment'];
            $run->completedat = $done === $total ? $now : 0; $run->timemodified = $now;
            $DB->update_record('local_ustar_check_runs', $run);
            $DB->delete_records('local_ustar_check_answers', ['runid' => $run->id]);
        }
        foreach ($items as $id => $item) {
            $answer = $answers[$id] ?? [];
            $DB->insert_record('local_ustar_check_answers', (object)[
                'runid' => $run->id, 'itemkey' => $id, 'checked' => !empty($answer['done']) ? 1 : 0,
                'comment' => trim((string)($answer['comment'] ?? '')), 'timecreated' => $now,
            ]);
        }
        people::log_action((int)$USER->id, (int)$USER->id, 'checklist_submitted', ['checklistid' => $checklist['id'], 'score' => $score, 'date' => $today]);
        $transaction->allow_commit();
        return ['json' => json_encode(['ok' => true, 'status' => $run->status, 'done' => $done, 'total' => $total, 'score' => $score], JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Checklist submit result')]);
    }
}
