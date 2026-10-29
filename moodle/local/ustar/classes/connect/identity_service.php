<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

/**
 * Canonical link storage. Private bridge/native callers own the authentication boundary.
 * claim_verified is INTERNAL: the scoped verifier bridge may call it only after
 * validating provider origin/freshness. Challenge issuance and the second
 * confirmation stay bound to the native Academy owner/browser session.
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

    /** Trusted origin proof, not arbitrary userid: private bridge must authenticate caller. */
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
            $proofhash = $identity->proof_hash($proofid);
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
        self::actor();
        self::mutate(static fn() => self::revoke_current_in_transaction($linkid));
    }

    /** INTERNAL: caller already holds the identity lock and owns its transaction. */
    public static function revoke_current_in_transaction(int $linkid): void {
        global $DB;
        if (!$DB->is_transaction_started()) { throw new \coding_exception('Owned transaction required'); }
        $userid = self::actor();
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
    }

    /** Resolution is read-only, not a replacement for per-command capability/scope checks. */
    public static function resolve_active_user(identity $identity): int {
        global $DB;
        $link = $DB->get_record('local_ustar_conn_links', ['identityhash' => $identity->key(), 'state' => 'linked']);
        return $link && self::eligible((int)$link->userid, false) ? (int)$link->userid : 0;
    }

    /** Real owner only; never expose lookup hashes or accept a caller-selected userid. */
    public static function owned_links(): array {
        global $DB;
        $userid = self::actor();
        $result = [];
        foreach ($DB->get_records('local_ustar_conn_links', ['userid' => $userid], 'provider, id') as $link) {
            $result[] = ['id' => (int)$link->id, 'provider' => $link->provider, 'tenant' => $link->tenant,
                'subject' => $link->subject, 'state' => $link->state, 'revision' => (int)$link->revision];
        }
        return $result;
    }

    /** Internal cron maintenance; bounded work, no sends or business writes. */
    public static function prune_expired(int $limit = 500): array {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('invalid_cleanup_limit');
        }
        return self::mutate(function() use ($limit): array {
            global $DB;
            $now = time();
            $challenges = array_keys($DB->get_records_select('local_ustar_conn_challenges',
                'expiresat <= :now', ['now' => $now], 'id', 'id', 0, $limit));
            if ($challenges) {
                [$sql, $params] = $DB->get_in_or_equal($challenges, SQL_PARAMS_NAMED);
                // Revocation can expire a code before its proof: preserve the
                // proof's original expiry, only detach its personal association.
                $DB->set_field_select('local_ustar_conn_proofs', 'challengeid', 0, 'challengeid ' . $sql, $params);
                $DB->delete_records_list('local_ustar_conn_challenges', 'id', $challenges);
            }
            $proofs = array_keys($DB->get_records_select('local_ustar_conn_proofs',
                'expiresat <= :now', ['now' => $now], 'id', 'id', 0, $limit));
            if ($proofs) { $DB->delete_records_list('local_ustar_conn_proofs', 'id', $proofs); }
            $entry = [];
            foreach (['local_ustar_conn_launches', 'local_ustar_conn_sessions', 'local_ustar_conn_signups'] as $table) {
                $ids = array_keys($DB->get_records_select($table, 'expiresat <= :now',
                    ['now' => $now], 'id', 'id', 0, $limit));
                if ($ids) { $DB->delete_records_list($table, 'id', $ids); }
                $entry[$table] = count($ids);
            }
            $actions = array_keys($DB->get_records_select('local_ustar_conn_acktokens',
                'expires <= :cutoff', ['cutoff' => $now - 7 * DAYSECS], 'id', 'id', 0, $limit));
            if ($actions) { $DB->delete_records_list('local_ustar_conn_acktokens', 'id', $actions); }
            return ['challenges' => count($challenges), 'proofs' => count($proofs),
                'launches' => $entry['local_ustar_conn_launches'], 'sessions' => $entry['local_ustar_conn_sessions'],
                'signups' => $entry['local_ustar_conn_signups']];
        });
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

    /** Low-frequency writes serialize until commit under one cross-worker Moodle lock. */
    public static function atomic(callable $operation) {
        return self::locked(function() use ($operation) {
            global $DB;
            $transaction = $DB->start_delegated_transaction();
            try {
                $result = $operation();
                $transaction->allow_commit();
                return $result;
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }
        });
    }

    /** INTERNAL lock-only wrapper: a canonical writer must own and commit its own transaction. */
    public static function locked(callable $operation) {
        global $DB;
        if ($DB->is_transaction_started()) {
            throw new \coding_exception('Connect identity mutations must own the transaction');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('connect-identity', 10);
        if (!$lock) {
            throw new \InvalidArgumentException('identity_busy');
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    private static function mutate(callable $operation) {
        return self::atomic($operation);
    }

    /** Internal transport replay barrier; the key is scoped and signature-verified upstream. */
    public static function remember_transport(string $hash, int $expires): void {
        if (!preg_match('/\A[0-9a-f]{64}\z/', $hash) || $expires <= time() || $expires > time()+90) {
            throw new \InvalidArgumentException('invalid_transport');
        }
        self::atomic(function() use ($hash, $expires): void {
            global $DB;
            if ($DB->record_exists('local_ustar_conn_proofs', ['proofhash' => $hash])) {
                throw new \InvalidArgumentException('replayed_transport');
            }
            $DB->insert_record('local_ustar_conn_proofs', (object)[
                'proofhash' => $hash, 'challengeid' => 0, 'timecreated' => time(), 'expiresat' => $expires,
            ]);
        });
    }
}
