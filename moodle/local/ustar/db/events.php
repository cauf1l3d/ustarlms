<?php
defined('MOODLE_INTERNAL') || die();
$observers = [
    ['eventname'=>'\\core\\event\\course_module_completion_updated','callback'=>'\\local_ustar\\observer::activity_completed','includefile'=>null,'internal'=>false,'priority'=>1000],
    [
        'eventname' => '\\core\\event\\user_created',
        'callback' => '\\local_ustar\\observer::user_created',
        'includefile' => null,
        'internal' => true,
        'priority' => 1000,
    ],
    [
        'eventname' => '\\core\\event\\course_completed',
        'callback' => '\\local_ustar\\observer::course_completed',
        'includefile' => null,
        'internal' => false,
        'priority' => 1000,
    ],
];
