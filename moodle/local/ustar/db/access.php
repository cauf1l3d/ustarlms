<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/ustar:gradeassessments' => ['captype'=>'write', 'riskbitmask'=>RISK_PERSONAL, 'contextlevel'=>CONTEXT_SYSTEM, 'archetypes'=>[]],
    'local/ustar:requeststaff' => ['captype'=>'write', 'riskbitmask'=>RISK_PERSONAL, 'contextlevel'=>CONTEXT_SYSTEM, 'archetypes'=>[]],

    'local/ustar:admin' => [
        'riskbitmask'  => RISK_CONFIG | RISK_DATALOSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    'local/ustar:viewteam' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    'local/ustar:hr' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    'local/ustar:hrmanage' => [
        'riskbitmask'  => RISK_PERSONAL | RISK_DATALOSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    'local/ustar:approveregistration' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    'local/ustar:manageadaptation' => [
        'riskbitmask'  => RISK_PERSONAL | RISK_DATALOSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    'local/ustar:executive' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    'local/ustar:viewas' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    'local/ustar:legacyui' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    'local/ustar:managecatalog' => [
        'riskbitmask'  => RISK_DATALOSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    'local/ustar:adjustcoin' => [
        'riskbitmask'  => RISK_PERSONAL | RISK_DATALOSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    'local/ustar:managecompetition' => [
        'riskbitmask'  => RISK_PERSONAL | RISK_DATALOSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    // Private development-profile results never become ordinary HR data.
    // This capability is deliberately separate from local/ustar:hr and
    // local/ustar:hrmanage so the HRD boundary can be assigned explicitly.
    'local/ustar:developmentanalytics' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
    'local/ustar:use' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'user'    => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/ustar:feedcreate' => [
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['user' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
    'local/ustar:feededit' => [
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
    'local/ustar:feedmanage' => [
        'riskbitmask' => RISK_CONFIG | RISK_DATALOSS | RISK_PERSONAL,
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
    'local/ustar:feedpublish' => [
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
    'local/ustar:feedpublishdepartment' => [
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM, 'archetypes' => [],
    ],
    'local/ustar:feedpublishacademy' => [
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM, 'archetypes' => [],
    ],
    'local/ustar:feedsetaudience' => [
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM, 'archetypes' => [],
    ],
    'local/ustar:feedmoderate' => [
        'riskbitmask' => RISK_DATALOSS | RISK_PERSONAL,
        'captype' => 'write', 'contextlevel' => CONTEXT_SYSTEM, 'archetypes' => [],
    ],
    'local/ustar:taskescalation' => [
        'riskbitmask' => RISK_PERSONAL, 'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM, 'archetypes' => [],
    ],
];
