<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for local_ustar.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_ustar_upgrade($oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026081300) {
        if ($DB->record_exists('user_info_field', ['shortname' => 'ustar_position'])) {
            $DB->set_field('user_info_field', 'locked', 1, ['shortname' => 'ustar_position']);
        }
        upgrade_plugin_savepoint(true, 2026081300, 'local', 'ustar');
    }

    if ($oldversion < 2026081301) {
        // Register new capabilities before assigning USTAR system roles.
        update_capabilities("local_ustar");
        // Micro-learning games.
        $table = new xmldb_table('local_ustar_games');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('code', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'quiz');
        $table->add_field('department', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('difficulty', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('code_uix', XMLDB_INDEX_UNIQUE, ['code']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_ustar_questions');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('gameid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('question', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('imageurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('optionsjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('correctoption', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('explanation', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('xpreward', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '25');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('gameid_fk', XMLDB_KEY_FOREIGN, ['gameid'], 'local_ustar_games', ['id']);
        $table->add_index('game_sort_idx', XMLDB_INDEX_NOTUNIQUE, ['gameid', 'sortorder']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_ustar_game_attempts');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('gameid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('selectedoption', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('iscorrect', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('xpearned', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('gameid_fk', XMLDB_KEY_FOREIGN, ['gameid'], 'local_ustar_games', ['id']);
        $table->add_key('questionid_fk', XMLDB_KEY_FOREIGN, ['questionid'], 'local_ustar_questions', ['id']);
        $table->add_index('user_game_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'gameid']);
        $table->add_index('user_time_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_ustar_hr_actions');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('targetuserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('action', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('detailsjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('actorid_fk', XMLDB_KEY_FOREIGN, ['actorid'], 'user', ['id']);
        $table->add_key('targetuserid_fk', XMLDB_KEY_FOREIGN, ['targetuserid'], 'user', ['id']);
        $table->add_index('target_time_idx', XMLDB_INDEX_NOTUNIQUE, ['targetuserid', 'timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // The position field is part of access control and must stay user-locked.
        if ($DB->record_exists('user_info_field', ['shortname' => 'ustar_position'])) {
            $DB->set_field('user_info_field', 'locked', 1, ['shortname' => 'ustar_position']);
        }

        // Reproducible USTAR system roles. No user is assigned automatically.
        $syscontext = context_system::instance();
        $roles = [
            'ustar_superadmin' => [
                'name' => 'USTAR Superadmin',
                'description' => 'Full USTAR Academy administration without granting Moodle site administrator.',
                'caps' => ['local/ustar:use', 'local/ustar:admin', 'local/ustar:viewteam'],
            ],
            'ustar_hr' => [
                'name' => 'USTAR HR',
                'description' => 'People management and learning analytics in USTAR Academy.',
                'caps' => ['local/ustar:use', 'local/ustar:hr', 'local/ustar:hrmanage'],
            ],
            'ustar_executive' => [
                'name' => 'USTAR Executive',
                'description' => 'Read-only executive analytics in USTAR Academy.',
                'caps' => ['local/ustar:use', 'local/ustar:executive'],
            ],
        ];
        foreach ($roles as $shortname => $definition) {
            $roleid = (int)$DB->get_field('role', 'id', ['shortname' => $shortname]);
            if (!$roleid) {
                $roleid = create_role($definition['name'], $shortname, $definition['description']);
                set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
            }
            foreach ($definition['caps'] as $capability) {
                assign_capability($capability, CAP_ALLOW, $roleid, $syscontext->id, true);
            }
        }
        accesslib_clear_all_caches(true);

        // Seed one empty game shell. Content can be edited safely in Superadmin.
        if (!$DB->record_exists('local_ustar_games', ['code' => 'guess_tool'])) {
            $now = time();
            $DB->insert_record('local_ustar_games', (object)[
                'code' => 'guess_tool',
                'title' => 'Угадай инструмент',
                'description' => 'Короткие раунды: фото инструмента, четыре варианта и объяснение после ответа.',
                'type' => 'image_quiz',
                'department' => null,
                'difficulty' => 1,
                'active' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        upgrade_plugin_savepoint(true, 2026081301, 'local', 'ustar');
    }


    if ($oldversion < 2026081302) {
        // Atomic Game Hub mastery prevents parallel requests from awarding XP twice.
        $table = new xmldb_table('local_ustar_game_mastery');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('gameid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('xpearned', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('gameid_fk', XMLDB_KEY_FOREIGN, ['gameid'], 'local_ustar_games', ['id']);
        $table->add_key('questionid_fk', XMLDB_KEY_FOREIGN, ['questionid'], 'local_ustar_questions', ['id']);
        $table->add_index('user_question_uix', XMLDB_INDEX_UNIQUE, ['userid', 'questionid']);
        $table->add_index('user_game_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'gameid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // HR reviews are separate from course completion: score + period + documented summary.
        $table = new xmldb_table('local_ustar_reviews');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('category', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, 'performance');
        $table->add_field('period', XMLDB_TYPE_CHAR, '128', null, null, null, null);
        $table->add_field('score', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '3');
        $table->add_field('summary', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('reviewerid_fk', XMLDB_KEY_FOREIGN, ['reviewerid'], 'user', ['id']);
        $table->add_index('user_time_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
        $table->add_index('reviewer_time_idx', XMLDB_INDEX_NOTUNIQUE, ['reviewerid', 'timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Keep product domains isolated: USTAR Superadmin configures the platform,
        // HR manages people, Executive receives read-only company analytics.
        $syscontext = context_system::instance();
        $rolecaps = [
            'ustar_superadmin' => ['local/ustar:use', 'local/ustar:admin', 'local/ustar:viewteam'],
            'ustar_hr' => ['local/ustar:use', 'local/ustar:hr', 'local/ustar:hrmanage'],
            'ustar_executive' => ['local/ustar:use', 'local/ustar:executive'],
        ];
        $allustarcaps = [
            'local/ustar:admin', 'local/ustar:viewteam', 'local/ustar:hr',
            'local/ustar:hrmanage', 'local/ustar:executive', 'local/ustar:use',
        ];
        foreach ($rolecaps as $shortname => $wanted) {
            $roleid = (int)$DB->get_field('role', 'id', ['shortname' => $shortname]);
            if (!$roleid) {
                continue;
            }
            foreach ($allustarcaps as $capability) {
                unassign_capability($capability, $roleid, $syscontext->id);
            }
            foreach ($wanted as $capability) {
                assign_capability($capability, CAP_ALLOW, $roleid, $syscontext->id, true);
            }
        }
        accesslib_clear_all_caches(true);
        upgrade_plugin_savepoint(true, 2026081302, 'local', 'ustar');
    }

    if ($oldversion < 2026081303) {
        // Brand Studio has no schema changes. The version bump refreshes external
        // service definitions, including the persistent branding asset uploader.
        upgrade_plugin_savepoint(true, 2026081303, 'local', 'ustar');
    }

    if ($oldversion < 2026081304) {
        // HR Workspace adds API surface only; no schema change is required.
        // The version bump refreshes db/services.php so the new read and bulk-assignment functions become available.
        upgrade_plugin_savepoint(true, 2026081304, 'local', 'ustar');
    }

    if ($oldversion < 2026081305) {
        // Production checklist executions. Definitions remain versioned JSON in local_ustar_structure.
        $table = new xmldb_table('local_ustar_check_runs');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('checklistkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('positionid', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('datekey', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('doneitems', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('totalitems', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('score', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('startedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('user_check_date_uix', XMLDB_INDEX_UNIQUE, ['userid', 'checklistkey', 'datekey']);
        $table->add_index('check_date_idx', XMLDB_INDEX_NOTUNIQUE, ['checklistkey', 'datekey']);
        if (!$dbman->table_exists($table)) { $dbman->create_table($table); }

        $table = new xmldb_table('local_ustar_check_answers');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('runid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('itemkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('checked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('runid_fk', XMLDB_KEY_FOREIGN, ['runid'], 'local_ustar_check_runs', ['id']);
        $table->add_index('run_item_idx', XMLDB_INDEX_NOTUNIQUE, ['runid', 'itemkey']);
        if (!$dbman->table_exists($table)) { $dbman->create_table($table); }

        if (!$DB->record_exists('local_ustar_structure', ['name' => 'checklists'])) {
            $DB->insert_record('local_ustar_structure', (object)[
                'name' => 'checklists',
                'jsondata' => json_encode(\local_ustar\checklists::defaults(), JSON_UNESCAPED_UNICODE),
                'version' => 1,
                'usermodified' => 0,
                'timemodified' => time(),
            ]);
        }
        upgrade_plugin_savepoint(true, 2026081305, 'local', 'ustar');
    }

    if ($oldversion < 2026081306) {
        // Skill evidence definitions.
        //
        // Org structure, positions, skills and required levels remain
        // versioned JSON in local_ustar_structure.
        //
        // This normalized table maps those skills to concrete Moodle
        // evidence sources without overloading course idnumbers.
        $table = new xmldb_table('local_ustar_skill_evidence');

        $table->add_field(
            'id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            XMLDB_SEQUENCE,
            null
        );

        $table->add_field(
            'skillid',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            XMLDB_NOTNULL,
            null,
            null
        );

        $table->add_field(
            'courseid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null
        );

        $table->add_field(
            'cmid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null
        );

        $table->add_field(
            'evidencetype',
            XMLDB_TYPE_CHAR,
            '32',
            null,
            XMLDB_NOTNULL,
            null,
            'learning'
        );

        $table->add_field(
            'weight',
            XMLDB_TYPE_INTEGER,
            '3',
            null,
            XMLDB_NOTNULL,
            null,
            '100'
        );

        $table->add_field(
            'required',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '1'
        );

        $table->add_field(
            'validdays',
            XMLDB_TYPE_INTEGER,
            '6',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'sortorder',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'active',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '1'
        );

        $table->add_field(
            'timecreated',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'timemodified',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'usermodified',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_key(
            'primary',
            XMLDB_KEY_PRIMARY,
            ['id']
        );

        $table->add_key(
            'courseid_fk',
            XMLDB_KEY_FOREIGN,
            ['courseid'],
            'course',
            ['id']
        );

        $table->add_key(
            'cmid_fk',
            XMLDB_KEY_FOREIGN,
            ['cmid'],
            'course_modules',
            ['id']
        );

        /*
         * usermodified intentionally has no DB-level FK.
         *
         * Existing USTAR tables use 0 for system-created records.
         * A strict user FK would make that convention invalid.
         */

        $table->add_index(
            'skill_active_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['skillid', 'active']
        );


        $table->add_index(
            'type_active_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['evidencetype', 'active']
        );

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(
            true,
            2026081306,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026081307) {
        // Scope skill evidence by position / alternative learning path.
        //
        // NULL positionid means the evidence is shared by every
        // position requiring the skill.

        $table = new xmldb_table(
            'local_ustar_skill_evidence'
        );

        $positionfield = new xmldb_field(
            'positionid',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            null,
            null,
            null,
            'skillid'
        );

        if (!$dbman->field_exists(
            $table,
            $positionfield
        )) {
            $dbman->add_field(
                $table,
                $positionfield
            );
        }


        $pathfield = new xmldb_field(
            'pathkey',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            null,
            null,
            null,
            'positionid'
        );

        if (!$dbman->field_exists(
            $table,
            $pathfield
        )) {
            $dbman->add_field(
                $table,
                $pathfield
            );
        }


        $index = new xmldb_index(
            'skill_position_idx',
            XMLDB_INDEX_NOTUNIQUE,
            [
                'skillid',
                'positionid',
                'active',
            ]
        );

        if (!$dbman->index_exists(
            $table,
            $index
        )) {
            $dbman->add_index(
                $table,
                $index
            );
        }


        upgrade_plugin_savepoint(
            true,
            2026081307,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026081308) {

        /*
         * ------------------------------------------------------------
         * USTAR CONTENT CORE
         * ------------------------------------------------------------
         *
         * Universal user-facing content catalog.
         *
         * sourcekind:
         *   ustar_file  -> content owned by USTAR / Moodle File API
         *   moodle_cm   -> existing Moodle activity
         *   external    -> external URL
         *
         * Access is resolved dynamically from position / department.
         */

        $table = new xmldb_table(
            'local_ustar_content'
        );

        $table->add_field(
            'id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            XMLDB_SEQUENCE
        );

        $table->add_field(
            'type',
            XMLDB_TYPE_CHAR,
            '32',
            null,
            XMLDB_NOTNULL,
            null,
            'document'
        );

        $table->add_field(
            'title',
            XMLDB_TYPE_CHAR,
            '255',
            null,
            XMLDB_NOTNULL
        );

        $table->add_field(
            'summary',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null
        );

        $table->add_field(
            'category',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            null
        );

        $table->add_field(
            'status',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            XMLDB_NOTNULL,
            null,
            'draft'
        );

        $table->add_field(
            'sourcekind',
            XMLDB_TYPE_CHAR,
            '32',
            null,
            XMLDB_NOTNULL,
            null,
            'ustar_file'
        );

        $table->add_field(
            'courseid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null
        );

        $table->add_field(
            'cmid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null
        );

        $table->add_field(
            'externalurl',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null
        );

        $table->add_field(
            'owneruserid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null
        );

        $table->add_field(
            'ackrequired',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'publishedat',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null
        );

        $table->add_field(
            'sortorder',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'timecreated',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'timemodified',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'usermodified',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_key(
            'primary',
            XMLDB_KEY_PRIMARY,
            ['id']
        );

        $table->add_key(
            'courseid_fk',
            XMLDB_KEY_FOREIGN,
            ['courseid'],
            'course',
            ['id']
        );

        $table->add_key(
            'cmid_fk',
            XMLDB_KEY_FOREIGN,
            ['cmid'],
            'course_modules',
            ['id']
        );

        $table->add_key(
            'owneruserid_fk',
            XMLDB_KEY_FOREIGN,
            ['owneruserid'],
            'user',
            ['id']
        );

        $table->add_index(
            'status_type_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['status', 'type']
        );

        $table->add_index(
            'source_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['sourcekind', 'cmid']
        );

        $table->add_index(
            'category_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['category']
        );

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }


        /*
         * Content versions.
         *
         * Every publishable content item can have a version row,
         * including Moodle-backed items. File-backed versions use
         * Moodle File API later with:
         *
         * component = local_ustar
         * filearea  = content_version
         * itemid    = version id
         */

        $table = new xmldb_table(
            'local_ustar_content_versions'
        );

        $table->add_field(
            'id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            XMLDB_SEQUENCE
        );

        $table->add_field(
            'contentid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL
        );

        $table->add_field(
            'versionno',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '1'
        );

        $table->add_field(
            'versionlabel',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            null
        );

        $table->add_field(
            'changenote',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null
        );

        $table->add_field(
            'effectivedate',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null
        );

        $table->add_field(
            'iscurrent',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '1'
        );

        $table->add_field(
            'status',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            XMLDB_NOTNULL,
            null,
            'draft'
        );

        $table->add_field(
            'timecreated',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'createdby',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_key(
            'primary',
            XMLDB_KEY_PRIMARY,
            ['id']
        );

        $table->add_key(
            'contentid_fk',
            XMLDB_KEY_FOREIGN,
            ['contentid'],
            'local_ustar_content',
            ['id']
        );

        $table->add_index(
            'content_version_uix',
            XMLDB_INDEX_UNIQUE,
            ['contentid', 'versionno']
        );

        $table->add_index(
            'content_current_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['contentid', 'iscurrent', 'status']
        );

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }


        /*
         * Dynamic access scopes:
         *
         *   all
         *   department
         *   position
         */

        $table = new xmldb_table(
            'local_ustar_content_access'
        );

        $table->add_field(
            'id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            XMLDB_SEQUENCE
        );

        $table->add_field(
            'contentid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL
        );

        $table->add_field(
            'scopetype',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            XMLDB_NOTNULL,
            null,
            'position'
        );

        $table->add_field(
            'scopeid',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            null
        );

        $table->add_field(
            'active',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '1'
        );

        $table->add_field(
            'timecreated',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'createdby',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_key(
            'primary',
            XMLDB_KEY_PRIMARY,
            ['id']
        );

        $table->add_key(
            'contentid_fk',
            XMLDB_KEY_FOREIGN,
            ['contentid'],
            'local_ustar_content',
            ['id']
        );

        $table->add_index(
            'content_active_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['contentid', 'active']
        );

        $table->add_index(
            'scope_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['scopetype', 'scopeid', 'active']
        );

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }


        /*
         * Version-specific acknowledgement.
         *
         * Acknowledging v3 never acknowledges v4.
         */

        $table = new xmldb_table(
            'local_ustar_content_ack'
        );

        $table->add_field(
            'id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            XMLDB_SEQUENCE
        );

        $table->add_field(
            'contentid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL
        );

        $table->add_field(
            'versionid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL
        );

        $table->add_field(
            'userid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL
        );

        $table->add_field(
            'acktime',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'method',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            XMLDB_NOTNULL,
            null,
            'manual'
        );

        $table->add_field(
            'timecreated',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_key(
            'primary',
            XMLDB_KEY_PRIMARY,
            ['id']
        );

        $table->add_key(
            'contentid_fk',
            XMLDB_KEY_FOREIGN,
            ['contentid'],
            'local_ustar_content',
            ['id']
        );

        $table->add_key(
            'versionid_fk',
            XMLDB_KEY_FOREIGN,
            ['versionid'],
            'local_ustar_content_versions',
            ['id']
        );

        $table->add_key(
            'userid_fk',
            XMLDB_KEY_FOREIGN,
            ['userid'],
            'user',
            ['id']
        );

        $table->add_index(
            'user_version_uix',
            XMLDB_INDEX_UNIQUE,
            ['userid', 'versionid']
        );

        $table->add_index(
            'content_user_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['contentid', 'userid']
        );

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }


        upgrade_plugin_savepoint(
            true,
            2026081308,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026081900) {
        /*
         * Account participation semantics are stored as a locked Moodle
         * profile menu field. Existing/empty values intentionally default
         * to employee in the service layer, so the upgrade cannot silently
         * remove people from workforce metrics.
         */
        \local_ustar\accounts::ensure_profile_field();

        upgrade_plugin_savepoint(
            true,
            2026081900,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082001) {
        $dbman = $DB->get_manager();

        // Register 1.5 capabilities before role grants below.
        update_capabilities('local_ustar');

        // File-like Knowledge hierarchy. Existing items remain root-level.
        $content = new xmldb_table('local_ustar_content');
        $parent = new xmldb_field('parentid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');
        if ($dbman->table_exists($content) && !$dbman->field_exists($content, $parent)) {
            $dbman->add_field($content, $parent);
            $dbman->add_key($content, new xmldb_key(
                'parentid_fk', XMLDB_KEY_FOREIGN, ['parentid'], 'local_ustar_content', ['id']
            ));
            $dbman->add_index($content, new xmldb_index(
                'parent_idx', XMLDB_INDEX_NOTUNIQUE, ['parentid', 'status']
            ));
        }

        $ledger = new xmldb_table('local_ustar_coin_ledger');
        $ledger->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $ledger->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $ledger->add_field('amount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $ledger->add_field('txtype', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'manual');
        $ledger->add_field('sourcekind', XMLDB_TYPE_CHAR, '32');
        $ledger->add_field('sourceid', XMLDB_TYPE_CHAR, '64');
        $ledger->add_field('idempotencykey', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL);
        $ledger->add_field('comment', XMLDB_TYPE_TEXT);
        $ledger->add_field('actorid', XMLDB_TYPE_INTEGER, '10');
        $ledger->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $ledger->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $ledger->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $ledger->add_key('actorid_fk', XMLDB_KEY_FOREIGN, ['actorid'], 'user', ['id']);
        $ledger->add_index('idempotency_uix', XMLDB_INDEX_UNIQUE, ['idempotencykey']);
        $ledger->add_index('user_time_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
        $ledger->add_index('source_idx', XMLDB_INDEX_NOTUNIQUE, ['sourcekind', 'sourceid']);
        if (!$dbman->table_exists($ledger)) {
            $dbman->create_table($ledger);
        }

        $reporting = new xmldb_table('local_ustar_reporting');
        $reporting->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $reporting->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $reporting->add_field('managerid', XMLDB_TYPE_INTEGER, '10');
        $reporting->add_field('source', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'manual');
        $reporting->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $reporting->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $reporting->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $reporting->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        // userid must be unique in the reporting map. Do not define XMLDB foreign
        // keys on userid/managerid here because XMLDB automatically creates an
        // index for every key, which collides with the explicit unique/non-unique
        // indexes required by this table. The user relations are enforced by the
        // service layer and the explicit indexes keep lookups deterministic.
        $reporting->add_index('userid_uix', XMLDB_INDEX_UNIQUE, ['userid']);
        $reporting->add_index('manager_idx', XMLDB_INDEX_NOTUNIQUE, ['managerid']);
        if (!$dbman->table_exists($reporting)) {
            $dbman->create_table($reporting);
        }

        $catalog = new xmldb_table('local_ustar_catalog');
        $catalog->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $catalog->add_field('parentid', XMLDB_TYPE_INTEGER, '10');
        $catalog->add_field('itemtype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'product');
        $catalog->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $catalog->add_field('slug', XMLDB_TYPE_CHAR, '128');
        $catalog->add_field('sku', XMLDB_TYPE_CHAR, '64');
        $catalog->add_field('summary', XMLDB_TYPE_TEXT);
        $catalog->add_field('description', XMLDB_TYPE_TEXT);
        $catalog->add_field('imageurl', XMLDB_TYPE_TEXT);
        $catalog->add_field('attributesjson', XMLDB_TYPE_TEXT);
        $catalog->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $catalog->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $catalog->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $catalog->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $catalog->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $catalog->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $catalog->add_key('parentid_fk', XMLDB_KEY_FOREIGN, ['parentid'], 'local_ustar_catalog', ['id']);
        $catalog->add_index('parent_type_idx', XMLDB_INDEX_NOTUNIQUE, ['parentid', 'itemtype', 'active']);
        $catalog->add_index('sku_idx', XMLDB_INDEX_NOTUNIQUE, ['sku']);
        if (!$dbman->table_exists($catalog)) {
            $dbman->create_table($catalog);
        }

        $boards = new xmldb_table('local_ustar_boards');
        $boards->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $boards->add_field('ownerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $boards->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $boards->add_field('documentjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $boards->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $boards->add_field('sharedteam', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $boards->add_field('deleted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $boards->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $boards->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $boards->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $boards->add_key('ownerid_fk', XMLDB_KEY_FOREIGN, ['ownerid'], 'user', ['id']);
        $boards->add_index('owner_deleted_idx', XMLDB_INDEX_NOTUNIQUE, ['ownerid', 'deleted']);
        $boards->add_index('shared_idx', XMLDB_INDEX_NOTUNIQUE, ['sharedteam', 'deleted']);
        if (!$dbman->table_exists($boards)) {
            $dbman->create_table($boards);
        }

        // Seed the visible root folders only when the content table is empty of folders.
        if ($DB->get_manager()->table_exists($content) && !$DB->record_exists('local_ustar_content', ['type' => 'folder'])) {
            $now = time();
            foreach (['Песочница', 'Обучение', 'Товары', 'Регламенты', 'Инструкции', 'Стандарты', 'HR', 'Охрана труда', 'Архив Moodle'] as $sort => $title) {
                $DB->insert_record('local_ustar_content', (object)[
                    'parentid' => null,
                    'type' => 'folder',
                    'title' => $title,
                    'summary' => '',
                    'category' => '',
                    'status' => 'published',
                    'sourcekind' => 'folder',
                    'courseid' => null,
                    'cmid' => null,
                    'externalurl' => null,
                    'owneruserid' => null,
                    'ackrequired' => 0,
                    'publishedat' => $now,
                    'sortorder' => $sort * 10,
                    'timecreated' => $now,
                    'timemodified' => $now,
                    'usermodified' => 0,
                ]);
            }
        }

        // Extend the existing USTAR Superadmin role with the new guarded 1.5 administration tools.
        $syscontext = \context_system::instance();
        $superadminroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'ustar_superadmin']);
        if ($superadminroleid) {
            foreach ([
                'local/ustar:viewas',
                'local/ustar:legacyui',
                'local/ustar:managecatalog',
                'local/ustar:adjustcoin',
            ] as $capability) {
                assign_capability($capability, CAP_ALLOW, $superadminroleid, $syscontext->id, true);
            }
            accesslib_clear_all_caches(true);
        }

        upgrade_plugin_savepoint(true, 2026082001, 'local', 'ustar');
    }


    if ($oldversion < 2026082002) {
        $dbman = $DB->get_manager();

        // Learning Route 2.0: one permanent route per position, versioned
        // checkpoints and immutable user completion snapshots. We deliberately
        // avoid XMLDB foreign keys here: the service layer validates relations,
        // while explicit indexes keep upgrades deterministic and avoid the
        // key/index collisions already observed on this production database.
        $routes = new xmldb_table('local_ustar_routes');
        $routes->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $routes->add_field('positionid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $routes->add_field('departmentid', XMLDB_TYPE_CHAR, '64');
        $routes->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $routes->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $routes->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $routes->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $routes->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $routes->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $routes->add_index('position_uix', XMLDB_INDEX_UNIQUE, ['positionid']);
        $routes->add_index('dept_active_idx', XMLDB_INDEX_NOTUNIQUE, ['departmentid', 'active']);
        if (!$dbman->table_exists($routes)) {
            $dbman->create_table($routes);
        }

        $points = new xmldb_table('local_ustar_route_points');
        $points->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $points->add_field('routeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $points->add_field('pointkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $points->add_field('phase', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'adaptation');
        $points->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $points->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $points->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $points->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $points->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $points->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $points->add_index('route_key_uix', XMLDB_INDEX_UNIQUE, ['routeid', 'pointkey']);
        $points->add_index('route_sort_idx', XMLDB_INDEX_NOTUNIQUE, ['routeid', 'active', 'sortorder']);
        if (!$dbman->table_exists($points)) {
            $dbman->create_table($points);
        }

        $versions = new xmldb_table('local_ustar_route_versions');
        $versions->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $versions->add_field('pointid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $versions->add_field('versionno', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $versions->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $versions->add_field('summary', XMLDB_TYPE_TEXT);
        $versions->add_field('requirementsjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $versions->add_field('renewalpolicy', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'keep');
        $versions->add_field('validdays', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '0');
        $versions->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'draft');
        $versions->add_field('effectivedate', XMLDB_TYPE_INTEGER, '10');
        $versions->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $versions->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $versions->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $versions->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $versions->add_index('point_version_uix', XMLDB_INDEX_UNIQUE, ['pointid', 'versionno']);
        $versions->add_index('point_status_idx', XMLDB_INDEX_NOTUNIQUE, ['pointid', 'status', 'effectivedate']);
        if (!$dbman->table_exists($versions)) {
            $dbman->create_table($versions);
        }

        $progress = new xmldb_table('local_ustar_route_progress');
        $progress->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $progress->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $progress->add_field('pointid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $progress->add_field('versionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $progress->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'complete');
        $progress->add_field('completedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $progress->add_field('expiresat', XMLDB_TYPE_INTEGER, '10');
        $progress->add_field('evidencejson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $progress->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $progress->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $progress->add_field('recordedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $progress->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $progress->add_index('user_point_version_uix', XMLDB_INDEX_UNIQUE, ['userid', 'pointid', 'versionid']);
        $progress->add_index('user_status_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'status', 'completedat']);
        $progress->add_index('point_version_idx', XMLDB_INDEX_NOTUNIQUE, ['pointid', 'versionid']);
        if (!$dbman->table_exists($progress)) {
            $dbman->create_table($progress);
        }

        upgrade_plugin_savepoint(true, 2026082002, 'local', 'ustar');
    }


    if ($oldversion < 2026082301) {
        $dbman = $DB->get_manager();

        // Personal Library: immutable learning events are the source, while
        // local_ustar_library is a rebuildable read model. Existing ACL rows,
        // acknowledgements and content are deliberately not backfilled because
        // CURRENT access is not evidence that a route material was studied.
        $events = new xmldb_table('local_ustar_content_events');
        $events->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $events->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $events->add_field('userid', XMLDB_TYPE_INTEGER, '10');
        $events->add_field('contentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $events->add_field('contentversionid', XMLDB_TYPE_INTEGER, '10');
        $events->add_field('routepointid', XMLDB_TYPE_INTEGER, '10');
        $events->add_field('routeversionid', XMLDB_TYPE_INTEGER, '10');
        $events->add_field('eventtype', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $events->add_field('idempotencykey', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL);
        $events->add_field('detailsjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $events->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $events->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $events->add_index('idempotency_uix', XMLDB_INDEX_UNIQUE, ['idempotencykey']);
        $events->add_index('user_content_time_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'contentid', 'timecreated']);
        $events->add_index('route_event_idx', XMLDB_INDEX_NOTUNIQUE, ['routepointid', 'routeversionid', 'eventtype']);
        $events->add_index('content_event_time_idx', XMLDB_INDEX_NOTUNIQUE, ['contentid', 'eventtype', 'timecreated']);
        if (!$dbman->table_exists($events)) {
            $dbman->create_table($events);
        }

        $library = new xmldb_table('local_ustar_library');
        $library->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $library->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $library->add_field('contentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $library->add_field('unlockedversionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $library->add_field('firsteventid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $library->add_field('routepointid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $library->add_field('routeversionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $library->add_field('unlockedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $library->add_field('lastaccessedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $library->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $library->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $library->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $library->add_index('user_content_uix', XMLDB_INDEX_UNIQUE, ['userid', 'contentid']);
        $library->add_index('user_access_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'lastaccessedat']);
        $library->add_index('content_idx', XMLDB_INDEX_NOTUNIQUE, ['contentid']);
        if (!$dbman->table_exists($library)) {
            $dbman->create_table($library);
        }

        upgrade_plugin_savepoint(true, 2026082301, 'local', 'ustar');
    }

    if ($oldversion < 2026082302) {
        $dbman = $DB->get_manager();
        require_once(__DIR__ . '/../classes/target_schema.php');
        foreach (\local_ustar\target_schema::definitions() as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->create_table($table);
            }
        }
        upgrade_plugin_savepoint(true, 2026082302, 'local', 'ustar');
    }

    if ($oldversion < 2026082701) {
        $dbman = $DB->get_manager();
        // Moodle stores capabilities separately from access.php. Register the
        // new protected capability before granting it to the newly created role.
        update_capabilities('local_ustar');
        require_once(__DIR__ . '/../classes/target_schema.php');
        foreach (\local_ustar\target_schema::development_assessment_definitions() as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->create_table($table);
            }
        }

        // The initial profile is original USTAR content. It must never be
        // represented as licensed Belbin material or used as a personnel verdict.
        require_once(__DIR__ . '/../classes/development_assessment.php');
        \local_ustar\development_assessment::ensure_team_profile(0);

        // HRD may view aggregated/private development outcomes. USTAR HR does
        // not gain this capability, and no account is assigned automatically.
        $syscontext = context_system::instance();
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'ustar_hrd']);
        if (!$roleid) {
            $roleid = create_role(
                'USTAR HRD',
                'ustar_hrd',
                'Development leadership: people administration plus protected development analytics.'
            );
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }
        foreach (['local/ustar:use', 'local/ustar:hr', 'local/ustar:hrmanage', 'local/ustar:developmentanalytics'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $syscontext->id, true);
        }
        accesslib_clear_all_caches(true);
        upgrade_plugin_savepoint(true, 2026082701, 'local', 'ustar');
    }

    if ($oldversion < 2026082702) {
        // Repeat the role grant in its own idempotent upgrade point. This
        // protects a recovered instance where 2026082701 created the role but
        // was interrupted before Moodle persisted its capability assignment.
        update_capabilities('local_ustar');
        $syscontext = context_system::instance();
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'ustar_hrd']);
        if (!$roleid) {
            $roleid = create_role(
                'USTAR HRD',
                'ustar_hrd',
                'Development leadership: people administration plus protected development analytics.'
            );
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        }
        foreach (['local/ustar:use', 'local/ustar:hr', 'local/ustar:hrmanage', 'local/ustar:developmentanalytics'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $syscontext->id, true);
        }
        accesslib_clear_all_caches(true);
        upgrade_plugin_savepoint(true, 2026082702, 'local', 'ustar');
    }

    if ($oldversion < 2026082703) {
        $dbman = $DB->get_manager();
        update_capabilities('local_ustar');
        require_once(__DIR__ . '/../classes/target_schema.php');
        foreach (\local_ustar\target_schema::competition_economy_definitions() as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->create_table($table);
            }
        }

        // The legacy ledger is preserved verbatim. A balance projection is
        // established once so all future debits can lock one deterministic
        // row and refuse to create a negative balance.
        $ledger = new xmldb_table('local_ustar_coin_ledger');
        $reversal = new xmldb_field('reversalofid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'actorid');
        if (!$dbman->field_exists($ledger, $reversal)) {
            $dbman->add_field($ledger, $reversal);
        }
        $reversalindex = new xmldb_index('reversal_uix', XMLDB_INDEX_UNIQUE, ['reversalofid']);
        if (!$dbman->index_exists($ledger, $reversalindex)) {
            $dbman->add_index($ledger, $reversalindex);
        }

        foreach ($DB->get_records_sql(
            'SELECT userid, COALESCE(SUM(amount), 0) AS balance FROM {local_ustar_coin_ledger} GROUP BY userid'
        ) as $row) {
            if ((int)$row->balance < 0) {
                throw new moodle_exception('USTAR USCOIN migration refused: existing balance is negative for user ' . (int)$row->userid);
            }
            if (!$DB->record_exists('local_ustar_coin_balance', ['userid' => (int)$row->userid])) {
                $DB->insert_record('local_ustar_coin_balance', (object)[
                    'userid' => (int)$row->userid,
                    'balance' => (int)$row->balance,
                    'timemodified' => time(),
                ]);
            }
        }

        $syscontext = context_system::instance();
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'ustar_superadmin']);
        if ($roleid) {
            foreach (['local/ustar:managecompetition', 'local/ustar:adjustcoin'] as $capability) {
                assign_capability($capability, CAP_ALLOW, $roleid, $syscontext->id, true);
            }
        }
        accesslib_clear_all_caches(true);
        upgrade_plugin_savepoint(true, 2026082703, 'local', 'ustar');
    }



    if ($oldversion < 2026082704) {
        $dbman = $DB->get_manager();

        // TARGET transition: one permanent route belongs directly to one position.
        // The obsolete route_positions table was only a compatibility mapping.
        //
        // IMPORTANT: do not delete route/point/version/progress records here.
        // They are historical learning state and completion evidence.
        $legacyroutepositions = new xmldb_table('local_ustar_route_positions');
        if ($dbman->table_exists($legacyroutepositions)) {
            $dbman->drop_table($legacyroutepositions);
        }

        // Legacy local_ustar_test_* tables are intentionally retained and
        // declared in install.xml as historical Development Center evidence.
        // Active TARGET runtime uses local_ustar_dev_assess*.

        upgrade_plugin_savepoint(true, 2026082704, 'local', 'ustar');
    }

    if ($oldversion < 2026082705) {
        $dbman = $DB->get_manager();
        require_once(__DIR__ . '/../classes/target_schema.php');

        // Production reconciliation: the previous release already had an older
        // competition schema under the same table names. The conflicting tables
        // are safe to replace only while they contain no business records.
        foreach (['local_ustar_competitions', 'local_ustar_comp_results', 'local_ustar_comp_scores'] as $tablename) {
            $table = new xmldb_table($tablename);
            if ($dbman->table_exists($table) && $DB->count_records($tablename) > 0) {
                throw new coding_exception('USTAR 2705 refuses to replace non-empty legacy competition table: ' . $tablename);
            }
        }

        // Drop dependent empty legacy tables before replacing competitions.
        foreach (['local_ustar_comp_scores', 'local_ustar_comp_results', 'local_ustar_competitions'] as $tablename) {
            $table = new xmldb_table($tablename);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }

        // Create/retain the active TARGET competition and USCOIN projection tables.
        foreach (\local_ustar\target_schema::competition_economy_definitions() as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->create_table($table);
            }
        }

        // Retain the old immutable score-event table as an explicit legacy/history
        // table. Production currently has no records in it, but keeping the schema
        // avoids destructive assumptions and makes historical restores interpretable.
        $table = new xmldb_table('local_ustar_comp_scores');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('competitionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('points', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('idempotencykey', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sourcekind', XMLDB_TYPE_CHAR, '32', null, null, null, null);
        $table->add_field('sourceid', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('competitionid_fk', XMLDB_KEY_FOREIGN, ['competitionid'], 'local_ustar_competitions', ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('idempotency_uix', XMLDB_INDEX_UNIQUE, ['idempotencykey']);
        $table->add_index('comp_user_time_idx', XMLDB_INDEX_NOTUNIQUE, ['competitionid', 'userid', 'timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // The following production tables contain historical workforce/economy
        // state and are intentionally retained unchanged:
        // local_ustar_coin_accounts, local_ustar_staff_places, local_ustar_assignments.
        // The legacy coin_ledger.cycle field/index is also retained; current runtime
        // ignores it while immutable ledger history remains fully interpretable.

        upgrade_plugin_savepoint(true, 2026082705, 'local', 'ustar');
    }

    if ($oldversion < 2026082706) {
        $dbman = $DB->get_manager();

        /*
         * TARGET Route Family v1.
         *
         * A family owns one common parent route plus any number of
         * position-specific child routes. Existing completion,
         * version and progress tables remain authoritative.
         */

        $familytable = new xmldb_table('local_ustar_route_families');

        $familytable->add_field(
            'id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            XMLDB_SEQUENCE,
            null
        );
        $familytable->add_field(
            'familykey',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            XMLDB_NOTNULL,
            null,
            null
        );
        $familytable->add_field(
            'name',
            XMLDB_TYPE_CHAR,
            '255',
            null,
            XMLDB_NOTNULL,
            null,
            null
        );
        $familytable->add_field(
            'departmentid',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            null,
            null,
            null
        );
        $familytable->add_field(
            'parentrouteid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null
        );
        $familytable->add_field(
            'active',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '1'
        );
        $familytable->add_field(
            'timecreated',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );
        $familytable->add_field(
            'timemodified',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );
        $familytable->add_field(
            'usermodified',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $familytable->add_key(
            'primary',
            XMLDB_KEY_PRIMARY,
            ['id']
        );

        $familytable->add_index(
            'familykey_uix',
            XMLDB_INDEX_UNIQUE,
            ['familykey']
        );
        $familytable->add_index(
            'dept_active_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['departmentid', 'active']
        );

        if (!$dbman->table_exists($familytable)) {
            $dbman->create_table($familytable);
        }


        /*
         * Routes gain family membership.
         * Parent routes intentionally have positionid = NULL.
         */
        $routes = new xmldb_table('local_ustar_routes');

        $positionid = new xmldb_field(
            'positionid',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            null,
            null,
            null,
            'id'
        );

        if ($dbman->field_exists($routes, $positionid)) {

            /*
             * Moodle refuses to change nullability while an index
             * depends on the field. Temporarily remove the canonical
             * one-position-per-route index, alter the field, then
             * restore the same unique index.
             */
            $positionindex = new xmldb_index(
                'position_uix',
                XMLDB_INDEX_UNIQUE,
                ['positionid']
            );

            if ($dbman->index_exists($routes, $positionindex)) {
                $dbman->drop_index(
                    $routes,
                    $positionindex
                );
            }

            $dbman->change_field_notnull(
                $routes,
                $positionid
            );

            if (!$dbman->index_exists($routes, $positionindex)) {
                $dbman->add_index(
                    $routes,
                    $positionindex
                );
            }
        }

        $familyidfield = new xmldb_field(
            'familyid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'departmentid'
        );

        if (!$dbman->field_exists($routes, $familyidfield)) {
            $dbman->add_field(
                $routes,
                $familyidfield
            );
        }

        $routekindfield = new xmldb_field(
            'routekind',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            XMLDB_NOTNULL,
            null,
            'position',
            'familyid'
        );

        if (!$dbman->field_exists($routes, $routekindfield)) {
            $dbman->add_field(
                $routes,
                $routekindfield
            );
        }

        $familykindindex = new xmldb_index(
            'family_kind_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['familyid', 'routekind', 'active']
        );

        if (!$dbman->index_exists($routes, $familykindindex)) {
            $dbman->add_index(
                $routes,
                $familykindindex
            );
        }


        /*
         * Materialised inheritance metadata.
         *
         * Existing points are local by default and therefore keep their
         * exact historical behaviour.
         */
        $points = new xmldb_table(
            'local_ustar_route_points'
        );

        $sourcepointid = new xmldb_field(
            'sourcepointid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'pointkey'
        );

        if (!$dbman->field_exists($points, $sourcepointid)) {
            $dbman->add_field(
                $points,
                $sourcepointid
            );
        }

        $inheritstate = new xmldb_field(
            'inheritstate',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            XMLDB_NOTNULL,
            null,
            'local',
            'sourcepointid'
        );

        if (!$dbman->field_exists($points, $inheritstate)) {
            $dbman->add_field(
                $points,
                $inheritstate
            );
        }

        $sourceversionid = new xmldb_field(
            'sourceversionid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'inheritstate'
        );

        if (!$dbman->field_exists($points, $sourceversionid)) {
            $dbman->add_field(
                $points,
                $sourceversionid
            );
        }

        $sourcepointindex = new xmldb_index(
            'source_point_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['sourcepointid', 'inheritstate']
        );

        if (!$dbman->index_exists($points, $sourcepointindex)) {
            $dbman->add_index(
                $points,
                $sourcepointindex
            );
        }


        /*
         * Seed only the structural family.
         *
         * Existing routes 14-19 remain the same route records.
         * Historical route 1 is intentionally NOT repurposed.
         */
        $departmentid =
            'dept_576ea5ace2669f';

        $family =
            $DB->get_record(
                'local_ustar_route_families',
                ['familykey' => 'tradehall']
            );

        if (!$family) {
            $now = time();

            $familyid = $DB->insert_record(
                'local_ustar_route_families',
                (object)[
                    'familykey' => 'tradehall',
                    'name' => 'Торговый зал',
                    'departmentid' => $departmentid,
                    'parentrouteid' => null,
                    'active' => 1,
                    'timecreated' => $now,
                    'timemodified' => $now,
                    'usermodified' => 0,
                ]
            );

            $family =
                $DB->get_record(
                    'local_ustar_route_families',
                    ['id' => $familyid],
                    '*',
                    MUST_EXIST
                );
        }

        $familyid = (int)$family->id;

        // Attach all canonical Trade Hall position routes.
        $DB->set_field_select(
            'local_ustar_routes',
            'familyid',
            $familyid,
            'departmentid = :departmentid',
            ['departmentid' => $departmentid]
        );

        $DB->set_field_select(
            'local_ustar_routes',
            'routekind',
            'position',
            'departmentid = :departmentid',
            ['departmentid' => $departmentid]
        );

        // Create the new common parent without changing old route semantics.
        $parent =
            $DB->get_record(
                'local_ustar_routes',
                [
                    'familyid' => $familyid,
                    'routekind' => 'parent',
                ]
            );

        if (!$parent) {
            $now = time();

            $parentrouteid =
                $DB->insert_record(
                    'local_ustar_routes',
                    (object)[
                        'positionid' => null,
                        'departmentid' => $departmentid,
                        'familyid' => $familyid,
                        'routekind' => 'parent',
                        'name' => 'ТОРГОВЫЙ ЗАЛ: ОБЩИЙ МАРШРУТ',
                        'active' => 1,
                        'timecreated' => $now,
                        'timemodified' => $now,
                        'usermodified' => 0,
                    ]
                );

            $DB->set_field(
                'local_ustar_route_families',
                'parentrouteid',
                $parentrouteid,
                ['id' => $familyid]
            );
        } else if (
            empty($family->parentrouteid)
            ||
            (int)$family->parentrouteid !== (int)$parent->id
        ) {
            $DB->set_field(
                'local_ustar_route_families',
                'parentrouteid',
                (int)$parent->id,
                ['id' => $familyid]
            );
        }

        upgrade_plugin_savepoint(
            true,
            2026082706,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082707) {
        // Route Family runtime. Schema was introduced in 2706;
        // 2707 activates materialised parent -> position inheritance.
        upgrade_plugin_savepoint(
            true,
            2026082707,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082708) {
        // TARGET Route Studio v2:
        // family parent + position children + inheritance controls.
        upgrade_plugin_savepoint(
            true,
            2026082708,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082709) {
        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_ustar_route_scope');

        $table->add_field(
            'id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            XMLDB_SEQUENCE
        );

        $table->add_field(
            'pointid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL
        );

        $table->add_field(
            'scopeid',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            XMLDB_NOTNULL
        );

        $table->add_field(
            'state',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            XMLDB_NOTNULL,
            null,
            'confirmed'
        );

        $table->add_field(
            'sourcekind',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            XMLDB_NOTNULL,
            null,
            'manual'
        );

        $table->add_field(
            'reason',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null
        );

        $table->add_field(
            'active',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '1'
        );

        $table->add_field(
            'timecreated',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'timemodified',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'usermodified',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_key(
            'primary',
            XMLDB_KEY_PRIMARY,
            ['id']
        );

        $table->add_key(
            'pointid_fk',
            XMLDB_KEY_FOREIGN,
            ['pointid'],
            'local_ustar_route_points',
            ['id']
        );

        $table->add_index(
            'point_scope_uix',
            XMLDB_INDEX_UNIQUE,
            ['pointid', 'scopeid']
        );

        $table->add_index(
            'scope_state_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['scopeid', 'state', 'active']
        );

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Existing parent #49 steps are confirmed common Trade Hall steps.
        $now = time();

        foreach (
            $DB->get_records(
                'local_ustar_route_points',
                ['routeid' => 49]
            ) as $point
        ) {
            if (
                !$DB->record_exists(
                    'local_ustar_route_scope',
                    [
                        'pointid' => (int)$point->id,
                        'scopeid' => 'all',
                    ]
                )
            ) {
                $DB->insert_record(
                    'local_ustar_route_scope',
                    (object)[
                        'pointid' => (int)$point->id,
                        'scopeid' => 'all',
                        'state' => 'confirmed',
                        'sourcekind' => 'migration',
                        'reason' => '2709: common Trade Hall parent step',
                        'active' => 1,
                        'timecreated' => $now,
                        'timemodified' => $now,
                        'usermodified' => 0,
                    ]
                );
            }
        }

        upgrade_plugin_savepoint(
            true,
            2026082709,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082710) {
        /*
         * TARGET Trade Hall cutover.
         *
         * route #49 becomes the single physical business route.
         * Point IDs 24/26/27/28 are preserved, therefore all historical
         * route progress remains attached to the same logical steps.
         */
        $DB->get_record(
            'local_ustar_routes',
            [
                'id' => 49,
                'familyid' => 1,
                'routekind' => 'parent',
                'active' => 1,
            ],
            '*',
            MUST_EXIST
        );

        $transaction = $DB->start_delegated_transaction();

        try {
            // Remove only temporary inherited draft materialisations.
            $copies = $DB->get_records_sql(
                "
                SELECT p.*
                  FROM {local_ustar_route_points} p
                  JOIN {local_ustar_routes} r
                    ON r.id = p.routeid
                 WHERE r.familyid = :familyid
                   AND r.routekind = :routekind
                   AND p.inheritstate = :inheritstate
                   AND p.sourcepointid IN (32,39,46,53)
                ",
                [
                    'familyid' => 1,
                    'routekind' => 'position',
                    'inheritstate' => 'inherited',
                ]
            );

            foreach ($copies as $copy) {
                if (
                    $DB->record_exists(
                        'local_ustar_route_progress',
                        ['pointid' => (int)$copy->id]
                    )
                ) {
                    throw new \coding_exception(
                        '2710 inherited point has progress: ' .
                        (int)$copy->id
                    );
                }

                if (
                    $DB->record_exists_select(
                        'local_ustar_route_versions',
                        'pointid = :pointid AND status <> :draft',
                        [
                            'pointid' => (int)$copy->id,
                            'draft' => 'draft',
                        ]
                    )
                ) {
                    throw new \coding_exception(
                        '2710 inherited point is not draft: ' .
                        (int)$copy->id
                    );
                }

                $DB->delete_records(
                    'local_ustar_route_scope',
                    ['pointid' => (int)$copy->id]
                );

                $DB->delete_records(
                    'local_ustar_route_versions',
                    ['pointid' => (int)$copy->id]
                );

                $DB->delete_records(
                    'local_ustar_route_points',
                    ['id' => (int)$copy->id]
                );
            }

            // Draft point 46 duplicated real SCORM point 28.
            if (
                $DB->record_exists(
                    'local_ustar_route_points',
                    ['id' => 46, 'routeid' => 49]
                )
            ) {
                if (
                    $DB->record_exists(
                        'local_ustar_route_progress',
                        ['pointid' => 46]
                    )
                ) {
                    throw new \coding_exception(
                        '2710 duplicate point 46 has progress'
                    );
                }

                if (
                    $DB->record_exists_select(
                        'local_ustar_route_versions',
                        'pointid = :pointid AND status <> :draft',
                        [
                            'pointid' => 46,
                            'draft' => 'draft',
                        ]
                    )
                ) {
                    throw new \coding_exception(
                        '2710 duplicate point 46 is not draft'
                    );
                }

                $DB->delete_records(
                    'local_ustar_route_scope',
                    ['pointid' => 46]
                );

                $DB->delete_records(
                    'local_ustar_route_versions',
                    ['pointid' => 46]
                );

                $DB->delete_records(
                    'local_ustar_route_points',
                    ['id' => 46]
                );
            }

            /*
             * Reserve 30-50 for TARGET native steps 3-6.
             *
             * Existing real steps become:
             * 60  duty instruction seller-cashier
             * 70  Trade Hall regulation
             * 90  storage workflow
             * 100 storefront formatting
             */
            $moves = [
                24 => 60,
                28 => 70,
                26 => 90,
                27 => 100,
            ];

            foreach ($moves as $pointid => $sortorder) {
                $point = $DB->get_record(
                    'local_ustar_route_points',
                    ['id' => $pointid],
                    '*',
                    MUST_EXIST
                );

                if (!in_array((int)$point->routeid, [18,49], true)) {
                    throw new \coding_exception(
                        '2710 unexpected route for point ' .
                        $pointid . ': ' . (int)$point->routeid
                    );
                }

                $point->routeid = 49;
                $point->sortorder = $sortorder;
                $point->phase = 'adaptation';
                $point->inheritstate = 'local';
                $point->sourcepointid = null;
                $point->sourceversionid = 0;

                $DB->update_record(
                    'local_ustar_route_points',
                    $point
                );
            }

            // Stable reserved positions for existing parent drafts.
            foreach (
                [
                    32 => 10,
                    39 => 20,
                    53 => 80,
                ] as $pointid => $sortorder
            ) {
                $point = $DB->get_record(
                    'local_ustar_route_points',
                    ['id' => $pointid, 'routeid' => 49]
                );

                if ($point) {
                    $point->sortorder = $sortorder;
                    $DB->update_record(
                        'local_ustar_route_points',
                        $point
                    );
                }
            }

            $transaction->allow_commit();

        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        upgrade_plugin_savepoint(
            true,
            2026082710,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082711) {
        // Route Studio resolved position views over one family parent route.
        upgrade_plugin_savepoint(
            true,
            2026082711,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082712) {
        // Canonical Team hierarchy + reusable route step 03 view.
        upgrade_plugin_savepoint(
            true,
            2026082712,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082713) {
        // Executive staffing hierarchy + department-head route dashboards.
        upgrade_plugin_savepoint(
            true,
            2026082713,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082714) {

        $routeid = 49;
        $now = time();

        $ensurepoint =
            static function(
                string $key,
                string $phase,
                int $sortorder,
                string $title,
                string $summary,
                array $requirements = []
            ) use ($DB, $routeid): int {

                $existing =
                    $DB->get_record(
                        'local_ustar_route_points',
                        [
                            'routeid' => $routeid,
                            'pointkey' => $key,
                        ]
                    );

                if ($existing) {
                    return (int)$existing->id;
                }

                $point =
                    \local_ustar\route_model::add_point(
                        $routeid,
                        $key,
                        $phase,
                        $sortorder,
                        [
                            'title' => $title,
                            'summary' => $summary,
                            'requirements' => $requirements,
                            'renewalpolicy' =>
                                \local_ustar\route_model::RENEW_KEEP,
                            'validdays' => 0,
                            'status' =>
                                \local_ustar\route_model::STATUS_DRAFT,
                            'effectivedate' => 0,
                        ],
                        0
                    );

                return (int)$point->id;
            };


        $scope =
            static function(
                int $pointid,
                string $scopeid,
                string $state = 'confirmed',
                string $sourcekind = 'migration'
            ) use ($DB, $now): void {

                $existing =
                    $DB->get_record(
                        'local_ustar_route_scope',
                        [
                            'pointid' => $pointid,
                            'scopeid' => $scopeid,
                        ]
                    );

                if ($existing) {
                    return;
                }

                $DB->insert_record(
                    'local_ustar_route_scope',
                    (object)[
                        'pointid' => $pointid,
                        'scopeid' => $scopeid,
                        'state' => $state,
                        'sourcekind' => $sourcekind,
                        'reason' =>
                            $state === 'proposed'
                            ? 'Канонический маршрут ТЗ: требуется подтверждение HRD'
                            : 'Канонический маршрут ТЗ',
                        'active' => 1,
                        'timecreated' => $now,
                        'timemodified' => $now,
                        'usermodified' => 0,
                    ]
                );
            };


        /*
         * 03 / 04 / 05 / 06.
         */
        $p03 = $ensurepoint(
            'target_team_structure',
            'adaptation',
            30,
            'Моё место в команде',
            'Реальная вертикаль и горизонталь сотрудника, аватары, должности и проверка «Кто есть кто?».'
        );
        $scope($p03, 'all');


        $p04 = $ensurepoint(
            'target_role_development',
            'adaptation',
            40,
            'Моя должность и развитие',
            'Текущая роль, матрица навыков, readiness, карьерная лестница и связь Должность ↔ Навык ↔ Обучение.'
        );
        $scope($p04, 'all');


        $p05 = $ensurepoint(
            'target_role_skills_check',
            'adaptation',
            50,
            'Проверка: моя должность и навыки',
            'Короткая нативная проверка понимания своей роли и обязательных навыков.'
        );
        $scope($p05, 'all');


        $p06 = $ensurepoint(
            'target_team_profile_reveal',
            'adaptation',
            55,
            'Познай себя: результат профиля',
            'Раскрытие результата шага 01 после двух промежуточных шагов, достижение и награда.'
        );
        $scope($p06, 'all');


        /*
         * 07 — role-specific normative slot.
         * Existing seller point 24 remains untouched.
         */
        $tovaroved = $ensurepoint(
            'target_role_norm_tovaroved',
            'adaptation',
            60,
            'Должностной норматив: Товаровед',
            'Утверждённая должностная инструкция товароведа.',
            [
                [
                    'type' => 'content',
                    'sourceid' => 55,
                    'completionmode' => 'ack',
                    'required' => true,
                    'label' => 'Должностная инструкция товароведа',
                ],
            ]
        );
        $scope(
            $tovaroved,
            'pos_edcafa7ab3e816d3'
        );


        foreach (
            [
                [
                    'key' => 'target_role_norm_director',
                    'title' => 'Должностной норматив: Директор розницы',
                    'scope' => 'pos_8dd9749f07a5bc80',
                ],
                [
                    'key' => 'target_role_norm_master',
                    'title' => 'Должностной норматив: Мастер менеджер',
                    'scope' => 'pos_291ca10e777807f0',
                ],
                [
                    'key' => 'target_role_norm_cashier',
                    'title' => 'Должностной норматив: Кассир',
                    'scope' => 'pos_ba27695e3e0796c2',
                ],
                [
                    'key' => 'target_role_norm_cleaner',
                    'title' => 'Должностной норматив: Техничка',
                    'scope' => 'pos_9c374b12835f0f66',
                ],
            ]
            as $role
        ) {
            $pid = $ensurepoint(
                $role['key'],
                'adaptation',
                60,
                $role['title'],
                'Черновой слот. Утверждённый норматив будет загружен следующей версией.'
            );

            $scope($pid, $role['scope']);
        }


        /*
         * 10 — ассортимент / товарный блок.
         */
        $p10 = $ensurepoint(
            'target_product_catalog',
            'adaptation',
            90,
            'Ассортимент и товароведение',
            'Изучение товарного блока и обязательного ассортимента.',
            [
                [
                    'type' => 'cm',
                    'sourceid' => 41,
                    'required' => true,
                    'label' => 'АССОРТИМЕНТ И ТОВАРОВЕДЕНИЕ',
                ],
            ]
        );

        foreach (
            [
                'pos_4551dd63496af62f',
                'pos_edcafa7ab3e816d3',
                'pos_ba27695e3e0796c2',
            ]
            as $positionid
        ) {
            $scope(
                $p10,
                $positionid,
                'proposed',
                'semantic'
            );
        }


        /*
         * Existing seller storage point becomes a supporting
         * item inside the product block. ID/progress preserved.
         */
        if (
            $DB->record_exists(
                'local_ustar_route_points',
                ['id' => 26, 'routeid' => 49]
            )
        ) {
            $DB->set_field(
                'local_ustar_route_points',
                'sortorder',
                95,
                ['id' => 26]
            );
        }


        $p11 = $ensurepoint(
            'target_product_check',
            'adaptation',
            100,
            'Проверка: товарный блок',
            'Черновой слот итоговой проверки по ассортименту, инструментам и товарному каталогу.'
        );

        foreach (
            [
                'pos_4551dd63496af62f',
                'pos_edcafa7ab3e816d3',
                'pos_ba27695e3e0796c2',
            ]
            as $positionid
        ) {
            $scope(
                $p11,
                $positionid,
                'proposed',
                'semantic'
            );
        }


        /*
         * 12 / 13 — sales block.
         */
        $p12 = $ensurepoint(
            'target_sales_video',
            'adaptation',
            110,
            'Продажи: рабочая модель',
            'Черновой слот видео 8–10 минут. Материал будет загружен следующей версией.'
        );

        $p13 = $ensurepoint(
            'target_sales_assessment',
            'adaptation',
            120,
            'Проверка: продажи',
            'Черновой слот оценки продаж. Результат будет использоваться как evidence навыка.'
        );

        foreach (
            [
                'pos_4551dd63496af62f',
                'pos_291ca10e777807f0',
            ]
            as $positionid
        ) {
            $scope(
                $p12,
                $positionid,
                'proposed',
                'semantic'
            );

            $scope(
                $p13,
                $positionid,
                'proposed',
                'semantic'
            );
        }


        /*
         * 14 / 15 — storefront regulation.
         * CM49/50 are selected as canonical; CM64/65 stay untouched.
         */
        $p14 = $ensurepoint(
            'target_storefront_regulation',
            'adaptation',
            130,
            'Регламент оформления Торгового зала',
            'Канонический SCORM по оформлению торгового зала.',
            [
                [
                    'type' => 'cm',
                    'sourceid' => 49,
                    'required' => true,
                    'label' => 'Регламент оформления Торгового зала',
                ],
            ]
        );

        if (
            $DB->record_exists(
                'local_ustar_route_points',
                ['id' => 27, 'routeid' => 49]
            )
        ) {
            $DB->set_field(
                'local_ustar_route_points',
                'sortorder',
                135,
                ['id' => 27]
            );
        }

        $p15 = $ensurepoint(
            'target_storefront_assessment',
            'adaptation',
            140,
            'Проверка: оформление Торгового зала',
            'Аттестация по регламенту оформления витрин.',
            [
                [
                    'type' => 'cm',
                    'sourceid' => 50,
                    'required' => true,
                    'label' => 'Аттестация по регламенту оформления витрин',
                ],
            ]
        );

        foreach (
            [
                'pos_4551dd63496af62f',
                'pos_edcafa7ab3e816d3',
                'pos_291ca10e777807f0',
            ]
            as $positionid
        ) {
            $scope(
                $p14,
                $positionid,
                'proposed',
                'semantic'
            );

            $scope(
                $p15,
                $positionid,
                'proposed',
                'semantic'
            );
        }


        /*
         * 16 — admission control point.
         */
        $p16 = $ensurepoint(
            'target_work_admission',
            'gate',
            150,
            'Контрольная точка допуска',
            'Финальное решение по рабочему допуску после обязательных элементов адаптации и аттестации.'
        );

        $scope($p16, 'all');


        upgrade_plugin_savepoint(
            true,
            2026082714,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082715) {

        /*
         * 03: successful native people-position assessment.
         */
        $latest =
            \local_ustar\route_model::latest_version(60);

        if (
            $latest
            &&
            (int)$latest->versionno < 2
        ) {
            \local_ustar\route_model::create_version(
                60,
                [
                    'title' =>
                        'Моё место в команде',

                    'summary' =>
                        'Изучение рабочей структуры и отдельная проверка «Кто есть кто?».',

                    'requirements' => [
                        [
                            'type' => 'native',
                            'sourcekey' =>
                                'team_structure',
                            'required' => true,
                            'label' =>
                                'Кто есть кто?',
                        ],
                    ],

                    'renewalpolicy' =>
                        \local_ustar\route_model::RENEW_KEEP,

                    'validdays' => 0,

                    'status' =>
                        \local_ustar\route_model::STATUS_DRAFT,

                    'effectivedate' => 0,
                ],
                0
            );
        }


        /*
         * 04: explicit acknowledgement of live role/skills view.
         */
        $latest =
            \local_ustar\route_model::latest_version(61);

        if (
            $latest
            &&
            (int)$latest->versionno < 2
        ) {
            \local_ustar\route_model::create_version(
                61,
                [
                    'title' =>
                        'Моя должность и развитие',

                    'summary' =>
                        'Текущая должность, требования навыков, evidence и подтверждение ознакомления.',

                    'requirements' => [
                        [
                            'type' => 'native',
                            'sourcekey' =>
                                'role_development',
                            'required' => true,
                            'label' =>
                                'Моя должность и развитие',
                        ],
                    ],

                    'renewalpolicy' =>
                        \local_ustar\route_model::RENEW_KEEP,

                    'validdays' => 0,

                    'status' =>
                        \local_ustar\route_model::STATUS_DRAFT,

                    'effectivedate' => 0,
                ],
                0
            );
        }


        upgrade_plugin_savepoint(
            true,
            2026082715,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082716) {

        $latest =
            \local_ustar\route_model::latest_version(62);

        if (
            $latest
            &&
            (int)$latest->versionno < 2
        ) {
            \local_ustar\route_model::create_version(
                62,
                [
                    'title' =>
                        'Проверка: моя должность и навыки',

                    'summary' =>
                        'Отдельная проверка понимания обязательных навыков и уровней текущей должности.',

                    'requirements' => [
                        [
                            'type' => 'native',
                            'sourcekey' =>
                                'role_skills_check',
                            'required' => true,
                            'label' =>
                                'Проверка: должность ↔ навыки',
                        ],
                    ],

                    'renewalpolicy' =>
                        \local_ustar\route_model::RENEW_KEEP,

                    'validdays' => 0,

                    'status' =>
                        \local_ustar\route_model::STATUS_DRAFT,

                    'effectivedate' => 0,
                ],
                0
            );
        }


        upgrade_plugin_savepoint(
            true,
            2026082716,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082717) {

        $latest =
            \local_ustar\route_model::latest_version(63);

        if (
            $latest
            &&
            (int)$latest->versionno < 2
        ) {
            \local_ustar\route_model::create_version(
                63,
                [
                    'title' =>
                        'Познай себя: результат профиля',

                    'summary' =>
                        'Отложенное раскрытие личного результата экспресс-профиля командного взаимодействия.',

                    'requirements' => [
                        [
                            'type' => 'native',
                            'sourcekey' =>
                                'team_profile_reveal',
                            'required' => true,
                            'label' =>
                                'Познай себя',
                        ],
                    ],

                    'renewalpolicy' =>
                        \local_ustar\route_model::RENEW_KEEP,

                    'validdays' => 0,

                    'status' =>
                        \local_ustar\route_model::STATUS_DRAFT,

                    'effectivedate' => 0,
                ],
                0
            );
        }


        upgrade_plugin_savepoint(
            true,
            2026082717,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082718) {

        \local_ustar\development_assessment::ensure_team_profile_v2(0);

        upgrade_plugin_savepoint(
            true,
            2026082718,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082719) {
        $now = time();

        /*
         * 07 — normative documents.
         * Explicit acknowledgement is mandatory for new employees.
         * Existing route progress remains valid via RENEW_KEEP.
         */
        foreach ([35, 55] as $contentid) {
            $content = $DB->get_record(
                'local_ustar_content',
                ['id' => $contentid],
                '*',
                IGNORE_MISSING
            );

            if ($content && (string)$content->status === 'published') {
                $content->ackrequired = 1;
                $content->timemodified = $now;
                $DB->update_record(
                    'local_ustar_content',
                    $content
                );
            }
        }

        /*
         * Seller-cashier DI — protected point 24.
         * Preserve ID and all historical progress.
         */
        $latest = \local_ustar\route_model::latest_version(24);

        if ($latest) {
            $reqs =
                \local_ustar\route_model::requirements_for_version(
                    $latest
                );

            $needsnew = true;

            if (count($reqs) === 1) {
                $r = reset($reqs);

                $needsnew = !(
                    (string)($r['type'] ?? '') === 'content'
                    &&
                    (int)($r['sourceid'] ?? 0) === 35
                    &&
                    (string)($r['completionmode'] ?? '') === 'ack'
                    &&
                    (string)$latest->status ===
                        \local_ustar\route_model::STATUS_PUBLISHED
                );
            }

            if ($needsnew) {
                \local_ustar\route_model::create_version(
                    24,
                    [
                        'title' =>
                            'Должностная инструкция продавца-кассира',

                        'summary' =>
                            'Обязательное ознакомление с должностной инструкцией с явным подтверждением сотрудником.',

                        'requirements' => [
                            [
                                'type' => 'content',
                                'sourceid' => 35,
                                'completionmode' => 'ack',
                                'required' => true,
                                'label' =>
                                    'Должностная инструкция продавца-кассира',
                            ],
                        ],

                        'renewalpolicy' =>
                            \local_ustar\route_model::RENEW_KEEP,

                        'validdays' => 0,

                        'status' =>
                            \local_ustar\route_model::STATUS_PUBLISHED,

                        'effectivedate' => $now,
                    ],
                    0
                );
            }
        }


        /*
         * Tovaroved DI — real existing document content55.
         */
        $latest = \local_ustar\route_model::latest_version(64);

        if ($latest) {
            \local_ustar\route_model::create_version(
                64,
                [
                    'title' =>
                        'Должностная инструкция товароведа',

                    'summary' =>
                        'Обязательное ознакомление с должностной инструкцией товароведа.',

                    'requirements' => [
                        [
                            'type' => 'content',
                            'sourceid' => 55,
                            'completionmode' => 'ack',
                            'required' => true,
                            'label' =>
                                'Должностная инструкция товароведа',
                        ],
                    ],

                    'renewalpolicy' =>
                        \local_ustar\route_model::RENEW_KEEP,

                    'validdays' => 0,

                    'status' =>
                        \local_ustar\route_model::STATUS_PUBLISHED,

                    'effectivedate' => $now,
                ],
                0
            );
        }


        /*
         * 08 — common Trade Hall regulation.
         * Keep real SCORM cm37 and optional client_service evidence.
         * Existing completions remain valid.
         */
        $latest = \local_ustar\route_model::latest_version(28);

        if ($latest) {
            $reqs =
                \local_ustar\route_model::requirements_for_version(
                    $latest
                );

            if (
                (string)$latest->title
                !== 'Регламент Торгового зала'
            ) {
                \local_ustar\route_model::create_version(
                    28,
                    [
                        'title' =>
                            'Регламент Торгового зала',

                        'summary' =>
                            'Обязательный регламент работы в Торговом зале.',

                        'requirements' => $reqs,

                        'renewalpolicy' =>
                            \local_ustar\route_model::RENEW_KEEP,

                        'validdays' => 0,

                        'status' =>
                            \local_ustar\route_model::STATUS_PUBLISHED,

                        'effectivedate' => $now,
                    ],
                    0
                );
            }
        }


        /*
         * 09 — quiz cm40.
         *
         * Moodle configuration is already canonical:
         * completionpassgrade = 1
         * gradepass = 17 / 25
         * attempts = 3
         *
         * route_model accepts states 1/2 and treats state 3 as failed.
         * Because completionpassgrade is enabled, successful completion is
         * represented by Moodle state 2.
         */
        $latest = \local_ustar\route_model::latest_version(53);

        if (
            $latest
            &&
            (string)$latest->status
                !== \local_ustar\route_model::STATUS_PUBLISHED
        ) {
            \local_ustar\route_model::create_version(
                53,
                [
                    'title' =>
                        'Аттестация: Регламент Торгового зала',

                    'summary' =>
                        'Обязательная аттестация по регламенту. Проходной результат определяется Moodle: не менее 17 из 25, максимум 3 попытки.',

                    'requirements' => [
                        [
                            'type' => 'cm',
                            'sourceid' => 40,
                            'required' => true,
                            'label' =>
                                'Аттестация по Регламенту Торгового зала',
                        ],
                    ],

                    'renewalpolicy' =>
                        \local_ustar\route_model::RENEW_KEEP,

                    'validdays' => 0,

                    'status' =>
                        \local_ustar\route_model::STATUS_PUBLISHED,

                    'effectivedate' => $now,
                ],
                0
            );
        }


        upgrade_plugin_savepoint(
            true,
            2026082719,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082720) {
        $now = time();

        /*
         * cm41 mirrors the already working cm37 completion mode.
         * route_model additionally reads actual SCORM
         * completed/passed lesson status.
         */
        $cm41 = $DB->get_record(
            'course_modules',
            ['id' => 41],
            '*',
            IGNORE_MISSING
        );

        if ($cm41) {
            $cm41->completion = 2;
            $DB->update_record(
                'course_modules',
                $cm41
            );
        }

        /*
         * Confirm ONLY scopes already proposed for the product block.
         * No new business scope is invented by this migration.
         */
        $productpositions = [
            'pos_4551dd63496af62f',
            'pos_edcafa7ab3e816d3',
            'pos_ba27695e3e0796c2',
        ];

        foreach ([69, 70] as $pointid) {
            foreach ($productpositions as $positionid) {
                if (
                    $DB->record_exists(
                        'local_ustar_route_scope',
                        [
                            'pointid' => $pointid,
                            'scopeid' => $positionid,
                            'state' => 'proposed',
                        ]
                    )
                ) {
                    $DB->set_field(
                        'local_ustar_route_scope',
                        'state',
                        'confirmed',
                        [
                            'pointid' => $pointid,
                            'scopeid' => $positionid,
                            'state' => 'proposed',
                        ]
                    );
                }
            }
        }

        /*
         * 10 — existing cm41 SCORM.
         */
        $latest =
            \local_ustar\route_model::latest_version(69);

        if (
            $latest
            && (string)$latest->status
                !== \local_ustar\route_model::STATUS_PUBLISHED
        ) {
            \local_ustar\route_model::create_version(
                69,
                [
                    'title' =>
                        'Ассортимент и товароведение',

                    'summary' =>
                        'Интерактивный товарный блок. Завершите все разделы SCORM перед аттестацией.',

                    'requirements' => [
                        [
                            'type' => 'cm',
                            'sourceid' => 41,
                            'required' => true,
                            'label' =>
                                'Ассортимент и товароведение',
                        ],
                    ],

                    'renewalpolicy' =>
                        \local_ustar\route_model::RENEW_KEEP,

                    'validdays' => 0,

                    'status' =>
                        \local_ustar\route_model::STATUS_PUBLISHED,

                    'effectivedate' => $now,
                ],
                0
            );
        }

        /*
         * 11 — native 25-question product mastery.
         */
        $latest =
            \local_ustar\route_model::latest_version(70);

        if (
            $latest
            && (string)$latest->status
                !== \local_ustar\route_model::STATUS_PUBLISHED
        ) {
            \local_ustar\route_model::create_version(
                70,
                [
                    'title' =>
                        'Мастер-Чемодан: товарная аттестация',

                    'summary' =>
                        '25 сбалансированных вопросов из актуального Каталога и существующего SCORM. Проходной результат — 80%, максимум три попытки.',

                    'requirements' => [
                        [
                            'type' => 'native',
                            'sourcekey' =>
                                'product_mastery',
                            'required' => true,
                            'label' =>
                                'Мастер-Чемодан',
                        ],
                    ],

                    'renewalpolicy' =>
                        \local_ustar\route_model::RENEW_KEEP,

                    'validdays' => 0,

                    'status' =>
                        \local_ustar\route_model::STATUS_PUBLISHED,

                    'effectivedate' => $now,
                ],
                0
            );
        }

        /*
         * Existing protected storage point moves after mastery.
         * ID, version and historical progress are untouched.
         */
        if (
            $DB->record_exists(
                'local_ustar_route_points',
                ['id' => 26]
            )
        ) {
            $DB->set_field(
                'local_ustar_route_points',
                'sortorder',
                105,
                ['id' => 26]
            );
        }

        upgrade_plugin_savepoint(
            true,
            2026082720,
            'local',
            'ustar'
        );
    }


    if ($oldversion < 2026082721) {
        $now = time();

        /* Fresh SCORM attempt for the new canonical route point. */
        if ($DB->record_exists('scorm', ['id' => 3])) {
            $DB->set_field(
                'scorm',
                'forcenewattempt',
                1,
                ['id' => 3]
            );
        }

        if ($DB->record_exists('course_modules', ['id' => 41])) {
            $DB->set_field(
                'course_modules',
                'completion',
                2,
                ['id' => 41]
            );
        }

        /*
         * Technical Moodle container for the real quiz.
         * USTAR launches cm69 directly; it is not shown on
         * the old Moodle course page.
         */
        if ($DB->record_exists('course', ['id' => 14])) {
            $DB->set_field(
                'course',
                'visible',
                1,
                ['id' => 14]
            );
        }

        if ($DB->record_exists('course_modules', ['id' => 69])) {
            $DB->set_field(
                'course_modules',
                'visible',
                1,
                ['id' => 69]
            );

            $DB->set_field(
                'course_modules',
                'visibleoncoursepage',
                0,
                ['id' => 69]
            );

            $DB->set_field(
                'course_modules',
                'completion',
                2,
                ['id' => 69]
            );

            $DB->set_field(
                'course_modules',
                'completiongradeitemnumber',
                0,
                ['id' => 69]
            );

            $DB->set_field(
                'course_modules',
                'completionpassgrade',
                1,
                ['id' => 69]
            );
        }

        /* 80% = 12 / 15. */
        if (
            $DB->record_exists(
                'grade_items',
                [
                    'itemmodule' => 'quiz',
                    'iteminstance' => 11,
                ]
            )
        ) {
            $DB->set_field(
                'grade_items',
                'gradepass',
                12,
                [
                    'itemmodule' => 'quiz',
                    'iteminstance' => 11,
                ]
            );
        }

        /*
         * 69: real SCORM + explicit employee confirmation.
         */
        \local_ustar\route_model::create_version(
            69,
            [
                'title' =>
                    'Ассортимент и товароведение',

                'summary' =>
                    'Пройдите товарный материал до конца и подтвердите завершение.',

                'requirements' => [
                    [
                        'type' => 'cm',
                        'sourceid' => 41,
                        'required' => true,
                        'label' =>
                            'Ассортимент и товароведение',
                    ],
                    [
                        'type' => 'native',
                        'sourcekey' =>
                            'product_scorm_ack',
                        'required' => true,
                        'label' =>
                            'Подтверждение завершения',
                    ],
                ],

                'renewalpolicy' =>
                    \local_ustar\route_model::RENEW_ALL,

                'validdays' => 0,

                'status' =>
                    \local_ustar\route_model::STATUS_PUBLISHED,

                'effectivedate' => $now,
            ],
            0
        );

        /*
         * 70: real existing Moodle quiz.
         */
        \local_ustar\route_model::create_version(
            70,
            [
                'title' =>
                    'Мастер-Чемодан: товарная аттестация',

                'summary' =>
                    '15 случайных вопросов из действующего банка. Автоматические ответы проверяются сразу, кейсы — HR.',

                'requirements' => [
                    [
                        'type' => 'cm',
                        'sourceid' => 69,
                        'required' => true,
                        'label' =>
                            'Товарная аттестация',
                    ],
                ],

                'renewalpolicy' =>
                    \local_ustar\route_model::RENEW_ALL,

                'validdays' => 0,

                'status' =>
                    \local_ustar\route_model::STATUS_PUBLISHED,

                'effectivedate' => $now,
            ],
            0
        );

        rebuild_course_cache(10, true);
        rebuild_course_cache(14, true);

        upgrade_plugin_savepoint(
            true,
            2026082721,
            'local',
            'ustar'
        );
    }


    /*
     * 2722 — Video Material UX.
     * No schema/data migration. Existing content/version/ACK
     * model remains authoritative.
     */
    if ($oldversion < 2026082722) {

        upgrade_plugin_savepoint(
            true,
            2026082722,
            'local',
            'ustar'
        );
    }


    /*
     * 2723 — Staffing Requests workflow.
     * Department head -> HRD decision -> employee lifecycle execution.
     */
    if ($oldversion < 2026082723) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_ustar_staff_requests');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('requesttype', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('departmentid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('positionid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('employeeid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('firstname', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('lastname', XMLDB_TYPE_CHAR, '100', null, null, null, null);
        $table->add_field('requesteddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('requestedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('reviewedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('reviewcomment', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('createduserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('reviewedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('employeeid_fk', XMLDB_KEY_FOREIGN, ['employeeid'], 'user', ['id']);
        $table->add_key('requestedby_fk', XMLDB_KEY_FOREIGN, ['requestedby'], 'user', ['id']);
        $table->add_key('reviewedby_fk', XMLDB_KEY_FOREIGN, ['reviewedby'], 'user', ['id']);
        $table->add_key('createduserid_fk', XMLDB_KEY_FOREIGN, ['createduserid'], 'user', ['id']);

        $table->add_index('status_time_idx', XMLDB_INDEX_NOTUNIQUE, ['status', 'timecreated']);
        $table->add_index('dept_status_idx', XMLDB_INDEX_NOTUNIQUE, ['departmentid', 'status']);
        $table->add_index('requester_status_idx', XMLDB_INDEX_NOTUNIQUE, ['requestedby', 'status']);
        $table->add_index('employee_status_idx', XMLDB_INDEX_NOTUNIQUE, ['employeeid', 'status']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026082723, 'local', 'ustar');
    }


    /*
     * 2724 — Canonical HR StaffPlace runtime + ACTING auto-renew marker.
     * Protected learning routes/skills/matrix are intentionally untouched.
     */
    if ($oldversion < 2026082724) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_ustar_assignments');
        $field = new xmldb_field(
            'autorenew',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'usermodified'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(
            true,
            2026082724,
            'local',
            'ustar'
        );
    }

    if ($oldversion < 2026082725) {
        // Business identity hardening. No schema changes; the version bump also
        // causes Moodle to reconcile db/tasks.php for local_ustar.
        upgrade_plugin_savepoint(true, 2026082725, 'local', 'ustar');
    }


    if ($oldversion < 2026082726) {
        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_ustar_route_testers');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sandboxuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('positionid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
        $table->add_field('lastreset', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('actorid_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['actorid'], 'user', ['id']);
        $table->add_key('sandboxuserid_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['sandboxuserid'], 'user', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_ustar_route_test_tokens');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('tokenhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sandboxuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('positionid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('expiresat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('actorid_fk', XMLDB_KEY_FOREIGN, ['actorid'], 'user', ['id']);
        $table->add_key('sandboxuserid_fk', XMLDB_KEY_FOREIGN, ['sandboxuserid'], 'user', ['id']);
        $table->add_index('tokenhash_uix', XMLDB_INDEX_UNIQUE, ['tokenhash']);
        $table->add_index('expires_idx', XMLDB_INDEX_NOTUNIQUE, ['expiresat', 'usedat']);
        $table->add_index('actor_idx', XMLDB_INDEX_NOTUNIQUE, ['actorid', 'timecreated']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026082726, 'local', 'ustar');
    }


    /*
     * 2727 — Generic assessment lifecycle.
     *
     * Business state (cycles/remediation/escalations) lives in USTAR. Moodle
     * Quiz and SCORM are adapters only. The migration seeds the one existing
     * Trade Hall assessment by semantic route structure; no employee id is
     * hard-coded and runtime state is reconciled lazily from provider facts.
     */
    if ($oldversion < 2026082727) {
        $dbman = $DB->get_manager();

        $table = new xmldb_table('local_ustar_assess_policy');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('pointid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('versionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('providerkind', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('providerref', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
        $table->add_field('attemptspercycle', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '3');
        $table->add_field('maxcycles', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '2');
        $table->add_field('remediationpointid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('cycle1escalation', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'direct_manager');
        $table->add_field('cycle2escalation', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'hrd');
        $table->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('pointid_fk', XMLDB_KEY_FOREIGN, ['pointid'], 'local_ustar_route_points', ['id']);
        $table->add_key('versionid_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['versionid'], 'local_ustar_route_versions', ['id']);
        $table->add_key('remediationpointid_fk', XMLDB_KEY_FOREIGN, ['remediationpointid'], 'local_ustar_route_points', ['id']);
        $table->add_index('point_active_idx', XMLDB_INDEX_NOTUNIQUE, ['pointid', 'active']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_ustar_assess_runtime');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('pointid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('versionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('policyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('cycle', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('attemptsused', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('failurecutoff', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('remediationpointid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('remediationversionid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('remediationstartedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('remediationcompletedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('unlockedattemptlimit', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('managerid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('managerescalatedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('hrdescalatedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('lastattemptid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('pointid_fk', XMLDB_KEY_FOREIGN, ['pointid'], 'local_ustar_route_points', ['id']);
        $table->add_key('versionid_fk', XMLDB_KEY_FOREIGN, ['versionid'], 'local_ustar_route_versions', ['id']);
        $table->add_key('policyid_fk', XMLDB_KEY_FOREIGN, ['policyid'], 'local_ustar_assess_policy', ['id']);
        $table->add_key('remediationpointid_fk', XMLDB_KEY_FOREIGN, ['remediationpointid'], 'local_ustar_route_points', ['id']);
        $table->add_key('remediationversionid_fk', XMLDB_KEY_FOREIGN, ['remediationversionid'], 'local_ustar_route_versions', ['id']);
        $table->add_key('managerid_fk', XMLDB_KEY_FOREIGN, ['managerid'], 'user', ['id']);
        $table->add_index('user_point_version_uix', XMLDB_INDEX_UNIQUE, ['userid', 'pointid', 'versionid']);
        $table->add_index('manager_status_idx', XMLDB_INDEX_NOTUNIQUE, ['managerid', 'status', 'timemodified']);
        $table->add_index('status_time_idx', XMLDB_INDEX_NOTUNIQUE, ['status', 'timemodified']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        /*
         * Seed existing Trade Hall assessment policies by logical route points.
         * This is data migration only. Runtime never infers remediation from a
         * CM id. The nearest prior repeatable learning point is stored explicitly.
         */
        $now = time();
        $assessmentpoints = $DB->get_records('local_ustar_route_points', [
            'pointkey' => 'target_tradehall_regulation_assessment',
            'active' => 1,
        ]);

        foreach ($assessmentpoints as $assessmentpoint) {
            $assessmentversion = $DB->get_record_sql(
                "SELECT *
                   FROM {local_ustar_route_versions}
                  WHERE pointid = :pointid
                    AND status = :status
                    AND (effectivedate IS NULL OR effectivedate = 0 OR effectivedate <= :now)
               ORDER BY versionno DESC, id DESC",
                [
                    'pointid' => (int)$assessmentpoint->id,
                    'status' => 'published',
                    'now' => $now,
                ],
                IGNORE_MULTIPLE
            );
            if (!$assessmentversion || $DB->record_exists('local_ustar_assess_policy', ['versionid' => (int)$assessmentversion->id])) {
                continue;
            }

            $providerref = '';
            $quizattempts = 3;
            $requirements = json_decode((string)$assessmentversion->requirementsjson, true);
            foreach (is_array($requirements) ? $requirements : [] as $requirement) {
                if ((string)($requirement['type'] ?? '') !== 'cm' || empty($requirement['required'])) {
                    continue;
                }
                $cmid = (int)($requirement['sourceid'] ?? 0);
                $cm = $DB->get_record('course_modules', ['id' => $cmid, 'deletioninprogress' => 0], 'id,module,instance', IGNORE_MISSING);
                if (!$cm) {
                    continue;
                }
                $modname = (string)$DB->get_field('modules', 'name', ['id' => (int)$cm->module]);
                if ($modname !== 'quiz') {
                    continue;
                }
                $providerref = 'cm:' . $cmid;
                $configuredattempts = (int)($DB->get_field('quiz', 'attempts', ['id' => (int)$cm->instance]) ?: 0);
                if ($configuredattempts > 0) {
                    $quizattempts = $configuredattempts;
                }
                break;
            }
            if ($providerref === '') {
                continue;
            }

            $remediationpointid = 0;
            $candidates = $DB->get_records_select(
                'local_ustar_route_points',
                'routeid = :routeid AND active = 1 AND sortorder < :sortorder',
                [
                    'routeid' => (int)$assessmentpoint->routeid,
                    'sortorder' => (int)$assessmentpoint->sortorder,
                ],
                'sortorder DESC, id DESC'
            );
            foreach ($candidates as $candidate) {
                $candidateversion = $DB->get_record_sql(
                    "SELECT *
                       FROM {local_ustar_route_versions}
                      WHERE pointid = :pointid
                        AND status = :status
                        AND (effectivedate IS NULL OR effectivedate = 0 OR effectivedate <= :now)
                   ORDER BY versionno DESC, id DESC",
                    [
                        'pointid' => (int)$candidate->id,
                        'status' => 'published',
                        'now' => $now,
                    ],
                    IGNORE_MULTIPLE
                );
                if (!$candidateversion) {
                    continue;
                }

                $repeatable = false;
                $candidateRequirements = json_decode((string)$candidateversion->requirementsjson, true);
                foreach (is_array($candidateRequirements) ? $candidateRequirements : [] as $requirement) {
                    if (empty($requirement['required'])) {
                        continue;
                    }
                    $type = (string)($requirement['type'] ?? '');
                    if (in_array($type, ['content', 'course', 'native', 'assessment'], true)) {
                        $repeatable = true;
                        break;
                    }
                    if ($type === 'cm') {
                        $cmid = (int)($requirement['sourceid'] ?? 0);
                        $cm = $DB->get_record('course_modules', ['id' => $cmid, 'deletioninprogress' => 0], 'id,module', IGNORE_MISSING);
                        if (!$cm) {
                            continue;
                        }
                        $modname = (string)$DB->get_field('modules', 'name', ['id' => (int)$cm->module]);
                        if ($modname !== 'quiz') {
                            $repeatable = true;
                            break;
                        }
                    }
                }
                if ($repeatable) {
                    $remediationpointid = (int)$candidate->id;
                    break;
                }
            }
            if ($remediationpointid <= 0) {
                continue;
            }

            $DB->insert_record('local_ustar_assess_policy', (object)[
                'pointid' => (int)$assessmentpoint->id,
                'versionid' => (int)$assessmentversion->id,
                'providerkind' => 'moodle_quiz',
                'providerref' => $providerref,
                'attemptspercycle' => max(1, $quizattempts),
                'maxcycles' => 2,
                'remediationpointid' => $remediationpointid,
                'cycle1escalation' => 'direct_manager',
                'cycle2escalation' => 'hrd',
                'active' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => 0,
            ]);
        }

        upgrade_plugin_savepoint(true, 2026082727, 'local', 'ustar');
    }


    if ($oldversion < 2026082728) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_ustar_adaptations');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('staffingrequestid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('managerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('assignmentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('positionid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('checklistkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, 'adaptation_standard');
        $table->add_field('definitionversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('startdate', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('plannedworkdays', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '10');
        $table->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('rulesjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('completedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('staffing_request_uix', XMLDB_INDEX_UNIQUE, ['staffingrequestid']);
        $table->add_index('user_status_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'status']);
        $table->add_index('manager_status_idx', XMLDB_INDEX_NOTUNIQUE, ['managerid', 'status']);
        $table->add_index('assignment_idx', XMLDB_INDEX_NOTUNIQUE, ['assignmentid']);
        if (!$dbman->table_exists($table)) { $dbman->create_table($table); }

        $submits = new xmldb_table('local_ustar_check_submits');
        $field = new xmldb_field('adaptationid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'assignmentid');
        if (!$dbman->field_exists($submits, $field)) { $dbman->add_field($submits, $field); }
        $index = new xmldb_index('adaptation_date_idx', XMLDB_INDEX_NOTUNIQUE, ['adaptationid', 'workdate']);
        if (!$dbman->index_exists($submits, $index)) { $dbman->add_index($submits, $index); }

        upgrade_plugin_savepoint(true, 2026082728, 'local', 'ustar');
    }


    if ($oldversion < 2026082729) {
        // RC26 uses the existing immutable workflow event journal for final reports,
        // escalation state and HRD decisions. No relational schema mutation required.
        upgrade_plugin_savepoint(true, 2026082729, 'local', 'ustar');
    }

return true;
}
