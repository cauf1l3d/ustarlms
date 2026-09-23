<?php
require_once(__DIR__ . '/../../config.php');

require_login();

global $USER, $DB;

$context = context_system::instance();
require_capability('local/ustar:use', $context);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_ALPHANUMEXT) === 'registrationrequest') {
    require_sesskey();
    try {
        \local_ustar\registration_service::request_department(
            (int)$USER->id,
            required_param('departmentid', PARAM_ALPHANUMEXT)
        );
        redirect(new moodle_url('/local/ustar/profile.php'),
            'Заявка отправлена руководителю подразделения.', null,
            \core\output\notification::NOTIFY_SUCCESS);
    } catch (Throwable $e) {
        \core\notification::error($e->getMessage());
    }
}

$PAGE->set_context($context);

$registration = \local_ustar\registration_service::state((int)$USER->id);
$registrationrequest = $registration['request'];
$employmentstatus = (string)$registration['employment']['status'];
$pending = $employmentstatus === \local_ustar\employment::PENDING;
if ($pending) {
    // Pending accounts have no approved job and must not invoke learner or
    // reward providers while merely viewing their profile.
    $user = $DB->get_record('user', ['id' => $USER->id], '*', MUST_EXIST);
    $profile = [
        'identity' => [
            'fullname' => fullname($user), 'firstname' => $user->firstname,
            'lastname' => $user->lastname, 'email' => $user->email,
            'positionid' => '', 'position' => '', 'department' => '',
            'lastaccess' => (int)$user->lastaccess, 'accounttypelabel' => 'Сотрудник',
        ],
        'learning' => ['assigned' => 0, 'completed' => 0, 'inprogress' => 0, 'items' => []],
        'knowledge' => ['assigned' => 0, 'pending' => 0, 'percent' => 0],
        'skills' => ['required' => 0, 'confirmed' => 0, 'gaps' => 0, 'items' => []],
        'readiness' => ['percent' => 0],
    ];
    $dashboard = [];
} else {
    $profile = \local_ustar\employee_profile::build((int)$USER->id);
    $dashboard = \local_ustar\native_data::dashboard();
}
$identity = $profile['identity'];
$learning = $profile['learning'];
$knowledge = $profile['knowledge'];
$skills = $profile['skills'];
$readiness = $profile['readiness'];
$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$registrationdepartments = \local_ustar\registration_service::departments();

$badges = [];
foreach (($dashboard['badges'] ?? []) as $badge) {
    $badges[] = [
        'name' => (string)($badge['name'] ?? 'Награда'),
        'date' => !empty($badge['dateissued']) ? userdate((int)$badge['dateissued'], '%d.%m.%Y') : '',
        'icon' => \local_ustar\ui::icon('trophy', 'u-feature-icon'),
    ];
}

$data = [
    'fullname' => $identity['fullname'],
    'email' => $identity['email'],
    'initials' => \local_ustar\ui::initials($identity['firstname'], $identity['lastname']),
    'avatarurl' => \local_ustar\team_presenter::avatar_url((int)$USER->id, 160),
    'photoediturl' => (new moodle_url('/local/ustar/profile_settings.php'))->out(false),
    'hasposition' => $identity['positionid'] !== '',
    'position' => $identity['position'] ?: 'Должность пока не назначена',
    'department' => $identity['department'] ?: 'Без подразделения',
    'lastaccess' => !empty($identity['lastaccess']) ? userdate((int)$identity['lastaccess'], '%d.%m.%Y %H:%M') : '—',
    'accounttypelabel' => $identity['accounttypelabel'],
    'employmentpending' => $pending,
    'registrationrequested' => $registrationrequest
        && (string)$registrationrequest->status === \local_ustar\staffing_requests::STATUS_PENDING,
    'registrationrejected' => $registrationrequest
        && (string)$registrationrequest->status === \local_ustar\staffing_requests::STATUS_REJECTED,
    'registrationreviewcomment' => $registrationrequest
        ? (string)($registrationrequest->reviewcomment ?? '') : '',
    'registrationdepartments' => $registrationdepartments,
    'hasregistrationdepartments' => !empty($registrationdepartments),
    'sesskey' => sesskey(),

    'assigned' => (int)$learning['assigned'],
    'completed' => (int)$learning['completed'],
    'inprogress' => (int)$learning['inprogress'],
    'learningitems' => $learning['items'],
    'haslearning' => !empty($learning['items']),

    'knowledgeassigned' => (int)$knowledge['assigned'],
    'knowledgepending' => (int)$knowledge['pending'],
    'knowledgepercent' => (int)$knowledge['percent'],

    'skillrequired' => (int)$skills['required'],
    'skillconfirmed' => (int)$skills['confirmed'],
    'skillgaps' => (int)$skills['gaps'],
    'skillitems' => $skills['items'],
    'hasskills' => !empty($skills['items']),

    'readiness' => (int)$readiness['percent'],
    'xp' => (int)($dashboard['xp'] ?? 0),
    'level' => (int)($dashboard['level'] ?? 1),
    'activeDays30' => (int)($dashboard['activeDays30'] ?? 0),

    'badges' => $badges,
    'hasbadges' => !empty($badges),

    'learningurl' => (new moodle_url('/local/ustar/home.php', ['view' => 'learning']))->out(false),
    'knowledgeurl' => (new moodle_url('/local/ustar/knowledge.php', ['view' => 'knowledge']))->out(false),
    'achievementsurl' => (new moodle_url('/local/ustar/achievements.php'))->out(false),
    'messagesurl' => (new moodle_url('/local/ustar/messages.php'))->out(false),
    'preferencesurl' => (new moodle_url('/local/ustar/profile_settings.php'))->out(false),

    'profileicon' => \local_ustar\ui::icon('profile', 'u-feature-icon'),
    'bookicon' => \local_ustar\ui::icon('book', 'u-feature-icon'),
    'knowledgeicon' => \local_ustar\ui::icon('knowledge', 'u-feature-icon'),
    'trophyicon' => \local_ustar\ui::icon('trophy', 'u-feature-icon'),
    'messageicon' => \local_ustar\ui::icon('message', 'u-feature-icon'),
    'settingsicon' => \local_ustar\ui::icon('settings', 'u-feature-icon'),
];

$PAGE->set_url(new moodle_url('/local/ustar/profile.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Личный кабинет | USTAR Academy');
$PAGE->set_heading('USTAR Academy');

$PAGE->requires->css(
    new moodle_url('/local/ustar/styles/team_hierarchy.css')
);
$PAGE->requires->css(new moodle_url('/local/ustar/styles/staffing.css'));

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/profile', $data);
echo $output->footer();
