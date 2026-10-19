<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Short-lived secrets with separate purposes; raw values never belong in storage. */
final class opaque_token {
    public static function issue(string $prefix): string {
        self::prefix($prefix);
        return $prefix . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
    public static function digest(string $token, string $prefix): string {
        self::prefix($prefix);
        if (!preg_match('/\A' . $prefix . '_[A-Za-z0-9_-]{43}\z/', $token)) {
            throw new \InvalidArgumentException('invalid_opaque_token');
        }
        $encoded = substr($token, 4);
        $bytes = base64_decode(strtr($encoded, '-_', '+/') . '=', true);
        if ($bytes === false || rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') !== $encoded) {
            throw new \InvalidArgumentException('invalid_opaque_token');
        }
        return hash('sha256', 'ustar-connect-' . $prefix . "-v1\0" . $bytes);
    }
    private static function prefix(string $prefix): void {
        if (!in_array($prefix, ['ul1', 'ue1', 'ub1', 'ur1', 'ud1'], true)) {
            throw new \InvalidArgumentException('invalid_token_purpose');
        }
    }
}
