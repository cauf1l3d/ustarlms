<?php
require_once(__DIR__ . '/../../config.php');

require_login();

global $USER;

$context = context_system::instance();
require_capability('local/ustar:use', $context);

$PAGE->set_context($context);
$PAGE->set_url(
    new moodle_url('/local/ustar/route_team.php')
);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title(
    'Моё место в команде | USTAR'
);
$PAGE->set_heading('USTAR Academy');

$data =
    \local_ustar\team_presenter::build(
        (int)$USER->id
    );

$data['quizurl'] =
    (
        new moodle_url(
            '/local/ustar/route_team_quiz.php'
        )
    )->out(false);

$data['routeurl'] =
    (
        new moodle_url(
            '/local/ustar/route.php'
        )
    )->out(false);

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/styles/team_hierarchy.css'
    )
);

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/styles/route_native.css'
    )
);

$output =
    $PAGE->get_renderer('local_ustar');

echo $output->header();

echo $output->render_from_template(
    'local_ustar/team_route',
    $data
);

echo $output->footer();
