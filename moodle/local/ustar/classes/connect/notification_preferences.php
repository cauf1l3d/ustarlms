<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Personal opt-in, stored in the existing Moodle preference store. */
final class notification_preferences {
    public const NAME = 'local_ustar_connect_notify_v1';
    public const CATEGORIES = ['registration', 'tasks', 'feed', 'grades'];
    public const SEVERITIES = ['normal', 'action', 'critical'];
    public static function defaults(): array {
        return ['enabled' => false, 'categories' => self::CATEGORIES, 'min_severity' => 'normal',
            'quiet_start' => '22:00', 'quiet_end' => '08:00', 'timezone' => 'Europe/Moscow', 'interval_minutes' => 5];
    }
    public static function validate(array $value): array {
        $keys = array_keys($value); sort($keys); $expected = array_keys(self::defaults()); sort($expected);
        if ($keys !== $expected || !is_bool($value['enabled']) || !is_array($value['categories'])
                || !array_is_list($value['categories']) || count($value['categories']) > 4
                || !is_string($value['min_severity']) || !in_array($value['min_severity'], self::SEVERITIES, true)
                || !is_int($value['interval_minutes']) || $value['interval_minutes'] < 1 || $value['interval_minutes'] > 1440
                || !is_string($value['timezone']) || !in_array($value['timezone'], \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException('invalid_preferences');
        }
        foreach ($value['categories'] as $category) {
            if (!is_string($category) || !in_array($category, self::CATEGORIES, true)) {
                throw new \InvalidArgumentException('invalid_preferences');
            }
        }
        if (count(array_unique($value['categories'])) !== count($value['categories'])) {
            throw new \InvalidArgumentException('invalid_preferences');
        }
        foreach (['quiet_start', 'quiet_end'] as $field) {
            if (!is_string($value[$field]) || !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $value[$field])) {
                throw new \InvalidArgumentException('invalid_preferences');
            }
        }
        return $value;
    }
    public static function for_user(int $userid): array {
        global $DB;
        // No per-process preference cache: revocation must be observed immediately at begin.
        $raw = $DB->get_field('user_preferences', 'value', ['userid' => $userid, 'name' => self::NAME]);
        if ($raw === false) { return self::defaults(); }
        try { return self::validate(json_decode($raw, true, 16, JSON_THROW_ON_ERROR)); }
        catch (\Throwable $error) { return self::defaults(); }
    }
    public static function save_own(array $value): array {
        global $USER;
        identity_service::owned_links();
        $value = self::validate($value);
        set_user_preference(self::NAME, json_encode($value, JSON_THROW_ON_ERROR), (int)$USER->id);
        return $value;
    }
    public static function allowed_now(array $value, int $now): bool {
        if ($value['quiet_start'] === $value['quiet_end']) { return true; }
        $clock = (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone($value['timezone']))->format('H:i');
        return $value['quiet_start'] < $value['quiet_end']
            ? !($clock >= $value['quiet_start'] && $clock < $value['quiet_end'])
            : !($clock >= $value['quiet_start'] || $clock < $value['quiet_end']);
    }
    public static function next_allowed(array $value, int $now): int {
        if (self::allowed_now($value, $now)) { return $now; }
        // Search actual instants, including DST gaps/folds; bounded by one day plus a DST transition.
        for ($next = $now + 60; $next <= $now + 26 * HOURSECS; $next += 60) {
            if (self::allowed_now($value, $next)) { return $next; }
        }
        return $now + DAYSECS;
    }
}
