<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Store a single image per offer and apply the shop's access rules to its URL. */
final class reward_shop_cover {
    public const AREA = 'rewardshopcover';
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_SIDE = 4096;
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function validate(string $path): string {
        $size = is_file($path) ? filesize($path) : false;
        if (!$size || $size > self::MAX_BYTES) {
            throw new \moodle_exception('rewardshop_coverinvalid', 'local_ustar');
        }
        $info = @getimagesize($path);
        $mime = $info['mime'] ?? '';
        if (!isset(self::EXTENSIONS[$mime]) || $info[0] < 1 || $info[1] < 1
                || max($info[0], $info[1]) > self::MAX_SIDE
                || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== $mime) {
            throw new \moodle_exception('rewardshop_coverinvalid', 'local_ustar');
        }
        return $mime;
    }

    /** Called within the locked offer save, after its revision has been checked. */
    public static function replace(int $offerid, ?string $path, ?string $mime): void {
        $storage = get_file_storage();
        $contextid = \context_system::instance()->id;
        $oldfiles = $storage->get_area_files($contextid, 'local_ustar', self::AREA, $offerid, 'id', false);
        $filename = $path !== null ? sha1_file($path).'.'.self::EXTENSIONS[$mime] : null;
        if ($filename !== null && !$storage->file_exists($contextid, 'local_ustar', self::AREA, $offerid, '/', $filename)) {
            // The photo belongs to the offer. The author of the edit is recorded on the offer.
            $storage->create_file_from_pathname([
                'contextid' => $contextid, 'component' => 'local_ustar', 'filearea' => self::AREA,
                'itemid' => $offerid, 'filepath' => '/', 'filename' => $filename, 'mimetype' => $mime,
            ], $path);
        }
        foreach ($oldfiles as $file) {
            if ($file->get_filename() !== $filename) {
                $file->delete();
            }
        }
    }

    /** Resolve the visible page's image URLs in one query. Controllers select accessible offers. */
    public static function urls(array $offerids): array {
        global $DB;
        if (!$offerids) {
            return [];
        }
        $contextid = \context_system::instance()->id;
        [$in, $params] = $DB->get_in_or_equal($offerids, SQL_PARAMS_NAMED, 'offer');
        $params += ['ctx' => $contextid, 'component' => 'local_ustar', 'area' => self::AREA];
        $files = $DB->get_records_select('files',
            "contextid = :ctx AND component = :component AND filearea = :area AND itemid $in
             AND filepath = '/' AND filename <> '.'", $params, 'id ASC', 'id, itemid, filename');
        $urls = [];
        foreach ($files as $file) {
            $urls[$file->itemid] = \moodle_url::make_pluginfile_url(
                $contextid, 'local_ustar', self::AREA, $file->itemid, '/', $file->filename,
            )->out(false);
        }
        return $urls;
    }

    public static function can_read(int $userid, int $offerid): bool {
        global $DB;
        $status = $DB->get_field(reward_shop::OFFER, 'status', ['id' => $offerid]);
        return $status !== false && (reward_shop::can_manage($userid)
            || ($status === 'published' && reward_shop::can_shop($userid)));
    }
}
