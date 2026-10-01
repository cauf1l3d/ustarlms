<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Standalone native groups use a reserved marker for ownership, not a course component link. */
final class chat_groups {
    public const ITEMTYPE = 'local_ustar_workchat';
    public const MAX_MEMBERS = 100;

    public static function can_create(int $userid): bool {
        global $CFG, $DB;
        $user = $DB->get_record('user', ['id' => $userid], 'id,deleted,suspended');
        return $user && !$user->deleted && !$user->suspended && !empty($CFG->messaging) && !view_as::active()
            && employment::is_active($userid)
            && has_capability('local/ustar:use', \context_system::instance(), $userid)
            && has_capability('moodle/site:sendmessage', \context_system::instance(), $userid);
    }

    private static function name(string $name): string {
        $name = trim(clean_param($name, PARAM_TEXT));
        if ($name === '' || \core_text::strlen($name) > 100) {
            throw new \invalid_parameter_exception('Название чата должно содержать от 1 до 100 символов.');
        }
        return $name;
    }

    private static function recipients(int $actorid, array $userids): array {
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (count($userids) > self::MAX_MEMBERS - 1) {
            throw new \invalid_parameter_exception('В чате может быть не более 100 участников.');
        }
        foreach ($userids as $userid) {
            if ($userid <= 1 || $userid === $actorid || !employment::is_active($userid)
                    || !has_capability('local/ustar:use', \context_system::instance(), $userid)
                    || !($target = \core_user::get_user($userid, 'id,deleted,suspended'))
                    || $target->deleted || $target->suspended
                    || !\core_message\api::can_send_message($userid, $actorid)) {
                throw new \invalid_parameter_exception('Один из выбранных сотрудников недоступен для общения.');
            }
        }
        return $userids;
    }

    public static function create(int $userid, string $name, array $members): int {
        global $DB;
        communication::require_actor($userid, true);
        if (!self::can_create($userid)) {
            throw new \required_capability_exception(\context_system::instance(), 'moodle/site:sendmessage', 'nopermissions', '');
        }
        $members = self::recipients($userid, $members);
        if (count($members) < 2) {
            throw new \invalid_parameter_exception('Выберите хотя бы двух коллег для группового чата.');
        }
        $name = self::name($name);
        $transaction = $DB->start_delegated_transaction();
        try {
            // Moodle 5.1's linked presenter assumes core_group/groups and warns for other components.
            // These are standalone groups: core owns members/messages; the reserved item marker
            // identifies the USTAR creator without entering the course-link presentation path.
            $conversation = \core_message\api::create_conversation(
                \core_message\api::MESSAGE_CONVERSATION_TYPE_GROUP, array_merge([$userid], $members),
                $name, \core_message\api::MESSAGE_CONVERSATION_ENABLED,
                null, self::ITEMTYPE, $userid, \context_system::instance()->id
            );
            $transaction->allow_commit();
            return (int)$conversation->id;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }
    }

    public static function can_manage(int $userid, int $conversationid): bool {
        global $DB;
        return self::can_create($userid)
            && \core_message\api::is_user_in_conversation($userid, $conversationid)
            && $DB->record_exists('message_conversations', [
                'id' => $conversationid, 'type' => \core_message\api::MESSAGE_CONVERSATION_TYPE_GROUP,
                'component' => null, 'itemtype' => self::ITEMTYPE,
                'itemid' => $userid, 'enabled' => \core_message\api::MESSAGE_CONVERSATION_ENABLED,
                'contextid' => \context_system::instance()->id,
            ]);
    }

    public static function change(int $userid, int $conversationid, string $action, string $name = '', array $members = []): void {
        global $DB;
        communication::require_actor($userid, true);
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('chat:' . $conversationid, 10);
        if (!$lock) {
            throw new \invalid_parameter_exception('Чат занят. Повторите действие.');
        }
        try {
            if (!self::can_manage($userid, $conversationid)) {
                throw new \invalid_parameter_exception('Управление этим чатом недоступно.');
            }
            $transaction = $DB->start_delegated_transaction();
            if ($action === 'rename') {
                \core_message\api::update_conversation_name($conversationid, self::name($name));
            } else if ($action === 'add') {
                $members = self::recipients($userid, $members);
                $existing = $DB->get_fieldset_select('message_conversation_members', 'userid',
                    'conversationid = :id', ['id' => $conversationid]);
                $members = array_values(array_diff($members, $existing));
                if (count($existing) + count($members) > self::MAX_MEMBERS) {
                    throw new \invalid_parameter_exception('В чате может быть не более 100 участников.');
                }
                if ($members) {
                    \core_message\api::add_members_to_conversation($members, $conversationid);
                }
            } else if ($action === 'remove') {
                $members = array_values(array_unique(array_map('intval', $members)));
                if (in_array($userid, $members, true)) {
                    throw new \invalid_parameter_exception('Создатель чата должен оставаться участником.');
                }
                if ($members) {
                    \core_message\api::remove_members_from_conversation($members, $conversationid);
                }
            } else {
                throw new \invalid_parameter_exception('Неизвестное действие с чатом.');
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (isset($transaction)) { $transaction->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
