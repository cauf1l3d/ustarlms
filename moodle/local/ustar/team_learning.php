<?php
require_once(__DIR__ . '/../../config.php');

require_login();

global $USER;

$context = context_system::instance();

require_capability(
    'local/ustar:use',
    $context
);

$userid =
    required_param(
        'userid',
        PARAM_INT
    );

$data =
    \local_ustar\department_learning::detail(
        (int)$USER->id,
        $userid
    );

$data['teamurl'] =
    (
        new moodle_url(
            '/local/ustar/team.php'
        )
    )->out(false);

$PAGE->set_context($context);
$PAGE->set_url(
    new moodle_url(
        '/local/ustar/team_learning.php',
        ['userid' => $userid]
    )
);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title(
    'Обучение сотрудника | USTAR'
);
$PAGE->set_heading('USTAR Academy');

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/styles/team_hierarchy.css'
    )
);
$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/styles/department_learning.css'
    )
);

$output =
    $PAGE->get_renderer('local_ustar');

echo $output->header();

echo $output->render_from_template(
    'local_ustar/team_learning',
    $data
);

echo $output->footer();
