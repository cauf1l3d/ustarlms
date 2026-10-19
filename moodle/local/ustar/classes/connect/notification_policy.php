<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Explicit canonical event map; raw titles, messages, metadata and URLs never leave Moodle. */
final class notification_policy {
    private const EVENTS = [
        'registration_requested' => 'registration', 'registration_approved' => 'registration',
        'registration_rejected' => 'registration', 'registration_adaptation_ready' => 'registration',
        'task_assigned' => 'tasks', 'task_revised' => 'tasks', 'task_reassigned' => 'tasks',
        'task_started' => 'tasks', 'task_sent_for_review' => 'tasks', 'task_completed' => 'tasks',
        'task_approved' => 'tasks', 'task_returned' => 'tasks', 'task_cancelled' => 'tasks',
        'task_reminder' => 'tasks', 'task_escalation' => 'tasks',
        'feed_comment' => 'feed', 'feed_reply' => 'feed',
        'grade_request' => 'grades', 'grade_approved' => 'grades', 'grade_returned' => 'grades',
    ];
    public static function category(string $event): ?string { return self::EVENTS[$event] ?? null; }
    public static function ttl(string $event): int {
        return in_array($event, ['task_reminder', 'feed_comment', 'feed_reply'], true) ? HOURSECS : DAYSECS;
    }
    public static function permitted(\stdClass $notice): bool {
        global $DB;
        $uid = (int)$notice->userid; $event = (string)$notice->eventtype; $key = (string)$notice->idempotencykey;
        if (!self::category($event) || $notice->status !== 'unread'
                || !$DB->record_exists('user', ['id' => $uid, 'deleted' => 0, 'suspended' => 0, 'confirmed' => 1])
                || !\local_ustar\accounts::is_business_account($uid)) { return false; }
        $employment = \local_ustar\employment::resolve($uid)['status'];
        if ($employment !== \local_ustar\employment::ACTIVE
                && !($event === 'registration_rejected' && $employment === \local_ustar\employment::PENDING)) { return false; }
        $ctx = \context_system::instance();
        if (self::category($event) === 'registration') {
            if (!preg_match('/\Aregistration-(requested|approved|rejected|adaptation-ready):([1-9][0-9]*)(?::([1-9][0-9]*))?\z/', $key, $m)) { return false; }
            $r = $DB->get_record('local_ustar_staff_requests', ['id' => (int)$m[2], 'requesttype' => 'registration']);
            if (!$r) { return false; }
            if ($event === 'registration_requested') {
                return $m[1] === 'requested' && (int)($m[3] ?? 0) === $uid && $r->status === 'pending'
                    && has_capability('local/ustar:approveregistration', $ctx, $uid)
                    && has_capability('local/ustar:hrmanage', $ctx, $uid);
            }
            if ($event === 'registration_adaptation_ready') {
                return $m[1] === 'adaptation-ready' && $r->status === 'approved' && (int)($m[3] ?? 0) === $uid
                    && self::manager((int)$r->employeeid) === $uid && (int)$r->employeeid !== $uid
                    && has_capability('local/ustar:viewteam', $ctx, $uid);
            }
            $decision = substr($event, strlen('registration_'));
            return $m[1] === $decision && $r->status === $decision && (int)$r->employeeid === $uid;
        }
        if (self::category($event) === 'grades') {
            if (!preg_match('/\Agrade-(request|decision):([1-9][0-9]*)(?::([1-9][0-9]*))?\z/', $key, $m)) { return false; }
            $r = $DB->get_record('local_ustar_grade_requests', ['id' => (int)$m[2]]);
            if (!$r) { return false; }
            if ($event === 'grade_request') {
                return $m[1] === 'request' && $r->status === 'pending' && self::manager((int)$r->userid) === $uid
                    && $uid !== (int)$r->userid && has_capability('local/ustar:viewteam', $ctx, $uid);
            }
            return $m[1] === 'decision' && (int)$r->userid === $uid
                && $r->status === ($event === 'grade_approved' ? 'approved' : 'rejected');
        }
        if (self::category($event) === 'feed') {
            if (!preg_match('/\A'.preg_quote($event, '/').':([1-9][0-9]*):'.$uid.':[0-9]+\z/', $key, $m)) { return false; }
            try { \local_ustar\feed_access::readable((int)$m[1], $uid); return true; }
            catch (\required_capability_exception $error) { return false; }
        }
        $taskid = 0;
        if (preg_match('/\Atask-(?:assigned|revised|reassigned-old|event|review):([1-9][0-9]*)(?::[A-Za-z0-9_]+)*\z/', $key, $m)) {
            $taskid = (int)$m[1];
        } else if (in_array($event, ['task_reminder', 'task_escalation'], true)) {
            // Parse a fixed native route; never fetch or return the stored URL.
            $parts = parse_url((string)$notice->actionurl); $query = [];
            if (($parts['path'] ?? '') !== '/local/ustar/tasks.php') { return false; }
            parse_str($parts['query'] ?? '', $query);
            if (!is_string($query['taskid'] ?? null) || !preg_match('/\A[1-9][0-9]*\z/', $query['taskid'])) { return false; }
            $taskid = (int)$query['taskid'];
        }
        $task = $taskid ? $DB->get_record('local_ustar_learning_tasks', ['id' => $taskid]) : false;
        if (!$task || !\local_ustar\task_workspace\service::can_view_record($task, $uid)) { return false; }
        if (in_array($event, ['task_reminder', 'task_escalation'], true)
                && in_array($task->status, ['completed', 'cancelled'], true)) { return false; }
        if (in_array($event, ['task_reminder', 'task_escalation'], true)) {
            return \local_ustar\task_workspace\worker::notification_recipient_is_current($task, $event, $key, $uid);
        }
        if ($event === 'task_sent_for_review') { return \local_ustar\task_workspace\service::can_review_record($task, $uid); }
        return (int)$task->assigneeid === $uid;
    }
    private static function manager(int $userid): int {
        $assignment = \local_ustar\organization_model::primary_assignment($userid);
        return $assignment ? \local_ustar\organization_model::manager_user_for_place((int)$assignment->staffplaceid) : 0;
    }
}
