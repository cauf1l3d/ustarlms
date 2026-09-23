<?php
defined('MOODLE_INTERNAL') || die();

$definitions = [
    'registration_throttle' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 900,
    ],
];
