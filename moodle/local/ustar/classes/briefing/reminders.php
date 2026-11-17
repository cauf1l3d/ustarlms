<?php
namespace local_ustar\briefing;

use local_ustar\workflow_notifications;

defined('MOODLE_INTERNAL') || die();

/** One reminder per authorized manager, department and weekly report. */
final class reminders {
    public static function permitted(int $actor, string $key): bool {
        if (!preg_match('/\Abriefing:([0-9]{4}-[0-9]{2}-[0-9]{2}):([a-f0-9]{24}):' . $actor . '\z/', $key, $matches)) {
            return false;
        }
        [, $week, $departmentkey] = $matches;
        if ($week !== service::current_week() || time() >= service::week_start($week)) {
            return false;
        }
        foreach (service::manager_departments($actor) as $department => $row) {
            if (self::department_key($department) === $departmentkey) {
                return self::pending($department, $week);
            }
        }
        return false;
    }

    private static function department_key(string $department): string {
        return substr(hash('sha256', $department), 0, 24);
    }

    private static function pending(string $department, string $week): bool {
        return !empty(service::template($department)->active) && empty(service::report($department, $week)->submittedat);
    }

    public static function run(): void {
        global $DB;
        if (!service::available()) {
            return;
        }
        $week = service::current_week();
        $meeting = service::week_start($week);
        $deadline = $meeting - service::due_minutes() * MINSECS;
        $now = time();
        if ($now < $deadline - HOURSECS || $now >= $meeting) {
            return;
        }
        $users = $DB->get_fieldset_select('local_ustar_assignments', 'DISTINCT userid',
            "status = 'active' AND (effectivefrom = 0 OR effectivefrom <= :fromtime)
                AND (effectiveto IS NULL OR effectiveto = 0 OR effectiveto > :totime)",
            ['fromtime' => $now, 'totime' => $now]);
        foreach ($users as $user) {
            $actor = (int)$user;
            foreach (service::manager_departments($actor) as $department => $row) {
                $key = 'briefing:' . $week . ':' . self::department_key($department) . ':' . $actor;
                if (self::pending($department, $week)) {
                    workflow_notifications::enqueue($actor, 'briefing_reminder', 'Пора сдать отчёт к брифингу',
                        userdate($deadline, '%d.%m %H:%M', service::ZONE) . ' МСК',
                        '/local/ustar/tasks.php?view=briefing&briefsection=report&briefdept=' . rawurlencode($department), $key);
                }
            }
        }
    }
}
