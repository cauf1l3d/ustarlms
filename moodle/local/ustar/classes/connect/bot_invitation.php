<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

/** Administrator-issued challenges for existing staff, stored with Connect link metadata. */
final class bot_invitation {
    private const CHALLENGES = 'local_ustar_conn_challenges';
    private const LINKS = 'local_ustar_conn_links';
    private const TTL = 600;

    public static function enabled(): bool {
        global $CFG;
        return ($CFG->ustar_connect_bot_enabled ?? false) === true
            && ($CFG->ustar_connect_bot_invitations_enabled ?? false) === true
            && link_management::channel() !== null;
    }

    public static function can_manage(): bool {
        global $USER, $CFG;
        return isloggedin() && !isguestuser() && !\core\session\manager::is_loggedinas()
            && !\local_ustar\view_as::active() && is_https()
            && parse_url($CFG->wwwroot, PHP_URL_SCHEME) === 'https'
            && self::operator_allowed((int)$USER->id);
    }

    private static function operator_allowed(int $userid): bool {
        return has_capability('local/ustar:admin', \context_system::instance(), $userid)
            && (is_siteadmin($userid) || \local_ustar\team_access::active_actor($userid));
    }

    public static function require_manager(): void {
        if (!self::can_manage()) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:admin', 'nopermissions', '');
        }
    }

    private static function assert_target(int $userid): void {
        receipt_actor::require_active($userid);
        if (!bot_actor::eligible($userid, true) || bot_actor::requires_native_role($userid)) {
            throw new \InvalidArgumentException('native_auth_required');
        }
    }

    private static function check_tenant(string $tenant): void {
        if (!self::enabled() || link_management::channel()['tenant'] !== $tenant) {
            throw new \InvalidArgumentException('invalid_invitation');
        }
    }

    public static function issue(int $userid, string $verifiedsubject): array {
        global $USER;
        self::require_manager();
        if ($verifiedsubject !== '' && (!preg_match('/\A[1-9][0-9]{0,15}\z/', $verifiedsubject)
                || (int)$verifiedsubject >= 2 ** 52)) {
            throw new \InvalidArgumentException('invalid_invitation');
        }
        return identity_service::atomic(static function() use ($userid, $verifiedsubject, $USER): array {
            global $DB;
            $channel = link_management::channel();
            self::check_tenant($channel['tenant'] ?? '');
            self::assert_target($userid);
            if (bot_agreement::pending($userid, true)['blocked']) {
                throw new \InvalidArgumentException('agreement_unavailable');
            }
            if ($DB->record_exists(self::LINKS, ['userid' => $userid, 'provider' => 'telegram', 'state' => 'linked'])) {
                throw new \InvalidArgumentException('link_conflict');
            }
            self::expire($userid);
            $token = opaque_token::issue('ui1');
            $now = time();
            $id = $DB->insert_record(self::CHALLENGES, (object)[
                'codehash' => opaque_token::digest($token, 'ui1'), 'userid' => $userid, 'provider' => 'telegram',
                'tenant' => $channel['tenant'], 'browserhash' => '', 'issuedby' => (int)$USER->id,
                'expectedsubject' => $verifiedsubject !== '' ? $verifiedsubject : null, 'subject' => '', 'proofhash' => '', 'timecreated' => $now,
                'expiresat' => $now + self::TTL, 'confirmedlinkid' => 0, 'confirmedrevision' => 0,
            ]);
            \local_ustar\event\connect_invitation_changed::record($userid, (int)$id, 'issued');
            return ['token' => $token, 'url' => 'https://t.me/' . $channel['username'] . '?start=' . $token];
        });
    }

    /** Called by the verified private bot router inside its transaction and identity lock. */
    public static function redeem(identity $sender, #[\SensitiveParameter] string $token, string $event): ?\stdClass {
        global $DB;
        self::check_tenant($sender->tenant);
        $challenge = $DB->get_record(self::CHALLENGES, ['codehash' => opaque_token::digest($token, 'ui1')]);
        self::assert_open($challenge, $sender);
        if (!empty($challenge->expectedsubject) && !hash_equals($challenge->expectedsubject, $sender->subject)) {
            throw new \InvalidArgumentException('invalid_invitation');
        }
        if (!empty($challenge->expectedsubject)) {
            return self::bind($challenge, $sender);
        }
        if ($challenge->subject === '') {
            $existing = $DB->record_exists_select(self::CHALLENGES,
                'provider = :provider AND tenant = :tenant AND subject = :subject AND issuedby > 0
                    AND confirmedlinkid = 0 AND expiresat > :now',
                ['provider' => 'telegram', 'tenant' => $sender->tenant, 'subject' => $sender->subject, 'now' => time()]);
            if ($existing) {
                throw new \InvalidArgumentException('invalid_invitation');
            }
            $challenge->subject = $sender->subject;
            $challenge->proofhash = self::verification_hash($challenge, $sender, $event);
            $DB->update_record(self::CHALLENGES, $challenge);
        }
        return null;
    }

    public static function pending_code(identity $sender): ?string {
        global $DB;
        if (!self::enabled() || link_management::channel()['tenant'] !== $sender->tenant) {
            return null;
        }
        $pending = $DB->get_records_select(self::CHALLENGES, 'provider = :provider AND tenant = :tenant AND subject = :subject
            AND issuedby > 0 AND expectedsubject IS NULL AND confirmedlinkid = 0 AND expiresat > :now',
            ['provider' => 'telegram', 'tenant' => $sender->tenant, 'subject' => $sender->subject,
                'now' => time()], 'id DESC', 'id,proofhash,issuedby', 0, 1);
        $row = reset($pending);
        if (!$row || !self::operator_allowed((int)$row->issuedby)) {
            return null;
        }
        return self::verification_code($row->proofhash);
    }

    public static function confirm(int $id, string $verification): void {
        self::require_manager();
        identity_service::atomic(static function() use ($id, $verification): void {
            global $DB;
            $challenge = $DB->get_record(self::CHALLENGES, ['id' => $id]);
            if (!$challenge || $challenge->subject === '' || !empty($challenge->expectedsubject)
                    || !preg_match('/\A[0-9A-F]{10}\z/', $verification)
                    || !hash_equals(self::verification_code($challenge->proofhash), $verification)) {
                throw new \InvalidArgumentException('invalid_invitation');
            }
            self::check_tenant($challenge->tenant);
            $sender = new identity('telegram', $challenge->tenant, $challenge->subject);
            self::assert_open($challenge, $sender);
            self::bind($challenge, $sender);
        });
    }

    public static function cancel(int $id): void {
        self::require_manager();
        identity_service::atomic(static function() use ($id): void {
            global $DB;
            $row = $DB->get_record(self::CHALLENGES, ['id' => $id], 'id,userid,issuedby,confirmedlinkid,expiresat');
            if (!$row || !$row->issuedby || $row->confirmedlinkid || (int)$row->expiresat <= time()) {
                throw new \InvalidArgumentException('invalid_invitation');
            }
            $DB->set_field(self::CHALLENGES, 'expiresat', time(), ['id' => $id]);
            \local_ustar\event\connect_invitation_changed::record((int)$row->userid, $id, 'cancelled');
        });
    }

    public static function revoke(int $linkid): void {
        self::require_manager();
        identity_service::atomic(static function() use ($linkid): void {
            global $DB;
            $link = $DB->get_record(self::LINKS, ['id' => $linkid, 'provider' => 'telegram',
                'state' => 'linked', 'botinvited' => 1]);
            if (!$link) {
                throw new \InvalidArgumentException('invalid_invitation');
            }
            identity_service::revoke_link_in_transaction($link);
            self::expire((int)$link->userid);
            \local_ustar\event\connect_invitation_changed::record((int)$link->userid, $linkid, 'revoked');
        });
    }

    private static function assert_open(\stdClass|false $challenge, identity $sender): void {
        global $DB;
        if (!$challenge || !(int)$challenge->issuedby || !self::operator_allowed((int)$challenge->issuedby)
                || $challenge->provider !== $sender->provider || $challenge->tenant !== $sender->tenant
                || (int)$challenge->expiresat <= time() || (int)$challenge->confirmedlinkid
                || ($challenge->subject !== '' && $challenge->subject !== $sender->subject)) {
            throw new \InvalidArgumentException('invalid_invitation');
        }
        self::assert_target((int)$challenge->userid);
        if ($DB->record_exists(self::LINKS, ['userid' => $challenge->userid,
                'provider' => $sender->provider, 'state' => 'linked'])) {
            throw new \InvalidArgumentException('link_conflict');
        }
    }

    private static function bind(\stdClass $challenge, identity $sender): \stdClass {
        global $DB;
        $userid = (int)$challenge->userid;
        $link = identity_service::bind_in_transaction($userid, $sender, true);
        $challenge->subject = $sender->subject;
        $challenge->confirmedlinkid = $link->id;
        $challenge->confirmedrevision = $link->revision;
        $DB->update_record(self::CHALLENGES, $challenge);
        \local_ustar\event\connect_invitation_changed::record($userid, (int)$challenge->id, 'linked');
        return $link;
    }

    private static function expire(int $userid): void {
        global $DB;
        $DB->set_field_select(self::CHALLENGES, 'expiresat', time(),
            'userid = :userid AND issuedby > 0 AND confirmedlinkid = 0 AND expiresat > :now',
            ['userid' => $userid, 'now' => time()]);
    }

    private static function verification_hash(\stdClass $challenge, identity $sender, string $event): string {
        return hash('sha256', bot_store::json([$challenge->codehash, $sender->key(), $event, bin2hex(random_bytes(16))]));
    }

    private static function verification_code(string $hash): string {
        return strtoupper(substr($hash, 0, 10));
    }
}
