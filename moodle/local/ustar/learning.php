<?php

require_once(__DIR__ . '/../../config.php');

use local_ustar\learning_path;

require_login();

$courseid =
    required_param(
        'courseid',
        PARAM_INT
    );

$systemcontext =
    context_system::instance();

require_capability(
    'local/ustar:use',
    $systemcontext
);


$course =
    $DB->get_record(
        'course',
        [
            'id' => $courseid,
        ],
        '*',
        MUST_EXIST
    );

$coursecontext =
    context_course::instance(
        $courseid
    );


$iselevated =
    is_siteadmin()
    ||
    has_capability(
        'local/ustar:admin',
        $systemcontext
    )
    ||
    has_capability(
        'local/ustar:hr',
        $systemcontext
    );


$isenrolled =
    is_enrolled(
        $coursecontext,
        $USER->id,
        '',
        true
    );


/*
 * Employees can only inspect courses to which they actually have
 * current Moodle access.
 *
 * HR / USTAR admin may inspect the path as a management preview.
 */
if (!$isenrolled && !$iselevated) {
    throw new required_capability_exception(
        $coursecontext,
        'moodle/course:view',
        'nopermissions',
        ''
    );
}


$path =
    learning_path::for_user(
        $courseid,
        (int)$USER->id
    );


/*
 * Hidden course:
 * - employee sees that the assigned route is not published yet;
 * - elevated user gets a management preview;
 * - we do not silently publish anything.
 */
$canlaunch =
    (
        !empty($course->visible)
        &&
        $isenrolled
    )
    ||
    is_siteadmin();


$path['canlaunch'] =
    $canlaunch;

$path['cannotlaunch'] =
    !$canlaunch;


if (
    !empty($path['next'])
) {
    $path['next']['canlaunch'] =
        $canlaunch;
}


$backurl =
    new moodle_url(
        '/local/ustar/home.php',
        [
            'view' => 'learning',
            'theme' => 'ustar',
        ]
    );


$PAGE->set_url(
    new moodle_url(
        '/local/ustar/learning.php',
        [
            'courseid' =>
                $courseid,

            'theme' =>
                'ustar',
        ]
    )
);

$PAGE->set_context(
    $coursecontext
);

$PAGE->set_pagelayout(
    'standard'
);

$PAGE->set_title(
    $course->fullname
);

$PAGE->set_heading(
    $course->fullname
);


$context = [
    'path' =>
        $path,

    'backurl' =>
        $backurl->out(false),

    'iselevated' =>
        $iselevated,

    'isenrolled' =>
        $isenrolled,
];


echo $OUTPUT->header();

echo $OUTPUT->render_from_template(
    'local_ustar/learning_path',
    $context
);

echo $OUTPUT->footer();
