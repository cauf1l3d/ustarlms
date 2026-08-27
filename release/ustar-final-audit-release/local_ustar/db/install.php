<?php
defined('MOODLE_INTERNAL') || die();

/** Post-install defaults for a fresh USTAR Academy installation. */
function xmldb_local_ustar_install(): void {
    global $DB;

    $syscontext = context_system::instance();
    $roles = [
        'ustar_superadmin' => ['USTAR Superadmin', ['local/ustar:use', 'local/ustar:admin', 'local/ustar:viewteam']],
        'ustar_hr' => ['USTAR HR', ['local/ustar:use', 'local/ustar:hr', 'local/ustar:hrmanage']],
        'ustar_hrd' => ['USTAR HRD', ['local/ustar:use', 'local/ustar:hr', 'local/ustar:hrmanage', 'local/ustar:developmentanalytics']],
        'ustar_executive' => ['USTAR Executive', ['local/ustar:use', 'local/ustar:executive']],
    ];
    foreach ($roles as $shortname => [$name, $caps]) {
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => $shortname]);
        if (!$roleid) {
            $roleid = create_role($name, $shortname, 'USTAR Academy system role');
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }
        foreach ($caps as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $syscontext->id, true);
        }
    }

    if (!$DB->record_exists('local_ustar_games', ['code' => 'guess_tool'])) {
        $now = time();
        $DB->insert_record('local_ustar_games', (object)[
            'code' => 'guess_tool', 'title' => 'Угадай инструмент',
            'description' => 'Фото инструмента, четыре варианта и короткое объяснение.',
            'type' => 'image_quiz', 'difficulty' => 1, 'active' => 1,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    if (!$DB->record_exists('local_ustar_structure', ['name' => 'checklists'])) {
        $DB->insert_record('local_ustar_structure', (object)[
            'name' => 'checklists',
            'jsondata' => json_encode(\local_ustar\checklists::defaults(), JSON_UNESCAPED_UNICODE),
            'version' => 1, 'usermodified' => 0, 'timemodified' => time(),
        ]);
    }
    // Explicit workforce/service/test account semantics used by USTAR metrics.
    \local_ustar\accounts::ensure_profile_field();
    \local_ustar\development_assessment::ensure_team_profile(0);

    accesslib_clear_all_caches(true);
}
