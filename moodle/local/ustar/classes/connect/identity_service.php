<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

/**
 * Canonical link storage, no public route, session issuance or provider verifier.
 * claim_verified is INTERNAL: only a future authenticated verifier bridge may
 * call it after validating provider origin/freshness and browser-bound nonce.
 * Never export it as an anonymous AJAX/external function.
 */
final class identity_service {
    private const TTL = 600;

    public static function issue(string $provider, string $tenant): array {
        $userid = self::actor();
        new identity($provider, $tenant, 'validation');
        return self::mutate(function() use ($userid, $provider, $tenant): array {
            global $DB;
            self::actor();
            // Bound unconfirmed secrets per account, including abandoned claims.
            if ($DB->count_records_select('local_ustar_conn_challenges',
                    'userid = :userid AND expiresat > :now AND confirmedlinkid = 0',
                    ['userid' => $userid, 'now' => time()]) >= 5) {
                throw new \InvalidArgumentException('rate_limited');
            }
            $code = identity::issue_code();
            $now = time();
            $DB->insert_record('local_ustar_conn_challenges', (object)[
                'codehash' => identity::code_hash($code), 'userid' => $userid,
                'provider' => $provider, 'tenant' => $tenant, 'browserhash' => self::browser_hash(),
                'subject' => '', 'proofhash' => '', 'timecreated' => $now,
                'expiresat' => $now + self::TTL, 'confirmedlinkid' => 0, 'confirmedrevision' => 0,
            ]);
            return ['code' => $code, 'created_at' => $now, 'expires_at' => $now + self::TTL];
        });
    }

    /** Trusted origin proof, not arbitrary userid: future bridge must authenticate caller. */
    public static function claim_verified(string $code, identity $identity, string $proofid): void {
        if (!preg_match('/\A[0-9a-f]{64}\z/', $proofid)) {
            throw new \InvalidArgumentException('invalid_proof');
        }
        $codehash = identity::code_hash($code);
        self::mutate(function() use ($codehash, $identity, $proofid): void {
            global $DB;
            $challenge = self::challenge($codehash);
            if ($challenge->provider !== $identity->provider || $challenge->tenant !== $identity->tenant) {
                throw new \InvalidArgumentException('provider_mismatch');
            }
            if ($challenge->subject !== '' || (int)$challenge->confirmedlinkid) {
                throw new \InvalidArgumentException('claimed_challenge');
            }
            // Replay key is provider/tenant+proof, not recipient code. Cannot move
            // one proof between challenges or subject IDs by changing the JSON.
            $proofhash = hash('sha256', json_encode([$identity->provider, $identity->tenant, $proofid]));
            if ($DB->record_exists('local_ustar_conn_proofs', ['proofhash' => $proofhash])) {
                throw new \InvalidArgumentException('replayed_proof');
            }
            $DB->insert_record('local_ustar_conn_proofs', (object)[
                'proofhash' => $proofhash, 'challengeid' => $challenge->id,
                'timecreated' => time(), 'expiresat' => $challenge->expiresat,
            ]);
            $challenge->subject = $identity->subject;
            $challenge->proofhash = $proofhash;
            $DB->update_record('local_ustar_conn_challenges', $challenge);
        });
    }

    public static function confirm(string $code): array {
        $userid = self::actor();
        $codehash = identity::code_hash($code);
        return self::mutate(function() use ($userid, $codehash): array {
            global $DB;
            self::actor();
            $challenge = self::challenge($codehash);
            if ((int)$challenge->userid !== $userid || !hash_equals($challenge->browserhash, self::browser_hash())) {
                throw new \InvalidArgumentException('invalid_challenge');
            }
            if ((int)$challenge->confirmedlinkid) {
                $link = $DB->get_record('local_ustar_conn_links', ['id' => $challenge->confirmedlinkid]);
                if (!$link || $link->state !== 'linked' || (int)$link->revision !== (int)$challenge->confirmedrevision) {
                    throw new \InvalidArgumentException('revoked_link');
                }
                return self::receipt($link);
            }
            if ($challenge->subject === '') {
                throw new \InvalidArgumentException('unclaimed_challenge');
            }
            $identity = new identity($challenge->provider, $challenge->tenant, $challenge->subject);
            $key = $identity->key();
            $userchannel = hash('sha256', $userid . ':' . $identity->provider);
            $link = $DB->get_record('local_ustar_conn_links', ['identityhash' => $key]);
            $slot = $DB->get_record('local_ustar_conn_links', ['userchannelhash' => $userchannel]);
            if (($link && (int)$link->userid !== $userid) || ($slot && $slot->identityhash !== $key)) {
                throw new \InvalidArgumentException('link_conflict');
            }
            if (!$link) {
                $link = (object)[
                    'identityhash' => $key, 'userchannelhash' => $userchannel,
                    'userid' => $userid, 'provider' => $identity->provider,
                    'tenant' => $identity->tenant, 'subject' => $identity->subject,
                    'state' => 'linked', 'revision' => 1, 'timecreated' => time(), 'timemodified' => time(),
                ];
                $link->id = $DB->insert_record('local_ustar_conn_links', $link);
            } else if ($link->state !== 'linked') {
                $link->state = 'linked';
                $link->revision = (int)$link->revision + 1;
                $link->timemodified = time();
                $DB->update_record('local_ustar_conn_links', $link);
            }
            $challenge->confirmedlinkid = $link->id;
            $challenge->confirmedrevision = $link->revision;
            $DB->update_record('local_ustar_conn_challenges', $challenge);
            return self::receipt($link);
        });
    }

    /** Owner-only preview for the explicit second confirmation; grants no access. */
    public static function preview(string $code): array {
        $userid = self::actor();
        $challenge = self::challenge(identity::code_hash($code));
        if ((int)$challenge->userid !== $userid || !hash_equals($challenge->browserhash, self::browser_hash())) {
            throw new \InvalidArgumentException('invalid_challenge');
        }
        return ['provider' => $challenge->provider, 'tenant' => $challenge->tenant,
            'subject' => $challenge->subject, 'claimed' => $challenge->subject !== '',
            'expires_at' => (int)$challenge->expiresat];
    }

    public static function revoke(int $linkid): void {
        $userid = self::actor();
        self::mutate(function() use ($userid, $linkid): void {
            global $DB;
            self::actor();
            $link = $DB->get_record('local_ustar_conn_links', ['id' => $linkid, 'userid' => $userid]);
            if (!$link) {
                throw new \InvalidArgumentException('unknown_link');
            }
            if ($link->state !== 'revoked') {
                $link->state = 'revoked';
                $link->revision = (int)$link->revision + 1;
                $link->timemodified = time();
                $DB->update_record('local_ustar_conn_links', $link);
            }
            // Invalidate all still-open codes for this channel. A new issue+claim
            // is needed: a pre-revocation code cannot resurrect the link.
            $DB->set_field('local_ustar_conn_challenges', 'expiresat', 0,
                ['userid' => $userid, 'provider' => $link->provider, 'confirmedlinkid' => 0]);
        });
    }

    /** Resolution is read-only, not a replacement for per-command capability/scope checks. */
    public static function resolve_active_user(identity $identity): int {
        global $DB;
        $link = $DB->get_record('local_ustar_conn_links', ['identityhash' => $identity->key(), 'state' => 'linked']);
        return $link && self::eligible((int)$link->userid, false) ? (int)$link->userid : 0;
    }

    private static function actor(): int {
        global $USER;
        if (!isloggedin() || isguestuser() || \core\session\manager::is_loggedinas()) {
            throw new \InvalidArgumentException('unauthenticated');
        }
        \local_ustar\view_as::assert_writable();
        $userid = (int)$USER->id;
        if (!self::eligible($userid, true)) {
            throw new \InvalidArgumentException('inactive_actor');
        }
        return $userid;
    }

    private static function eligible(int $userid, bool $allowpending): bool {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0, 'confirmed' => 1], 'id');
        if (!$user || !\local_ustar\accounts::is_business_account($userid)) {
            return false;
        }
        $status = \local_ustar\employment::resolve($userid)['status'];
        return $status === \local_ustar\employment::ACTIVE
            || ($allowpending && $status === \local_ustar\employment::PENDING);
    }

    private static function browser_hash(): string {
        return hash('sha256', "ustar-connect-browser-v1\0" . sesskey());
    }

    private static function challenge(string $hash): \stdClass {
        global $DB;
        $record = $DB->get_record('local_ustar_conn_challenges', ['codehash' => $hash]);
        if (!$record) {
            throw new \InvalidArgumentException('invalid_challenge');
        }
        if ((int)$record->expiresat <= time()) {
            throw new \InvalidArgumentException('expired_challenge');
        }
        return $record;
    }

    private static function receipt(\stdClass $link): array {
        return ['id' => (int)$link->id, 'userid' => (int)$link->userid,
            'provider' => $link->provider, 'state' => $link->state, 'revision' => (int)$link->revision];
    }

    /** Low-frequency writes serialize under one cross-worker Moodle lock. */
    private static function mutate(callable $operation) {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Connect identity mutations must own the transaction');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('connect-identity', 10);
        if (!$lock) {
            throw new \InvalidArgumentException('identity_busy');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $result = $operation();
                $transaction->allow_commit();
                return $result;
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }
        } finally {
            $lock->release();
        }
    }
}
