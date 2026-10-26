<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Trust server TLS state and exact peers only, never client-forwarded headers. */
final class bridge_http {
    public static function guard(array $server, bool $enabled, array $peers): void {
        if (!$enabled) { throw new \InvalidArgumentException('bridge_disabled'); }
        $peer = $server['REMOTE_ADDR'] ?? '';
        if (!is_string($peer) || !filter_var($peer, FILTER_VALIDATE_IP) || !in_array($peer, $peers, true)
                || !in_array(strtolower((string)($server['HTTPS'] ?? '')), ['on', '1'], true)) {
            throw new \InvalidArgumentException('invalid_transport');
        }
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST' || ($server['QUERY_STRING'] ?? '') !== ''
                || parse_url((string)($server['REQUEST_URI'] ?? ''), PHP_URL_PATH) !== bridge_auth::PATH
                || strtolower(trim(explode(';', (string)($server['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json'
                || (int)($server['CONTENT_LENGTH'] ?? 0) > 16384) {
            throw new \InvalidArgumentException('invalid_request');
        }
    }
}
