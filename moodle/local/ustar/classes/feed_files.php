<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Private post attachments. No public Moodle file URL bypasses feed ACL. */
final class feed_files {
    public const AREA = 'feed_attachment';

    public static function store(int $postid, array $uploads): void {
        if (!$uploads) {
            return;
        }
        $contextid = \context_system::instance()->id;
        foreach ($uploads as $upload) {
            if (!is_array($upload) || empty($upload['tmp']) || !is_uploaded_file($upload['tmp'])
                    || empty($upload['filename'])
                    || $upload['filename'] !== clean_param($upload['filename'], PARAM_FILE)
                    || (int)($upload['size'] ?? 0) > task_files::max_file_bytes()) {
                throw new \invalid_parameter_exception('Недопустимое вложение публикации.');
            }
            get_file_storage()->create_file_from_pathname([
                'contextid' => $contextid, 'component' => 'local_ustar',
                'filearea' => self::AREA, 'itemid' => $postid,
                'filepath' => '/v1/', 'filename' => $upload['filename'],
            ], $upload['tmp']);
        }
    }

    public static function list_for(int $postid, int $userid): array {
        feed_access::readable($postid, $userid);
        $contextid = \context_system::instance()->id;
        $out = [];
        foreach (get_file_storage()->get_area_files($contextid, 'local_ustar', self::AREA,
                $postid, 'timecreated ASC, id ASC', false) as $file) {
            $image = in_array($file->get_mimetype(), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
            $out[] = ['name' => $file->get_filename(), 'size' => (int)$file->get_filesize(),
                'image' => $image,
                'url' => \moodle_url::make_pluginfile_url($contextid, 'local_ustar', self::AREA,
                    $postid, $file->get_filepath(), $file->get_filename(), !$image)->out(false)];
        }
        return $out;
    }
}
