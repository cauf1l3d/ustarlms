<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class admin_upload_brand_asset extends base {

    private const MAX_BYTES = 3 * 1024 * 1024;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'filename' => new external_value(PARAM_FILE, 'Original image filename'),
            'data' => new external_value(PARAM_RAW, 'Base64 encoded image bytes'),
        ]);
    }

    public static function execute(string $filename, string $data): array {
        global $CFG;
        self::guard();
        \local_ustar\view_as::assert_writable();
        require_capability('local/ustar:admin', \context_system::instance());
        $params = self::validate_parameters(self::execute_parameters(), [
            'filename' => $filename,
            'data' => $data,
        ]);

        $raw = base64_decode($params['data'], true);
        if ($raw === false || $raw === '') {
            throw new \invalid_parameter_exception('Invalid base64 image payload');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new \invalid_parameter_exception('Brand image must be 3 MB or smaller');
        }

        $imageinfo = @getimagesizefromstring($raw);
        if (!$imageinfo || empty($imageinfo['mime'])) {
            throw new \invalid_parameter_exception('Uploaded file is not a valid image');
        }
        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$imageinfo['mime']])) {
            throw new \invalid_parameter_exception('Only PNG, JPEG and WebP images are allowed');
        }
        $width = (int)($imageinfo[0] ?? 0);
        $height = (int)($imageinfo[1] ?? 0);
        if ($width < 64 || $height < 64 || $width > 8000 || $height > 8000) {
            throw new \invalid_parameter_exception('Image dimensions must be between 64 and 8000 pixels');
        }

        $context = \context_system::instance();
        $ext = $allowed[$imageinfo['mime']];
        $stem = pathinfo($params['filename'], PATHINFO_FILENAME);
        $stem = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $stem) ?: 'brand';
        $stem = trim($stem, '-_');
        if ($stem === '') {
            $stem = 'brand';
        }
        $storedname = substr($stem, 0, 48) . '-' . time() . '-' . random_string(6) . '.' . $ext;

        $fs = get_file_storage();
        $record = [
            'contextid' => $context->id,
            'component' => 'local_ustar',
            'filearea' => 'branding',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $storedname,
        ];
        $file = $fs->create_file_from_string($record, $raw);
        $url = new \moodle_url('/local/ustar/brand_asset.php', [
            'file' => $file->get_filename(),
        ]);

        return [
            'url' => $url->out(false),
            'filename' => $file->get_filename(),
            'width' => $width,
            'height' => $height,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'url' => new external_value(PARAM_URL),
            'filename' => new external_value(PARAM_FILE),
            'width' => new external_value(PARAM_INT),
            'height' => new external_value(PARAM_INT),
        ]);
    }
}
