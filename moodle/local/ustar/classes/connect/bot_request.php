<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Pure v4 bot envelope. Transport authentication is a separate mandatory boundary. */
final class bot_request {
    public const FIELDS = ['auth_date','callback','event','media','message','provider','subject','tenant','text'];
    public static function validate(array $value, int $now): array {
        $keys=array_keys($value); sort($keys);
        if ($keys!==self::FIELDS || !is_int($value['auth_date'])
                || $value['auth_date']<$now-120 || $value['auth_date']>$now+30) { self::deny(); }
        foreach ($value as $key=>$item) {
            if ($key!=='auth_date' && !is_string($item)) { self::deny(); }
        }
        foreach (['event','message','subject','tenant'] as $key) {
            if (!preg_match('/\A[1-9][0-9]{0,15}\z/',$value[$key]) || (int)$value[$key]>=4503599627370496) { self::deny(); }
        }
        if ($value['provider']!=='telegram' || strlen($value['text'])>12000
                || preg_match_all('/./us',$value['text'])>4000 || !preg_match('//u',$value['text'])
                || strlen($value['media'])>128
                || ($value['media']!=='' && !preg_match('/\Abf4_[A-Za-z0-9_-]{43}\z/',$value['media']))) { self::deny(); }
        if ($value['callback']!=='') {
            try { self::digest($value['callback']); } catch (\InvalidArgumentException $e) { self::deny(); }
            if ($value['text']!=='' || $value['media']!=='') { self::deny(); }
        }
        return $value;
    }
    public static function token(): string {
        return 'cb4_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
    }
    public static function digest(string $value): string {
        if (!preg_match('/\Acb4_[A-Za-z0-9_-]{43}\z/',$value)) { self::deny(); }
        $bytes=base64_decode(strtr(substr($value,4),'-_','+/').'=',true);
        if ($bytes===false || 'cb4_'.rtrim(strtr(base64_encode($bytes),'+/','-_'),'=')!==$value) { self::deny(); }
        return hash('sha256',"ustar-connect-bot-action-v4\0".$bytes);
    }
    private static function deny(): never { throw new \InvalidArgumentException('invalid_bot_request'); }
}
