<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

final class user_history_reset {
    /** Only a real site administrator may reset an employee or their own Route Tester. */
    public static function assert_allowed(int $userid, int $actorid): void {
        global $DB, $USER;
        require_capability('moodle/site:config', \context_system::instance());
        view_as::assert_writable();
        if ($actorid !== (int)$USER->id || !is_siteadmin($actorid)) {
            throw new \required_capability_exception(\context_system::instance(), 'moodle/site:config', 'nopermissions', '');
        }
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,username', MUST_EXIST);
        if ($userid <= 1 || $userid === $actorid || is_siteadmin($userid)) {
            throw new \moodle_exception('Нельзя сбросить учебную историю администратора.');
        }
        if (accounts::is_business_account($userid)) {
            return;
        }
        $tester = $DB->get_record('local_ustar_route_testers', ['actorid' => $actorid, 'sandboxuserid' => $userid]);
        if (!$tester || accounts::type_of($userid) !== accounts::TYPE_TEST
                || (string)$user->username !== route_tester::USERNAME_PREFIX . $actorid) {
            throw new \moodle_exception('Сброс разрешён для сотрудников и вашей учётной записи Route Tester.');
        }
    }

    public static function preview(int $userid): array {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $runtimeids = array_keys($DB->get_records('local_ustar_assess_runtime', ['userid' => $userid], '', 'id'));
        $workflowevents = 0;
        if ($runtimeids) {
            [$insql, $params] = $DB->get_in_or_equal($runtimeids, SQL_PARAMS_NAMED, 'rt');
            $params['etype'] = 'assessment_runtime';
            $workflowevents = $DB->count_records_select('local_ustar_workflow_events', "entitytype = :etype AND entityid {$insql}", $params);
        }
        return [
            'userid' => $userid,
            'username' => $user->username,
            'fullname' => fullname($user),
            'routeprogress' => $DB->count_records('local_ustar_route_progress', ['userid' => $userid]),
            'confirmedcycles' => $DB->count_records('local_ustar_completion_cycle', ['userid' => $userid, 'status' => 'confirmed']),
            'routerewards' => route_rewards::summary($userid)['count'],
            'assessruntime' => count($runtimeids),
            'workflowevents' => $workflowevents,
            'contentack' => $DB->count_records('local_ustar_content_ack', ['userid' => $userid]),
            'contentevents' => $DB->count_records('local_ustar_content_events', ['userid' => $userid]),
            'libraryroute' => $DB->count_records_select('local_ustar_library', 'userid = :u AND routepointid IS NOT NULL', ['u' => $userid]),
            'devassesstry' => $DB->count_records('local_ustar_dev_assess_try', ['userid' => $userid]),
            'gatedecisions' => $DB->get_manager()->table_exists('local_ustar_gate_decisions') ? $DB->count_records('local_ustar_gate_decisions', ['userid' => $userid]) : 0,
            'quizattempts' => $DB->count_records('quiz_attempts', ['userid' => $userid]),
            'quizoverrides' => $DB->count_records('quiz_overrides', ['userid' => $userid]),
            'scormattempts' => $DB->get_manager()->table_exists('scorm_attempt') ? $DB->count_records('scorm_attempt', ['userid' => $userid]) : 0,
            'cmcompletion' => $DB->count_records('course_modules_completion', ['userid' => $userid]),
            'coursecompletion' => $DB->count_records('course_completions', ['userid' => $userid]),
        ];
    }

    public static function execute(int $userid, int $actorid): array {
        global $CFG, $DB;
        self::assert_allowed($userid, $actorid);
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $DB->get_record('user', ['id' => $actorid, 'deleted' => 0], 'id', MUST_EXIST);

        $factory = \core\lock\lock_config::get_lock_factory('local_ustar');
        $lock = $factory->get_lock('user_history_reset_' . $userid, 10);
        if (!$lock) {
            throw new \RuntimeException('Could not obtain reset lock for user ' . $userid);
        }

        $before = self::preview($userid);
        $transaction = $DB->start_delegated_transaction();
        try {
            self::assert_allowed($userid, $actorid);
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            require_once($CFG->dirroot . '/mod/scorm/locallib.php');
            require_once($CFG->dirroot . '/mod/scorm/lib.php');

            // Preserve immutable completion and money history while removing their
            // active effect. The outer transaction rolls back the whole reset if
            // any credit or evidence cannot be reversed.
            foreach ($DB->get_records('local_ustar_completion_cycle', [
                    'userid' => $userid, 'status' => 'confirmed']) as $cycle) {
                $cycle->status = 'reset';
                $DB->update_record('local_ustar_completion_cycle', $cycle);
            }
            $evidence = $DB->get_records_select('local_ustar_evidence_rec',
                'userid = :userid AND (sourcekind = :cycle OR sourcekind = :progress)',
                ['userid' => $userid, 'cycle' => 'completion_cycle', 'progress' => 'route_progress']);
            foreach ($evidence as $fact) {
                if (target_core::evidence_is_valid((int)$fact->id)) {
                    target_core::append_evidence_event((int)$fact->id, 'revoked',
                        'Сброс учебного маршрута администратором', $actorid);
                }
            }
            $rewards = $DB->get_records_select('local_ustar_coin_ledger',
                'userid = :userid AND txtype = :reward AND amount > 0'
                . ' AND (sourcekind = :cycle OR sourcekind = :progress)',
                ['userid' => $userid, 'reward' => 'route_reward',
                    'cycle' => 'completion_cycle', 'progress' => 'route_progress']);
            foreach ($rewards as $reward) {
                economy::revoke_credit((int)$reward->id, 'reset-route-reward:' . $reward->id,
                    'Возврат награды при сбросе учебного маршрута', $actorid);
            }

            foreach ($DB->get_records('quiz_attempts', ['userid' => $userid], 'id ASC') as $attempt) {
                $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
                quiz_delete_attempt($attempt, $quiz);
            }
            $DB->delete_records('quiz_overrides', ['userid' => $userid]);

            if ($DB->get_manager()->table_exists('scorm_attempt')) {
                $affectedscorms = [];
                foreach ($DB->get_records('scorm_attempt', ['userid' => $userid], 'id ASC') as $attempt) {
                    $scorm = $DB->get_record('scorm', ['id' => $attempt->scormid], '*', MUST_EXIST);
                    $affectedscorms[$scorm->id] = $scorm;
                    scorm_delete_attempt($userid, $scorm, $attempt);
                }
                foreach ($affectedscorms as $scorm) {
                    scorm_update_grades($scorm, $userid, true);
                }
            }

            $DB->delete_records('course_modules_completion', ['userid' => $userid]);
            if ($DB->get_manager()->table_exists('course_modules_viewed')) {
                $DB->delete_records('course_modules_viewed', ['userid' => $userid]);
            }
            if ($DB->get_manager()->table_exists('course_completion_crit_compl')) {
                $DB->delete_records('course_completion_crit_compl', ['userid' => $userid]);
            }
            $DB->delete_records('course_completions', ['userid' => $userid]);

            $runtimeids = array_keys($DB->get_records('local_ustar_assess_runtime', ['userid' => $userid], '', 'id'));
            if ($runtimeids) {
                [$insql, $params] = $DB->get_in_or_equal($runtimeids, SQL_PARAMS_NAMED, 'rt');
                $params['etype'] = 'assessment_runtime';
                $DB->delete_records_select('local_ustar_workflow_events', "entitytype = :etype AND entityid {$insql}", $params);
            }

            $DB->delete_records('local_ustar_workflow_events', ['entitytype' => 'route_native', 'actorid' => $userid]);

            $DB->delete_records('local_ustar_assess_runtime', ['userid' => $userid]);
            $DB->delete_records('local_ustar_route_progress', ['userid' => $userid]);
            $DB->delete_records('local_ustar_content_ack', ['userid' => $userid]);
            $DB->delete_records('local_ustar_content_events', ['userid' => $userid]);
            $DB->delete_records('local_ustar_dev_assess_try', ['userid' => $userid]);
            $DB->delete_records_select('local_ustar_library', 'userid = :u AND routepointid IS NOT NULL', ['u' => $userid]);
            if ($DB->get_manager()->table_exists('local_ustar_gate_decisions')) {
                $DB->delete_records('local_ustar_gate_decisions', ['userid' => $userid]);
            }

            $DB->insert_record('local_ustar_workflow_events', (object)[
                'entitytype' => 'user_history_reset',
                'entityid' => $userid,
                'eventtype' => 'admin_history_reset',
                'actorid' => $actorid,
                'reason' => 'Администратор сбросил учебный маршрут и связанные награды',
                'detailsjson' => json_encode(['userid' => $userid, 'username' => $user->username, 'before' => $before], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timecreated' => time(),
            ]);

            $after = self::preview($userid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        } finally {
            $lock->release();
        }

        try {
            purge_all_caches();
        } catch (\Throwable $e) {
            debugging('USTAR history reset cache purge failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return ['before' => $before, 'after' => $after];
    }
}
