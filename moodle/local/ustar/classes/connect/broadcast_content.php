<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

use local_ustar\feed_files;
use local_ustar\task_files;

/** Exact text and File API inventory explicitly approved for Telegram. */
final class broadcast_content {
    public static function require_fit(string $text, array $files): void {
        $limit = $files ? 1024 : 4096;
        if (trim($text) === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)
                || strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) > 2 * $limit) {
            throw new \invalid_parameter_exception("Для отправки в Telegram укажите текст до {$limit} символов.");
        }
        if (count($files) > task_files::MAX_FILES) {
            throw new \invalid_parameter_exception('Для рассылки можно выбрать до 10 файлов.');
        }
        $total = 0;
        foreach ($files as $file) {
            $total += $file['size'];
            if ($file['size'] <= 0 || $file['size'] > 50 * 1024 * 1024
                    || $file['name'] === '' || in_array($file['name'], ['.', '..'], true)
                    || strlen($file['name']) > 1020 || preg_match('~[\x00-\x1F\x7F"\\\\/]~', $file['name'])) {
                throw new \invalid_parameter_exception('Проверьте имя и размер файла. Лимит Telegram — 50 МБ на файл.');
            }
        }
        if ($total > 100 * 1024 * 1024) {
            throw new \invalid_parameter_exception('Общий размер вложений превышает 100 МБ.');
        }
    }

    private static function descriptor(\stored_file $file): array {
        return ['id' => (int)$file->get_id(), 'name' => $file->get_filename(),
            'size' => (int)$file->get_filesize(), 'hash' => $file->get_contenthash()];
    }

    public static function snapshot(\stdClass $post): array {
        $files = [];
        foreach (get_file_storage()->get_area_files(\context_system::instance()->id, 'local_ustar',
                feed_files::AREA, $post->id, 'timecreated ASC, id ASC', false) as $file) {
            if ($file->is_external_file()) {
                throw new \invalid_parameter_exception('Загрузите вложение в Академию, чтобы отправить его в Telegram.');
            }
            $files[] = self::descriptor($file);
        }
        $text = (string)$post->body;
        self::require_fit($text, $files);
        $content = ['text' => $text, 'files' => $files];
        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 15800) {
            throw new \invalid_parameter_exception('Сократите имена вложений или текст рассылки.');
        }
        return $content + ['stamp' => hash('sha256', $encoded)];
    }

    public static function key(\stdClass $post, int $userid, string $stamp): string {
        $digest = rtrim(strtr(base64_encode(hex2bin($stamp)), '+/', '-_'), '=');
        return announcements::BROADCAST_EVENT . ':' . (int)$post->id . ':' . (int)$post->version . ':' . $userid . ':' . $digest;
    }

    public static function for_notice(\stdClass $notice): ?array {
        $post = announcements::post_for_notice($notice);
        if ($post === null) {
            return null;
        }
        try {
            $content = self::snapshot($post);
        } catch (\invalid_parameter_exception $e) {
            return null;
        }
        return hash_equals(self::key($post, (int)$notice->userid, $content['stamp']), $notice->idempotencykey)
            ? $content : null;
    }

    public static function leased_file(array $content, int $fileid): \stored_file {
        foreach ($content['files'] as $descriptor) {
            if ($descriptor['id'] === $fileid) {
                $file = get_file_storage()->get_file_by_id($fileid);
                if ($file && !$file->is_external_file() && self::descriptor($file) === $descriptor) {
                    return $file;
                }
                break;
            }
        }
        throw new \InvalidArgumentException('invalid_delivery');
    }
}
