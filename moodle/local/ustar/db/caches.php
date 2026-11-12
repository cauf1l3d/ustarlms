<?php
defined('MOODLE_INTERNAL') || die();

$definitions = [
    'departmentxp' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 180,
    ],
    'registration_throttle' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 900,
    ],
];
