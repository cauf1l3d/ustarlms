<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Shared achievement facts for self-service and already-authorized staff profiles. */
final class achievement_summary {
    public static function read(int $userid, ?array $courses = null): array {
        global $CFG, $DB;
        $courses ??= external\base::user_courses($userid);
        $xp = reward_control::xp($userid, $courses);
        $level = (int)floor(sqrt(max(0, $xp['xp']) / 50)) + 1;
        require_once($CFG->libdir . '/badgeslib.php');
        $badges = [];
        if (function_exists('badges_get_user_badges')) {
            foreach (badges_get_user_badges($userid) as $badge) {
                $badges[] = ['name' => $badge->name, 'dateissued' => (int)$badge->dateissued];
            }
        }
        $badges = array_merge($badges, route_rewards::summary($userid)['badges']);
        $sql = "SELECT COUNT(DISTINCT " . $DB->sql_concat(
            "EXTRACT(YEAR FROM to_timestamp(timemodified))", "'-'",
            "EXTRACT(DOY FROM to_timestamp(timemodified))") . ")
                FROM {course_modules_completion} WHERE userid = :uid AND timemodified > :since";
        try {
            $streak = (int)$DB->count_records_sql($sql, ['uid' => $userid, 'since' => time() - 30 * DAYSECS]);
        } catch (\dml_read_exception $e) {
            $streak = 0;
        }
        return [
            'xp' => $xp['xp'],
            'gameXp' => $xp['gamexp'],
            'level' => $level,
            'nextLevelXp' => 50 * $level * $level,
            'completedCourses' => count(array_filter($courses, static fn($course) => $course['progress'] >= 100)),
            'badges' => $badges,
            'activeDays30' => $streak,
        ];
    }

    public static function badges_for_display(array $badges): array {
        return array_map(static fn($badge) => [
            'name' => (string)($badge['name'] ?? 'Награда'),
            'date' => empty($badge['dateissued']) ? '' : userdate((int)$badge['dateissued'], '%d.%m.%Y'),
            'icon' => ui::icon('trophy', 'u-feature-icon'),
        ], $badges);
    }
}
