<?php
// Compatibility entry point for bookmarks to the retired organization canvas.
require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/ustar:hr', $context);
require_capability('local/ustar:use', $context);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw new invalid_parameter_exception('Этот адрес доступен только для просмотра.');
}

// The old q parameter searched the whole HR dataset. The team page has a
// narrower per-user scope and no equivalent search, so do not forward it.
redirect(new moodle_url('/local/ustar/team.php'));
