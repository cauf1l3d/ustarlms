<?php
require_once(__DIR__ . '/../../config.php');

require_login();

global $USER;

$context = context_system::instance();

require_capability(
    'local/ustar:use',
    $context
);

$PAGE->set_context($context);
$PAGE->set_url(
    new moodle_url('/local/ustar/team.php')
);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Моя команда | USTAR Academy');
$PAGE->set_heading('USTAR Academy');

$hierarchy =
    \local_ustar\team_presenter::build(
        (int)$USER->id
    );

$learning = [
    'allowed' => false,
    'people' => [],
    'summary' => [],
];

$assessmentalerts = [
    'alerts' => [],
    'count' => 0,
];

if (!empty($hierarchy['ishead'])) {
    $learning =
        \local_ustar\department_learning::for_manager(
            (int)$USER->id
        );

    if (\local_ustar\assessment_lifecycle::available()) {
        $assessmentalerts =
            \local_ustar\assessment_lifecycle::manager_alerts(
                (int)$USER->id
            );
    }
}

$departmentview = \local_ustar\team_presenter::department_view(
    (int)$USER->id,
    $hierarchy,
    $learning,
    optional_param('department', '', PARAM_TEXT)
);
$learning = $departmentview['learning'];
unset($departmentview['learning']);

$data = array_merge(
    $hierarchy,
    $departmentview,
    [
        'teamicon' =>
            \local_ustar\ui::icon(
                'team',
                'u-feature-icon'
            ),

        'hasexecutive' =>
            has_capability(
                'local/ustar:executive',
                $context
            ),

        'executiveurl' =>
            has_capability(
                'local/ustar:executive',
                $context
            )
            ? (
                new moodle_url(
                    '/local/ustar/executive.php'
                )
            )->out(false)
            : '',

          'staffingurl' =>
              !empty($hierarchy['ishead'])
              ? (new moodle_url('/local/ustar/staffing.php'))->out(false)
              : '',

        'hasassessmentalerts' =>
            !empty($assessmentalerts['alerts']),

        'assessmentalertcount' =>
            (int)($assessmentalerts['count'] ?? 0),

        'assessmentalerts' =>
            $assessmentalerts['alerts'] ?? [],

        'sesskey' => sesskey(),

        'canviewdepartmentlearning' =>
            !empty($learning['allowed']),

        'learningdepartment' =>
            (string)(
                $learning['department']
                ?? ''
            ),

        'learningpeople' =>
            $learning['people']
            ?? [],

        'haslearningpeople' =>
            !empty($learning['people']),

        'learningtotal' =>
            (int)(
                $learning['summary']['total']
                ?? 0
            ),

        'learningcomplete' =>
            (int)(
                $learning['summary']['complete']
                ?? 0
            ),

        'learninginprogress' =>
            (int)(
                $learning['summary']['inprogress']
                ?? 0
            ),

        'learningnotstarted' =>
            (int)(
                $learning['summary']['notstarted']
                ?? 0
            ),

        'learningnoroute' =>
            (int)(
                $learning['summary']['noroute']
                ?? 0
            ),
    ]
);

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/styles/team_hierarchy.css',
        ['v' => hash_file('sha256', __DIR__ . '/styles/team_hierarchy.css')]
    )
);

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/styles/department_learning.css',
        ['v' => hash_file('sha256', __DIR__ . '/styles/department_learning.css')]
    )
);

$PAGE->requires->css(
    new moodle_url('/local/ustar/styles/business_orgchart.css',
        ['v' => hash_file('sha256', __DIR__ . '/styles/business_orgchart.css')])
);

$output =
    $PAGE->get_renderer('local_ustar');

echo $output->header();

echo $output->render_from_template(
    'local_ustar/team',
    $data
);

echo $output->footer();
