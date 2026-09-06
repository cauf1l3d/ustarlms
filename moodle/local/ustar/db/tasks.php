<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_ustar\task\sync_enrolments',
        'blocking'  => 0,
        'minute'    => '*/30',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_ustar\\task\\renew_acting_assignments',
        'blocking'  => 0,
        'minute'    => '20',
        'hour'      => '3',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
];
