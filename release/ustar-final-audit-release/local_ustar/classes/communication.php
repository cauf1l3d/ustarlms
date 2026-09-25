<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * USTAR presentation layer for Moodle core messaging/notifications.
 *
 * Conversation membership, visibility, deletion state, privacy and message
 * sending are delegated to Moodle's core_message API. USTAR owns only the
 * presentation layer.
 */
final class communication {
    public static function counts(int $userid): array {
        global $DB;

        $targettable = $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_notifications'));
        $unreadnotifications = $targettable
            ? (int)$DB->count_records('local_ustar_notifications', ['userid' => $userid, 'status' => 'unread'])
            : (int)$DB->count_records_select('notifications', 'useridto = :userid AND timeread IS NULL', ['userid' => $userid]);

        $unreadconversations = 0;
        try {
            $user = \core_user::get_user($userid, '*', MUST_EXIST);
            $unreadconversations = (int)\core_message\api::count_unread_conversations($user);
        } catch (\Throwable $e) {
            // The shell must remain renderable when messaging is disabled.
            $unreadconversations = 0;
        }

        return [
            'messages' => $unreadconversations,
            'notifications' => $unreadnotifications,
        ];
    }

    /**
     * Build a readable conversation title using data already filtered by core_message.
     */
    private static function conversation_title(object $conversation, int $userid): string {
        $title = trim((string)($conversation->name ?? ''));
        if ($title !== '') {
            return $title;
        }

        $names = [];
        foreach (($conversation->members ?? []) as $member) {
            if (!is_object($member)) {
                continue;
            }
            $memberid = (int)($member->id ?? 0);
            if ($memberid === $userid && count($conversation->members ?? []) > 1) {
                continue;
            }
            $fullname = trim((string)($member->fullname ?? ''));
            if ($fullname === '') {
                $fullname = trim((string)($member->firstname ?? '') . ' ' . (string)($member->lastname ?? ''));
            }
            if ($fullname !== '') {
                $names[] = $fullname;
            }
        }

        return $names ? implode(', ', $names) : 'Личные заметки';
    }

    public static function conversations(int $userid, int $limit = 50): array {
        $limit = max(1, min(100, $limit));
        $records = \core_message\api::get_conversations($userid, 0, $limit);

        $rows = [];
        foreach ($records as $conversation) {
            if (!is_object($conversation) || empty($conversation->id)) {
                continue;
            }

            $last = null;
            if (!empty($conversation->messages) && is_array($conversation->messages)) {
                $last = reset($conversation->messages);
            }

            $preview = '';
            $timecreated = 0;
            if (is_object($last)) {
                $preview = shorten_text(strip_tags((string)($last->text ?? '')), 96);
                $timecreated = (int)($last->timecreated ?? 0);
            }

            $unread = max(0, (int)($conversation->unreadcount ?? 0));
            $conversationid = (int)$conversation->id;
            $rows[] = [
                'id' => $conversationid,
                'title' => self::conversation_title($conversation, $userid),
                'preview' => $preview,
                'timecreated' => $timecreated,
                'time' => $timecreated > 0 ? userdate($timecreated, '%d.%m %H:%M') : '',
                'unread' => $unread,
                'hasunread' => $unread > 0,
                'cansend' => !empty($conversation->cansendmessagetoconversation),
                'url' => (new \moodle_url('/local/ustar/messages.php', [
                    'conversationid' => $conversationid,
                ]))->out(false),
            ];
        }

        return $rows;
    }

    public static function conversation(int $userid, int $conversationid): array {
        if (!\core_message\api::is_user_in_conversation($userid, $conversationid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/ustar:use',
                'nopermissions',
                ''
            );
        }

        // Read oldest-first so the thread is immediately displayable in chat order.
        $conversation = \core_message\api::get_conversation(
            $userid,
            $conversationid,
            false,
            false,
            50,
            0,
            200,
            0,
            false
        );

        if (!$conversation) {
            throw new \moodle_exception('Conversation not found');
        }

        if (\core_message\api::can_mark_all_messages_as_read($userid, $conversationid)) {
            \core_message\api::mark_all_messages_as_read($userid, $conversationid);
        }

        $rows = [];
        foreach (($conversation->messages ?? []) as $message) {
            if (!is_object($message)) {
                continue;
            }
            $senderid = (int)($message->useridfrom ?? 0);
            $mine = $senderid === $userid;

            $sender = 'Пользователь';
            if ($mine) {
                $sender = 'Вы';
            } else {
                foreach (($conversation->members ?? []) as $member) {
                    if (!is_object($member) || (int)($member->id ?? 0) !== $senderid) {
                        continue;
                    }
                    $sender = trim((string)($member->fullname ?? ''));
                    if ($sender === '') {
                        $sender = trim((string)($member->firstname ?? '') . ' ' . (string)($member->lastname ?? ''));
                    }
                    if ($sender === '') {
                        $sender = 'Пользователь';
                    }
                    break;
                }
            }

            // core_message has already formatted the stored message. Clean once more
            // before triple-Mustache output to keep the custom UI defensive.
            $text = clean_text((string)($message->text ?? ''), FORMAT_HTML);
            $timecreated = (int)($message->timecreated ?? 0);
            $rows[] = [
                'id' => (int)($message->id ?? 0),
                'mine' => $mine,
                'theirs' => !$mine,
                'sender' => $sender,
                'text' => $text,
                'time' => $timecreated > 0 ? userdate($timecreated, '%d.%m.%Y %H:%M') : '',
            ];
        }

        return [
            'id' => $conversationid,
            'title' => self::conversation_title($conversation, $userid),
            'messages' => $rows,
            'hasmessages' => !empty($rows),
            'cansend' => !empty($conversation->cansendmessagetoconversation),
        ];
    }

    public static function send(int $userid, int $conversationid, string $message): void {
        require_capability('moodle/site:sendmessage', \context_system::instance());
        $message = trim($message);
        if ($message === '') {
            throw new \invalid_parameter_exception('Message cannot be empty');
        }
        if (\core_text::strlen($message) > 4000) {
            throw new \invalid_parameter_exception('Message is too long');
        }
        if (!\core_message\api::can_send_message_to_conversation($userid, $conversationid)) {
            throw new \moodle_exception('You cannot send a message to this conversation.');
        }
        \core_message\api::send_message_to_conversation($userid, $conversationid, $message, FORMAT_PLAIN);
    }

    public static function start(int $userid, int $otheruserid, string $message = ''): int {
        require_capability('moodle/site:sendmessage', \context_system::instance());

        if ($userid === $otheruserid) {
            $conversation = \core_message\api::get_self_conversation($userid);
            if (!$conversation) {
                throw new \moodle_exception('Unable to create self conversation.');
            }
            $conversationid = (int)$conversation->id;
        } else {
            if (!\core_message\api::can_send_message($otheruserid, $userid)) {
                throw new \moodle_exception('You cannot message this user.');
            }
            $conversationid = \core_message\api::get_conversation_between_users([$userid, $otheruserid]);
            if (!$conversationid) {
                $conversation = \core_message\api::create_conversation(
                    \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
                    [$userid, $otheruserid]
                );
                $conversationid = (int)$conversation->id;
            }
        }

        if (trim($message) !== '') {
            self::send($userid, (int)$conversationid, $message);
        }

        return (int)$conversationid;
    }

    public static function search_users(int $userid, string $query): array {
        $query = trim($query);
        if (\core_text::strlen($query) < 2) {
            return [];
        }

        try {
            // Moodle returns [contacts, noncontacts]. Both sets have already
            // passed core visibility checks; canmessage is still respected below.
            $sets = \core_message\api::message_search_users($userid, $query, 0, 20);
        } catch (\Throwable $e) {
            return [];
        }

        $found = [];
        foreach ($sets as $set) {
            if (!is_array($set)) {
                continue;
            }
            foreach ($set as $item) {
                if (!is_object($item) || empty($item->id)) {
                    continue;
                }
                $id = (int)$item->id;
                if ($id !== $userid && !empty($item->canmessage)) {
                    $found[$id] = $item;
                }
            }
        }

        $rows = [];
        foreach ($found as $item) {
            $id = (int)$item->id;
            $fullname = trim((string)($item->fullname ?? ''));
            $firstname = (string)($item->firstname ?? '');
            $lastname = (string)($item->lastname ?? '');
            if ($fullname === '') {
                $fullname = trim($firstname . ' ' . $lastname);
            }
            $rows[] = [
                'id' => $id,
                'fullname' => $fullname !== '' ? $fullname : 'Пользователь #' . $id,
                'initials' => ui::initials($firstname, $lastname),
            ];
        }
        return $rows;
    }

    public static function notifications(int $userid, int $limit = 100): array {
        global $DB;

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_notifications'))) {
            $records = $DB->get_records(
                'local_ustar_notifications', ['userid' => $userid], 'timecreated DESC', '*', 0, max(1, min(200, $limit))
            );
            $rows = [];
            foreach ($records as $record) {
                $actionurl = clean_param((string)$record->actionurl, PARAM_URL);
                $unread = (string)$record->status === 'unread';
                $rows[] = [
                    'id' => (int)$record->id,
                    'subject' => (string)$record->subject,
                    'message' => shorten_text(strip_tags((string)$record->message), 220),
                    'component' => 'local_ustar',
                    'eventtype' => (string)$record->eventtype,
                    'severity' => (string)$record->severity,
                    'unread' => $unread,
                    'read' => !$unread,
                    'time' => userdate((int)$record->timecreated, '%d.%m.%Y %H:%M'),
                    'hasurl' => $actionurl !== '',
                    'url' => $actionurl,
                    'urlname' => 'Открыть действие',
                ];
            }
            return $rows;
        }

        $records = $DB->get_records(
            'notifications',
            ['useridto' => $userid],
            'timecreated DESC',
            '*',
            0,
            max(1, min(200, $limit))
        );

        $rows = [];
        foreach ($records as $record) {
            $contexturl = clean_param((string)$record->contexturl, PARAM_URL);
            $rows[] = [
                'id' => (int)$record->id,
                'subject' => trim((string)$record->subject) ?: 'Уведомление',
                'message' => shorten_text(strip_tags((string)($record->smallmessage ?: $record->fullmessage)), 220),
                'component' => (string)$record->component,
                'eventtype' => (string)$record->eventtype,
                'unread' => empty($record->timeread),
                'read' => !empty($record->timeread),
                'time' => userdate((int)$record->timecreated, '%d.%m.%Y %H:%M'),
                'hasurl' => $contexturl !== '',
                'url' => $contexturl,
                'urlname' => trim((string)$record->contexturlname) ?: 'Открыть',
            ];
        }
        return $rows;
    }

    public static function mark_notification(int $userid, int $notificationid): void {
        global $DB;
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_notifications'))) {
            $record = $DB->get_record('local_ustar_notifications', ['id' => $notificationid, 'userid' => $userid], '*', MUST_EXIST);
            if ((string)$record->status === 'unread') {
                $record->status = 'read';
                $record->timemodified = time();
                $DB->update_record('local_ustar_notifications', $record);
            }
            return;
        }
        $record = $DB->get_record(
            'notifications',
            ['id' => $notificationid, 'useridto' => $userid],
            '*',
            MUST_EXIST
        );
        if (empty($record->timeread)) {
            \core_message\api::mark_notification_as_read($record);
        }
    }

    public static function mark_all_notifications(int $userid): void {
        global $DB;
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_notifications'))) {
            $DB->set_field_select(
                'local_ustar_notifications', 'timemodified', time(), 'userid = :userid AND status = :status',
                ['userid' => $userid, 'status' => 'unread']
            );
            $DB->set_field_select(
                'local_ustar_notifications', 'status', 'read', 'userid = :userid AND status = :status',
                ['userid' => $userid, 'status' => 'unread']
            );
            return;
        }
        \core_message\api::mark_all_notifications_as_read($userid);
    }
}
