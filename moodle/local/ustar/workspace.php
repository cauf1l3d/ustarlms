<?php
require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/ustar:hr', $context);

$d = \local_ustar\native_data::hr_workspace();
$qraw = trim(optional_param('q', '', PARAM_TEXT));
$q = \core_text::strtolower($qraw);

$people = array_values($d['people'] ?? []);
$positions = array_values($d['positions'] ?? []);
$sourceDepartments = array_values($d['departments'] ?? []);

$departmentids = [];
foreach ($sourceDepartments as $department) {
    $departmentids[(string)($department['id'] ?? '')] = true;
}

$preparePerson = static function(array $person) use ($q): ?array {
    $haystack = \core_text::strtolower(
        (string)($person['fullname'] ?? '') . ' ' .
        (string)($person['position'] ?? '') . ' ' .
        (string)($person['email'] ?? '')
    );
    if ($q !== '' && \core_text::strpos($haystack, $q) === false) {
        return null;
    }

    $person['initials'] = \local_ustar\ui::initials(
        (string)($person['firstname'] ?? ''),
        (string)($person['lastname'] ?? '')
    );
    $person['positionlabel'] = trim((string)($person['position'] ?? '')) ?: 'Без должности';
    $person['suspended'] = !empty($person['suspended']);
    $person['active'] = empty($person['suspended']);
    $person['statuslabel'] = $person['suspended'] ? 'Приостановлен' : 'Активен';
    $person['lastaccesslabel'] = !empty($person['lastaccess'])
        ? userdate((int)$person['lastaccess'], '%d.%m.%Y')
        : 'не входил';
    $person['personurl'] = (new moodle_url('/local/ustar/hr.php', [
        'userid' => (int)$person['id'],
    ]))->out(false);
    return $person;
};

$departments = [];
$matchedIds = [];
foreach ($sourceDepartments as $department) {
    $departmentid = (string)($department['id'] ?? '');
    $department['people'] = [];
    foreach ($people as $person) {
        $persondept = (string)($person['department'] ?? '');
        if ($persondept !== $departmentid) {
            continue;
        }
        $prepared = $preparePerson($person);
        if ($prepared === null) {
            continue;
        }
        $matchedIds[(int)$person['id']] = true;
        $department['people'][] = $prepared;
    }
    $department['peoplecount'] = count($department['people']);
    $department['haspeople'] = !empty($department['people']);
    $department['laneid'] = preg_replace('/[^a-zA-Z0-9_-]/', '-', $departmentid ?: 'department');
    $departments[] = $department;
}

// Users without a mapped USTAR position must remain visible in Структура компании.
$unassigned = [];
foreach ($people as $person) {
    if (isset($matchedIds[(int)$person['id']])) {
        continue;
    }
    $persondept = (string)($person['department'] ?? '');
    if ($persondept !== '' && isset($departmentids[$persondept])) {
        // Person matched a real department but may have been filtered by search.
        continue;
    }
    $prepared = $preparePerson($person);
    if ($prepared !== null) {
        $unassigned[] = $prepared;
    }
}
if ($unassigned) {
    $departments[] = [
        'id' => 'unassigned',
        'laneid' => 'unassigned',
        'name' => 'Без должности / подразделения',
        'people' => $unassigned,
        'peoplecount' => count($unassigned),
        'haspeople' => true,
        'isunassigned' => true,
    ];
}

$data = [
    'departments' => $departments,
    'q' => s($qraw),
    'peoplecount' => count($people),
    'positioncount' => count($positions),
    'departmentcount' => count($sourceDepartments),
    'workspaceicon' => \local_ustar\ui::icon('workspace', 'u-feature-icon'),
    'peopleurl' => (new moodle_url('/local/ustar/hr.php'))->out(false),
    'positionsurl' => (new moodle_url('/local/ustar/positions.php'))->out(false),
    'routesurl' => has_capability('local/ustar:hrmanage', $context)
        ? (new moodle_url('/local/ustar/route_studio.php'))->out(false)
        : '',
    'hasroutes' => has_capability('local/ustar:hrmanage', $context),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/workspace.php', $qraw !== '' ? ['q' => $qraw] : []));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Оргпространство | Центр управления USTAR');
$PAGE->set_heading('Центр управления USTAR');
$PAGE->requires->js_call_amd('local_ustar/workspace', 'init');

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/workspace', $data);
echo $output->footer();
