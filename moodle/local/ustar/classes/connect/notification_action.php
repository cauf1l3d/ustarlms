<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Recipient-bound ACK capability, separate from actor-read tokens and transport leases. */
final class notification_action {
    private const TABLE = 'local_ustar_conn_acktokens';

    public static function require_enabled(): void {
        global $CFG;
        if (($CFG->ustar_connect_ack_enabled ?? false) !== true
                || !is_int($CFG->ustar_connect_ack_since ?? null) || $CFG->ustar_connect_ack_since <= 0) {
            throw new \InvalidArgumentException('ack_disabled');
        }
    }

    private static function digest(#[\SensitiveParameter] string $action): string {
        // ua1 also names actor-read tokens: validate encoding, but NEVER reuse their digest/domain.
        opaque_token::digest($action, 'ua1');
        return hash('sha256', "ustar-connect-notification-ack-v1\0" . $action);
    }

    private static function recipient_allowed(int $userid): bool {
        global $CFG;
        return property_exists($CFG, 'ustar_connect_notify_userids') && notification_delivery::recipient_enabled($userid);
    }

    /** Internal: begin owns the identity lock and commits sending + token in the same transaction. */
    public static function issue(\stdClass $notice, \stdClass $delivery, \stdClass $link, int $now): ?array {
        global $DB, $CFG;
        self::require_enabled();
        if (!$DB->is_transaction_started()) { throw new \coding_exception('Action issue requires begin transaction'); }
        if ((int)$notice->timecreated < $CFG->ustar_connect_ack_since
                || !self::recipient_allowed((int)$notice->userid)
                || (int)$delivery->connexpires <= $now || !notification_policy::category((string)$notice->eventtype)) {
            return null;
        }
        try { receipt_actor::require_active((int)$notice->userid); }
        catch (\InvalidArgumentException $error) { return null; }
        if ($delivery->status !== 'sending' || (int)$delivery->connectversion !== 1 || $delivery->channel !== 'telegram'
                || (int)$delivery->attempts < 1 || (int)$delivery->notificationid !== (int)$notice->id
                || (int)$delivery->connlinkid !== (int)$link->id || (int)$delivery->connrevision !== (int)$link->revision
                || $link->state !== 'linked' || $link->provider !== 'telegram' || (int)$link->userid !== (int)$notice->userid) {
            throw new \coding_exception('Action issue requires current sending recipient');
        }
        $action = opaque_token::issue('ua1');
        $expires = (int)$delivery->connexpires;
        $DB->insert_record(self::TABLE, (object)[
            'tokenhash' => self::digest($action), 'notificationid' => (int)$notice->id,
            'deliveryid' => (int)$delivery->id, 'attempt' => (int)$delivery->attempts, 'userid' => (int)$notice->userid,
            'provider' => $link->provider, 'tenant' => $link->tenant, 'subject' => $link->subject,
            'linkid' => (int)$link->id, 'linkrevision' => (int)$link->revision, 'timecreated' => $now, 'expires' => $expires,
        ]);
        return ['action' => $action, 'expires' => $expires];
    }

    public static function acknowledge(#[\SensitiveParameter] string $action, identity $sender,
            string $messageid, int $now): array {
        self::require_enabled();
        try { $hash = self::digest($action); }
        catch (\InvalidArgumentException $error) { throw new \InvalidArgumentException('ack_unavailable'); }
        if ($sender->provider !== 'telegram' || !preg_match('/\A[1-9][0-9]{0,18}\z/', $messageid)
                || filter_var($messageid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new \InvalidArgumentException('ack_unavailable');
        }
        return identity_service::atomic(function() use ($hash, $sender, $messageid, $now): array {
            global $DB;
            self::require_enabled();
            $row = $DB->get_record(self::TABLE, ['tokenhash' => $hash]);
            if (!$row || (int)$row->expires <= $now || $row->provider !== $sender->provider
                    || $row->tenant !== $sender->tenant || $row->subject !== $sender->subject
                    || !self::recipient_allowed((int)$row->userid)) {
                throw new \InvalidArgumentException('ack_unavailable');
            }
            $link = $DB->get_record('local_ustar_conn_links', ['id' => $row->linkid, 'userid' => $row->userid,
                'provider' => $sender->provider, 'tenant' => $sender->tenant, 'subject' => $sender->subject, 'state' => 'linked']);
            $notice = $DB->get_record('local_ustar_notifications', ['id' => $row->notificationid, 'userid' => $row->userid]);
            $delivery = $DB->get_record('local_ustar_notify_delivery', ['id' => $row->deliveryid,
                'notificationid' => $row->notificationid, 'channel' => 'telegram', 'connectversion' => 1]);
            if (!$link || (int)$link->revision !== (int)$row->linkrevision || !$notice
                    || !in_array($notice->status, ['unread', 'read', 'acknowledged'], true) || !$delivery
                    || !in_array($delivery->status, ['sending', 'provider_accepted', 'uncertain'], true)
                    || (int)$delivery->attempts !== (int)$row->attempt
                    || (int)$delivery->connlinkid !== (int)$link->id || (int)$delivery->connrevision !== (int)$link->revision
                    || ((string)$delivery->providerref !== '' && (string)$delivery->providerref !== $messageid)
                    || !($category = notification_policy::category((string)$notice->eventtype))) {
                throw new \InvalidArgumentException('ack_unavailable');
            }
            try { receipt_actor::require_active((int)$row->userid); }
            catch (\InvalidArgumentException $error) { throw new \InvalidArgumentException('ack_unavailable'); }
            \local_ustar\target_core::acknowledge_notification((int)$notice->id, (int)$row->userid);
            return ['status' => 'acknowledged', 'acknowledged_at' => (int)$DB->get_field(
                'local_ustar_notifications', 'ackat', ['id' => $notice->id], MUST_EXIST), 'category' => $category];
        });
    }
}
