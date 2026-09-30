<?php
namespace local_ustar\task_workspace;
defined('MOODLE_INTERNAL') || die();

/** Explicit task calendar. Never guesses holidays or an employee's shift. */
final class calendar {
    public static function timezone(string $name): \DateTimeZone {
        try { return new \DateTimeZone($name); }
        catch (\Exception $e) { throw new \invalid_parameter_exception('Укажите часовой пояс из списка IANA.'); }
    }

    public static function timestamp(string $date, string $clock, string $zone): int {
        $tz = self::timezone($zone);
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $clock, $tz);
        if (!$value || $value->format('Y-m-d H:i') !== $date . ' ' . $clock) {
            throw new \invalid_parameter_exception('Укажите корректную дату и время.');
        }
        return $value->getTimestamp();
    }

    public static function date(int $timestamp, string $zone): string {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(self::timezone($zone))->format('Y-m-d');
    }

    public static function next_date(string $date, string $zone): string {
        return (new \DateTimeImmutable($date, self::timezone($zone)))->modify('+1 day')->format('Y-m-d');
    }

    public static function is_workday(string $date, array $policy): bool {
        $day = (int)(new \DateTimeImmutable($date, self::timezone($policy['timezone'])))->format('N');
        return in_array($day, $policy['weekdays'], true) && !in_array($date, $policy['excludedates'], true);
    }

    /** DST-aware business-clock addition, limited to a year to reject impossible calendars. */
    public static function add_minutes(int $from, int $minutes, array $policy): int {
        if ($policy['mode'] === 'calendar') { return $from + $minutes * 60; }
        $cursor = (new \DateTimeImmutable('@' . $from))->setTimezone(self::timezone($policy['timezone']));
        $remaining = $minutes * 60;
        for ($i = 0; $i < 367; $i++) {
            $date = $cursor->format('Y-m-d');
            if (self::is_workday($date, $policy)) {
                $start = self::timestamp($date, $policy['startclock'], $policy['timezone']);
                $end = self::timestamp($date, $policy['endclock'], $policy['timezone']);
                $at = max($cursor->getTimestamp(), $start);
                if ($at < $end) {
                    $available = $end - $at;
                    if ($remaining <= $available) { return $at + $remaining; }
                    $remaining -= $available;
                }
            }
            $cursor = $cursor->modify('+1 day')->setTime(0, 0);
        }
        throw new \invalid_parameter_exception('Рабочий календарь не позволяет рассчитать срок в пределах года.');
    }
}
