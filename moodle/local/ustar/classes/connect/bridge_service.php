<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Fixed operation router. Service credentials cannot choose Moodle actors or PHP methods. */
final class bridge_service {
    public static function dispatch(string $body, array $headers, array $keys): array {
        $request = bridge_auth::verify($body, $headers, $keys, time());
        $op = $request['operation'];
        $payload = $request['payload'];
        $fields = match ($op) {
            'launch.create' => ['provider', 'subject', 'tenant'],
            'launch.exchange' => ['auth_date', 'browser', 'launch', 'proof', 'provider', 'subject', 'tenant'],
            'entry.status', 'entry.revoke' => ['browser', 'session'],
            'link.claim' => ['auth_date', 'code', 'proof', 'provider', 'subject', 'tenant'],
        };
        $actual = array_keys($payload); sort($actual);
        if ($actual !== $fields) { throw new \InvalidArgumentException('invalid_payload'); }
        foreach ($payload as $key => $value) {
            if ($key === 'auth_date' ? !is_int($value) : !is_string($value)) {
                throw new \InvalidArgumentException('invalid_payload');
            }
        }
        $sender = null;
        if (isset($payload['provider'])) {
            if ($payload['provider'] !== $request['provider'] || $payload['tenant'] !== $request['tenant']) {
                throw new \InvalidArgumentException('provider_mismatch');
            }
            $sender = new identity($payload['provider'], $payload['tenant'], $payload['subject']);
        }
        identity_service::remember_transport($request['nonce_hash'], $request['nonce_expires']);
        $kid = $request['key_id'];
        if ($op === 'launch.create') { return entry_service::create_launch($sender, $kid); }
        if ($op === 'launch.exchange') {
            return entry_service::exchange($sender, $payload['launch'], $payload['proof'],
                $payload['auth_date'], $payload['browser'], $kid);
        }
        if ($op === 'entry.status') {
            return entry_service::status($payload['session'], $payload['browser'], $kid, $request['provider'], $request['tenant']);
        }
        if ($op === 'entry.revoke') {
            entry_service::revoke($payload['session'], $payload['browser'], $kid, $request['provider'], $request['tenant']);
            return ['revoked' => true];
        }
        if ($payload['auth_date'] < time()-300 || $payload['auth_date'] > time()+30) {
            throw new \InvalidArgumentException('expired_launch');
        }
        identity_service::claim_verified($payload['code'], $sender, $payload['proof']);
        return ['claimed' => true, 'academy_confirmation_required' => true];
    }
}
