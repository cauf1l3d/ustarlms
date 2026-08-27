<?php

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/ustar:hr', $context);

$dashboard = \local_ustar\hr_operations::dashboard();

$data = [
    'generated' => userdate((int)$dashboard['generatedat'], '%d.%m.%Y %H:%M'),
    'employees' => (int)$dashboard['employees'],

    'compliancepending' => (int)$dashboard['compliance']['pendingassignments'],
    'compliancepeoplecount' => (int)$dashboard['compliance']['pendingpeople'],
    'compliancepeople' => $dashboard['compliance']['people'],
    'hascompliancepeople' => !empty($dashboard['compliance']['people']),

    'learningnotstarted' => (int)$dashboard['learning']['notstarted'],
    'learninginprogress' => (int)$dashboard['learning']['inprogress'],
    'learningcompleted' => (int)$dashboard['learning']['completed'],
    'learningpeople' => $dashboard['learning']['people'],
    'haslearningpeople' => !empty($dashboard['learning']['people']),

    'skillgaps' => (int)$dashboard['skills']['gaps'],
    'skillpeoplecount' => (int)$dashboard['skills']['peoplewithgaps'],
    'skillpeople' => $dashboard['skills']['people'],
    'hasskillpeople' => !empty($dashboard['skills']['people']),

    'workspaceurl' => (new moodle_url('/local/ustar/workspace.php'))->out(false),
    'positionsurl' => (new moodle_url('/local/ustar/positions.php'))->out(false),
    'materialsurl' => (new moodle_url('/local/ustar/materials.php'))->out(false),
    'routesurl' => has_capability('local/ustar:hrmanage', $context)
        ? (new moodle_url('/local/ustar/route_studio.php'))->out(false)
        : '',
    'hasroutes' => has_capability('local/ustar:hrmanage', $context),
    'brandurl' => has_capability('local/ustar:admin', $context)
        ? (new moodle_url('/local/ustar/brand.php'))->out(false)
        : '',
    'hasbrand' => has_capability('local/ustar:admin', $context),
    'gamestudiourl' => has_capability('local/ustar:admin', $context)
        ? (new moodle_url('/local/ustar/game_studio.php'))->out(false)
        : '',
    'hasgamestudio' => has_capability('local/ustar:admin', $context),
    'competitionurl' => has_capability('local/ustar:managecompetition', $context)
        ? (new moodle_url('/local/ustar/competition_studio.php'))->out(false)
        : '',
    'hascompetitionstudio' => has_capability('local/ustar:managecompetition', $context),
    'checkliststudiourl' => has_capability('local/ustar:hrmanage', $context) || has_capability('local/ustar:admin', $context)
        ? (new moodle_url('/local/ustar/checklist_studio.php'))->out(false)
        : '',
    'hascheckliststudio' => has_capability('local/ustar:hrmanage', $context) || has_capability('local/ustar:admin', $context),
    'workspaceicon' => \local_ustar\ui::icon('workspace', 'u-feature-icon'),
    'routeicon' => \local_ustar\ui::icon('route', 'u-feature-icon'),
    'knowledgeicon' => \local_ustar\ui::icon('knowledge', 'u-feature-icon'),
    'paletteicon' => \local_ustar\ui::icon('palette', 'u-feature-icon'),
    'gameicon' => \local_ustar\ui::icon('game', 'u-feature-icon'),
    'competitionicon' => \local_ustar\ui::icon('trophy', 'u-feature-icon'),
    'checkicon' => \local_ustar\ui::icon('check', 'u-feature-icon'),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/operations.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Контроль | Центр управления USTAR');
$PAGE->set_heading('Центр управления USTAR');

$output = $PAGE->get_renderer('local_ustar');

echo $output->header();
echo $output->render_from_template('local_ustar/operations', $data);
echo $output->footer();
