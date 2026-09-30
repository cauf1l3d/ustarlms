<?php
namespace local_ustar\task_workspace;
use local_ustar\{learning_tasks, org, forced_retraining, workflow_notifications, team_access};
defined('MOODLE_INTERNAL') || die();

/** Trusted bounded cron commands. Reads never invoke this worker. */
final class worker {
    public static function run(int $limit = 100): array {
        global $DB;
        $limit = min(100, max(1, $limit));
        $stats = ['generated' => 0, 'checked' => 0, 'notified' => 0, 'completed' => 0, 'errors' => 0];
        if (!service::available()) { return $stats; }
        $lock = service::lock('task-workspace-worker');
        try {
            // No full workforce scan: only indexed active series with an imminent nextdate.
            $series = $DB->get_records_select('local_ustar_task_series', 'status=:status AND nextdate<=:horizon',
                ['status' => 'active', 'horizon' => gmdate('Y-m-d', time() + 14 * DAYSECS)], 'nextdate ASC, id ASC', '*', 0, 30);
            foreach ($series as $s) {
                if ($stats['generated'] >= $limit) { break; }
                try { $stats['generated'] += self::generate((int)$s->id, min(14, $limit - $stats['generated'])); }
                catch (\Throwable $e) { $stats['errors']++; mtrace('USTAR task series ' . $s->id . ': ' . $e->getMessage()); }
            }
            // Fair rotation: pending/future records do not permanently starve later tasks.
            $tasks = $DB->get_records_sql("SELECT t.* FROM {local_ustar_learning_tasks} t
                JOIN {local_ustar_task_meta} m ON m.taskid=t.id
                WHERE t.privacy='assigned' AND t.status IN ('assigned','in_progress','in_review')
                ORDER BY m.lastcheckedat ASC, t.id ASC", [], 0, $limit);
            foreach ($tasks as $task) {
                $stats['checked']++;
                try {
                    $result = self::check((int)$task->id);
                    $stats['notified'] += $result['notified']; $stats['completed'] += $result['completed'];
                } catch (\Throwable $e) { $stats['errors']++; mtrace('USTAR task ' . $task->id . ': ' . $e->getMessage()); }
                $DB->set_field('local_ustar_task_meta', 'lastcheckedat', time(), ['taskid' => $task->id]);
            }
        } finally { $lock->release(); }
        return $stats;
    }

    private static function generate(int $id, int $limit): int {
        global $DB;
        $lock = service::lock('task-series:' . $id);
        try {
            $tx = $DB->start_delegated_transaction();
            $s = $DB->get_record('local_ustar_task_series', ['id' => $id], '*', MUST_EXIST);
            if ($s->status !== 'active') { $tx->allow_commit(); return 0; }
            if (!learning_tasks::can_assign((int)$s->assignerid, (int)$s->assigneeid)) {
                $s->status = 'paused'; $s->revision++; $s->timemodified = time();
                $DB->update_record('local_ustar_task_series', $s); $tx->allow_commit(); return 0;
            }
            $p = policy::validate(json_decode($s->policyjson, true));
            $horizon = calendar::date(time() + 14 * DAYSECS, $s->timezone);
            $count = 0;
            for ($i = 0; $i < 31 && $s->nextdate <= min($horizon, $s->enddate) && $count < $limit; $i++) {
                $date = $s->nextdate;
                if (calendar::is_workday($date, $p)
                        && !$DB->record_exists('local_ustar_task_meta', ['seriesid' => $id, 'occurdate' => $date])) {
                    $t = learning_tasks::assign((int)$s->assignerid, (int)$s->assigneeid, ['title' => $s->title,
                        'description' => $s->description, 'requirereview' => 1,
                        'dueat' => calendar::timestamp($date, $s->dueclock, $s->timezone)]);
                    $DB->insert_record('local_ustar_task_meta', (object)['taskid' => $t['id'],
                        'kind' => $s->templateversionid ? 'checklist' : 'task', 'parentid' => 0,
                        'templateversionid' => $s->templateversionid, 'seriesid' => $id, 'occurdate' => $date,
                        'timezone' => $s->timezone, 'kpiweight' => $s->kpiweight, 'requirephoto' => $s->requirephoto,
                        'submittedat' => 0, 'reviewdueat' => 0, 'reviewedat' => 0, 'lastcheckedat' => 0,
                        'policyjson' => $s->policyjson]);
                    $count++;
                }
                $s->nextdate = calendar::next_date($date, $s->timezone);
            }
            if ($s->nextdate > $s->enddate) { $s->status = 'finished'; }
            $s->timemodified = time(); $DB->update_record('local_ustar_task_series', $s);
            $tx->allow_commit(); return $count;
        } finally { $lock->release(); }
    }

    public static function check(int $taskid): array {
        global $DB;
        $lock = service::lock('learning-task:' . $taskid);
        try {
            $tx = $DB->start_delegated_transaction();
            $t = $DB->get_record_sql('SELECT * FROM {local_ustar_learning_tasks} WHERE id=:id FOR UPDATE', ['id' => $taskid], MUST_EXIST);
            $m = service::meta($taskid); $result = ['notified' => 0, 'completed' => 0];
            if (!$m || in_array($t->status, ['completed', 'cancelled'], true)) { $tx->allow_commit(); return $result; }
            if ($m->kind === 'retraining') {
                $snapshot = forced_retraining::assignment_state((int)$t->assigneeid, (int)$t->relatedid, true);
                if (!empty($snapshot['completed']) || !empty($snapshot['cancelled'])) {
                    $t->status = !empty($snapshot['cancelled']) ? 'cancelled' : 'completed';
                    $t->version++; $t->timemodified = time();
                    if ($t->status === 'completed') {
                        $t->completedat = (int)($snapshot['completedat'] ?: time());
                        $m->submittedat = $t->completedat; $m->reviewedat = time(); $result['completed']++;
                    } else { $t->cancelledat = time(); }
                    $DB->update_record('local_ustar_learning_tasks', $t); $DB->update_record('local_ustar_task_meta', $m);
                    service::event($taskid, 0, 'task_learning_result', ['assignmentid' => $t->relatedid, 'status' => $t->status]);
                    $tx->allow_commit(); return $result;
                }
            }
            $p = policy::validate(json_decode($m->policyjson, true));
            $review = $t->status === 'in_review';
            $lane = $review ? 'review' : 'execution';
            $trigger = $review ? (int)$m->reviewdueat : (int)$t->dueat;
            if (!$review && $trigger > time() && $p['remindminutes'] > 0
                    && $trigger - $p['remindminutes'] * 60 <= time() && team_access::active_actor((int)$t->assigneeid)) {
                $key = hash('sha256', $taskid . ':reminder:' . $trigger);
                if (!$DB->record_exists('local_ustar_task_escalations', ['cyclekey' => $key, 'recipientid' => $t->assigneeid])) {
                    $DB->insert_record('local_ustar_task_escalations', (object)['taskid' => $taskid, 'lane' => 'reminder',
                        'cyclekey' => $key, 'levelno' => 0, 'recipientid' => $t->assigneeid, 'triggerat' => $trigger, 'timecreated' => time()]);
                    workflow_notifications::enqueue((int)$t->assigneeid, 'task_reminder', 'Приближается срок задачи', $t->title,
                        '/local/ustar/tasks.php?taskid=' . $taskid, 'task-reminder:' . $key);
                    service::event($taskid, 0, 'task_reminder', ['dueat' => $trigger]); $result['notified']++;
                }
            }
            if (!$trigger || $trigger > time()) { $tx->allow_commit(); return $result; }
            $subject = $review ? 'Просрочена проверка задачи' : 'Просрочено исполнение задачи';
            $parentdepth = 0;
            foreach ($p['levels'] as $index => $level) {
                if (calendar::add_minutes($trigger, (int)$level['minutes'], $p) > time()) { continue; }
                if ($level['recipient'] === 'direct') { $parentdepth = 0; }
                if ($level['recipient'] === 'parent') { $parentdepth++; }
                $recipients = self::recipients($t, $level['recipient'], $review, $parentdepth);
                foreach ($recipients as $recipient) {
                    $key = hash('sha256', $taskid . ':' . $lane . ':' . $trigger . ':' . $index);
                    if ($DB->record_exists('local_ustar_task_escalations', ['cyclekey' => $key, 'recipientid' => $recipient])) { continue; }
                    $DB->insert_record('local_ustar_task_escalations', (object)['taskid' => $taskid, 'lane' => $lane,
                        'cyclekey' => $key, 'levelno' => $index + 1, 'recipientid' => $recipient,
                        'triggerat' => $trigger, 'timecreated' => time()]);
                    workflow_notifications::enqueue($recipient, 'task_escalation', $subject, $t->title,
                        '/local/ustar/tasks.php?view=control&taskid=' . $taskid,
                        'task-escalation:' . $key . ':' . $recipient);
                    service::event($taskid, 0, 'task_escalated', ['lane' => $lane, 'level' => $index + 1, 'recipientid' => $recipient]);
                    $result['notified']++;
                }
            }
            $tx->allow_commit(); return $result;
        } finally { $lock->release(); }
    }

    private static function recipients(\stdClass $task, string $kind, bool $review, int $depth): array {
        $direct = $review ? org::manager_id(service::reviewer($task)) : service::reviewer($task);
        $id = $direct;
        for ($i = 0; $kind === 'parent' && $i < $depth && $id > 1; $i++) { $id = org::manager_id($id); }
        if ($kind !== 'hrd' && $id > 1 && service::can_view_record($task, $id)) { return [$id]; }
        // Missing manager is an explicit exception queue, never a guessed recipient.
        $users = get_users_by_capability(\context_system::instance(), 'local/ustar:taskescalation', 'u.id', 'u.id', 0, 50);
        return array_values(array_filter(array_map(static fn($u) => (int)$u->id, $users),
            static fn($uid) => service::is_hrd($uid) && service::can_view_record($task, $uid)));
    }
}
