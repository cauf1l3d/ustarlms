<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Shared checklist access and presentation for native pages and external API. */
final class checklist_service {
    private static function assert_actor(int $userid): void {
        $context = \context_system::instance();
        if (!has_capability('local/ustar:use', $context, $userid)
                || !employment::is_active($userid)) {
            throw new \required_capability_exception($context, 'local/ustar:use', 'nopermissions', '');
        }
    }

    public static function date_key(string $requested = ''): string {
        if ($requested === '') {
            return userdate(time(), '%Y-%m-%d');
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $requested, $parts)
                || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            throw new \invalid_parameter_exception('Укажите корректную дату чек-листа.');
        }
        return $requested;
    }

    public static function list_for(int $userid, string $datekey = ''): array {
        global $DB;
        self::assert_actor($userid);
        $datekey = self::date_key($datekey);
        $positionid = (string)(structure::resolve_user($userid)['position']['id'] ?? '');
        $runs = $DB->get_records('local_ustar_check_runs', ['userid' => $userid, 'datekey' => $datekey]);
        $bykey = [];
        foreach ($runs as $run) {
            $bykey[(string)$run->checklistkey] = $run;
        }
        $rows = [];
        foreach ((checklists::get()['items'] ?? []) as $checklist) {
            if (!checklists::applies_to($checklist, $positionid)) {
                continue;
            }
            $run = $bykey[(string)$checklist['id']] ?? null;
            $total = count(checklists::flat_items($checklist));
            $rows[] = $checklist + ['today' => $run ? [
                'status' => (string)$run->status, 'done' => (int)$run->doneitems,
                'total' => (int)$run->totalitems, 'score' => (int)$run->score,
                'comment' => (string)$run->comment, 'completedAt' => (int)$run->completedat,
            ] : [
                'status' => 'pending', 'done' => 0, 'total' => $total,
                'score' => 0, 'comment' => '', 'completedAt' => 0,
            ]];
        }
        return ['date' => $datekey, 'positionid' => $positionid, 'checklists' => $rows];
    }

    public static function present(int $userid, string $selected = '', string $datekey = ''): array {
        global $DB;
        $payload = self::list_for($userid, $datekey);
        $datekey = $payload['date'];
        $rows = [];
        $current = null;
        foreach ($payload['checklists'] as $checklist) {
            $id = (string)$checklist['id'];
            if ($selected === '') {
                $selected = $id;
            }
            $checklist['url'] = (new \moodle_url('/local/ustar/tasks.php',
                ['tab' => 'checklists', 'id' => $id, 'date' => $datekey]))->out(false);
            $today = $checklist['today'];
            $checklist['complete'] = $today['status'] === 'completed';
            $checklist['selected'] = $id === $selected;
            $checklist['progress'] = !empty($today['total'])
                ? min(100, (int)round((int)$today['done'] * 100 / max(1, (int)$today['total']))) : 0;
            if ($id === $selected) {
                $answers = [];
                $run = $DB->get_record('local_ustar_check_runs',
                    ['checklistkey' => $id, 'userid' => $userid, 'datekey' => $datekey]);
                if ($run) {
                    foreach ($DB->get_records('local_ustar_check_answers', ['runid' => (int)$run->id]) as $answer) {
                        $answers[(string)$answer->itemkey] = $answer;
                    }
                }
                foreach (($checklist['sections'] ?? []) as &$section) {
                    foreach (($section['items'] ?? []) as &$item) {
                        $item['fieldid'] = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$item['id']);
                        $answer = $answers[(string)$item['id']] ?? null;
                        $item['checked'] = $answer && !empty($answer->checked);
                        $item['comment'] = $answer ? (string)$answer->comment : '';
                    }
                    unset($item);
                }
                unset($section);
                $checklist['todaycomment'] = $run ? (string)$run->comment : (string)$today['comment'];
                $current = $checklist;
            }
            $rows[] = $checklist;
        }
        if ($selected !== '' && !$current) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
        }
        return [
            'date' => $datekey, 'checklists' => $rows, 'haschecklists' => !empty($rows),
            'current' => $current, 'hascurrent' => $current !== null,
            'selectedid' => $selected,
            'canedit' => $datekey === self::date_key(),
            'sesskey' => sesskey(),
            'studiourl' => (new \moodle_url('/local/ustar/checklist_studio.php'))->out(false),
            'canstudio' => has_capability('local/ustar:hrmanage', \context_system::instance())
                || has_capability('local/ustar:admin', \context_system::instance()),
        ];
    }

    public static function submit(int $userid, string $id, array $answers, string $comment = ''): array {
        global $DB;
        self::assert_actor($userid);
        view_as::assert_writable();
        $checklist = checklists::find($id);
        $positionid = (string)(structure::resolve_user($userid)['position']['id'] ?? '');
        if (!$checklist || !checklists::applies_to($checklist, $positionid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
        }
        $items = checklists::flat_items($checklist);
        $done = 0;
        foreach ($items as $itemid => $item) {
            if (!empty($answers[$itemid]['done'])) {
                $done++;
            }
        }
        $total = count($items);
        $score = $total ? (int)round($done * 100 / $total) : 100;
        $today = self::date_key();
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $run = $DB->get_record('local_ustar_check_runs',
                ['checklistkey' => $id, 'userid' => $userid, 'datekey' => $today]);
            if (!$run) {
                $run = (object)[
                    'checklistkey' => $id, 'userid' => $userid, 'positionid' => $positionid,
                    'datekey' => $today, 'status' => $done === $total ? 'completed' : 'partial',
                    'doneitems' => $done, 'totalitems' => $total, 'score' => $score,
                    'comment' => $comment, 'startedat' => $now,
                    'completedat' => $done === $total ? $now : 0, 'timemodified' => $now,
                ];
                $run->id = $DB->insert_record('local_ustar_check_runs', $run);
            } else {
                $run->positionid = $positionid;
                $run->status = $done === $total ? 'completed' : 'partial';
                $run->doneitems = $done;
                $run->totalitems = $total;
                $run->score = $score;
                $run->comment = $comment;
                $run->completedat = $done === $total ? $now : 0;
                $run->timemodified = $now;
                $DB->update_record('local_ustar_check_runs', $run);
                $DB->delete_records('local_ustar_check_answers', ['runid' => $run->id]);
            }
            foreach ($items as $itemid => $item) {
                $answer = $answers[$itemid] ?? [];
                $DB->insert_record('local_ustar_check_answers', (object)[
                    'runid' => $run->id, 'itemkey' => $itemid,
                    'checked' => !empty($answer['done']) ? 1 : 0,
                    'comment' => trim((string)($answer['comment'] ?? '')),
                    'timecreated' => $now,
                ]);
            }
            people::log_action($userid, $userid, 'checklist_submitted',
                ['checklistid' => $id, 'score' => $score, 'date' => $today]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        return ['ok' => true, 'status' => $run->status, 'done' => $done,
            'total' => $total, 'score' => $score];
    }

    public static function posted_answers(array $definition): array {
        $answers = [];
        foreach (checklists::flat_items($definition) as $id => $item) {
            $fieldid = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$id);
            $answers[$id] = [
                'done' => optional_param('done_' . $fieldid, 0, PARAM_BOOL),
                'comment' => optional_param('comment_' . $fieldid, '', PARAM_TEXT),
            ];
        }
        return $answers;
    }
}
