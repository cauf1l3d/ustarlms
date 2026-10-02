<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Isolated end-to-end route tester.
 *
 * The real administrator stays logged in on the primary host. A TYPE_TEST
 * sandbox identity is opened on a dedicated host with a distinct Moodle
 * session cookie. This allows real Quiz/SCORM/completion/runtime writes
 * without making the administrator or sandbox part of workforce metrics.
 */
final class route_tester {
    public const USERNAME_PREFIX = 'ustar_rt_';
    private const TOKEN_TTL = 120;

    public static function can_use(?int $userid = null): bool {
        global $USER;
        $userid = $userid ?? (int)$USER->id;
        return $userid > 0 && is_siteadmin($userid);
    }

    public static function primary_url(): string {
        global $CFG;
        return rtrim((string)($CFG->ustar_primary_wwwroot ?? $CFG->wwwroot), '/');
    }

    public static function tester_url(): string {
        global $CFG;
        $url = trim((string)($CFG->ustar_route_tester_wwwroot ?? ''));
        if ($url === '') {
            throw new \moodle_exception('USTAR Route Tester host is not configured');
        }
        return rtrim($url, '/');
    }

    private static function request_host(): string {
        $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        if ($forwarded !== '') {
            $forwarded = trim(explode(',', $forwarded)[0]);
        }
        $host = $forwarded !== '' ? $forwarded : trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        return strtolower((string)preg_replace('/:\\d+$/', '', $host));
    }

    public static function is_tester_host(): bool {
        $host = (string)parse_url(self::tester_url(), PHP_URL_HOST);
        return $host !== '' && self::request_host() === strtolower($host);
    }

    public static function assert_tester_host(): void {
        if (!self::is_tester_host()) {
            throw new \moodle_exception('Route Tester entry is allowed only on the isolated tester host');
        }
    }

    /** @return array{position:array,department:array|null} */
    public static function position_context(string $positionid): array {
        $positionid = trim($positionid);
        $structure = structure::get(structure::NAME_STRUCTURE);
        $position = null;
        foreach ($structure['positions'] ?? [] as $candidate) {
            if ((string)($candidate['id'] ?? '') === $positionid) {
                $position = $candidate;
                break;
            }
        }
        if (!$position) {
            throw new \invalid_parameter_exception('Должность USTAR не найдена');
        }
        $department = null;
        foreach ($structure['departments'] ?? [] as $candidate) {
            if ((string)($candidate['id'] ?? '') === (string)($position['department'] ?? '')) {
                $department = $candidate;
                break;
            }
        }
        return ['position' => $position, 'department' => $department];
    }

    private static function sandbox_username(int $actorid): string {
        return self::USERNAME_PREFIX . $actorid;
    }

    private static function valid_sandbox_user(int $userid, int $actorid): ?\stdClass {
        global $DB;
        if ($userid <= 0) {
            return null;
        }
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$user || is_siteadmin($user)) {
            return null;
        }
        if ((string)$user->username !== self::sandbox_username($actorid)) {
            return null;
        }
        if (accounts::type_of((int)$user->id) !== accounts::TYPE_TEST) {
            return null;
        }
        return $user;
    }

    /** Ensure one reusable sandbox identity for the real site administrator. */
    public static function ensure_sandbox(int $actorid): int {
        global $CFG, $DB;
        if (!self::can_use($actorid)) {
            throw new \required_capability_exception(\context_system::instance(), 'moodle/site:config', 'nopermissions', '');
        }

        $map = $DB->get_record('local_ustar_route_testers', ['actorid' => $actorid], '*', IGNORE_MISSING);
        if ($map) {
            $valid = self::valid_sandbox_user((int)$map->sandboxuserid, $actorid);
            if ($valid) {
                return (int)$valid->id;
            }
        }

        $username = self::sandbox_username($actorid);
        $existing = $DB->get_record('user', [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ], '*', IGNORE_MISSING);

        if ($existing && !is_siteadmin($existing)) {
            $sandboxid = (int)$existing->id;
            accounts::set_type($sandboxid, accounts::TYPE_TEST);
        } else {
            require_once($CFG->dirroot . '/user/lib.php');
            $user = (object)[
                'auth' => 'manual',
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'username' => $username,
                'password' => bin2hex(random_bytes(24)),
                'firstname' => 'Тестер маршрутов',
                'lastname' => 'USTAR',
                'email' => 'ustar-route-tester-' . $actorid . '@example.invalid',
                'emailstop' => 1,
                'maildisplay' => 0,
                'suspended' => 0,
            ];
            $sandboxid = (int)user_create_user($user, true, false);
            set_user_preference('auth_forcepasswordchange', 0, $sandboxid);
            accounts::set_type($sandboxid, accounts::TYPE_TEST);
        }

        $now = time();
        if ($map) {
            $map->sandboxuserid = $sandboxid;
            $map->positionid = '';
            $map->lastreset = 0;
            $map->timemodified = $now;
            $DB->update_record('local_ustar_route_testers', $map);
        } else {
            $DB->insert_record('local_ustar_route_testers', (object)[
                'actorid' => $actorid,
                'sandboxuserid' => $sandboxid,
                'positionid' => '',
                'lastreset' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        return $sandboxid;
    }

    private static function assert_sandbox(int $userid): \stdClass {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        if (is_siteadmin($user) || accounts::type_of($userid) !== accounts::TYPE_TEST ||
            !str_starts_with((string)$user->username, self::USERNAME_PREFIX)) {
            throw new \coding_exception('Route Tester reset refused for non-sandbox identity');
        }
        return $user;
    }

    private static function table_exists(string $table): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table($table));
    }

    /** Delete child rows by parent ids if both table and key are present. */
    private static function delete_children(string $table, string $field, array $ids): void {
        global $DB;
        if (!$ids || !self::table_exists($table)) {
            return;
        }
        $columns = $DB->get_columns($table);
        if (!isset($columns[$field])) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values(array_map('intval', $ids)), SQL_PARAMS_NAMED, 'rt');
        $DB->delete_records_select($table, $field . ' ' . $insql, $params);
    }

    /** Reset USTAR-owned learner state for a guarded TYPE_TEST account. */
    private static function reset_ustar_state(int $userid): void {
        global $DB;

        $runids = self::table_exists('local_ustar_check_runs')
            ? array_keys($DB->get_records('local_ustar_check_runs', ['userid' => $userid], '', 'id')) : [];
        self::delete_children('local_ustar_check_answers', 'runid', $runids);

        $notificationids = self::table_exists('local_ustar_notifications')
            ? array_keys($DB->get_records('local_ustar_notifications', ['userid' => $userid], '', 'id')) : [];
        self::delete_children('local_ustar_notify_delivery', 'notificationid', $notificationids);

        $attemptids = self::table_exists('local_ustar_test_attempts')
            ? array_keys($DB->get_records('local_ustar_test_attempts', ['userid' => $userid], '', 'id')) : [];
        self::delete_children('local_ustar_test_answers', 'attemptid', $attemptids);

        $participantids = self::table_exists('local_ustar_comp_participants')
            ? array_keys($DB->get_records('local_ustar_comp_participants', ['userid' => $userid], '', 'id')) : [];
        self::delete_children('local_ustar_comp_score_events', 'participantid', $participantids);
        self::delete_children('local_ustar_comp_results', 'participantid', $participantids);

        $useridtables = [
            'local_ustar_route_progress', 'local_ustar_goals', 'local_ustar_game_attempts',
            'local_ustar_game_mastery', 'local_ustar_check_runs', 'local_ustar_content_ack',
            'local_ustar_library', 'local_ustar_evidence_rec', 'local_ustar_gate_decisions',
            'local_ustar_check_submits', 'local_ustar_official_tasks', 'local_ustar_personal_tasks',
            'local_ustar_notifications', 'local_ustar_dev_assess_try', 'local_ustar_test_attempts',
            'local_ustar_test_results', 'local_ustar_coin_accounts', 'local_ustar_coin_balance',
            'local_ustar_comp_scores', 'local_ustar_comp_participants',
        ];
        foreach ($useridtables as $table) {
            if (!self::table_exists($table)) {
                continue;
            }
            $columns = $DB->get_columns($table);
            if (isset($columns['userid'])) {
                $DB->delete_records($table, ['userid' => $userid]);
            }
        }

        // Tables where the sandbox may be either target or actor.
        $multifields = [
            'local_ustar_content_events' => ['userid', 'actorid'],
            'local_ustar_reviews' => ['userid', 'reviewerid'],
            'local_ustar_coin_ledger' => ['userid', 'actorid'],
            'local_ustar_hr_actions' => ['targetuserid', 'actorid'],
            'local_ustar_staff_requests' => ['employeeid', 'requestedby', 'reviewedby', 'createduserid'],
            'local_ustar_route_test_tokens' => ['sandboxuserid'],
        ];
        foreach ($multifields as $table => $fields) {
            if (!self::table_exists($table)) {
                continue;
            }
            $columns = $DB->get_columns($table);
            foreach ($fields as $field) {
                if (isset($columns[$field])) {
                    $DB->delete_records($table, [$field => $userid]);
                }
            }
        }
    }

    /** Reset Moodle completion, attempts, grades and enrolments for sandbox. */
    private static function reset_moodle_state(int $userid): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        // Quiz API deletes question usage safely.
        if ($DB->get_manager()->table_exists(new \xmldb_table('quiz_attempts'))) {
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            $attempts = $DB->get_records('quiz_attempts', ['userid' => $userid], 'id ASC');
            foreach ($attempts as $attempt) {
                $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', IGNORE_MISSING);
                if ($quiz && function_exists('quiz_delete_attempt')) {
                    quiz_delete_attempt($attempt, $quiz);
                }
            }
            $DB->delete_records('quiz_grades', ['userid' => $userid]);
        }

        foreach ([
            'scorm_scoes_track', 'course_modules_completion', 'course_completions',
            'course_completion_crit_compl', 'grade_grades', 'grade_grades_history',
            'badge_issued', 'user_lastaccess',
        ] as $table) {
            if ($DB->get_manager()->table_exists(new \xmldb_table($table))) {
                $columns = $DB->get_columns($table);
                if (isset($columns['userid'])) {
                    $DB->delete_records($table, ['userid' => $userid]);
                }
            }
        }

        // Remove prior-position enrolments through their enrol plugins.
        $sql = "SELECT ue.id AS ueid, e.*
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :uid";
        foreach ($DB->get_records_sql($sql, ['uid' => $userid]) as $instance) {
            $plugin = enrol_get_plugin((string)$instance->enrol);
            if ($plugin) {
                try {
                    $plugin->unenrol_user($instance, $userid);
                } catch (\Throwable $e) {
                    // A test reset must continue; stale enrolment is removed below only for this guarded sandbox.
                }
            }
        }
        $enrolids = array_keys($DB->get_records('user_enrolments', ['userid' => $userid], '', 'id'));
        if ($enrolids) {
            $DB->delete_records('user_enrolments', ['userid' => $userid]);
        }
    }

    public static function reset_sandbox(int $userid, int $actorid): array {
        global $DB;
        if (!self::can_use($actorid)) {
            throw new \required_capability_exception(\context_system::instance(), 'moodle/site:config', 'nopermissions', '');
        }
        self::assert_sandbox($userid);
        $transaction = $DB->start_delegated_transaction();
        self::reset_ustar_state($userid);
        self::reset_moodle_state($userid);
        $transaction->allow_commit();

        $now = time();
        $map = $DB->get_record('local_ustar_route_testers', ['actorid' => $actorid], '*', IGNORE_MISSING);
        if ($map && (int)$map->sandboxuserid === $userid) {
            $map->lastreset = $now;
            $map->timemodified = $now;
            $DB->update_record('local_ustar_route_testers', $map);
        }
        return ['ok' => true, 'userid' => $userid, 'lastreset' => $now];
    }

    /** Ensure the sandbox has learner access without granting HR/manager/business capabilities. */
    private static function ensure_safe_learner_role(int $userid): array {
        global $DB;
        $context = \context_system::instance();
        if (has_capability('local/ustar:use', $context, $userid)) {
            return ['needed' => false, 'roleid' => 0];
        }

        $dangerous = [
            'local/ustar:admin', 'local/ustar:hr', 'local/ustar:hrmanage',
            'local/ustar:executive', 'local/ustar:viewteam', 'local/ustar:viewas',
        ];
        foreach ($dangerous as $capability) {
            if (has_capability($capability, $context, $userid)) {
                throw new \moodle_exception('Route Tester sandbox unexpectedly has privileged capability: ' . $capability);
            }
        }
        $sql = "SELECT DISTINCT r.id, r.shortname
                  FROM {role} r
                  JOIN {role_capabilities} rc ON rc.roleid = r.id
                 WHERE rc.contextid = :ctx
                   AND rc.capability = :cap
                   AND rc.permission > 0
              ORDER BY r.id";
        foreach ($DB->get_records_sql($sql, ['ctx' => $context->id, 'cap' => 'local/ustar:use']) as $role) {
            [$insql, $params] = $DB->get_in_or_equal($dangerous, SQL_PARAMS_NAMED, 'cap');
            $params['roleid'] = (int)$role->id;
            $params['ctx'] = $context->id;
            $hasdanger = $DB->record_exists_select(
                'role_capabilities',
                'roleid = :roleid AND contextid = :ctx AND capability ' . $insql . ' AND permission > 0',
                $params
            );
            if ($hasdanger) {
                continue;
            }
            role_assign((int)$role->id, $userid, $context->id, 'local_ustar', 0);
            if (has_capability('local/ustar:use', $context, $userid)) {
                return ['needed' => true, 'roleid' => (int)$role->id, 'shortname' => (string)$role->shortname];
            }
        }
        throw new \moodle_exception('No safe learner role grants local/ustar:use for Route Tester sandbox');
    }

    /** Configure sandbox as the selected position and provision only learner access. */
    public static function configure_sandbox(int $userid, int $actorid, string $positionid): array {
        global $DB;
        self::assert_sandbox($userid);
        $ctx = self::position_context($positionid);

        accounts::set_type($userid, accounts::TYPE_TEST);
        people::set_position_id($userid, $positionid);

        // Deliberately do NOT call position_access::sync_user(): a test of an HR/head
        // position must never gain live HR/staffing/manager powers over real employees.
        $access = self::ensure_safe_learner_role($userid);
        $learning = assignment::sync_user($userid);

        $map = $DB->get_record('local_ustar_route_testers', ['actorid' => $actorid], '*', MUST_EXIST);
        $map->sandboxuserid = $userid;
        $map->positionid = $positionid;
        $map->timemodified = time();
        $DB->update_record('local_ustar_route_testers', $map);

        return ['context' => $ctx, 'access' => $access, 'learning' => $learning];
    }

    /** Create a short-lived one-time bridge token for the isolated host. */
    public static function issue_token(int $actorid, int $sandboxuserid, string $positionid): string {
        global $DB;
        if (!self::can_use($actorid)) {
            throw new \required_capability_exception(\context_system::instance(), 'moodle/site:config', 'nopermissions', '');
        }
        self::assert_sandbox($sandboxuserid);
        self::position_context($positionid);

        $now = time();
        $DB->delete_records_select(
            'local_ustar_route_test_tokens',
            'expiresat < :cutoff OR usedat > 0',
            ['cutoff' => $now - HOURSECS]
        );

        $raw = bin2hex(random_bytes(32));
        $DB->insert_record('local_ustar_route_test_tokens', (object)[
            'tokenhash' => hash('sha256', $raw),
            'actorid' => $actorid,
            'sandboxuserid' => $sandboxuserid,
            'positionid' => $positionid,
            'expiresat' => $now + self::TOKEN_TTL,
            'usedat' => 0,
            'timecreated' => $now,
        ]);
        return $raw;
    }

    /** @return array{actorid:int,sandboxuserid:int,positionid:string} */
    public static function consume_token(string $raw): array {
        global $DB;
        self::assert_tester_host();
        if (!preg_match('/^[a-f0-9]{64}$/', $raw)) {
            throw new \invalid_parameter_exception('Некорректный токен Route Tester');
        }
        $hash = hash('sha256', $raw);
        $transaction = $DB->start_delegated_transaction();
        $row = $DB->get_record('local_ustar_route_test_tokens', ['tokenhash' => $hash], '*', MUST_EXIST);
        $now = time();
        if ((int)$row->usedat > 0 || (int)$row->expiresat < $now) {
            throw new \moodle_exception('Route Tester token expired or was already used');
        }
        if (!self::can_use((int)$row->actorid)) {
            throw new \moodle_exception('Route Tester actor is no longer a site administrator');
        }
        self::assert_sandbox((int)$row->sandboxuserid);
        self::position_context((string)$row->positionid);
        $row->usedat = $now;
        $DB->update_record('local_ustar_route_test_tokens', $row);
        $transaction->allow_commit();
        return [
            'actorid' => (int)$row->actorid,
            'sandboxuserid' => (int)$row->sandboxuserid,
            'positionid' => (string)$row->positionid,
        ];
    }

    public static function launch_url(string $token): string {
        return self::tester_url() . '/local/ustar/route_tester_enter.php?token=' . rawurlencode($token);
    }

    public static function active(): bool {
        global $SESSION, $USER;
        if (!self::is_tester_host() || !\core\session\manager::is_loggedinas()) {
            return false;
        }
        if (empty($SESSION->ustar_route_tester) || empty($SESSION->ustar_route_tester['active'])) {
            return false;
        }
        return accounts::type_of((int)$USER->id) === accounts::TYPE_TEST;
    }

    public static function banner_html(): string {
        global $SESSION;
        if (!self::active()) {
            return '';
        }
        $meta = (array)$SESSION->ustar_route_tester;
        $position = s((string)($meta['positionname'] ?? 'Тестовая должность'));
        $department = s((string)($meta['departmentname'] ?? ''));
        $exit = new \moodle_url('/local/ustar/route_tester_exit.php', ['sesskey' => sesskey()]);
        $label = $department !== '' ? $department . ' · ' . $position : $position;
        return '<style id="ustar-route-tester-banner-style">body{padding-bottom:54px!important}.u-route-tester-banner{position:fixed;z-index:100000;left:0;right:0;bottom:0;min-height:46px;background:#171717;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:8px 18px;font:600 14px/1.25 system-ui,-apple-system,Segoe UI,sans-serif;box-shadow:0 -3px 18px rgba(0,0,0,.22)}.u-route-tester-banner strong{letter-spacing:.04em}.u-route-tester-banner a{color:#fff;border:1px solid rgba(255,255,255,.6);border-radius:8px;padding:6px 10px;text-decoration:none;white-space:nowrap}</style>'
            . '<div class="u-route-tester-banner"><span><strong>ТЕСТОВЫЙ РЕЖИМ</strong> · ' . $label . '</span><a href="' . s($exit->out(false)) . '">Вернуться разработчиком</a></div>';
    }

    /** Status for the primary control screen. */
    public static function status(int $actorid): array {
        global $DB;
        $map = $DB->get_record('local_ustar_route_testers', ['actorid' => $actorid], '*', IGNORE_MISSING);
        if (!$map) {
            return ['exists' => false];
        }
        $user = self::valid_sandbox_user((int)$map->sandboxuserid, $actorid);
        return [
            'exists' => (bool)$user,
            'userid' => $user ? (int)$user->id : 0,
            'username' => $user ? (string)$user->username : '',
            'positionid' => (string)$map->positionid,
            'lastreset' => (int)$map->lastreset,
        ];
    }
}
