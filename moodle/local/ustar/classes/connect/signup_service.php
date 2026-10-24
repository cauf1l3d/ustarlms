<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Signup metadata around the one canonical Academy registration writer. No login or role grants. */
final class signup_service {
    public static function submit(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $browser,
            #[\SensitiveParameter] string $command, #[\SensitiveParameter] array $input,
            string $keyid, string $provider, string $tenant, #[\SensitiveParameter] string $secrethex): array {
        $input = signup_request::form($input);
        $payloadhash = signup_request::fingerprint($input, $secrethex);
        return identity_service::locked(function() use ($token, $browser, $command, $input, $keyid,
                $provider, $tenant, $payloadhash): array {
            global $DB;
            // Current link revision/flags are checked before both creation and receipt replay.
            entry_service::status($token, $browser, $keyid, $provider, $tenant);
            $session = entry_service::require_session($token, $browser, $keyid, $provider, $tenant);
            $sender = new identity($session->provider, $session->tenant, $session->subject);
            $commandhash = signup_request::command_hash($sender, $command);
            $receipt = $DB->get_record('local_ustar_conn_signups', ['identityhash' => $sender->key()]);
            if ($receipt) {
                if (!hash_equals($receipt->commandhash, $commandhash)) {
                    throw new \InvalidArgumentException('already_registered');
                }
                if ((int)$receipt->expiresat <= time()) { throw new \InvalidArgumentException('receipt_expired'); }
                if ($receipt->keyid !== $keyid || !hash_equals($receipt->payloadhash, $payloadhash)) {
                    throw new \InvalidArgumentException('idempotency_conflict');
                }
                if ((int)$receipt->userid !== (int)$session->userid || (int)$receipt->linkid !== (int)$session->linkid
                        || (int)$receipt->linkrevision !== (int)$session->linkrevision) {
                    throw new \InvalidArgumentException('revoked_entry');
                }
                return ['outcome' => 'registered', 'replayed' => true,
                    'registration' => self::registration((int)$receipt->userid)];
            }
            if ((int)$session->linkid || $DB->record_exists('local_ustar_conn_links', ['identityhash' => $sender->key()])) {
                throw new \InvalidArgumentException('already_registered');
            }
            // No outer transaction: canonical register retains its email lock through the REAL commit.
            $userid = \local_ustar\registration_service::register($input,
                function(int $userid, int $requestid) use ($sender, $session, $keyid, $commandhash, $payloadhash): void {
                    global $DB;
                    $now = time();
                    $linkid = (int)$DB->insert_record('local_ustar_conn_links', (object)[
                        'identityhash' => $sender->key(), 'userchannelhash' => hash('sha256', $userid . ':' . $sender->provider),
                        'userid' => $userid, 'provider' => $sender->provider, 'tenant' => $sender->tenant,
                        'subject' => $sender->subject, 'state' => 'linked', 'revision' => 1,
                        'timecreated' => $now, 'timemodified' => $now,
                    ]);
                    $DB->insert_record('local_ustar_conn_signups', (object)[
                        'identityhash' => $sender->key(), 'commandhash' => $commandhash, 'payloadhash' => $payloadhash,
                        'keyid' => $keyid, 'userid' => $userid, 'requestid' => $requestid,
                        'linkid' => $linkid, 'linkrevision' => 1, 'timecreated' => $now, 'expiresat' => $now+30*DAYSECS,
                    ]);
                    // Only this proven browser explicitly submitted the signup. No other session is upgraded.
                    $session->userid = $userid; $session->linkid = $linkid; $session->linkrevision = 1;
                    $DB->update_record('local_ustar_conn_sessions', $session);
                });
            return ['outcome' => 'registered', 'replayed' => false, 'registration' => self::registration($userid)];
        });
    }

    public static function status(string $token, string $browser, string $keyid, string $provider, string $tenant): array {
        $state = entry_service::status($token, $browser, $keyid, $provider, $tenant);
        $session = entry_service::require_session($token, $browser, $keyid, $provider, $tenant);
        return $state + ['registration' => (int)$session->userid ? self::registration((int)$session->userid) : null];
    }

    public static function options(string $token, string $browser, string $keyid, string $provider, string $tenant): array {
        entry_service::status($token, $browser, $keyid, $provider, $tenant);
        return ['departments' => \local_ustar\registration_service::departments()];
    }

    private static function registration(int $userid): ?array {
        $request = \local_ustar\registration_service::state($userid)['request'];
        if (!$request) { return null; }
        return ['status' => (string)$request->status, 'submitted_at' => (int)$request->timecreated,
            'reviewed_at' => empty($request->reviewedat) ? null : (int)$request->reviewedat];
    }
}
