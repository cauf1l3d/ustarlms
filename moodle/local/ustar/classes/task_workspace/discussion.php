<?php
namespace local_ustar\task_workspace;

use core_message\api as messaging;
use local_ustar\{chat_history, communication};

defined('MOODLE_INTERNAL') || die();

/** A task-authorized view of native Moodle messages. There is no durable native membership to go stale. */
final class discussion {
    public const ITEMTYPE = 'local_ustar_task_discussion';

    public static function readable(int $userid, int $taskid): ?\stdClass {
        global $DB;
        $task = $DB->get_record('local_ustar_learning_tasks', ['id' => $taskid], 'id,title,privacy,assigneeid');
        return $task && service::can_view_record($task, $userid) ? $task : null;
    }

    private static function require_task(int $userid, int $taskid): \stdClass {
        $task = self::readable($userid, $taskid);
        if (!$task) {
            throw new \invalid_parameter_exception('Обсуждение недоступно.');
        }
        return $task;
    }

    private static function conversation(int $taskid): ?\stdClass {
        global $DB;
        return $DB->get_record('message_conversations', [
            'component' => null, 'itemtype' => self::ITEMTYPE, 'itemid' => $taskid,
            'contextid' => \context_system::instance()->id, 'type' => messaging::MESSAGE_CONVERSATION_TYPE_GROUP,
            'enabled' => messaging::MESSAGE_CONVERSATION_DISABLED,
        ], 'id', IGNORE_MISSING) ?: null;
    }

    public static function url(int $taskid = 0, array $params = []): \moodle_url {
        return new \moodle_url('/local/ustar/messages.php', $params + ['taskchats' => 1] + ($taskid ? ['taskid' => $taskid] : []));
    }

    public static function thread(int $userid, int $taskid, int $before = 0, ?array $known = null,
            bool $read = true, string $query = ''): array {
        communication::require_actor($userid);
        $task = self::require_task($userid, $taskid);
        $conversation = self::conversation($taskid);
        $window = chat_history::window($userid, (int)($conversation->id ?? 0), 0, $before, $known, $read, true, $query);
        $params = $query === '' ? [] : ['find' => $query];
        return $window + [
            'taskid' => $taskid, 'title' => $task->title,
            'cansend' => has_capability('moodle/site:sendmessage', \context_system::instance(), $userid),
            'isolder' => $before > 0, 'issearch' => $query !== '',
            'olderurl' => self::url($taskid, $params + ['before' => $window['oldest']])->out(false),
            'newerurl' => self::url($taskid, $params)->out(false),
            'taskurl' => (new \moodle_url('/local/ustar/tasks.php', ['taskid' => $taskid], 'uw-discussion'))->out(false),
        ];
    }

    /** Only called within communication::send(), under the canonical task and DB locks. */
    public static function prepare_send(int $userid, int $taskid): int {
        global $DB;
        if (!$DB->is_transaction_started()) {
            throw new \coding_exception('Task discussions require the task send transaction.');
        }
        self::require_task($userid, $taskid);
        $conversation = self::conversation($taskid) ?? messaging::create_conversation(
            messaging::MESSAGE_CONVERSATION_TYPE_GROUP, [], 'Обсуждение задачи', messaging::MESSAGE_CONVERSATION_DISABLED,
            null, self::ITEMTYPE, $taskid, \context_system::instance()->id);
        if ($DB->record_exists('message_conversation_members', ['conversationid' => $conversation->id])) {
            throw new \coding_exception('Unexpected permanent task conversation membership.');
        }
        return (int)$conversation->id;
    }

    /** Moodle's supported writer requires a sender membership. It exists only inside this transaction. */
    public static function send_native(int $userid, int $conversationid, string $message): int {
        global $DB;
        if (!$DB->is_transaction_started()) {
            throw new \coding_exception('Task discussions require the task send transaction.');
        }
        messaging::add_members_to_conversation([$userid], $conversationid);
        $sent = messaging::send_message_to_conversation($userid, $conversationid, $message, FORMAT_PLAIN);
        messaging::remove_members_from_conversation([$userid], $conversationid);
        $DB->set_field('message_conversations', 'timemodified', time(), ['id' => $conversationid]);
        return (int)$sent->id;
    }

    public static function inbox(int $userid, int $offset, string $query): array {
        global $DB;
        communication::require_actor($userid);
        [$where, $params] = service::scope_sql($userid, 'accessible');
        $params += ['marker' => self::ITEMTYPE, 'context' => \context_system::instance()->id,
            'disabled' => messaging::MESSAGE_CONVERSATION_DISABLED, 'group' => messaging::MESSAGE_CONVERSATION_TYPE_GROUP];
        if ($query !== '') {
            $where .= ' AND ' . $DB->sql_like('t.title', ':titlequery', false);
            $params['titlequery'] = '%' . $DB->sql_like_escape($query) . '%';
        }
        $records = $DB->get_records_sql("SELECT c.id AS conversationid, t.id, t.title, t.privacy, t.assigneeid
              FROM {message_conversations} c
              JOIN {local_ustar_learning_tasks} t ON t.id = c.itemid
             WHERE c.component IS NULL AND c.itemtype = :marker AND c.contextid = :context AND c.type = :group
                   AND c.enabled = :disabled AND $where
                   AND EXISTS (SELECT 1 FROM {messages} m WHERE m.conversationid = c.id)
          ORDER BY c.timemodified DESC, c.id DESC", $params, $offset, chat_history::PAGE_SIZE + 1);
        // Reuse the canonical object policy before returning names or previews.
        $records = array_filter($records, static fn($task) => service::can_view_record($task, $userid));
        if (!$records) {
            return [];
        }
        $last = chat_history::previews($userid, array_keys($records));
        $out = [];
        foreach ($records as $task) {
            $message = $last[$task->conversationid] ?? null;
            if (!$message) {
                continue;
            }
            $out[] = [
                'id' => (int)$task->id, 'title' => $task->title, 'url' => self::url((int)$task->id)->out(false),
                'preview' => shorten_text(strip_tags($message->smallmessage), 96),
                'time' => userdate((int)$message->timecreated, '%d.%m %H:%M'),
                'hasunread' => (int)$message->useridfrom !== $userid && !$message->readid, 'unread' => 'Новое',
            ];
        }
        return $out;
    }
}
