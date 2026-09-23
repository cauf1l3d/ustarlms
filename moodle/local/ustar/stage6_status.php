<?php
// Preserve previously shared links while consolidating the HR workspace.
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/ustar:hr', context_system::instance());
redirect(new moodle_url('/local/ustar/operations.php'));
