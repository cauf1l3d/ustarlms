<?php
require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();

require_capability(
    'local/ustar:executive',
    $context
);

$d =
    \local_ustar\native_data::executive();

$total =
    max(
        1,
        (int)($d['totalPeople'] ?? 0)
    );

$coverage =
    min(
        100,
        (int)round(
            (
                (int)($d['assignedPeople'] ?? 0)
                /
                $total
            )
            *
            100
        )
    );

$departments =
    $d['departments']
    ?? [];

$maxpeople = 1;

foreach ($departments as $department) {
    $maxpeople =
        max(
            $maxpeople,
            (int)$department['people']
        );
}

foreach ($departments as &$department) {
    $department['width'] =
        min(
            100,
            (int)round(
                (
                    (int)$department['people']
                    /
                    $maxpeople
                )
                *
                100
            )
        );
}
unset($department);

$qualification =
    \local_ustar\analytics::qualification_summary();

$company =
    \local_ustar\company_hierarchy::build();

$data = [
    'totalPeople' =>
        (int)($d['totalPeople'] ?? 0),

    'assignedPeople' =>
        (int)($d['assignedPeople'] ?? 0),

    'unassignedPeople' =>
        (int)($d['unassignedPeople'] ?? 0),

    'activeLearners30' =>
        (int)($d['activeLearners30'] ?? 0),

    'completedCourses30' =>
        (int)($d['completedCourses30'] ?? 0),

    'coverage' => $coverage,

    'departments' => $departments,
    'hasdepartments' => !empty($departments),

    'qualified' =>
        (int)$qualification['qualified'],

    'withgaps' =>
        (int)$qualification['withgaps'],

    'expired' =>
        (int)$qualification['expired'],

    'qualcoverage' =>
        (int)$qualification['coverage'],

    'topgaps' =>
        $qualification['topgaps'],

    'hastopgaps' =>
        !empty($qualification['topgaps']),

    'companydepartments' =>
        $company['departments'],

    'hascompanydepartments' =>
        !empty($company['departments']),

    'exactreporting' =>
        !empty($company['exactreporting']),

    'structuralmode' =>
        !empty($company['structuralmode']),

    'generated' =>
        userdate(
            time(),
            '%d.%m.%Y %H:%M'
        ),

    'execicon' =>
        \local_ustar\ui::icon(
            'executive',
            'u-feature-icon'
        ),
];

$PAGE->set_context($context);
$PAGE->set_url(
    new moodle_url('/local/ustar/executive.php')
);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title(
    'Руководство | USTAR Academy'
);
$PAGE->set_heading('USTAR Academy');

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/styles/executive_2713.css'
    )
);

$output =
    $PAGE->get_renderer('local_ustar');

echo $output->header();

echo $output->render_from_template(
    'local_ustar/executive',
    $data
);

echo $output->footer();
