<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Structural request values only. Canonical registration owns all business validation. */
final class signup_request {
    public const FIELDS = ['departmentid', 'email', 'firstname', 'lastname', 'password', 'username'];

    public static function form(#[\SensitiveParameter] array $input): array {
        ksort($input);
        if (array_keys($input) !== self::FIELDS) { throw new \InvalidArgumentException('invalid_payload'); }
        foreach ($input as $value) {
            if (!is_string($value) || strlen($value) > 4096 || str_contains($value, "\0")) {
                throw new \InvalidArgumentException('invalid_payload');
            }
        }
        return $input;
    }

    /** Key stays outside the DB, preventing a receipt from becoming a fast password verifier. */
    public static function fingerprint(#[\SensitiveParameter] array $input,
            #[\SensitiveParameter] string $secrethex): string {
        $input = self::form($input);
        if (!preg_match('/\A[0-9a-f]{64}\z/', $secrethex)) {
            throw new \InvalidArgumentException('invalid_transport');
        }
        return hash_hmac('sha256', "ustar-connect-signup-payload-v1\0" .
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), hex2bin($secrethex));
    }

    public static function command_hash(identity $sender, #[\SensitiveParameter] string $command): string {
        return hash('sha256', "ustar-connect-signup-command-v1\0" . $sender->key() . ':' .
            opaque_token::digest($command, 'ur1'));
    }

    public static function validation_error(\local_ustar\registration_validation_exception $error): array {
        return ['ok' => false, 'reason' => 'validation_failed',
            'field' => in_array($error->field, self::FIELDS, true) ? $error->field : 'form'];
    }
}
