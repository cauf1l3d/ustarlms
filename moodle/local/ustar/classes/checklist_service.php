<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Shared checklist access and presentation for native pages and external API. */
final class checklist_service {
    private static function assert_actor(int $userid): void {
        global $USER;
        $context = \context_system::instance();
        if ((int)$USER->id !== $userid
                || !has_capability('local/ustar:use', $context, $userid)
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
        $seen = [];
        foreach ((checklists::get()['items'] ?? []) as $checklist) {
            $run = $bykey[(string)$checklist['id']] ?? null;
            $seen[(string)$checklist['id']] = true;
            if (!checklists::applies_to($checklist, $positionid) && !$run) {
                continue;
            }
            $definition = $run && $run->definitionversion
                ? checklists::published_version((string)$checklist['id'], (int)$run->definitionversion) : null;
            $missingversion = $run && $run->definitionversion && !$definition;
            $definition ??= $missingversion ? [
                'id' => (string)$checklist['id'], 'title' => 'Старая версия недоступна',
                'description' => 'Опубликованная версия не найдена. Обратитесь к администратору.',
                'sections' => [], 'recurrence' => 'manual',
            ] : $checklist;
            $total = count(checklists::flat_items($definition));
            $rows[] = $definition + ['today' => $run ? [
                'status' => (string)$run->status, 'done' => (int)$run->doneitems,
                'total' => (int)$run->totalitems, 'score' => (int)$run->score,
                'comment' => (string)$run->comment, 'completedAt' => (int)$run->completedat,
                'revision' => (int)$run->revision, 'submissionid' => (int)$run->lastsubmissionid,
                'legacy' => !$run->definitionversion || $missingversion,
            ] : [
                'status' => 'pending', 'done' => 0, 'total' => $total,
                'score' => 0, 'comment' => '', 'completedAt' => 0,
                'revision' => 0, 'submissionid' => 0, 'legacy' => false,
            ]];
        }
        foreach ($bykey as $key => $run) {
            if (isset($seen[$key])) {
                continue;
            }
            $definition = $run->definitionversion
                ? checklists::published_version($key, (int)$run->definitionversion) : null;
            $definition ??= ['id' => $key, 'title' => 'Архивный чек-лист',
                'description' => 'Опубликованная версия не найдена.', 'sections' => [], 'recurrence' => 'manual'];
            $rows[] = $definition + ['today' => [
                'status' => (string)$run->status, 'done' => (int)$run->doneitems,
                'total' => (int)$run->totalitems, 'score' => (int)$run->score,
                'comment' => (string)$run->comment, 'completedAt' => (int)$run->completedat,
                'revision' => (int)$run->revision, 'submissionid' => (int)$run->lastsubmissionid,
                'legacy' => !$run->definitionversion || !$definition['sections'],
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
                if (!empty($today['legacy'])) {
                    // No historical definition was pinned before the migration. Never
                    // mislabel old answers with today's possibly edited item titles.
                    $items = [];
                    foreach ($answers as $key => $answer) {
                        $items[] = ['id' => $key, 'fieldid' => $key,
                            'title' => 'Исторический пункт ' . $key,
                            'checked' => !empty($answer->checked), 'comment' => (string)$answer->comment];
                    }
                    $checklist['sections'] = $items
                        ? [['title' => 'Сохранённые ответы', 'items' => $items]] : [];
                    $answers = [];
                }
                foreach (($checklist['sections'] ?? []) as &$section) {
                    foreach (($section['items'] ?? []) as &$item) {
                        $item['fieldid'] = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$item['id']);
                        $answer = $answers[(string)$item['id']] ?? null;
                        $item['checked'] = $answer ? !empty($answer->checked) : !empty($item['checked']);
                        $item['comment'] = $answer ? (string)$answer->comment : (string)($item['comment'] ?? '');
                    }
                    unset($item);
                }
                unset($section);
                $checklist['todaycomment'] = $run ? (string)$run->comment : (string)$today['comment'];
                $checklist['definitionversion'] = max(1, (int)($checklist['version'] ?? 1));
                $checklist['hasfinal'] = !empty($today['submissionid']);
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
            'canedit' => $datekey === self::date_key() && empty($current['today']['legacy']),
            'legacy' => !empty($current['today']['legacy']),
            'sesskey' => sesskey(),
            'studiourl' => (new \moodle_url('/local/ustar/checklist_studio.php'))->out(false),
            'canstudio' => has_capability('local/ustar:hrmanage', \context_system::instance())
                || has_capability('local/ustar:admin', \context_system::instance()),
        ];
    }

    public static function submit(int $userid, string $id, array $answers, string $comment = '',
            string $mode = 'final', int $expectedrevision = -1, string $correctionreason = '',
            int $expecteddefinitionversion = -1): array {
        global $DB;
        self::assert_actor($userid);
        view_as::assert_writable();
        if (!in_array($mode, ['draft', 'final'], true)) {
            throw new \invalid_parameter_exception('Неизвестный способ сохранения чек-листа.');
        }
        $checklist = checklists::find($id);
        $positionid = (string)(structure::resolve_user($userid)['position']['id'] ?? '');
        if (!$checklist) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
        }
        $today = self::date_key();
        $now = time();
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('check-run-' . $userid . '-' . $id . '-' . $today, 10);
        if (!$lock) {
            throw new \moodle_exception('Чек-лист сейчас сохраняется. Повторите попытку.');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
            $run = $DB->get_record('local_ustar_check_runs',
                ['checklistkey' => $id, 'userid' => $userid, 'datekey' => $today]);
            if ($run && !$run->definitionversion) {
                throw new \invalid_parameter_exception('Историческое выполнение без версии доступно только для просмотра.');
            }
            if (!$run && !checklists::applies_to($checklist, $positionid)) {
                throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
            }
            if ($expectedrevision >= 0 && $expectedrevision !== (int)($run->revision ?? 0)) {
                throw new \invalid_parameter_exception('Чек-лист изменился в другом окне. Обновите страницу.');
            }
            $version = $run ? (int)$run->definitionversion : max(1, (int)($checklist['version'] ?? 1));
            if ($expecteddefinitionversion >= 0 && $expecteddefinitionversion !== $version) {
                throw new \invalid_parameter_exception('Шаблон обновился. Обновите страницу чек-листа.');
            }
            $definition = checklists::published_version($id, $version);
            if (!$definition) {
                throw new \invalid_parameter_exception('Версия чек-листа недоступна. Обратитесь к администратору.');
            }
            $items = checklists::flat_items($definition);
            $normalized = [];
            $done = 0;
            foreach ($items as $itemid => $item) {
                $answer = $answers[$itemid] ?? [];
                $checked = !empty($answer['done']);
                $done += (int)$checked;
                $normalized[$itemid] = ['done' => $checked,
                    'comment' => trim((string)($answer['comment'] ?? ''))];
            }
            $total = count($items);
            $score = $total ? (int)round($done * 100 / $total) : 100;
            $comment = trim($comment);
            $lastsubmission = $run && $run->lastsubmissionid
                ? $DB->get_record('local_ustar_check_submits', ['id' => $run->lastsubmissionid]) : null;
            if ($run && $run->lastsubmissionid && !$lastsubmission) {
                throw new \coding_exception('Checklist submission reference is missing');
            }
            if ($lastsubmission && $mode === 'draft') {
                throw new \invalid_parameter_exception('После отправки изменения оформляются исправлением.');
            }
            if ($lastsubmission && $mode === 'final') {
                $oldanswers = json_decode((string)$lastsubmission->answersjson, true);
                $oldissues = json_decode((string)$lastsubmission->issuesjson, true);
                if ($oldanswers == $normalized && (string)($oldissues['items']['comment'] ?? '') === $comment) {
                    $transaction->allow_commit();
                    return ['ok' => true, 'status' => (string)$run->status, 'done' => (int)$run->doneitems,
                        'total' => (int)$run->totalitems, 'score' => (int)$run->score,
                        'revision' => (int)$run->revision, 'submissionid' => (int)$run->lastsubmissionid];
                }
                if (trim($correctionreason) === '') {
                    throw new \invalid_parameter_exception('Укажите причину исправления отправленного чек-листа.');
                }
            }
            if (!$run) {
                $run = (object)[
                    'checklistkey' => $id, 'userid' => $userid, 'positionid' => $positionid,
                    'definitionversion' => $version, 'revision' => 1, 'lastsubmissionid' => null,
                    'datekey' => $today, 'status' => $mode === 'draft' ? 'draft' : ($done === $total ? 'completed' : 'partial'),
                    'doneitems' => $done, 'totalitems' => $total, 'score' => $score,
                    'comment' => $comment, 'startedat' => $now,
                    'completedat' => $mode === 'final' && $done === $total ? $now : 0, 'timemodified' => $now,
                ];
                $run->id = $DB->insert_record('local_ustar_check_runs', $run);
            } else {
                $run->positionid = $positionid;
                $run->revision++;
                $run->status = $mode === 'draft' ? 'draft' : ($done === $total ? 'completed' : 'partial');
                $run->doneitems = $done;
                $run->totalitems = $total;
                $run->score = $score;
                $run->comment = $comment;
                $run->completedat = $mode === 'final' && $done === $total ? $now : 0;
                $run->timemodified = $now;
                $DB->delete_records('local_ustar_check_answers', ['runid' => $run->id]);
            }
            foreach ($normalized as $itemid => $answer) {
                $DB->insert_record('local_ustar_check_answers', (object)[
                    'runid' => $run->id, 'itemkey' => $itemid,
                    'checked' => $answer['done'] ? 1 : 0, 'comment' => $answer['comment'],
                    'timecreated' => $now,
                ]);
            }
            if ($mode === 'final') {
                $run->lastsubmissionid = target_core::submit_checklist([
                    'userid' => $userid, 'checklistkey' => $id, 'definitionversion' => $version,
                    'perspective' => 'employee', 'workdate' => $today, 'status' => $run->status,
                    'answers' => $normalized, 'issues' => ['comment' => $comment],
                    'correctionofid' => $lastsubmission ? (int)$lastsubmission->id : null,
                    'correctionreason' => trim($correctionreason),
                ], $userid);
                people::log_action($userid, $userid, $lastsubmission ? 'checklist_corrected' : 'checklist_submitted',
                    ['checklistid' => $id, 'score' => $score, 'date' => $today]);
            }
            $DB->update_record('local_ustar_check_runs', $run);
            $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
        return ['ok' => true, 'status' => $run->status, 'done' => $done,
            'total' => $total, 'score' => $score, 'revision' => (int)$run->revision,
            'submissionid' => (int)$run->lastsubmissionid];
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
