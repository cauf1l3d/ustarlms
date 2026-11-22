<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Common composer metadata for messages and the task card. */
final class chat_view {
    public static function composer(\moodle_url $posturl, string $draft = '', string $requestid = ''): array {
        $maxbytes = chat_files::max_bytes();
        return [
            'posturl' => $posturl->out(false), 'draft' => $draft,
            'requestid' => $requestid ?: bin2hex(random_bytes(16)), 'sesskey' => sesskey(),
            'apiurl' => (new \moodle_url('/local/ustar/messages_api.php'))->out(false),
            'maxbytes' => $maxbytes, 'maxsize' => display_size($maxbytes),
            'maxpostbytes' => get_real_size(ini_get('post_max_size')),
        ];
    }
}
