<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves game media from Moodle file storage without persisting host names.
 */
final class game_media {
    public static function question_image_url(\stdClass $question): string {
        $questionid = (int)($question->id ?? 0);
        if ($questionid > 0) {
            $context = \context_system::instance();
            $files = get_file_storage()->get_area_files(
                $context->id,
                'local_ustar',
                'game_question_image',
                $questionid,
                'filename ASC',
                false
            );

            foreach ($files as $file) {
                return \moodle_url::make_pluginfile_url(
                    $context->id,
                    'local_ustar',
                    'game_question_image',
                    $questionid,
                    $file->get_filepath(),
                    $file->get_filename(),
                    false
                )->out(false);
            }
        }

        // Preserve intentional external images when no Moodle-owned file exists.
        return trim((string)($question->imageurl ?? ''));
    }
}
