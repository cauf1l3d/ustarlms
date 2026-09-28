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

    /** Read attachments for one already paged feed, applying the current audience in SQL. */
    public static function list_for_visible_posts(array $postids, int $userid): array {
        global $DB;
        feed_access::require_reader($userid);
        $postids = array_values(array_unique(array_filter(array_map('intval', $postids))));
        if (!$postids) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($postids, SQL_PARAMS_NAMED, 'feedfile');
        $params = $inparams + [
            'contextid' => \context_system::instance()->id,
            'component' => 'local_ustar', 'filearea' => self::AREA,
            'published' => 'published', 'sourcepublished' => 'published',
        ];
        $audience = feed_access::audience_sql($userid, 'p', $params, 'file');
        $sourceaudience = feed_access::audience_sql($userid, 'src', $params, 'filesource');
        $files = $DB->get_records_sql("SELECT f.id, f.itemid, f.filename, f.filepath, f.mimetype, f.filesize
                FROM {files} f
                JOIN {local_ustar_feed_posts} p ON p.id = f.itemid
               WHERE f.contextid = :contextid AND f.component = :component
                 AND f.filearea = :filearea AND f.itemid {$insql}
                 AND f.filename <> '.' AND p.status = :published AND {$audience}
                 AND (p.sourcepostid IS NULL OR EXISTS (
                     SELECT 1 FROM {local_ustar_feed_posts} src
                      WHERE src.id = p.sourcepostid AND src.status = :sourcepublished
                        AND {$sourceaudience}))
            ORDER BY f.itemid, f.timecreated, f.id", $params);
        $out = [];
        foreach ($files as $file) {
            $image = in_array($file->mimetype, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
            $out[(int)$file->itemid][] = [
                'name' => $file->filename, 'size' => (int)$file->filesize, 'image' => $image,
                'url' => \moodle_url::make_pluginfile_url($params['contextid'], 'local_ustar',
                    self::AREA, (int)$file->itemid, $file->filepath, $file->filename, !$image)->out(false),
            ];
        }
        return $out;
    }
}
