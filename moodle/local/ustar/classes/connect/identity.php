<?php
namespace local_ustar\connect;

defined('MOODLE_INTERNAL') || die();

/** Namespaced provider value; construction alone is NOT authentication. */
final class identity {
    public function __construct(
        public readonly string $provider,
        public readonly string $tenant,
        public readonly string $subject
    ) {
        if (!in_array($provider, ['telegram', 'bitrix', 'whatsapp'], true)) {
            throw new \InvalidArgumentException('invalid_provider');
        }
        foreach ([$tenant, $subject] as $value) {
            if (!preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $value)) {
                throw new \InvalidArgumentException('invalid_provider_identity');
            }
        }
    }

    public function key(): string {
        return hash('sha256', "ustar-connect-identity-v1\0" .
            json_encode([$this->provider, $this->tenant, $this->subject], JSON_UNESCAPED_SLASHES));
    }

    public static function issue_code(): string {
        return 'uc1_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Byte-compatible with CON01A worker; hash is not an access token. */
    public static function code_hash(string $code): string {
        if (!preg_match('/\Auc1_[A-Za-z0-9_-]{43}\z/', $code)) {
            throw new \InvalidArgumentException('invalid_link_code');
        }
        $encoded = substr($code, 4);
        $bytes = base64_decode(strtr($encoded, '-_', '+/') . '=', true);
        if ($bytes === false || rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') !== $encoded) {
            throw new \InvalidArgumentException('invalid_link_code');
        }
        return hash('sha256', "ustar-connect-link-v1\0" . $bytes);
    }
}
