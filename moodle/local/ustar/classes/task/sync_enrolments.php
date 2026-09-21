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

        // Effective manager occupancy can change when ACTING expires.
        $reportingtx = $DB->start_delegated_transaction();
        try {
            \local_ustar\organization_model::rebuild_reporting();
            $reportingtx->allow_commit();
        } catch (\Throwable $e) {
            $reportingtx->rollback($e);
        }
        // Bounded recovery of saved progress whose reward could not be committed.
        \local_ustar\route_rewards::reconcile(200);
        $users = \local_ustar\organization_directory::users(true);

        $processed = 0;
        $enrolled = 0;
        $missingmanual = 0;
        $errors = 0;


        foreach ($users as $user) {

            if (!\local_ustar\employment::learning_allowed((int)$user->id)) {
                continue;
            }

            try {

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


        mtrace(
            "USTAR reconciliation complete: "
            . "processed={$processed}, "
            . "enrolled={$enrolled}, "
            . "missingmanual={$missingmanual}, "
            . "errors={$errors}"
        );
    }
}
