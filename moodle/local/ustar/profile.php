<?php
require_once(__DIR__ . '/../../config.php');

require_login();

global $USER, $DB;

$context = context_system::instance();
require_capability('local/ustar:use', $context);

$profile = \local_ustar\employee_profile::build((int)$USER->id);
$dashboard = \local_ustar\native_data::dashboard();
$identity = $profile['identity'];
$learning = $profile['learning'];
$knowledge = $profile['knowledge'];
$skills = $profile['skills'];
$readiness = $profile['readiness'];

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
    'hasposition' => $identity['positionid'] !== '',
    'position' => $identity['position'] ?: 'Должность пока не назначена',
    'department' => $identity['department'] ?: 'Без подразделения',
    'lastaccess' => !empty($identity['lastaccess']) ? userdate((int)$identity['lastaccess'], '%d.%m.%Y %H:%M') : '—',
    'accounttypelabel' => $identity['accounttypelabel'],

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
    'preferencesurl' => (new moodle_url('/user/preferences.php', ['userid' => $USER->id]))->out(false),

    'profileicon' => \local_ustar\ui::icon('profile', 'u-feature-icon'),
    'bookicon' => \local_ustar\ui::icon('book', 'u-feature-icon'),
    'knowledgeicon' => \local_ustar\ui::icon('knowledge', 'u-feature-icon'),
    'trophyicon' => \local_ustar\ui::icon('trophy', 'u-feature-icon'),
    'messageicon' => \local_ustar\ui::icon('message', 'u-feature-icon'),
    'settingsicon' => \local_ustar\ui::icon('settings', 'u-feature-icon'),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/profile.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Личный кабинет | USTAR Academy');
$PAGE->set_heading('USTAR Academy');

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/profile', $data);
echo $output->footer();
