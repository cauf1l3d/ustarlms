<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_ustar_get_workspace' => [
        'classname' => 'local_ustar\\external\\get_workspace',
        'description' => 'Bootstrap: user, USTAR role, feature capabilities, branding and preferences',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],
    'local_ustar_get_dashboard' => [
        'classname' => 'local_ustar\\external\\get_dashboard',
        'description' => 'Personal learning dashboard, progress, XP, goals and badges',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],
    'local_ustar_get_skills' => [
        'classname' => 'local_ustar\\external\\get_skills',
        'description' => 'Personal skills with levels and linked courses',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],
    'local_ustar_get_matrix' => [
        'classname' => 'local_ustar\\external\\get_matrix',
        'description' => 'Skill matrix scoped by USTAR role',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],
    'local_ustar_get_ladder' => [
        'classname' => 'local_ustar\\external\\get_ladder',
        'description' => 'Career ladder with readiness and skill gaps',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],
    'local_ustar_get_team' => [
        'classname' => 'local_ustar\\external\\get_team',
        'description' => 'Department team overview for heads and USTAR superadmins',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],
    'local_ustar_get_games' => [
        'classname' => 'local_ustar\\external\\get_games',
        'description' => 'Available USTAR Game Hub games and personal stats',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],
    'local_ustar_get_game_question' => [
        'classname' => 'local_ustar\\external\\get_game_question',
        'description' => 'Return a game question without disclosing the correct answer',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],
    'local_ustar_submit_game_answer' => [
        'classname' => 'local_ustar\\external\\submit_game_answer',
        'description' => 'Validate a Game Hub answer server-side and award XP once',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],
    'local_ustar_save_prefs' => [
        'classname' => 'local_ustar\\external\\save_prefs',
        'description' => 'Save personal USTAR UI preferences',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],
    'local_ustar_save_goal' => [
        'classname' => 'local_ustar\\external\\save_goal',
        'description' => 'Create or complete a personal learning goal',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],

    'local_ustar_get_checklists' => [
        'classname' => 'local_ustar\external\get_checklists',
        'description' => 'Assigned operational checklists and today completion state',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],
    'local_ustar_submit_checklist' => [
        'classname' => 'local_ustar\external\submit_checklist',
        'description' => 'Persist checklist answers and audit completion',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:use',
    ],

    'local_ustar_hr_get_workspace' => [
        'classname' => 'local_ustar\\external\\hr_get_workspace',
        'description' => 'Live HR organizational workspace from Moodle users, USTAR structure, learning and Game Hub data',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:hr',
    ],
    'local_ustar_hr_bulk_assign_positions' => [
        'classname' => 'local_ustar\\external\\hr_bulk_assign_positions',
        'description' => 'Bulk assign locked USTAR positions to existing Moodle users',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:hrmanage',
    ],
    'local_ustar_hr_get_dashboard' => [
        'classname' => 'local_ustar\\external\\hr_get_dashboard',
        'description' => 'HR people and learning analytics summary',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:hr',
    ],
    'local_ustar_hr_get_people' => [
        'classname' => 'local_ustar\\external\\hr_get_people',
        'description' => 'Search employees with USTAR positions and access state',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:hr',
    ],
    'local_ustar_hr_get_person' => [
        'classname' => 'local_ustar\\external\\hr_get_person',
        'description' => 'Employee learning and career profile for HR',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:hr',
    ],
    'local_ustar_hr_save_person' => [
        'classname' => 'local_ustar\\external\\hr_save_person',
        'description' => 'Create/update/suspend employee and assign locked USTAR position',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:hrmanage',
    ],
    'local_ustar_hr_save_review' => [
        'classname' => 'local_ustar\\external\\hr_save_review',
        'description' => 'Record a structured HR review for an employee',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:hrmanage',
    ],
    'local_ustar_hr_import_people' => [
        'classname' => 'local_ustar\\external\\hr_import_people',
        'description' => 'Bulk create employees and assign USTAR positions from validated rows',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:hrmanage',
    ],

    'local_ustar_hr_get_checklists' => [
        'classname' => 'local_ustar\external\hr_get_checklists',
        'description' => 'Checklist definitions and operational completion results for HR',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:hrmanage',
    ],
    'local_ustar_hr_save_checklists' => [
        'classname' => 'local_ustar\external\hr_save_checklists',
        'description' => 'Publish checklist definitions and assignments',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:hrmanage',
    ],
    'local_ustar_hr_save_learning' => [
        'classname' => 'local_ustar\external\hr_save_learning',
        'description' => 'HR content editor for skills, Moodle course links and position matrix',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:hrmanage',
    ],

    'local_ustar_executive_get_dashboard' => [
        'classname' => 'local_ustar\\external\\executive_get_dashboard',
        'description' => 'Read-only company learning overview for executives',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:executive',
    ],

    'local_ustar_admin_get_structure' => [
        'classname' => 'local_ustar\\external\\admin_get_structure',
        'description' => 'Full structure JSON for USTAR superadmin',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:admin',
    ],
    'local_ustar_admin_save_structure' => [
        'classname' => 'local_ustar\\external\\admin_save_structure',
        'description' => 'Replace structure or branding for USTAR superadmin',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:admin',
    ],
    'local_ustar_admin_upload_brand_asset' => [
        'classname' => 'local_ustar\\external\\admin_upload_brand_asset',
        'description' => 'Upload a persistent public branding image for USTAR Brand Studio',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:admin',
    ],
    'local_ustar_admin_get_games' => [
        'classname' => 'local_ustar\\external\\admin_get_games',
        'description' => 'Game Hub content editor data',
        'type' => 'read', 'ajax' => true, 'capabilities' => 'local/ustar:admin',
    ],
    'local_ustar_admin_save_game' => [
        'classname' => 'local_ustar\\external\\admin_save_game',
        'description' => 'Create or update Game Hub game and questions',
        'type' => 'write', 'ajax' => true, 'capabilities' => 'local/ustar:admin',
    ],
];

$services = [
    'USTAR Workspace' => [
        'functions' => array_merge(array_keys($functions), [
            'core_webservice_get_site_info',
            'core_files_upload',
            'core_user_add_user_private_files',
            'core_user_get_private_files_info',
        ]),
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'ustar_workspace',
        'downloadfiles' => 1,
        'uploadfiles' => 1,
    ],
];
