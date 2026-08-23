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


    return true;
}
