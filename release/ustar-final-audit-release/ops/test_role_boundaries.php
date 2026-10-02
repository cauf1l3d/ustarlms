<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

global $CFG, $DB;

if (!in_array($CFG->wwwroot, [
    'http://127.0.0.1:18080',
    'http://127.0.0.1:18081',
    'http://127.0.0.1:18082',
], true) || empty($CFG->noemailever)) {
    fwrite(STDERR, "Refusing non-isolated Moodle instance\n");
    exit(1);
}

$context = context_system::instance();
$capabilities = [
    'local/ustar:use',
    'local/ustar:viewteam',
    'local/ustar:hr',
    'local/ustar:hrmanage',
    'local/ustar:executive',
    'local/ustar:admin',
    'local/ustar:viewas',
    'local/ustar:legacyui',
    'local/ustar:managecatalog',
    'local/ustar:adjustcoin',
    'moodle/webservice:createtoken',
    'webservice/rest:use',
];

$personas = [
    'employee' => [
        'username' => 'audit_employee',
        'allowed' => ['local/ustar:use'],
    ],
    'manager' => [
        'username' => 'audit_retail_head',
        'allowed' => ['local/ustar:use', 'local/ustar:viewteam'],
    ],
    'hr' => [
        'username' => 'audit_hr',
        'allowed' => ['local/ustar:use', 'local/ustar:hr', 'local/ustar:hrmanage'],
    ],
    'executive' => [
        'username' => 'audit_ceo',
        // CEO is also a declared position head, so position_access projects
        // ustar_manager for the full-company team tree required by this role.
        'allowed' => [
            'local/ustar:use',
            'local/ustar:viewteam',
            'local/ustar:executive',
        ],
    ],
    'superadmin' => [
        'username' => 'audit_superadmin',
        'allowed' => [
            'local/ustar:use',
            'local/ustar:viewteam',
            'local/ustar:admin',
            'local/ustar:viewas',
            'local/ustar:legacyui',
            'local/ustar:managecatalog',
            'local/ustar:adjustcoin',
        ],
    ],
];

$pagegates = [
    '/local/ustar/team.php' => ['all' => ['local/ustar:use']],
    '/local/ustar/hr.php' => ['all' => ['local/ustar:hr']],
    '/local/ustar/operations.php' => ['all' => ['local/ustar:hr']],
    '/local/ustar/positions.php' => ['all' => ['local/ustar:hr']],
    '/local/ustar/materials.php' => ['all' => ['local/ustar:hr']],
    '/local/ustar/material_ack_export.php' => ['all' => ['local/ustar:hr']],
    '/local/ustar/route_studio.php' => ['all' => ['local/ustar:hrmanage']],
    '/local/ustar/executive.php' => ['all' => ['local/ustar:executive']],
    '/local/ustar/brand.php' => ['all' => ['local/ustar:admin']],
    '/local/ustar/game_studio.php' => ['all' => ['local/ustar:admin']],
    '/local/ustar/checklist_studio.php' => [
        'any' => ['local/ustar:hrmanage', 'local/ustar:admin'],
    ],
    '/local/ustar/material_bulk.php' => [
        'any' => ['local/ustar:hrmanage', 'local/ustar:admin'],
    ],
];

$result = [
    'environment' => [
        'wwwroot' => $CFG->wwwroot,
        'no_email' => (bool)$CFG->noemailever,
    ],
    'personas' => [],
    'page_gate_projection' => [],
    'revocation_rehearsal' => [],
    'conflicts' => [
        'hrd_role_missing' => !$DB->record_exists('role', ['shortname' => 'ustar_hrd']),
        'hr_role_combines_read_and_manage' => true,
    ],
    'failures' => [],
];

foreach ($personas as $name => $definition) {
    $user = $DB->get_record('user', [
        'username' => $definition['username'],
        'mnethostid' => $CFG->mnet_localhost_id,
        'deleted' => 0,
    ], '*', MUST_EXIST);

    $actual = [];
    foreach ($capabilities as $capability) {
        $actual[$capability] = has_capability($capability, $context, (int)$user->id);
        $expected = in_array($capability, $definition['allowed'], true);
        if ($actual[$capability] !== $expected) {
            $result['failures'][] = [
                'persona' => $name,
                'capability' => $capability,
                'expected' => $expected,
                'actual' => $actual[$capability],
            ];
        }
    }

    $assignedroles = [];
    foreach (get_user_roles($context, (int)$user->id, false) as $role) {
        $assignedroles[] = $role->shortname;
    }
    sort($assignedroles);

    $result['personas'][$name] = [
        'username' => $definition['username'],
        'roles' => $assignedroles,
        'capabilities' => $actual,
    ];

    foreach ($pagegates as $path => $gate) {
        $allowed = true;
        if (!empty($gate['all'])) {
            foreach ($gate['all'] as $capability) {
                $allowed = $allowed && !empty($actual[$capability]);
            }
        }
        if (!empty($gate['any'])) {
            $allowed = false;
            foreach ($gate['any'] as $capability) {
                $allowed = $allowed || !empty($actual[$capability]);
            }
        }
        $result['page_gate_projection'][$path][$name] = $allowed;
    }
}

$employee = $DB->get_record('user', [
    'username' => 'audit_employee',
    'mnethostid' => $CFG->mnet_localhost_id,
    'deleted' => 0,
], '*', MUST_EXIST);
$managerroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'ustar_manager'], MUST_EXIST);
$existingassignment = $DB->record_exists('role_assignments', [
    'roleid' => $managerroleid,
    'userid' => (int)$employee->id,
    'contextid' => $context->id,
]);

if ($existingassignment || has_capability('local/ustar:viewteam', $context, (int)$employee->id)) {
    $result['failures'][] = [
        'test' => 'revocation_rehearsal',
        'reason' => 'audit_employee already has manager access',
    ];
} else {
    role_assign($managerroleid, (int)$employee->id, $context->id, '', 0);
    accesslib_clear_all_caches(true);
    $afterassign = has_capability('local/ustar:viewteam', $context, (int)$employee->id);

    role_unassign($managerroleid, (int)$employee->id, $context->id, '', 0);
    accesslib_clear_all_caches(true);
    $afterrevoke = has_capability('local/ustar:viewteam', $context, (int)$employee->id);

    $result['revocation_rehearsal'] = [
        'baseline' => false,
        'after_assign' => $afterassign,
        'after_revoke' => $afterrevoke,
        'residual_assignment' => $DB->record_exists('role_assignments', [
            'roleid' => $managerroleid,
            'userid' => (int)$employee->id,
            'contextid' => $context->id,
        ]),
    ];

    if (!$afterassign || $afterrevoke || $result['revocation_rehearsal']['residual_assignment']) {
        $result['failures'][] = [
            'test' => 'revocation_rehearsal',
            'reason' => 'manager capability did not follow assign/revoke lifecycle',
        ];
    }
}

$result['status'] = empty($result['failures']) ? 'PASS' : 'FAIL';
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(empty($result['failures']) ? 0 : 1);
