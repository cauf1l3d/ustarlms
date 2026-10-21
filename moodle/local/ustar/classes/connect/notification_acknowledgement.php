<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Own native receipt only: never a delegated actor, provider receipt or task command. */
final class notification_acknowledgement {
    private static function actor(): int {
        global $USER;
        // Existing owner boundary rejects guest, login-as, deleted/suspended and view-as.
        // It also allows pending link owners, so acknowledgement checks ACTIVE separately.
        identity_service::owned_links();
        $userid = (int)$USER->id;
        if (!\local_ustar\employment::is_active($userid)) {
            throw new \InvalidArgumentException('inactive_actor');
        }
        require_capability('local/ustar:use', \context_system::instance(), $userid);
        return $userid;
    }

    /** Read-only presentation hint. Every POST rechecks the actor and target. */
    public static function available(): bool {
        try { self::actor(); return true; }
        catch (\InvalidArgumentException | \moodle_exception $error) { return false; }
    }

    public static function own(int $id, string $source = 'local'): array {
        if ($id < 1 || $source !== 'local') { throw new \InvalidArgumentException('invalid_notification'); }
        return identity_service::atomic(function() use ($id): array {
            global $DB;
            $userid = self::actor();
            $notice = $DB->get_record('local_ustar_notifications', ['id' => $id, 'userid' => $userid], 'id,status');
            if (!$notice || !in_array($notice->status, ['unread', 'read', 'acknowledged'], true)) {
                throw new \InvalidArgumentException('invalid_notification');
            }
            \local_ustar\target_core::acknowledge_notification($id, $userid);
            $notice = $DB->get_record('local_ustar_notifications', ['id' => $id, 'userid' => $userid], 'status,ackat', MUST_EXIST);
            return ['source' => 'local', 'id' => $id, 'status' => $notice->status, 'acknowledged_at' => (int)$notice->ackat];
        });
    }

    public static function handle(string $method, array $input): array {
        $keys = array_keys($input); sort($keys);
        if ($method !== 'POST' || $keys !== ['id', 'sesskey', 'source']
                || !is_string($input['sesskey']) || !confirm_sesskey($input['sesskey'])) {
            throw new \InvalidArgumentException('invalid_request');
        }
        if (!is_string($input['id']) || !preg_match('/\A[1-9][0-9]{0,18}\z/', $input['id'])
                || filter_var($input['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || !is_string($input['source']) || $input['source'] !== 'local') {
            throw new \InvalidArgumentException('invalid_notification');
        }
        return self::own((int)$input['id'], $input['source']);
    }
}
