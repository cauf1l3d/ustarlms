<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Fixed operation router. Service credentials cannot choose Moodle actors or PHP methods. */
final class bridge_service {
    public static function dispatch(#[\SensitiveParameter] string $body, array $headers,
            #[\SensitiveParameter] array $keys): array {
        $request = bridge_auth::verify($body, $headers, $keys, time());
        $op = $request['operation'];
        $payload = $request['payload'];
        $fields = match ($op) {
            'launch.create' => ['provider', 'subject', 'tenant'],
            'launch.exchange' => ['auth_date', 'browser', 'launch', 'proof', 'provider', 'subject', 'tenant'],
            'entry.status', 'entry.revoke' => ['browser', 'session'],
            'registration.status', 'registration.options' => ['browser', 'session'],
            'registration.submit' => ['browser', 'command', 'departmentid', 'email', 'firstname', 'lastname', 'password', 'session', 'username'],
            'link.claim' => ['auth_date', 'code', 'proof', 'provider', 'subject', 'tenant'],
            'notification.lease' => ['limit'],
            'notification.begin' => ['delivery', 'lease'],
            'notification.finish' => ['delivery', 'lease', 'outcome', 'provider_ref', 'reason', 'retry_after'],
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
        if ($op === 'notification.lease') {
            if ($payload['limit'] !== '1') { throw new \InvalidArgumentException('invalid_payload'); }
            return notification_delivery::lease($kid, $request['provider'], $request['tenant']) ?? ['status' => 'empty'];
        }
        if ($op === 'notification.begin' || $op === 'notification.finish') {
            if (!preg_match('/\A[1-9][0-9]{0,18}\z/', $payload['delivery'])
                    || filter_var($payload['delivery'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new \InvalidArgumentException('invalid_delivery');
            }
            if ($op === 'notification.begin') {
                return notification_delivery::begin((int)$payload['delivery'], $payload['lease'], $kid, $request['provider'], $request['tenant']);
            }
            if (!preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/', $payload['retry_after'])) { throw new \InvalidArgumentException('invalid_delivery'); }
            return notification_delivery::finish((int)$payload['delivery'], $payload['lease'], $payload['outcome'],
                $payload['provider_ref'], $payload['reason'], (int)$payload['retry_after'], $kid, $request['provider'], $request['tenant']);
        }
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
        if ($op === 'registration.submit') {
            $form = array_intersect_key($payload, array_flip(signup_request::FIELDS));
            return signup_service::submit($payload['session'], $payload['browser'], $payload['command'], $form,
                $kid, $request['provider'], $request['tenant'], $keys[$kid]['secret']);
        }
        if ($op === 'registration.status') {
            return signup_service::status($payload['session'], $payload['browser'], $kid, $request['provider'], $request['tenant']);
        }
        if ($op === 'registration.options') {
            return signup_service::options($payload['session'], $payload['browser'], $kid, $request['provider'], $request['tenant']);
        }
        if ($payload['auth_date'] < time()-300 || $payload['auth_date'] > time()+30) {
            throw new \InvalidArgumentException('expired_launch');
        }
        identity_service::claim_verified($payload['code'], $sender, $payload['proof']);
        return ['claimed' => true, 'academy_confirmation_required' => true];
    }
}
