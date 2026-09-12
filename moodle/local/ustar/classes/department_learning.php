<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Learning visibility for any real USTAR department head.
 */
final class department_learning {

    public static function for_manager(
        int $managerid
    ): array {
        global $DB;

        $scope = self::manager_scope($managerid);

        if (empty($scope['allowed'])) {
            return [
                'allowed' => false,
                'people' => [],
            ];
        }

        $alloweduserids = array_fill_keys(
            array_map('intval', $scope['userids'] ?? []),
            true
        );

        $people = [];
        if (!$alloweduserids) {
            return ['allowed' => true, 'departmentid' => (string)($scope['departmentid'] ?? ''),
                'department' => (string)($scope['department'] ?? ''), 'people' => [], 'haspeople' => false,
                'summary' => ['total' => 0, 'complete' => 0, 'inprogress' => 0, 'notstarted' => 0, 'noroute' => 0]];
        }
        [$usersql, $userparams] = $DB->get_in_or_equal(array_keys($alloweduserids), SQL_PARAMS_NAMED, 'teamuser');

        $sql =
            "SELECT u.id,u.firstname,u.lastname,
                    u.lastaccess,d.data AS positionid
               FROM {user} u
               JOIN {user_info_data} d
                 ON d.userid=u.id
               JOIN {user_info_field} f
                 ON f.id=d.fieldid
                AND f.shortname='ustar_position'
              WHERE u.deleted=0
                AND u.suspended=0
                AND u.id>1 AND u.id {$usersql}";

        foreach ($DB->get_records_sql($sql, $userparams) as $u) {
            if ((int)$u->id === $managerid) {
                continue;
            }

            if (!accounts::participates((int)$u->id)) {
                continue;
            }

            $person =
                org::person(
                    (int)$u->id,
                    fullname($u)
                );

            if (!isset($alloweduserids[(int)$u->id])) {
                continue;
            }

            $snapshot =
                route_model::read_only_snapshot(
                    (string)$person['positionid'],
                    (int)$u->id
                );

            $total =
                (int)(
                    $snapshot['totalpoints']
                    ?? 0
                );

            $done =
                (int)(
                    $snapshot['donepoints']
                    ?? 0
                );

            if (empty($snapshot['ok'])) {
                $state = 'noroute';
                $statelabel =
                    'Маршрут не настроен';

            } else if (
                !empty($snapshot['complete'])
            ) {
                $state = 'complete';
                $statelabel =
                    'Всё актуально';

            } else if ($done > 0) {
                $state = 'inprogress';
                $statelabel =
                    'В процессе';

            } else {
                $state = 'notstarted';
                $statelabel =
                    'Не начат';
            }

            $current =
                $snapshot['currentpoint']
                ?? null;

            $person['avatarurl'] =
                team_presenter::avatar_url(
                    (int)$u->id,
                    64
                );

            $person['state'] = $state;
            $person['statelabel'] = $statelabel;

            $person['progress'] =
                (int)(
                    $snapshot['progress']
                    ?? 0
                );

            $person['donepoints'] = $done;
            $person['totalpoints'] = $total;

            $person['currenttitle'] =
                $current
                ? (string)$current['title']
                : (
                    $state === 'complete'
                    ? 'Все текущие шаги завершены'
                    : '—'
                );

            $activity =
                max(
                    (int)(
                        $snapshot[
                            'lastprogressat'
                        ] ?? 0
                    ),
                    (int)$u->lastaccess
                );

            $person['lastactivity'] =
                $activity > 0
                ? userdate(
                    $activity,
                    '%d.%m.%Y %H:%M'
                )
                : 'Не входил';

            $person['detailurl'] =
                (
                    new \moodle_url(
                        '/local/ustar/team_learning.php',
                        [
                            'userid' =>
                                (int)$u->id,
                        ]
                    )
                )->out(false);

            $people[] = $person;
        }

        $weights = [
            'inprogress' => 10,
            'notstarted' => 20,
            'complete' => 30,
            'noroute' => 40,
        ];

        usort(
            $people,
            static function(
                array $a,
                array $b
            ) use ($weights): int {
                return [
                    $weights[$a['state']] ?? 99,
                    (string)$a['fullname'],
                ] <=> [
                    $weights[$b['state']] ?? 99,
                    (string)$b['fullname'],
                ];
            }
        );

        $summary = [
            'total' => count($people),
            'complete' => 0,
            'inprogress' => 0,
            'notstarted' => 0,
            'noroute' => 0,
        ];

        foreach ($people as $person) {
            if (
                array_key_exists(
                    $person['state'],
                    $summary
                )
            ) {
                $summary[
                    $person['state']
                ]++;
            }
        }

        return [
            'allowed' => true,
            'departmentid' =>
                (string)$scope['departmentid'],
            'department' =>
                (string)$scope['department'],
            'people' => $people,
            'haspeople' => !empty($people),
            'summary' => $summary,
        ];
    }


    public static function detail(
        int $managerid,
        int $userid
    ): array {
        global $DB;

        if (!self::can_view($managerid, $userid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/ustar:viewteam',
                'nopermissions',
                ''
            );
        }

        $u = $DB->get_record(
            'user',
            [
                'id' => $userid,
                'deleted' => 0,
            ],
            'id,firstname,lastname,lastaccess',
            MUST_EXIST
        );

        $person =
            org::person(
                $userid,
                fullname($u)
            );

        $person['avatarurl'] =
            team_presenter::avatar_url(
                $userid,
                112
            );

        $person['lastaccess'] =
            !empty($u->lastaccess)
            ? userdate(
                (int)$u->lastaccess,
                '%d.%m.%Y %H:%M'
            )
            : 'Не входил';

        $snapshot =
            route_model::read_only_snapshot(
                (string)$person['positionid'],
                $userid
            );

        $passed = [];
        $future = [];

        foreach (
            ($snapshot['points'] ?? [])
            as $point
        ) {
            if (!empty($point['done'])) {
                $passed[] = $point;
            } else if (!empty($point['locked'])) {
                $future[] = $point;
            }
        }

        return [
            'person' => $person,
            'snapshot' => $snapshot,
            'current' =>
                $snapshot['currentpoint']
                ?? null,
            'hascurrent' =>
                !empty(
                    $snapshot['currentpoint']
                ),
            'passed' => $passed,
            'haspassed' => !empty($passed),
            'future' => $future,
            'hasfuture' => !empty($future),
        ];
    }


    public static function can_view(
        int $managerid,
        int $userid
    ): bool {
        if (is_siteadmin($managerid)) {
            return true;
        }

        $scope =
            self::manager_scope($managerid);

        if (empty($scope['allowed'])) {
            return false;
        }

        return in_array(
            $userid,
            array_map('intval', $scope['userids'] ?? []),
            true
        );
    }


    private static function manager_scope(
        int $managerid
    ): array {
        return team_access::learning_scope($managerid);
    }
}
