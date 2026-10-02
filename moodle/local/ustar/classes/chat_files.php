<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Attachments belong to native messages and inherit current membership and per-user deletion. */
final class chat_files {
    public const AREA = 'message_attachment';
    public const MAX_FILES = 5;

    public static function max_bytes(): int {
        return min(25 * 1024 * 1024, task_files::max_file_bytes());
    }

    public static function validate(array $uploads): array {
        if (count($uploads) > self::MAX_FILES) {
            throw new \invalid_parameter_exception('К сообщению можно приложить не более 5 файлов.');
        }
        $total = 0;
        $names = [];
        foreach ($uploads as &$upload) {
            $size = (int)($upload['size'] ?? 0);
            $filename = (string)($upload['filename'] ?? '');
            $path = (string)($upload['tmp'] ?? '');
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($filename === '' || $filename !== clean_param($filename, PARAM_FILE)
                    || isset($names[$filename]) || !is_uploaded_file($path)
                    || $size <= 0 || $size > self::max_bytes() || filesize($path) !== $size
                    || !in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov',
                        'mp3', 'm4a', 'ogg', 'wav', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx',
                        'ppt', 'pptx', 'zip'], true)) {
                throw new \invalid_parameter_exception('Недопустимый файл или превышен допустимый размер.');
            }
            $total += $size;
            $names[$filename] = true;
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $images = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp'];
            if (isset($images[$extension]) && ($detected !== $images[$extension] || !getimagesize($path))) {
                throw new \invalid_parameter_exception('Изображение повреждено или имеет неверный формат.');
            }
            $media = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
                'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'wav' => 'audio/x-wav'];
            if (isset($media[$extension]) && !in_array($detected,
                    match ($extension) {
                        'ogg' => ['audio/ogg', 'application/ogg'],
                        'wav' => ['audio/x-wav', 'audio/wav'],
                        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
                        default => [$media[$extension]],
                    }, true)) {
                throw new \invalid_parameter_exception('Медиафайл повреждён или имеет неверный формат.');
            }
            $upload['mimetype'] = $images[$extension] ?? $media[$extension] ?? 'application/octet-stream';
        }
        unset($upload);
        if ($total > 50 * 1024 * 1024) {
            throw new \invalid_parameter_exception('Общий размер вложений сообщения не должен превышать 50 МБ.');
        }
        return $uploads;
    }

    /** Invoked only after validation, inside the native message send transaction. */
    public static function store(int $messageid, int $userid, array $uploads): void {
        foreach ($uploads as $upload) {
            get_file_storage()->create_file_from_pathname([
                'contextid' => \context_system::instance()->id, 'component' => 'local_ustar',
                'filearea' => self::AREA, 'itemid' => $messageid, 'filepath' => '/',
                'filename' => $upload['filename'], 'mimetype' => $upload['mimetype'], 'userid' => $userid,
            ], $upload['tmp']);
        }
    }

    public static function can_read(int $userid, int $messageid): bool {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], 'id,deleted,suspended');
        if (!$user || $user->deleted || $user->suspended || view_as::active() || !employment::is_active($userid)) { return false; }
        $message = $DB->get_record('messages', ['id' => $messageid], 'id,conversationid');
        return $message
            && has_capability('local/ustar:use', \context_system::instance(), $userid)
            && \core_message\api::is_user_in_conversation($userid, (int)$message->conversationid)
            && $DB->record_exists('message_conversations',
                ['id' => $message->conversationid, 'enabled' => \core_message\api::MESSAGE_CONVERSATION_ENABLED])
            && !$DB->record_exists('message_user_actions', ['userid' => $userid, 'messageid' => $messageid,
                'action' => \core_message\api::MESSAGE_ACTION_DELETED]);
    }

    /** Message IDs must come from the native, permission-filtered conversation response. */
    public static function for_visible_messages(array $messageids): array {
        global $DB;
        if (!$messageids) {
            return [];
        }
        [$sql, $params] = $DB->get_in_or_equal($messageids, SQL_PARAMS_NAMED);
        $params += ['context' => \context_system::instance()->id, 'component' => 'local_ustar', 'area' => self::AREA];
        $records = $DB->get_records_select('files',
            "contextid = :context AND component = :component AND filearea = :area AND itemid $sql AND filename <> '.'",
            $params, 'id ASC', 'id,itemid,filename,mimetype,filesize');
        $out = [];
        foreach ($records as $file) {
            $image = in_array($file->mimetype, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
            $video = in_array($file->mimetype, ['video/mp4', 'video/webm', 'video/quicktime'], true);
            $audio = in_array($file->mimetype, ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/x-wav'], true);
            $out[(int)$file->itemid][] = [
                'name' => $file->filename, 'size' => display_size($file->filesize),
                'image' => $image, 'video' => $video, 'audio' => $audio,
                'url' => \moodle_url::make_pluginfile_url($params['context'], 'local_ustar', self::AREA,
                    $file->itemid, '/', $file->filename, !($image || $video || $audio))->out(false),
                'downloadurl' => \moodle_url::make_pluginfile_url($params['context'], 'local_ustar', self::AREA,
                    $file->itemid, '/', $file->filename, true)->out(false),
            ];
        }
        return $out;
    }
}
