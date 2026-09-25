<?php
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/ustar:use', context_system::instance());
require_sesskey();
\local_ustar\view_as::assert_writable();

redirect(\local_ustar\route_continue::next_url((int)$USER->id));
