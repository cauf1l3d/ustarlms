<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Idempotent writes into the existing Academy notification store. */
final class workflow_notifications {
    public static function enqueue(int $userid, string $eventtype, string $subject,
            string $message, string $url, string $key): void {
        global $DB;
        if ($userid <= 0) { throw new \invalid_parameter_exception('Notification requires a recipient'); }
        $key = \core_text::substr(trim($key), 0, 128);
        if ($key === '' || trim($eventtype) === '' || trim($subject) === '') {
            throw new \invalid_parameter_exception('Notification key, event and subject are required');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('workflow-notification:' . sha1($key), 10);
        if (!$lock) { throw new \moodle_exception('Notification lock timeout'); }
        try {
            $existing = $DB->get_record('local_ustar_notifications', ['idempotencykey' => $key]);
            if ($existing) {
                if ((int)$existing->userid !== $userid || $existing->eventtype !== $eventtype) {
                    throw new \coding_exception('Notification key reused for another event or recipient');
                }
                return;
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                $now = time();
                $notificationid = (int)$DB->insert_record('local_ustar_notifications', (object)[
                    'userid' => $userid, 'severity' => 'normal', 'eventtype' => $eventtype,
                    'subject' => $subject, 'message' => $message, 'actionurl' => $url, 'dueat' => null,
                    'status' => 'unread', 'idempotencykey' => $key, 'ackat' => null,
                    'timecreated' => $now, 'timemodified' => $now,
                ]);
                $DB->insert_record('local_ustar_notify_delivery', (object)[
                    'notificationid' => $notificationid, 'channel' => 'ustar',
                    'status' => 'delivered', 'attempts' => 1, 'nextattempt' => null,
                    'providerref' => null, 'lasterror' => null,
                    'timecreated' => $now, 'timemodified' => $now,
                ]);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            // Storage errors must reach the enclosing business transaction.
            // Never swallow all dml_write_exception as successful redelivery.
            $lock->release();
        }
    }
}
