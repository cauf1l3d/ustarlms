<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

use local_ustar\learning_tasks;
use local_ustar\task_workspace\calendar;
use local_ustar\task_workspace\service;

/** The assignee can start an ordinary task from the same card that opens its report. */
final class bot_task_widget {
    public const INTENT = 'work.task.widget';

    public static function in_pilot(int $userid): bool {
        global $CFG;
        return bot_widgets::in_pilot($userid, $CFG->ustar_connect_task_widget_userids ?? []);
    }

    public static function view(\stdClass $dialog, array $task, array $navigation, string $event): ?array {
        $userid = (int)$dialog->userid;
        if (!self::in_pilot($userid) || $task['assigneeid'] !== $userid || $task['kind'] !== 'task'
                || !empty($task['relatedtype']) || !in_array($task['status'], ['assigned', 'in_progress'], true)) {
            return null;
        }
        bot_store::state($dialog, 'menu');
        $target = ['id' => $task['id'], 'version' => $task['version'], 'status' => $task['status']];
        $action = $task['status'] === 'assigned' ? bot_store::action($dialog, self::INTENT, $target + [
            'event' => $event, 'message' => '', 'timezone' => $task['timezone'],
        ]) : '';
        $details = self::details($task['description'], $task['dueat'], $task['timezone']);
        $buttons = [
            bot_store::button($dialog, 'Заполнить отчёт', 'work.report', ['id' => $task['id']]),
            bot_store::button($dialog, 'Подробности задачи', 'work.task',
                ['id' => $task['id'], 'plain' => true, 'list' => 'work.mine'] + $navigation),
            bot_store::button($dialog, '← К списку задач', 'work.mine', $navigation),
        ];
        $status = $task['status'] === 'assigned' ? 'Назначена' : 'В работе';
        $view = bot_store::card($task['title'], $status . "\nСрок: " . $details['due'] . "\n\n" .
            $details['description'], $buttons);
        return bot_store::guarded($view + ['widget' => ['kind' => 'task', 'status' => $task['status'],
            'action' => $action] + $details], ['task_widget' => $target]);
    }

    private static function details(string $description, int $dueat, string $timezone): array {
        $description = trim((string)preg_replace('/[\s\p{Cc}]+/u', ' ', $description));
        if (\core_text::strlen($description) > 160) {
            $description = rtrim(\core_text::substr($description, 0, 159)) . '…';
        }
        $due = $dueat ? (new \DateTimeImmutable('@' . $dueat))->setTimezone(calendar::timezone($timezone))
            ->format('d.m.Y H:i (\\U\\T\\CP)') : 'не указан';
        return ['description' => $description, 'due' => $due];
    }

    /** Recheck ownership and version for cached views, initial binding and button presses. */
    public static function verify(int $userid, array $target): void {
        global $DB;
        if (!self::in_pilot($userid) || !bot_actor::eligible($userid)) {
            throw new \InvalidArgumentException('forbidden_scope');
        }
        $task = $DB->get_record('local_ustar_learning_tasks', ['id' => $target['id']],
            'id,privacy,assigneeid,status,version');
        if (!$task || (int)$task->assigneeid !== $userid || !service::can_view_record($task, $userid)) {
            throw new \InvalidArgumentException('forbidden_scope');
        }
        if ((int)$task->version !== $target['version'] || $task->status !== $target['status']) {
            throw new \InvalidArgumentException('stale_revision');
        }
    }

    public static function start(int $userid, \stdClass $action, array $target, string $command): array {
        global $DB;
        if ($command !== 'start') {
            return ['status' => 'stale'];
        }
        if ($action->status === 'done') {
            self::verify($userid, array_replace($target, ['version' => $target['version'] + 1, 'status' => 'in_progress']));
            return json_decode($action->resultjson, true, 16, JSON_THROW_ON_ERROR);
        }
        self::verify($userid, $target);
        service::actor($userid);
        $transaction = $DB->start_delegated_transaction();
        try {
            $task = learning_tasks::transition($target['id'], $userid, 'start', $target['version']);
            $receipt = ['status' => 'started'] +
                self::details($task['descriptionplain'], $task['dueat'], $target['timezone']);
            bot_store::done($action, $receipt);
            $transaction->allow_commit();
            return $receipt;
        } catch (\Throwable $error) {
            $transaction->rollback($error);
        }
    }
}
