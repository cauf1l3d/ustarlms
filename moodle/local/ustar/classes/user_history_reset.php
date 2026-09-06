<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

final class user_history_reset {
    public static function preview(int $userid): array {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,username,firstname,lastname', MUST_EXIST);
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
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            require_once($CFG->dirroot . '/mod/scorm/locallib.php');
            require_once($CFG->dirroot . '/mod/scorm/lib.php');

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
                'reason' => 'Администратор очистил учебную историю сотрудника',
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
