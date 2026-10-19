<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

/** Pure request verifier. A successful result still needs durable nonce consumption. */
final class bridge_auth {
    public const PATH = '/local/ustar/connect_bridge.php';
    public const OPERATIONS = ['launch.create', 'launch.exchange', 'entry.status', 'entry.revoke', 'link.claim',
        'registration.options', 'registration.submit', 'registration.status',
        'notification.lease', 'notification.begin', 'notification.finish',
        'actor.open', 'actor.status', 'actor.revoke'];

    public static function verify(#[\SensitiveParameter] string $body, array $headers,
            #[\SensitiveParameter] array $keys, int $now): array {
        if (strlen($body) > 16384) { throw new \InvalidArgumentException('invalid_transport'); }
        $kid = $headers['X-Ustar-Key'] ?? '';
        $time = $headers['X-Ustar-Time'] ?? '';
        $nonce = $headers['X-Ustar-Nonce'] ?? '';
        $signature = $headers['X-Ustar-Signature'] ?? '';
        if (!is_string($kid) || !preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $kid)
                || !is_string($time) || !preg_match('/\A[1-9][0-9]{0,11}\z/', $time)
                || abs((int)$time - $now) > 30 || !is_string($nonce)
                || !preg_match('/\Arn1_[A-Za-z0-9_-]{43}\z/', $nonce)
                || !is_string($signature) || !preg_match('/\A[0-9a-f]{64}\z/', $signature)) {
            throw new \InvalidArgumentException('invalid_transport');
        }
        $encoded = substr($nonce, 4);
        $decoded = base64_decode(strtr($encoded, '-_', '+/') . '=', true);
        if ($decoded === false || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $encoded) {
            throw new \InvalidArgumentException('invalid_transport');
        }
        $key = $keys[$kid] ?? null;
        if (!is_array($key) || !is_string($key['secret'] ?? null)
                || !preg_match('/\A[0-9a-f]{64}\z/', $key['secret'])) {
            throw new \InvalidArgumentException('invalid_transport');
        }
        $message = implode("\n", ['USTAR-CONNECT-BRIDGE-v1', 'POST', self::PATH,
            $kid, $time, $nonce, hash('sha256', $body)]);
        if (!hash_equals(hash_hmac('sha256', $message, hex2bin($key['secret'])), $signature)) {
            throw new \InvalidArgumentException('invalid_transport');
        }
        try { $value = json_decode($body, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new \InvalidArgumentException('invalid_request'); }
        if (!is_array($value) || array_keys($value) !== ['operation', 'payload']
                || !is_string($value['operation']) || !is_array($value['payload'])) {
            throw new \InvalidArgumentException('invalid_request');
        }
        ksort($value['payload']);
        $canonical = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!hash_equals($canonical, $body)) {
            throw new \InvalidArgumentException('invalid_request');
        }
        $operation = $value['operation'];
        if (!in_array($operation, self::OPERATIONS, true) || !is_array($key['operations'] ?? null)
                || !in_array($operation, $key['operations'], true)) {
            throw new \InvalidArgumentException('forbidden_operation');
        }
        if (!in_array($key['provider'] ?? '', ['telegram', 'bitrix', 'whatsapp'], true)
                || !is_string($key['tenant'] ?? null) || $key['tenant'] === '') {
            throw new \InvalidArgumentException('invalid_transport');
        }
        return $value + ['key_id' => $kid, 'provider' => $key['provider'], 'tenant' => $key['tenant'],
            'nonce_hash' => hash('sha256', "ustar-connect-transport-v1\0" . $kid . ':' . $nonce),
            'nonce_expires' => (int)$time + 31];
    }
}
