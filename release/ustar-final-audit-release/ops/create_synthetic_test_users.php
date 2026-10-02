<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

global $CFG, $DB;

if (!in_array($CFG->wwwroot, [
    'http://127.0.0.1:18080',
    'http://127.0.0.1:18081',
    'http://127.0.0.1:18082',
], true) || empty($CFG->noemailever)) {
    fwrite(STDERR, "Refusing non-isolated Moodle instance\n");
    exit(1);
}

$password = getenv('USTAR_AUDIT_PASSWORD');
if ($password === false || strlen($password) < 15) {
    fwrite(STDERR, "Set a test-only USTAR_AUDIT_PASSWORD of at least 15 characters\n");
    exit(1);
}
$systemcontext = context_system::instance();
$scenarios = [
    [
        'username' => 'audit_employee',
        'firstname' => 'Audit',
        'lastname' => 'Employee',
        'position' => 'retail_seller',
        'role' => null,
    ],
    [
        'username' => 'audit_retail_head',
        'firstname' => 'Audit',
        'lastname' => 'Retail Head',
        'position' => 'retail_head',
        'role' => 'ustar_manager',
    ],
    [
        'username' => 'audit_hr',
        'firstname' => 'Audit',
        'lastname' => 'HR',
        'position' => 'hr_head',
        'role' => 'ustar_hr',
    ],
    [
        'username' => 'audit_ceo',
        'firstname' => 'Audit',
        'lastname' => 'Executive',
        'position' => 'ceo',
        'role' => 'ustar_executive',
    ],
    [
        'username' => 'audit_superadmin',
        'firstname' => 'Audit',
        'lastname' => 'Superadmin',
        'position' => 'it_specialist',
        'role' => 'ustar_superadmin',
    ],
];

foreach ($scenarios as $scenario) {
    $user = $DB->get_record('user', ['username' => $scenario['username'], 'mnethostid' => $CFG->mnet_localhost_id]);
    if (!$user) {
        $user = (object)[
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'username' => $scenario['username'],
            'password' => $password,
            'firstname' => $scenario['firstname'],
            'lastname' => $scenario['lastname'],
            'email' => $scenario['username'] . '@invalid.example',
            'city' => '',
            'country' => 'RU',
            'lang' => 'ru',
        ];
        $user->id = user_create_user($user, true, false);
    } else {
        update_internal_user_password($user, $password, false);
    }

    // These fixtures are used after the mandatory-policy journey has been
    // tested separately. Keep subsequent capability/entry-point tests from
    // being intercepted by the policy redirect.
    $DB->set_field('user', 'policyagreed', 1, ['id' => $user->id]);

    $profile = (object)[
        'id' => $user->id,
        'profile_field_ustar_account_type' => 'employee',
        'profile_field_ustar_position' => $scenario['position'],
    ];
    profile_save_data($profile);

    if ($scenario['role']) {
        $roleid = $DB->get_field('role', 'id', ['shortname' => $scenario['role']], MUST_EXIST);
        role_assign($roleid, $user->id, $systemcontext->id);
    }

    echo $scenario['username'] . '|position=' . $scenario['position'] . '|role=' . ($scenario['role'] ?? 'default-user-only') . PHP_EOL;
}
