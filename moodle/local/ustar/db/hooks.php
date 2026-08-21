<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core_user\hook\after_login_completed::class,
        'callback' => [\local_ustar\hook_callbacks::class, 'after_login_completed'],
        'priority' => 100,
    ],
];
