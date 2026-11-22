<?php
namespace local_ustar;

use core_message\api as messaging;
use local_ustar\task_workspace\discussion;

defined('MOODLE_INTERNAL') || die();

/** Attachments belong to native messages and inherit current membership and per-user deletion. */
final class chat_files {
    public const AREA = 'message_attachment';
    public const MAX_FILES = 5;
    public const INLINE_IMAGE_BYTES = 256 * 1024;
    public const PREVIEW_IMAGE_BYTES = 4 * 1024 * 1024;
    public const TOTAL_BYTES = 50 * 1024 * 1024;
    private const IMAGES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public static function max_bytes(): int {
        return min(25 * 1024 * 1024, task_files::max_file_bytes());
    }

    public static function validate(array $uploads): array {
        if (!$uploads) {
            return [];
        }
        if (count($uploads) > self::MAX_FILES) {
            throw new chat_input_error('К сообщению можно приложить не более 5 файлов.');
        }
        $total = 0;
        $names = [];
        $maximum = self::max_bytes();
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        foreach ($uploads as &$upload) {
            $size = (int)($upload['size'] ?? 0);
            $filename = (string)($upload['filename'] ?? '');
            $path = (string)($upload['tmp'] ?? '');
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($filename === '' || $filename !== clean_param($filename, PARAM_FILE)
                    || isset($names[$filename]) || !is_uploaded_file($path)
                    || $size <= 0 || $size > $maximum || filesize($path) !== $size
                    || !in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov',
                        'mp3', 'm4a', 'ogg', 'wav', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx',
                        'ppt', 'pptx', 'zip'], true)) {
                throw new chat_input_error('Недопустимый файл или превышен допустимый размер.');
            }
            $total += $size;
            if ($total > self::TOTAL_BYTES) {
                throw new chat_input_error('Общий размер вложений сообщения не должен превышать 50 МБ.');
            }
            $names[$filename] = true;
            $detected = $detector->file($path);
            $images = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp'];
            if (isset($images[$extension]) && ($detected !== $images[$extension] || !getimagesize($path))) {
                throw new chat_input_error('Изображение повреждено или имеет неверный формат.');
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
                throw new chat_input_error('Медиафайл повреждён или имеет неверный формат.');
            }
            $upload['mimetype'] = $images[$extension] ?? $media[$extension] ?? 'application/octet-stream';
        }
        unset($upload);
        foreach ($uploads as $upload) {
            \core\antivirus\manager::scan_file($upload['tmp'], $upload['filename'], true);
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
        $context = \context_system::instance();
        if (!has_capability('local/ustar:use', $context, $userid)) {
            return false;
        }
        $conversation = $DB->get_record_sql('SELECT c.id, c.itemtype, c.itemid, c.contextid, c.type, c.enabled, c.component
              FROM {messages} m JOIN {message_conversations} c ON c.id = m.conversationid
             WHERE m.id = :message AND NOT EXISTS (
                   SELECT 1 FROM {message_user_actions} a
                    WHERE a.messageid = m.id AND a.userid = :actor AND a.action = :deleted)',
            ['message' => $messageid, 'actor' => $userid, 'deleted' => messaging::MESSAGE_ACTION_DELETED]);
        if (!$conversation) {
            return false;
        }
        if ($conversation->itemtype === discussion::ITEMTYPE) {
            return $conversation->component === null && (int)$conversation->contextid === $context->id
                && (int)$conversation->type === messaging::MESSAGE_CONVERSATION_TYPE_GROUP
                && (int)$conversation->enabled === messaging::MESSAGE_CONVERSATION_DISABLED
                && discussion::readable($userid, (int)$conversation->itemid) !== null;
        }
        return (int)$conversation->enabled === messaging::MESSAGE_CONVERSATION_ENABLED
            && messaging::is_user_in_conversation($userid, (int)$conversation->id);
    }

    /** Reuse Moodle's content-hash thumbnail storage after the original file's authorization. */
    public static function preview(\stored_file $file): ?\stored_file {
        if (!in_array($file->get_mimetype(), self::IMAGES, true) || $file->get_filesize() > self::PREVIEW_IMAGE_BYTES) {
            return null;
        }
        $info = $file->get_imageinfo();
        if (!$info || $info['width'] * $info['height'] > 6000000) {
            return null;
        }
        $factory = \core\lock\lock_config::get_lock_factory('local_ustar');
        $lock = $factory->get_lock('chat-preview:' . $file->get_contenthash(), 2);
        if (!$lock) {
            return null;
        }
        try {
            return get_file_storage()->get_file_preview($file, 'bigthumb') ?: null;
        } finally {
            $lock->release();
        }
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
            $image = in_array($file->mimetype, self::IMAGES, true);
            $video = in_array($file->mimetype, ['video/mp4', 'video/webm', 'video/quicktime'], true);
            $audio = in_array($file->mimetype, ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/x-wav'], true);
            $url = \moodle_url::make_pluginfile_url($params['context'], 'local_ustar', self::AREA,
                $file->itemid, '/', $file->filename, !($image || $video || $audio));
            $preview = clone $url;
            if ($file->filesize > self::INLINE_IMAGE_BYTES) {
                $preview->param('preview', 'bigthumb');
            }
            $out[(int)$file->itemid][] = [
                'name' => $file->filename, 'size' => display_size($file->filesize),
                'image' => $image && $file->filesize <= self::PREVIEW_IMAGE_BYTES,
                'openimage' => $image && $file->filesize > self::PREVIEW_IMAGE_BYTES,
                'video' => $video, 'audio' => $audio, 'url' => $url->out(false), 'previewurl' => $preview->out(false),
                'downloadurl' => \moodle_url::make_pluginfile_url($params['context'], 'local_ustar', self::AREA,
                    $file->itemid, '/', $file->filename, true)->out(false),
            ];
        }
        return $out;
    }
}
