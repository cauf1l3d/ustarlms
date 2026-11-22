<?php
namespace local_ustar\privacy;

use core_message\api as messaging;
use core_privacy\local\request\{contextlist, userlist, writer};
use local_ustar\chat_files;
use local_ustar\task_workspace\{discussion, personal_plan};

defined('MOODLE_INTERNAL') || die();

/** Core's per-user message export follows membership. Task discussions use current task access instead. */
final class workplace {
    /** Select native conversation IDs without including ordinary groups or course chats. */
    private static function discussions(): array {
        return [
            'SELECT id FROM {message_conversations}
              WHERE component IS NULL AND itemtype = :workmarker AND contextid = :workcontext
                    AND type = :worktype AND enabled = :workenabled',
            ['workmarker' => discussion::ITEMTYPE, 'workcontext' => \context_system::instance()->id,
                'worktype' => messaging::MESSAGE_CONVERSATION_TYPE_GROUP, 'workenabled' => messaging::MESSAGE_CONVERSATION_DISABLED],
        ];
    }

    private static function files(): array {
        return ["contextid = :filecontext AND component = 'local_ustar' AND filearea = :chatarea AND filename <> '.'",
            ['filecontext' => \context_system::instance()->id, 'chatarea' => chat_files::AREA]];
    }

    public static function contexts(contextlist $contexts, int $userid): void {
        [$threads, $params] = self::discussions();
        [$files, $fileparams] = self::files();
        $contexts->add_from_sql("SELECT c.id FROM {context} c WHERE c.contextlevel = :worklevel AND (
              EXISTS (SELECT 1 FROM {messages} m WHERE m.conversationid IN ($threads) AND m.useridfrom = :author)
           OR EXISTS (SELECT 1 FROM {files} WHERE $files AND userid = :uploader)
           OR EXISTS (SELECT 1 FROM {user_preferences} WHERE name = :planpref AND userid = :planner))",
            $params + $fileparams + ['worklevel' => CONTEXT_SYSTEM, 'author' => $userid, 'uploader' => $userid,
                'planpref' => personal_plan::PREF, 'planner' => $userid]);
        $contexts->add_from_sql("SELECT c.id FROM {context} c WHERE c.contextlevel = :worklevel AND
              EXISTS (SELECT 1 FROM {message_user_actions} a
                JOIN {messages} m ON m.id = a.messageid WHERE m.conversationid IN ($threads) AND a.userid = :reader)",
            $params + ['worklevel' => CONTEXT_SYSTEM, 'reader' => $userid]);
    }

    public static function users(userlist $users): void {
        [$threads, $params] = self::discussions();
        [$files, $fileparams] = self::files();
        $users->add_from_sql('userid', "SELECT DISTINCT useridfrom AS userid FROM {messages}
            WHERE conversationid IN ($threads) AND useridfrom > 0", $params);
        $users->add_from_sql('userid', "SELECT DISTINCT a.userid FROM {message_user_actions} a
            JOIN {messages} m ON m.id = a.messageid WHERE m.conversationid IN ($threads) AND a.userid > 0", $params);
        $users->add_from_sql('userid', "SELECT DISTINCT userid FROM {files} WHERE $files AND userid > 0", $fileparams);
        $users->add_from_sql('userid', 'SELECT userid FROM {user_preferences} WHERE name = :preference', ['preference' => personal_plan::PREF]);
    }

    public static function export_preference(int $userid): void {
        global $DB;
        $raw = $DB->get_field('user_preferences', 'value', ['userid' => $userid, 'name' => personal_plan::PREF]);
        if ($raw !== false) {
            writer::export_user_preference('local_ustar', personal_plan::PREF, $raw,
                get_string('privacy:metadata:workplan', 'local_ustar'));
        }
    }

    public static function export(int $userid): void {
        global $DB;
        $context = \context_system::instance();
        [$threads, $params] = self::discussions();
        [$files, $fileparams] = self::files();
        $data = writer::with_context($context);
        $messages = $DB->get_recordset_sql("SELECT m.id, c.itemid AS taskid, m.timecreated, m.fullmessage AS text
            FROM {messages} m JOIN {message_conversations} c ON c.id = m.conversationid
            WHERE m.conversationid IN ($threads) AND m.useridfrom = :actor ORDER BY m.id",
            $params + ['actor' => $userid]);
        try {
            foreach ($messages as $message) {
                $data->export_data(['Task discussions', (string)$message->taskid, (string)$message->id], $message);
            }
        } finally {
            $messages->close();
        }
        $ids = $DB->get_fieldset_sql("SELECT DISTINCT itemid FROM {files} WHERE $files AND userid = :actor",
            $fileparams + ['actor' => $userid]);
        foreach ($ids as $id) {
            $data->export_area_files(['Chat attachments', (string)$id], 'local_ustar', chat_files::AREA, (int)$id);
        }
    }

    /** Called inside the privacy provider's transaction. Moodle owns physical file reclamation. */
    public static function erase(?array $userids): void {
        global $DB;
        $contextid = \context_system::instance()->id;
        [$threads, $params] = self::discussions();
        $messageids = "SELECT id FROM {messages} WHERE conversationid IN ($threads)";
        $storage = get_file_storage();
        if ($userids === null) {
            $DB->delete_records_select('message_user_actions', "messageid IN ($messageids)", $params);
            $DB->delete_records_select('messages', "conversationid IN ($threads)", $params);
            $DB->delete_records_select('message_conversations', "id IN ($threads)", $params);
            $DB->delete_records('user_preferences', ['name' => personal_plan::PREF]);
            $storage->delete_area_files($contextid, 'local_ustar', chat_files::AREA);
            return;
        }
        [$users, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'privateuser');
        $DB->delete_records_select('message_user_actions', "userid $users AND messageid IN ($messageids)", $params + $userparams);
        $DB->delete_records_select('message_user_actions', "messageid IN ($messageids AND useridfrom $users)", $params + $userparams);
        $DB->delete_records_select('messages', "conversationid IN ($threads) AND useridfrom $users", $params + $userparams);
        $DB->delete_records_select('user_preferences', "name = :planpref AND userid $users", ['planpref' => personal_plan::PREF] + $userparams);
        [$filewhere, $fileparams] = self::files();
        $records = $DB->get_recordset_select('files', "$filewhere AND userid $users", $fileparams + $userparams, '', 'id');
        try {
            foreach ($records as $file) {
                $stored = $storage->get_file_by_id((int)$file->id);
                if ($stored) {
                    $stored->delete();
                }
            }
        } finally {
            $records->close();
        }
    }
}
