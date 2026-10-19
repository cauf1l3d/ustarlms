<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Provider-verified entry only. Does not create a Moodle login or business capability. */
final class entry_service {
    private const TTL = 600;

    public static function create_launch(identity $sender, string $keyid): array {
        self::keyid($keyid);
        return identity_service::atomic(function() use ($sender, $keyid): array {
            global $DB;
            if ($DB->count_records_select('local_ustar_conn_launches',
                    'identityhash = :identity AND consumedat = 0 AND expiresat > :now',
                    ['identity' => $sender->key(), 'now' => time()]) >= 5) {
                throw new \InvalidArgumentException('rate_limited');
            }
            $token = opaque_token::issue('ul1');
            $link = self::link($sender);
            $DB->insert_record('local_ustar_conn_launches', (object)[
                'noncehash' => opaque_token::digest($token, 'ul1'), 'identityhash' => $sender->key(),
                'provider' => $sender->provider, 'tenant' => $sender->tenant, 'subject' => $sender->subject,
                'keyid' => $keyid, 'userid' => (int)($link->userid ?? 0),
                'timecreated' => time(), 'expiresat' => time()+self::TTL, 'consumedat' => 0,
            ]);
            return ['launch' => $token, 'expires_at' => time()+self::TTL];
        });
    }

    public static function exchange(identity $sender, string $launch, string $proofid,
            int $authdate, string $browser, string $keyid): array {
        self::keyid($keyid);
        $noncehash = opaque_token::digest($launch, 'ul1');
        $browserhash = opaque_token::digest($browser, 'ub1');
        $proofhash = $sender->proof_hash($proofid);
        return identity_service::atomic(function() use ($sender, $noncehash, $browserhash, $proofhash, $authdate, $keyid): array {
            global $DB;
            $ticket = $DB->get_record('local_ustar_conn_launches', ['noncehash' => $noncehash]);
            if (!$ticket || $ticket->identityhash !== $sender->key() || $ticket->keyid !== $keyid) {
                throw new \InvalidArgumentException('launch_mismatch');
            }
            if ((int)$ticket->expiresat <= time() || $authdate < time()-300 || $authdate > time()+30
                    || $authdate < (int)$ticket->timecreated-30) {
                throw new \InvalidArgumentException('expired_launch');
            }
            if ((int)$ticket->consumedat !== 0) {
                throw new \InvalidArgumentException('launch_consumed');
            }
            if ($DB->record_exists('local_ustar_conn_proofs', ['proofhash' => $proofhash])) {
                throw new \InvalidArgumentException('replayed_proof');
            }
            $DB->insert_record('local_ustar_conn_proofs', (object)[
                'proofhash' => $proofhash, 'challengeid' => 0, 'timecreated' => time(),
                'expiresat' => max(time()+1, $authdate+331),
            ]);
            $session = opaque_token::issue('ue1');
            $link = self::link($sender);
            $DB->insert_record('local_ustar_conn_sessions', (object)[
                'tokenhash' => opaque_token::digest($session, 'ue1'), 'browserhash' => $browserhash,
                'identityhash' => $sender->key(), 'provider' => $sender->provider,
                'tenant' => $sender->tenant, 'subject' => $sender->subject, 'keyid' => $keyid,
                'userid' => (int)($link->userid ?? 0), 'linkid' => (int)($link->id ?? 0),
                'linkrevision' => (int)($link->revision ?? 0), 'timecreated' => time(),
                'expiresat' => time()+self::TTL, 'revokedat' => 0,
            ]);
            $ticket->consumedat = time();
            $DB->update_record('local_ustar_conn_launches', $ticket);
            return ['session' => $session, 'scope' => 'onboarding', 'expires_at' => time()+self::TTL];
        });
    }

    public static function status(string $token, string $browser, string $keyid, string $provider, string $tenant): array {
        global $DB;
        $session = self::session($token, $browser, $keyid, $provider, $tenant);
        $sender = new identity($session->provider, $session->tenant, $session->subject);
        $link = self::link($sender);
        if ((int)$session->linkid) {
            if (!$link || $link->state !== 'linked' || (int)$link->id !== (int)$session->linkid
                    || (int)$link->revision !== (int)$session->linkrevision
                    || (int)$link->userid !== (int)$session->userid) {
                throw new \InvalidArgumentException('revoked_entry');
            }
            $user = $DB->get_record('user', ['id' => $session->userid, 'deleted' => 0, 'suspended' => 0, 'confirmed' => 1], 'id');
            if (!$user || !\local_ustar\accounts::is_business_account((int)$user->id)) {
                throw new \InvalidArgumentException('revoked_entry');
            }
            $employment = \local_ustar\employment::resolve((int)$user->id)['status'];
            if (!in_array($employment, [\local_ustar\employment::ACTIVE, \local_ustar\employment::PENDING], true)) {
                throw new \InvalidArgumentException('revoked_entry');
            }
            $state = $employment === \local_ustar\employment::ACTIVE ? 'linked_active' : 'pending_approval';
        } else {
            if ($link) { throw new \InvalidArgumentException('restart_required'); }
            $state = 'unlinked';
        }
        return ['scope' => 'onboarding', 'account_state' => $state, 'expires_at' => (int)$session->expiresat];
    }

    public static function revoke(string $token, string $browser, string $keyid, string $provider, string $tenant): void {
        identity_service::atomic(function() use ($token, $browser, $keyid, $provider, $tenant): void {
            global $DB;
            $session = self::session($token, $browser, $keyid, $provider, $tenant);
            $DB->set_field('local_ustar_conn_sessions', 'revokedat', time(), ['id' => $session->id]);
        });
    }

    private static function session(string $token, string $browser, string $keyid, string $provider, string $tenant): \stdClass {
        global $DB;
        self::keyid($keyid);
        $session = $DB->get_record('local_ustar_conn_sessions', ['tokenhash' => opaque_token::digest($token, 'ue1')]);
        $binding = opaque_token::digest($browser, 'ub1');
        if (!$session || $session->keyid !== $keyid || $session->provider !== $provider || $session->tenant !== $tenant
                || !hash_equals($session->browserhash, $binding) || (int)$session->expiresat <= time()
                || (int)$session->revokedat !== 0) {
            throw new \InvalidArgumentException('invalid_entry');
        }
        return $session;
    }

    private static function link(identity $sender) {
        global $DB;
        return $DB->get_record('local_ustar_conn_links', ['identityhash' => $sender->key()]);
    }
    private static function keyid(string $keyid): void {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $keyid)) {
            throw new \InvalidArgumentException('invalid_transport');
        }
    }
}
