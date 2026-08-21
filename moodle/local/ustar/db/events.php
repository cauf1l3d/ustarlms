<?php
defined('MOODLE_INTERNAL') || die();
$observers = [
    [
        'eventname' => '\\core\\event\\course_completed',
        'callback' => '\\local_ustar\\observer::course_completed',
        'includefile' => null,
        'internal' => false,
        'priority' => 1000,
    ],
];
