<?php
namespace local_ustar\task;

defined('MOODLE_INTERNAL') || die();

use local_ustar\assignment;

/**
 * Reconcile current USTAR position requirements with Moodle enrolments.
 *
 * Immediate HR mutations call assignment::sync_user() directly.
 * This task is the repair/reconciliation layer.
 *
 * Core v1 only adds missing required enrolments.
 * It never deletes historical completion or previous enrolments.
 */
class sync_enrolments extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('taskssync', 'local_ustar');
    }


    public function execute() {
        global $DB;
        // Cursor bounds the cost of one run; every cycle restarts at the
        // beginning so missed HR changes are eventually reconciled.
        $cursor = max(0, (int)get_config('local_ustar', 'enrol_sync_cursor'));
        $limit = 200;
        $users = $DB->get_records_select('user',
            'id > :cursor AND id > 1 AND deleted = 0 AND suspended = 0',
            ['cursor' => $cursor], 'id ASC', 'id,username', 0, $limit);

        $processed = 0;
        $enrolled = 0;
        $missingmanual = 0;
        $errors = 0;


        foreach ($users as $user) {
            $cursor = (int)$user->id;
            try {
                if (!\local_ustar\accounts::is_business_account($cursor)
                        || !\local_ustar\accounts::participates($cursor)
                        || !\local_ustar\employment::learning_allowed($cursor)) {
                    continue;
                }

                $result =
                    assignment::sync_user(
                        (int)$user->id
                    );

                $processed++;

                foreach (
                    $result['enrolled']
                    ?? []
                    as $course
                ) {

                    $enrolled++;

                    mtrace(
                        "USTAR: enrolled user "
                        . $user->id
                        . " ({$user->username})"
                        . " into course "
                        . $course['id']
                        . " ({$course['name']})"
                    );
                }


                foreach (
                    $result['missingManualInstance']
                    ?? []
                    as $course
                ) {

                    $missingmanual++;

                    mtrace(
                        "USTAR WARNING: course "
                        . $course['id']
                        . " ({$course['name']})"
                        . " has no enabled manual enrolment instance"
                    );
                }

            } catch (\Throwable $e) {

                $errors++;

                mtrace(
                    "USTAR ERROR: user "
                    . $user->id
                    . " ({$user->username}): "
                    . $e->getMessage()
                );
            }
        }

        set_config('enrol_sync_cursor', count($users) < $limit ? 0 : $cursor, 'local_ustar');


        mtrace(
            "USTAR reconciliation complete: "
            . "processed={$processed}, "
            . "enrolled={$enrolled}, "
            . "missingmanual={$missingmanual}, "
            . "errors={$errors}"
            . ", cursor=" . (count($users) < $limit ? 0 : $cursor)
        );
    }
}
