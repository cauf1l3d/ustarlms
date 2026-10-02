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
        $unreadnotifications = (int)$DB->count_records_select(
            'notifications', 'useridto = :userid AND timeread IS NULL', ['userid' => $userid]);
        if ($targettable) {
            $unreadnotifications += (int)$DB->count_records('local_ustar_notifications',
                ['userid' => $userid, 'status' => 'unread']);
        }

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

    /** Private communication always uses the real session identity. */
    public static function require_actor(int $userid, bool $write = false): void {
        global $USER, $CFG, $DB;
        $user = $DB->get_record('user', ['id' => $userid], 'id,deleted,suspended');
        if ((int)$USER->id !== $userid || !$user || $user->deleted || $user->suspended
                || empty($CFG->messaging) || view_as::active() || !employment::is_active($userid)) {
            throw new \invalid_parameter_exception('Общение недоступно для текущей учётной записи.');
        }
        require_capability('local/ustar:use', \context_system::instance(), $userid);
        if ($write) { require_capability('moodle/site:sendmessage', \context_system::instance(), $userid); }
    }

    public static function conversations(int $userid, int $limit = 50, int $offset = 0): array {
        self::require_actor($userid);
        $limit = max(1, min(100, $limit));
        $records = \core_message\api::get_conversations($userid, max(0, $offset), $limit);

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

    public static function conversation(int $userid, int $conversationid, int $offset = 0): array {
        global $DB;
        self::require_actor($userid);
        if (!\core_message\api::is_user_in_conversation($userid, $conversationid)
                || !$DB->record_exists('message_conversations', ['id' => $conversationid,
                    'enabled' => \core_message\api::MESSAGE_CONVERSATION_ENABLED])) {
            throw new \invalid_parameter_exception('Чат недоступен.');
        }
        $offset = max(0, min(100000, $offset));
        // Latest bounded page; sort by ID too so messages in the same second stay stable.
        $conversation = \core_message\api::get_conversation($userid, $conversationid,
            false, false, chat_groups::MAX_MEMBERS, 0, 1, 0, true);
        $page = \core_message\api::get_conversation_messages($userid, $conversationid,
            $offset, 51, 'timecreated DESC, m.id DESC');
        $records = array_values($page['messages']);
        $hasolder = count($records) > 50;
        $records = array_reverse(array_slice($records, 0, 50));
        $files = chat_files::for_visible_messages(array_map(static fn($message) => (int)$message->id, $records));
        $members = [];
        foreach ($conversation->members as $member) {
            $members[(int)$member->id] = [
                'id' => (int)$member->id, 'fullname' => (string)$member->fullname,
                'removable' => (int)$member->id !== $userid,
            ];
        }
        $rows = [];
        foreach ($records as $message) {
            $mine = (int)$message->useridfrom === $userid;
            $rows[] = [
                'id' => (int)$message->id, 'mine' => $mine,
                'sender' => $mine ? 'Вы' : ($members[(int)$message->useridfrom]['fullname'] ?? 'Бывший участник'),
                'text' => clean_text((string)$message->text, FORMAT_HTML),
                'time' => userdate((int)$message->timecreated, '%d.%m.%Y %H:%M'),
                'attachments' => $files[(int)$message->id] ?? [],
            ];
        }
        if ($offset === 0 && \core_message\api::can_mark_all_messages_as_read($userid, $conversationid)) {
            \core_message\api::mark_all_messages_as_read($userid, $conversationid);
        }
        $url = static fn($offset) => (new \moodle_url('/local/ustar/messages.php',
            ['conversationid' => $conversationid, 'offset' => $offset]))->out(false);
        return [
            'id' => $conversationid, 'title' => self::conversation_title($conversation, $userid),
            'messages' => $rows, 'hasmessages' => !empty($rows),
            'cansend' => !empty($conversation->cansendmessagetoconversation),
            'isgroup' => (int)$conversation->type === \core_message\api::MESSAGE_CONVERSATION_TYPE_GROUP,
            'members' => array_values($members), 'membercount' => (int)$conversation->membercount,
            'canmanage' => chat_groups::can_manage($userid, $conversationid),
            'hasolder' => $hasolder, 'isolder' => $offset > 0,
            'olderurl' => $url($offset + 50), 'newerurl' => $url(max(0, $offset - 50)),
        ];
    }

    public static function send(int $userid, int $conversationid, string $message, array $uploads = [], string $requestid = ''): int {
        global $DB;
        self::require_actor($userid, true);
        $message = trim($message);
        if (($message === '' && !$uploads) || \core_text::strlen($message) > 4000) {
            throw new \invalid_parameter_exception('Напишите до 4000 символов или приложите файл.');
        }
        if ($requestid !== '' && !preg_match('/^[a-zA-Z0-9_-]{16,80}$/D', $requestid)) {
            throw new \invalid_parameter_exception('Некорректный идентификатор отправки.');
        }
        $uploads = chat_files::validate($uploads);
        $fingerprint = hash('sha256', json_encode([$conversationid, $message,
            array_map(static fn($file) => [$file['filename'], hash_file('sha256', $file['tmp'])], $uploads)]));
        $factory = \core\lock\lock_config::get_lock_factory('local_ustar');
        $chatlock = $factory->get_lock('chat:' . $conversationid, 10);
        if (!$chatlock) { throw new \invalid_parameter_exception('Чат занят. Повторите отправку.'); }
        $actorlock = null;
        try {
            $actorlock = $factory->get_lock('chat-send:' . $userid, 10);
            if (!$actorlock) { throw new \invalid_parameter_exception('Отправка уже выполняется.'); }
            if (!$DB->record_exists('message_conversations', ['id' => $conversationid,
                    'enabled' => \core_message\api::MESSAGE_CONVERSATION_ENABLED])
                    || !\core_message\api::can_send_message_to_conversation($userid, $conversationid)) {
                throw new \invalid_parameter_exception('Отправка в этот чат недоступна.');
            }
            $transaction = $DB->start_delegated_transaction();
            // A bounded retry receipt is presentation metadata, not a parallel message store.
            $pref = $DB->get_field('user_preferences', 'value', ['userid' => $userid, 'name' => 'ustar_chat_receipts']);
            $receipts = json_decode($pref ?: '{}', true) ?: [];
            if ($requestid !== '' && isset($receipts[$requestid])) {
                if ($receipts[$requestid]['hash'] !== $fingerprint) {
                    throw new \invalid_parameter_exception('Эта отправка уже использована для другого сообщения.');
                }
                $id = (int)$receipts[$requestid]['id'];
                $transaction->allow_commit();
                return $id;
            }
            $sent = \core_message\api::send_message_to_conversation($userid, $conversationid,
                $message !== '' ? $message : 'Вложения', FORMAT_PLAIN);
            chat_files::store((int)$sent->id, $userid, $uploads);
            if ($requestid !== '') {
                $receipts[$requestid] = ['id' => (int)$sent->id, 'hash' => $fingerprint];
                set_user_preference('ustar_chat_receipts', json_encode(array_slice($receipts, -30, null, true)), $userid);
            }
            $transaction->allow_commit();
            return (int)$sent->id;
        } catch (\Throwable $e) {
            if (isset($transaction)) { $transaction->rollback($e); }
            throw $e;
        } finally {
            if ($actorlock) { $actorlock->release(); }
            $chatlock->release();
        }
    }

    public static function start(int $userid, int $otheruserid, string $message = ''): int {
        self::require_actor($userid, true);
        $target = \core_user::get_user($otheruserid, 'id,deleted,suspended', MUST_EXIST);
        if ($target->deleted || $target->suspended || !employment::is_active($otheruserid)) {
            throw new \invalid_parameter_exception('Сотрудник недоступен для общения.');
        }

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
        self::require_actor($userid);
        $query = trim($query);
        if (\core_text::strlen($query) < 2) {
            return [];
        }

        // Core search omits privacy details: canmessage is normally null.
        // Visibility comes from search; send permission must be checked separately.
        $sets = \core_message\api::message_search_users($userid, $query, 0, 20);

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
                if ($id !== $userid && !isset($found[$id])
                        && employment::is_active($id)
                        && has_capability('local/ustar:use', \context_system::instance(), $id)
                        && \core_message\api::can_send_message($id, $userid)) {
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
        $limit = max(1, min(200, $limit));
        $rows = [];
        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ustar_notifications'))) {
            $records = $DB->get_records(
                'local_ustar_notifications', ['userid' => $userid], 'timecreated DESC', '*', 0, max(1, min(200, $limit))
            );
            // The key ties legacy notification rows to an exact request and
            // recipient. Fetch all candidates at once, then verify the type.
            $registrationids = [];
            foreach ($records as $record) {
                if ((string)$record->eventtype === 'registration_requested'
                        && preg_match('/^registration-requested:([1-9][0-9]*):' . $userid . '$/',
                            (string)$record->idempotencykey, $match)) {
                    $registrationids[] = (int)$match[1];
                }
            }
            $requests = [];
            if ($registrationids) {
                $requests = $DB->get_records_list('local_ustar_staff_requests', 'id',
                    array_values(array_unique($registrationids)));
            }
            $departmentmap = $registrationids
                ? people::department_map(structure::get(structure::NAME_STRUCTURE)) : [];
            $canreview = has_capability('local/ustar:hrmanage', \context_system::instance(), $userid)
                && has_capability('local/ustar:approveregistration', \context_system::instance(), $userid)
                && team_access::active_actor($userid);
            foreach ($records as $record) {
                $actionurl = clean_param((string)$record->actionurl, PARAM_URL);
                $message = (string)$record->message;
                $eventlabel = (string)$record->eventtype === 'registration_requested'
                    ? 'Регистрация сотрудника' : 'Уведомление Академии';
                if ((string)$record->eventtype === 'registration_requested') {
                    $actionurl = '';
                    $message = 'Заявка на регистрацию сотрудника.';
                    if ($canreview && preg_match('/^registration-requested:([1-9][0-9]*):' . $userid . '$/',
                            (string)$record->idempotencykey, $match)) {
                        $requestid = (int)$match[1];
                        $request = $requests[$requestid] ?? null;
                        if ($request && (string)$request->requesttype === staffing_requests::TYPE_REGISTRATION) {
                            $metadata = json_decode((string)($record->metadatajson ?? ''), true);
                            $snapshot = is_array($metadata) && (int)($metadata['version'] ?? 0) === 1
                                && (int)($metadata['requestid'] ?? 0) === $requestid
                                && (string)($metadata['departmentid'] ?? '') === (string)$request->departmentid
                                ? trim((string)($metadata['departmentname'] ?? '')) : '';
                            $department = $snapshot !== '' ? $snapshot
                                : (string)($departmentmap[(string)$request->departmentid]['name']
                                    ?? 'Подразделение недоступно');
                            $fullname = trim((string)$request->lastname . ' ' . (string)$request->firstname);
                            $message = ($fullname !== '' ? $fullname : 'Сотрудник')
                                . ' указал подразделение «' . $department . '» для подтверждения HRD.';
                            $actionurl = (new \moodle_url('/local/ustar/staffing.php',
                                ['requestid' => $requestid], 'request-' . $requestid))->out(false);
                        }
                    }
                }
                $unread = (string)$record->status === 'unread';
                $rows[] = [
                    'id' => (int)$record->id,
                    'source' => 'local',
                    'timecreated' => (int)$record->timecreated,
                    'subject' => (string)$record->subject,
                    'message' => shorten_text(strip_tags($message), 220),
                    'eventlabel' => $eventlabel,
                    'severity' => (string)$record->severity,
                    'unread' => $unread,
                    'read' => !$unread,
                    'time' => userdate((int)$record->timecreated, '%d.%m.%Y %H:%M'),
                    'hasurl' => $actionurl !== '',
                    'url' => $actionurl,
                    'urlname' => 'Открыть действие',
                ];
            }
        }

        $records = $DB->get_records(
            'notifications',
            ['useridto' => $userid],
            'timecreated DESC',
            '*',
            0,
            max(1, min(200, $limit))
        );

        foreach ($records as $record) {
            $contexturl = clean_param((string)$record->contexturl, PARAM_URL);
            $rows[] = [
                'id' => (int)$record->id,
                'source' => 'moodle',
                'timecreated' => (int)$record->timecreated,
                'subject' => trim((string)$record->subject) ?: 'Уведомление',
                'message' => shorten_text(strip_tags((string)($record->smallmessage ?: $record->fullmessage)), 220),
                'eventlabel' => 'Уведомление Академии',
                'unread' => empty($record->timeread),
                'read' => !empty($record->timeread),
                'time' => userdate((int)$record->timecreated, '%d.%m.%Y %H:%M'),
                'hasurl' => $contexturl !== '',
                'url' => $contexturl,
                'urlname' => trim((string)$record->contexturlname) ?: 'Открыть',
            ];
        }
        usort($rows, static fn(array $a, array $b): int =>
            $b['timecreated'] <=> $a['timecreated'] ?: $b['id'] <=> $a['id']);
        return array_slice($rows, 0, $limit);
    }

    public static function mark_notification(int $userid, int $notificationid, string $source = 'local'): void {
        global $DB;
        if (!in_array($source, ['local', 'moodle'], true)) {
            throw new \invalid_parameter_exception('Unknown notification source');
        }
        if ($source === 'local') {
            if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_notifications'))) {
                throw new \invalid_parameter_exception('Local notifications are unavailable');
            }
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
        }
        \core_message\api::mark_all_notifications_as_read($userid);
    }
}
