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

        $sql = "
            SELECT
                u.id,
                u.username,
                TRIM(d.data) AS positionid
            FROM {user} u
            JOIN {user_info_data} d
              ON d.userid = u.id
            JOIN {user_info_field} f
              ON f.id = d.fieldid
             AND f.shortname = 'ustar_position'
            WHERE u.deleted = 0
              AND u.suspended = 0
              AND TRIM(d.data) <> ''
            ORDER BY u.id
        ";

        $users = $DB->get_records_sql($sql);

        $processed = 0;
        $enrolled = 0;
        $missingmanual = 0;
        $errors = 0;


        foreach ($users as $user) {

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
