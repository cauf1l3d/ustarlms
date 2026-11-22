<?php
namespace local_ustar;

use core_message\api as messaging;

defined('MOODLE_INTERNAL') || die();

/** Bounded projection of native messages. Callers authorize the conversation first. */
final class chat_history {
    public const PAGE_SIZE = 50;

    /** Last visible message in each authorized conversation, with one batched read. */
    public static function previews(int $userid, array $conversationids): array {
        global $DB;
        if (!$conversationids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($conversationids, SQL_PARAMS_NAMED, 'preview');
        return $DB->get_records_sql("SELECT m.conversationid, m.useridfrom, m.smallmessage, m.timecreated, a.id AS readid
              FROM {messages} m
              JOIN (SELECT v.conversationid, MAX(v.id) AS lastid FROM {messages} v
                     WHERE v.conversationid $in AND NOT EXISTS (
                           SELECT 1 FROM {message_user_actions} d WHERE d.messageid = v.id
                                   AND d.userid = :previewreader AND d.action = :previewdeleted)
                  GROUP BY v.conversationid) latest ON latest.lastid = m.id
         LEFT JOIN {message_user_actions} a ON a.messageid = m.id AND a.userid = :actor AND a.action = :readaction",
            $params + ['previewreader' => $userid, 'previewdeleted' => messaging::MESSAGE_ACTION_DELETED,
                'actor' => $userid, 'readaction' => messaging::MESSAGE_ACTION_READ]);
    }

    /**
     * A null $known returns the full window. A supplied list returns bodies only for new IDs.
     * The complete ordered ID list includes late commits and drops per-user deletions.
     */
    public static function window(int $userid, int $conversationid, int $offset = 0, int $before = 0,
            ?array $known = null, bool $read = false, bool $task = false, string $query = ''): array {
        global $DB;
        $empty = ['ids' => [], 'messages' => [], 'hasmessages' => false, 'oldest' => 0, 'hasolder' => false];
        if (!$conversationid) {
            return $empty;
        }
        if ($known !== null && count($known) > self::PAGE_SIZE) {
            throw new \invalid_parameter_exception('Некорректная страница чата.');
        }
        $params = ['conversation' => $conversationid, 'deleteduser' => $userid, 'deleteaction' => messaging::MESSAGE_ACTION_DELETED,
            'readuser' => $userid, 'readaction' => messaging::MESSAGE_ACTION_READ];
        $where = "m.conversationid = :conversation AND NOT EXISTS (
            SELECT 1 FROM {message_user_actions} d
             WHERE d.messageid = m.id AND d.userid = :deleteduser AND d.action = :deleteaction)";
        if ($before) {
            $anchor = $DB->get_record('messages', ['id' => $before, 'conversationid' => $conversationid], 'timecreated');
            if (!$anchor) {
                throw new \invalid_parameter_exception('Откройте чат заново, чтобы обновить историю.');
            }
            $where .= ' AND (m.timecreated < :older OR (m.timecreated = :same AND m.id < :before))';
            $params += ['older' => $anchor->timecreated, 'same' => $anchor->timecreated, 'before' => $before];
        }
        if ($query !== '') {
            // Search only the latest 2,000 visible candidates in this authorized conversation.
            // Older history is still accessible through the normal cursor.
            $recent = $DB->get_records_sql("SELECT m.id FROM {messages} m WHERE $where
                ORDER BY m.timecreated DESC, m.id DESC",
                array_diff_key($params, ['readuser' => true, 'readaction' => true]), 0, 2000);
            if (!$recent) {
                return $empty;
            }
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($recent), SQL_PARAMS_NAMED, 'searched');
            $where = 'm.id ' . $insql . ' AND (' . $DB->sql_like('m.smallmessage', ':smallquery', false)
                . ' OR ' . $DB->sql_like('m.fullmessage', ':fullquery', false) . ')';
            $pattern = '%' . $DB->sql_like_escape($query) . '%';
            $params = array_intersect_key($params, ['readuser' => true, 'readaction' => true])
                + $inparams + ['smallquery' => $pattern, 'fullquery' => $pattern];
        }
        $keys = $DB->get_records_sql("SELECT m.id, m.useridfrom, a.id AS readid
              FROM {messages} m
         LEFT JOIN {message_user_actions} a ON a.messageid = m.id AND a.userid = :readuser AND a.action = :readaction
             WHERE $where
          ORDER BY m.timecreated DESC, m.id DESC", $params, $offset, self::PAGE_SIZE + 1);
        $hasolder = count($keys) > self::PAGE_SIZE;
        $keys = array_reverse(array_slice($keys, 0, self::PAGE_SIZE, true), true);
        $ids = array_map('intval', array_keys($keys));
        $unseen = array_diff($ids, $known ?? []);
        $rows = self::bodies($userid, $unseen);
        // A message can be globally erased between the ID and body reads.
        $ids = array_values(array_diff($ids, array_diff($unseen, array_column($rows, 'id'))));
        if ($read && $ids) {
            $displayed = $task ? [end($ids)] : $ids;
            foreach ($displayed as $id) {
                $key = $keys[$id];
                if ((int)$key->useridfrom !== $userid && !$key->readid) {
                    try {
                        messaging::mark_message_as_read($userid, $key);
                    } catch (\dml_write_exception $error) {
                        // The native drawer or another window may acknowledge the same message.
                        if (!$DB->record_exists('message_user_actions', ['userid' => $userid,
                                'messageid' => $id, 'action' => messaging::MESSAGE_ACTION_READ])) {
                            throw $error;
                        }
                    }
                }
            }
        }
        return ['ids' => $ids, 'messages' => $rows, 'hasmessages' => (bool)$ids, 'oldest' => $ids[0] ?? 0,
            'hasolder' => $hasolder];
    }

    private static function bodies(int $userid, array $ids): array {
        global $DB, $CFG;
        if (!$ids) {
            return [];
        }
        require_once($CFG->dirroot . '/message/lib.php');
        $records = $DB->get_records_list('messages', 'id', $ids, '',
            'id,useridfrom,fullmessage,fullmessagehtml,fullmessageformat,smallmessage,timecreated');
        $authorids = array_values(array_unique(array_column($records, 'useridfrom')));
        $authors = $authorids ? $DB->get_records_list('user', 'id', $authorids, '',
            'id,deleted,' . implode(',', \core_user\fields::get_name_fields())) : [];
        $files = chat_files::for_visible_messages(array_keys($records));
        $rows = [];
        foreach ($ids as $id) {
            if (!isset($records[$id])) {
                continue;
            }
            $message = $records[$id];
            $author = $authors[$message->useridfrom] ?? null;
            $mine = (int)$message->useridfrom === $userid;
            $rows[] = [
                'id' => (int)$id, 'mine' => $mine,
                'sender' => $mine ? 'Вы' : ($author && !$author->deleted ? fullname($author) : 'Бывший участник'),
                'text' => clean_text(message_format_message_text($message), FORMAT_HTML),
                'time' => userdate((int)$message->timecreated, '%d.%m.%Y %H:%M'),
                'attachments' => $files[$id] ?? [],
            ];
        }
        return $rows;
    }
}
