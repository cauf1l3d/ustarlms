<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Private post attachments. No public Moodle file URL bypasses feed ACL. */
final class feed_files {
    public const AREA = 'feed_attachment';
    public const SAVED_AREA = 'feed_saved';

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
            $out[] = ['id' => (int)$file->get_id(), 'name' => $file->get_filename(), 'size' => (int)$file->get_filesize(),
                'image' => $image,
                'url' => \moodle_url::make_pluginfile_url($contextid, 'local_ustar', self::AREA,
                    $postid, $file->get_filepath(), $file->get_filename(), !$image)->out(false),
                'downloadurl' => \moodle_url::make_pluginfile_url($contextid, 'local_ustar', self::AREA,
                    $postid, $file->get_filepath(), $file->get_filename(), true)->out(false)];
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
                'id' => (int)$file->id, 'name' => $file->filename, 'size' => (int)$file->filesize, 'image' => $image,
                'url' => \moodle_url::make_pluginfile_url($params['contextid'], 'local_ustar',
                    self::AREA, (int)$file->itemid, $file->filepath, $file->filename, !$image)->out(false),
                'downloadurl' => \moodle_url::make_pluginfile_url($params['contextid'], 'local_ustar',
                    self::AREA, (int)$file->itemid, $file->filepath, $file->filename, true)->out(false),
            ];
        }
        return $out;
    }

    /** Save a private, independent copy in the employee's personal knowledge library. */
    public static function save_to_library(int $postid, int $userid, int $fileid): int {
        global $DB;
        feed_access::require_actor($userid);
        if (!accounts::participates($userid) || !employment::is_active($userid)) {
            throw new \invalid_parameter_exception('Личная библиотека недоступна.');
        }
        $post = feed_access::readable($postid, $userid);
        $contextid = \context_system::instance()->id;
        $file = get_file_storage()->get_file_by_id($fileid);
        if (!$file || $file->is_directory() || (int)$file->get_contextid() !== $contextid
                || $file->get_component() !== 'local_ustar' || $file->get_filearea() !== self::AREA
                || (int)$file->get_itemid() !== (int)$post->id || $file->get_filepath() !== '/v1/') {
            throw new \invalid_parameter_exception('Вложение недоступно.');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('feed-save:' . $userid . ':' . $fileid, 10);
        if (!$lock) {
            throw new \moodle_exception('Не удалось сохранить вложение.');
        }
        try {
            $existing = $DB->get_field('local_ustar_feed_saves', 'id',
                ['userid' => $userid, 'sourcefileid' => $fileid]);
            if ($existing) {
                return (int)$existing;
            }
            $transaction = $DB->start_delegated_transaction();
            $savedid = (int)$DB->insert_record('local_ustar_feed_saves', (object)[
                'userid' => $userid, 'sourcepostid' => $postid, 'sourcefileid' => $fileid,
                'filename' => $file->get_filename(), 'timecreated' => time(),
            ]);
            get_file_storage()->create_file_from_storedfile([
                'contextid' => $contextid, 'component' => 'local_ustar',
                'filearea' => self::SAVED_AREA, 'itemid' => $savedid,
                'filepath' => '/', 'filename' => $file->get_filename(),
            ], $file);
            $transaction->allow_commit();
            return $savedid;
        } finally {
            $lock->release();
        }
    }

    /** Files are independent of subsequent feed visibility changes. */
    public static function saved_for_library(int $userid, string $query = '', int $page = 0): array {
        global $DB;
        $where = 'userid = :userid';
        $params = ['userid' => $userid];
        if (trim($query) !== '') {
            $where .= ' AND ' . $DB->sql_like('filename', ':filename', false);
            $params['filename'] = '%' . $DB->sql_like_escape(trim($query)) . '%';
        }
        $total = $DB->count_records_select('local_ustar_feed_saves', $where, $params);
        $records = $DB->get_records_select('local_ustar_feed_saves', $where, $params,
            'timecreated DESC, id DESC', '*', min(10000, max(0, $page)) * 24, 24);
        $out = [];
        $contextid = \context_system::instance()->id;
        foreach ($records as $record) {
            $file = get_file_storage()->get_file($contextid, 'local_ustar', self::SAVED_AREA,
                (int)$record->id, '/', $record->filename);
            if (!$file || $file->is_directory()) {
                continue;
            }
            $out[] = ['id' => (int)$record->id, 'title' => $record->filename,
                'date' => userdate((int)$record->timecreated, '%d.%m.%Y'),
                'size' => display_size($file->get_filesize()),
                'url' => \moodle_url::make_pluginfile_url($contextid, 'local_ustar', self::SAVED_AREA,
                    (int)$record->id, '/', $record->filename, true)->out(false)];
        }
        return ['items' => $out, 'total' => $total,
            'hasnext' => (max(0, $page) + 1) * 24 < $total];
    }

    public static function remove_from_library(int $savedid, int $userid): void {
        global $DB;
        $record = $DB->get_record('local_ustar_feed_saves', ['id' => $savedid, 'userid' => $userid]);
        if (!$record) {
            throw new \invalid_parameter_exception('Вложение недоступно.');
        }
        $transaction = $DB->start_delegated_transaction();
        get_file_storage()->delete_area_files(\context_system::instance()->id,
            'local_ustar', self::SAVED_AREA, $savedid);
        $DB->delete_records('local_ustar_feed_saves', ['id' => $savedid, 'userid' => $userid]);
        $transaction->allow_commit();
    }
}
