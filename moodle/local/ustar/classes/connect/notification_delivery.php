<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Durable leases in the existing delivery store, never in a worker-side business queue. */
final class notification_delivery {
    private const TABLE = 'local_ustar_notify_delivery';
    private static function channel(): ?string {
        global $CFG;
        $value = $CFG->ustar_connect_channels['telegram'] ?? null;
        if (!is_array($value)) { return null; }
        $keys = array_keys($value); sort($keys);
        return $keys === ['tenant', 'username'] && is_string($value['tenant']) && is_string($value['username'])
            && preg_match('/\A[1-9][0-9]{0,19}\z/', $value['tenant'])
            && preg_match('/\A[A-Za-z][A-Za-z0-9_]{1,28}[Bb][Oo][Tt]\z/', $value['username']) ? $value['tenant'] : null;
    }
    private static function enabled(): bool {
        global $CFG;
        return ($CFG->ustar_connect_notifications_enabled ?? false) === true
            && is_int($CFG->ustar_connect_notify_since ?? null) && $CFG->ustar_connect_notify_since > 0;
    }
    private static function eligible(\stdClass $notice, ?\stdClass $delivery = null): ?\stdClass {
        global $DB, $CFG;
        $tenant = self::channel();
        if (!self::enabled() || !$tenant || !self::recipient_enabled((int)$notice->userid)
                || (int)$notice->timecreated < $CFG->ustar_connect_notify_since
                || !notification_policy::permitted($notice)) { return null; }
        $preferences = notification_preferences::for_user((int)$notice->userid);
        if (!$preferences['enabled'] || !in_array(notification_policy::category($notice->eventtype), $preferences['categories'], true)
                || array_search($notice->severity, notification_preferences::SEVERITIES, true) === false
                || array_search($notice->severity, notification_preferences::SEVERITIES, true)
                    < array_search($preferences['min_severity'], notification_preferences::SEVERITIES, true)) { return null; }
        $link = $DB->get_record('local_ustar_conn_links', ['userid' => $notice->userid, 'provider' => 'telegram',
            'tenant' => $tenant, 'state' => 'linked']);
        if (!$link || !preg_match('/\A[1-9][0-9]{0,15}\z/', $link->subject) || (int)$link->subject >= 2 ** 52
                || ($delivery && ((int)$delivery->connlinkid !== (int)$link->id
                    || (int)$delivery->connrevision !== (int)$link->revision))) { return null; }
        return $link;
    }
    /** A configured pilot list is checked at enqueue, lease and again at begin. */
    public static function recipient_enabled(int $userid): bool {
        global $CFG;
        if (!property_exists($CFG, 'ustar_connect_notify_userids')) { return true; }
        $ids = $CFG->ustar_connect_notify_userids;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 10
                || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) { return false; }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) { return false; }
        }
        return in_array($userid, $ids, true);
    }
    /** Called only while the NEW canonical notification writer owns its transaction. */
    public static function enqueue(int $notificationid): void {
        global $DB;
        if (!self::enabled()) { return; }
        if (!$DB->is_transaction_started()) { throw new \coding_exception('Notification writer must own enqueue transaction'); }
        $notice = $DB->get_record('local_ustar_notifications', ['id' => $notificationid], '*', MUST_EXIST);
        $link = self::eligible($notice);
        if (!$link) { return; }
        $DB->insert_record(self::TABLE, (object)[
            'notificationid' => $notificationid, 'channel' => 'telegram', 'status' => 'pending', 'attempts' => 0,
            'nextattempt' => time(), 'timecreated' => time(), 'timemodified' => time(),
            'connectversion' => 1, 'connlinkid' => (int)$link->id, 'connrevision' => (int)$link->revision,
            'connexpires' => (int)$notice->timecreated + notification_policy::ttl($notice->eventtype),
        ]);
    }
    private static function scope(string $kid, string $provider, string $tenant): void {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $kid) || $provider !== 'telegram'
                || !preg_match('/\A[1-9][0-9]{0,19}\z/', $tenant)) { throw new \InvalidArgumentException('invalid_delivery'); }
    }
    private static function state(\stdClass $row, string $state, int $now, string $reason = ''): array {
        global $DB;
        $row->status = $state; $row->timemodified = $now; $row->lasterror = $reason ?: null;
        $DB->update_record(self::TABLE, $row);
        return ['status' => $state];
    }
    /** Current quiet hours and recipient-wide cooldown. Never return a pre-rendered private message. */
    private static function next_time(\stdClass $row, \stdClass $notice, int $now): int {
        global $DB;
        $prefs = notification_preferences::for_user((int)$notice->userid);
        $last = (int)$DB->get_field_sql('SELECT MAX(d.connbegun) FROM {local_ustar_notify_delivery} d
            JOIN {local_ustar_notifications} n ON n.id = d.notificationid
            WHERE n.userid = :uid AND d.connectversion = 1 AND d.channel = :channel',
            ['uid' => $notice->userid, 'channel' => 'telegram']);
        $next = max($now, $last ? $last + $prefs['interval_minutes'] * 60 : $now);
        $busy = (int)$DB->get_field_sql('SELECT MAX(d.connleaseuntil) FROM {local_ustar_notify_delivery} d
            JOIN {local_ustar_notifications} n ON n.id = d.notificationid
            WHERE n.userid = :uid AND d.id <> :id AND d.connectversion = 1 AND d.channel = :channel
              AND (d.status = :leased OR d.status = :sending) AND d.connleaseuntil > :now',
            ['uid' => $notice->userid, 'id' => $row->id, 'channel' => 'telegram', 'leased' => 'leased', 'sending' => 'sending', 'now' => $now]);
        return notification_preferences::next_allowed($prefs, max($next, $busy));
    }
    public static function lease(string $kid, string $provider, string $tenant, ?int $now = null): ?array {
        self::scope($kid, $provider, $tenant); $now ??= time();
        return identity_service::atomic(function() use ($kid, $tenant, $now): ?array {
            global $DB;
            if (!self::enabled() || self::channel() !== $tenant) { return null; }
            // A provider-wide429 must survive restarts and stop a different queued recipient too.
            $cooldown = (int)$DB->get_field_sql('SELECT MAX(d.nextattempt) FROM {local_ustar_notify_delivery} d
                JOIN {local_ustar_conn_links} l ON l.id = d.connlinkid
                WHERE d.connectversion = 1 AND d.channel = :channel AND l.tenant = :tenant
                  AND d.status = :status AND (d.lasterror = :limited OR d.lasterror = :unauthorized)',
                ['channel' => 'telegram', 'tenant' => $tenant, 'status' => 'retry_wait',
                    'limited' => 'rate_limited', 'unauthorized' => 'unauthorized']);
            if ($cooldown > $now) { return null; }
            $rows = $DB->get_records_sql('SELECT d.* FROM {local_ustar_notify_delivery} d
                JOIN {local_ustar_conn_links} l ON l.id = d.connlinkid
                WHERE d.connectversion = 1 AND d.channel = :channel AND l.tenant = :tenant
                  AND (((d.status = :pending OR d.status = :retry) AND d.nextattempt <= :due)
                    OR ((d.status = :leased OR d.status = :sending) AND d.connleaseuntil <= :expired))
                ORDER BY d.id', ['channel' => 'telegram', 'tenant' => $tenant, 'pending' => 'pending',
                    'retry' => 'retry_wait', 'due' => $now, 'leased' => 'leased', 'sending' => 'sending', 'expired' => $now], 0, 50);
            foreach ($rows as $row) {
                if ($row->status === 'sending') { self::state($row, 'uncertain', $now, 'interrupted'); continue; }
                $notice = $DB->get_record('local_ustar_notifications', ['id' => $row->notificationid]);
                if (!$notice || (int)$row->connexpires <= $now || !self::eligible($notice, $row)) {
                    self::state($row, 'cancelled', $now); continue;
                }
                if ((int)$row->attempts >= 5) { self::state($row, 'failed', $now, 'max_attempts'); continue; }
                $next = self::next_time($row, $notice, $now);
                if ($next > $now) { $row->nextattempt = $next; self::state($row, 'pending', $now); continue; }
                $secret = opaque_token::issue('ud1');
                $row->connleasehash = opaque_token::digest($secret, 'ud1');
                $row->connleaseuntil = $now + 60; $row->connkeyid = $kid;
                self::state($row, 'leased', $now);
                return ['delivery' => (int)$row->id, 'lease' => $secret, 'expires' => (int)$row->connleaseuntil];
            }
            return null;
        });
    }
    private static function fenced(int $id, string $lease, string $kid, string $provider, string $tenant): \stdClass {
        global $DB;
        self::scope($kid, $provider, $tenant);
        try { $hash = opaque_token::digest($lease, 'ud1'); }
        catch (\InvalidArgumentException $error) { throw new \InvalidArgumentException('invalid_delivery'); }
        $row = $DB->get_record(self::TABLE, ['id' => $id, 'connectversion' => 1, 'channel' => 'telegram', 'connkeyid' => $kid]);
        $link = $row ? $DB->get_record('local_ustar_conn_links', ['id' => $row->connlinkid, 'provider' => $provider, 'tenant' => $tenant]) : false;
        if (!$row || !$link || !hash_equals($row->connleasehash, $hash)) { throw new \InvalidArgumentException('invalid_delivery'); }
        return $row;
    }
    public static function begin(int $id, #[\SensitiveParameter] string $lease, string $kid, string $provider, string $tenant, ?int $now = null): array {
        $now ??= time();
        return identity_service::atomic(function() use ($id, $lease, $kid, $provider, $tenant, $now): array {
            global $DB;
            $row = self::fenced($id, $lease, $kid, $provider, $tenant);
            if ($row->status !== 'leased' || (int)$row->connleaseuntil <= $now) { throw new \InvalidArgumentException('invalid_delivery'); }
            $notice = $DB->get_record('local_ustar_notifications', ['id' => $row->notificationid]);
            $link = $notice ? self::eligible($notice, $row) : null;
            if (!$link || (int)$row->connexpires <= $now) { return self::state($row, 'cancelled', $now); }
            $next = self::next_time($row, $notice, $now);
            if ($next > $now) { $row->nextattempt = $next; return self::state($row, 'retry_wait', $now); }
            if ((int)$row->attempts >= 5) { return self::state($row, 'failed', $now, 'max_attempts'); }
            $row->attempts = (int)$row->attempts + 1; $row->connbegun = $now;
            // This transaction commits before the private worker receives any permission to send.
            self::state($row, 'sending', $now);
            return ['status' => 'sending', 'subject' => $link->subject,
                'category' => notification_policy::category($notice->eventtype), 'severity' => $notice->severity,
                'occurred_at' => (int)$notice->timecreated, 'send_before' => $now + 10];
        });
    }
    public static function finish(int $id, #[\SensitiveParameter] string $lease, string $outcome,
            string $ref, string $reason, int $retry, string $kid, string $provider, string $tenant): array {
        if (!in_array($outcome, ['provider_accepted', 'retry_wait', 'uncertain', 'failed'], true)
                || ($outcome === 'provider_accepted' ? (!preg_match('/\A[1-9][0-9]{0,18}\z/', $ref) || $reason !== '' || $retry !== 0)
                    : ($ref !== '' || !in_array($reason, ['rate_limited', 'unauthorized', 'forbidden', 'api_rejected',
                        'transport_unavailable', 'invalid_response', 'interrupted'], true)))
                || ($outcome === 'retry_wait' ? (!in_array($reason, ['rate_limited', 'unauthorized'], true) || $retry < 1 || $retry > 86400)
                    : $retry !== 0)) { throw new \InvalidArgumentException('invalid_delivery'); }
        return identity_service::atomic(function() use ($id, $lease, $outcome, $ref, $reason, $retry, $kid, $provider, $tenant): array {
            global $DB;
            $row = self::fenced($id, $lease, $kid, $provider, $tenant);
            if ($outcome === 'retry_wait' && (int)$row->attempts >= 5) { $outcome = 'failed'; $reason = 'max_attempts'; $retry = 0; }
            $same = $row->status === $outcome && (string)$row->providerref === $ref && (string)$row->lasterror === $reason;
            if ($same) {
                if ($outcome === 'retry_wait' && (int)$row->nextattempt !== (int)$row->timemodified + $retry) {
                    throw new \InvalidArgumentException('receipt_conflict');
                }
                return ['status' => $row->status];
            }
            if ($row->status !== 'sending' && !($row->status === 'uncertain' && $outcome === 'provider_accepted')) {
                throw new \InvalidArgumentException('receipt_conflict');
            }
            $row->providerref = $ref ?: null; $row->nextattempt = $outcome === 'retry_wait' ? time() + $retry : null;
            // Provider acceptance is never a human ACK or a business decision.
            return self::state($row, $outcome, time(), $reason);
        });
    }
    public static function own_summary(): array {
        global $DB, $USER;
        identity_service::owned_links();
        $rows = $DB->get_records_sql('SELECT d.id,d.status,d.attempts,d.nextattempt,d.timemodified,n.eventtype
            FROM {local_ustar_notify_delivery} d JOIN {local_ustar_notifications} n ON n.id = d.notificationid
            WHERE d.connectversion = 1 AND d.channel = :channel AND n.userid = :uid ORDER BY d.id DESC',
            ['channel' => 'telegram', 'uid' => (int)$USER->id], 0, 20);
        return array_map(static fn($r) => ['category' => notification_policy::category($r->eventtype),
            'status' => $r->status, 'attempts' => (int)$r->attempts, 'nextattempt' => (int)$r->nextattempt,
            'timemodified' => (int)$r->timemodified], array_values($rows));
    }
}
