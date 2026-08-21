<?php
// Public delivery of non-sensitive USTAR Brand Studio images.
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../../config.php');

$filename = required_param('file', PARAM_FILE);
$context = context_system::instance();
$file = get_file_storage()->get_file(
    $context->id,
    'local_ustar',
    'branding',
    0,
    '/',
    $filename
);
if (!$file || $file->is_directory()) {
    send_file_not_found();
}

send_stored_file($file, DAYSECS, 0, false, [
    'cacheability' => 'public',
]);
