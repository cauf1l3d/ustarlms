<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** A failed transport after COMMIT is not a failed grade write. */
final class grading_commit {
    public static function finish($transaction, callable $verify): bool {
        global $DB;
        try {
            $transaction->allow_commit();
            return false;
        } catch (\Throwable $error) {
            // Never swallow a SQL, rollback, validation or unrelated observer error.
            if ($DB->is_transaction_started()
                    || !$error instanceof \moodle_exception
                    || !in_array((string)$error->errorcode, ['Message was not sent.', 'Message was not sent'], true)
                    || !$verify()) {
                throw $error;
            }
            debugging('USTAR grade committed; notification delivery failed: ' . $error->getMessage(), DEBUG_DEVELOPER);
            return true;
        }
    }
}
