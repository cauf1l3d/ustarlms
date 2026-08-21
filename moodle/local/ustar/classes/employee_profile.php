<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only operational employee profile used by HR Control Center.
 * Every metric is derived from Moodle/USTAR persisted data.
 */
class employee_profile {

    /** @return array<string,mixed> */
    public static function build(int $userid): array {
        global $DB;

        $user = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0],
            'id,username,firstname,lastname,email,suspended,lastaccess',
            MUST_EXIST
        );

        $structure = structure::get(structure::NAME_STRUCTURE);
        $positions = people::position_map($structure);
        $departments = people::department_map($structure);

        $positionid = people::position_id($userid);
        $position = $positions[$positionid] ?? null;
        $departmentid = $position
            ? trim((string)($position['department'] ?? ''))
            : '';
        $department = $departments[$departmentid] ?? null;

        $accounttype = accounts::type_of($userid);
        $accountlabels = accounts::labels();

        $learning = self::learning($userid, $positionid);
        $skills = self::skills($userid, $positionid, $structure);
        $knowledge = compliance::for_user($userid);
        $audit = self::audit($userid);

        $requiredskillcount = count($skills['items']);
        $confirmedskillcount = $skills['confirmed'];

        return [
            'identity' => [
                'userid' => (int)$user->id,
                'username' => (string)$user->username,
                'firstname' => (string)$user->firstname,
                'lastname' => (string)$user->lastname,
                'fullname' => fullname($user),
                'email' => (string)$user->email,
                'suspended' => !empty($user->suspended),
                'lastaccess' => (int)$user->lastaccess,
                'positionid' => $positionid,
                'position' => $position['name'] ?? '',
                'departmentid' => $departmentid,
                'department' => $department['name'] ?? $departmentid,
                'accounttype' => $accounttype,
                'accounttypelabel' => $accountlabels[$accounttype] ?? $accounttype,
                'participates' => accounts::participates($userid),
            ],
            'learning' => $learning,
            'knowledge' => $knowledge,
            'skills' => $skills,
            'assignments' => $learning['assignment'],
            'audit' => $audit,
            'readiness' => [
                'requiredskills' => $requiredskillcount,
                'confirmedskills' => $confirmedskillcount,
                'gaps' => max(0, $requiredskillcount - $confirmedskillcount),
                'percent' => $requiredskillcount > 0
                    ? (int)round(($confirmedskillcount / $requiredskillcount) * 100)
                    : 0,
                'basis' => 'evidence',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function learning(int $userid, string $positionid): array {
        $plan = assignment::plan_user($userid);
        $livecourses = \local_ustar\external\base::user_courses($userid);

        $livebyid = [];
        foreach ($livecourses as $course) {
            $livebyid[(int)$course['id']] = $course;
        }

        $items = [];
        $completed = 0;
        $inprogress = 0;
        $notstarted = 0;

        foreach ($plan['required'] ?? [] as $required) {
            $courseid = (int)$required['id'];
            $live = $livebyid[$courseid] ?? null;
            $progress = $live ? (int)($live['progress'] ?? 0) : 0;
            $status = $live ? (string)($live['status'] ?? 'new') : 'new';

            if ($progress >= 100 || $status === 'done') {
                $state = 'completed';
                $completed++;
            } else if ($status === 'active' || $progress > 0) {
                $state = 'inprogress';
                $inprogress++;
            } else {
                $state = 'notstarted';
                $notstarted++;
            }

            $next = $live['nextActivity'] ?? null;

            $items[] = [
                'courseid' => $courseid,
                'name' => (string)$required['name'],
                'visible' => !empty($required['visible']),
                'progress' => $progress,
                'status' => $state,
                'statuslabel' => match ($state) {
                    'completed' => 'Завершено',
                    'inprogress' => 'В процессе',
                    default => 'Не начато',
                },
                'tracked' => $live ? (int)($live['tracked'] ?? 0) : 0,
                'done' => $live ? (int)($live['done'] ?? 0) : 0,
                'nextname' => $next['name'] ?? '',
                'hasnext' => !empty($next['name']),
                'url' => (new \moodle_url('/local/ustar/home.php', [
                    'view' => 'learning',
                    'courseid' => $courseid,
                    'theme' => 'ustar',
                ]))->out(false),
            ];
        }

        return [
            'positionid' => $positionid,
            'assigned' => count($items),
            'completed' => $completed,
            'inprogress' => $inprogress,
            'notstarted' => $notstarted,
            'items' => $items,
            'assignment' => [
                'status' => (string)($plan['status'] ?? ''),
                'required' => array_values($plan['required'] ?? []),
                'alreadyenrolled' => array_values($plan['alreadyEnrolled'] ?? []),
                'toenrol' => array_values($plan['toEnrol'] ?? []),
                'missingmanualinstance' => array_values($plan['missingManualInstance'] ?? []),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function skills(int $userid, string $positionid, array $structure): array {
        if ($positionid === '') {
            return [
                'required' => 0,
                'confirmed' => 0,
                'gaps' => 0,
                'items' => [],
            ];
        }

        $required = $structure['matrix'][$positionid] ?? [];
        $skillmap = [];
        foreach ($structure['skills'] ?? [] as $skill) {
            $skillmap[(string)$skill['id']] = $skill;
        }

        $items = [];
        $confirmed = 0;

        foreach ($required as $skillid => $targetlevel) {
            $evaluation = evidence::evaluate_skill((string)$skillid, $positionid, $userid);
            $satisfied = !empty($evaluation['satisfied']);
            if ($satisfied) {
                $confirmed++;
            }

            $best = $evaluation['bestpath'] ?? null;
            $sources = [];
            foreach (($best['items'] ?? []) as $item) {
                $source = trim((string)($item['activityname'] ?? ''));
                if ($source === '') {
                    $source = !empty($item['cmid'])
                        ? strtoupper((string)($item['modname'] ?? 'activity')) . ' #' . (int)$item['cmid']
                        : (!empty($item['courseid']) ? 'Course #' . (int)$item['courseid'] : (string)$item['type']);
                }

                $sources[] = [
                    'label' => $source,
                    'type' => (string)$item['type'],
                    'status' => (string)$item['status'],
                    'satisfied' => !empty($item['satisfied']),
                    'required' => !empty($item['required']),
                ];
            }

            $skill = $skillmap[(string)$skillid] ?? [];
            $items[] = [
                'skillid' => (string)$skillid,
                'name' => (string)($skill['name'] ?? $skillid),
                'category' => (string)($skill['category'] ?? ''),
                'targetlevel' => (int)$targetlevel,
                'evidencedlevel' => $satisfied ? (int)$targetlevel : 0,
                'configured' => !empty($evaluation['configured']),
                'satisfied' => $satisfied,
                'gap' => !$satisfied,
                'progress' => $evaluation['progress'] === null
                    ? null
                    : (int)$evaluation['progress'],
                'pathkey' => $best['pathkey'] ?? '',
                'sources' => $sources,
                'hassources' => !empty($sources),
            ];
        }

        usort($items, static function(array $a, array $b): int {
            if ($a['satisfied'] !== $b['satisfied']) {
                return $a['satisfied'] ? 1 : -1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return [
            'required' => count($items),
            'confirmed' => $confirmed,
            'gaps' => max(0, count($items) - $confirmed),
            'items' => $items,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function audit(int $userid): array {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_hr_actions'))) {
            return [];
        }

        $actionlabels = [
            'person_created' => 'Сотрудник создан',
            'person_updated' => 'Профиль изменён',
            'person_imported' => 'Сотрудник импортирован',
            'bulk_position_updated' => 'Должность изменена импортом',
            'position_bulk_assigned' => 'Должность назначена',
            'assignment_synced' => 'Обучение синхронизировано',
            'assignment_sync_failed' => 'Ошибка синхронизации обучения',
            'account_type_changed' => 'Тип учётной записи изменён',
        ];

        $rows = [];
        foreach ($DB->get_records(
            'local_ustar_hr_actions',
            ['targetuserid' => $userid],
            'timecreated DESC',
            '*',
            0,
            20
        ) as $action) {
            $details = json_decode((string)$action->detailsjson, true);
            $rows[] = [
                'action' => (string)$action->action,
                'label' => $actionlabels[$action->action] ?? (string)$action->action,
                'actorid' => (int)$action->actorid,
                'timecreated' => (int)$action->timecreated,
                'time' => userdate((int)$action->timecreated, '%d.%m.%Y %H:%M'),
                'details' => is_array($details) ? $details : [],
            ];
        }

        return $rows;
    }
}
