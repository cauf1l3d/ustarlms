<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Immutable completion facts for native USTAR route activities.
 *
 * Uses the existing local_ustar_workflow_events event store.
 * Does not write local_ustar_route_progress directly.
 */
final class native_learning {

    public const TEAM_STRUCTURE = 'team_structure';
    public const ROLE_DEVELOPMENT = 'role_development';
    public const ROLE_SKILLS_CHECK = 'role_skills_check';
    public const TEAM_PROFILE_REVEAL = 'team_profile_reveal';
    public const PRODUCT_MASTERY = 'product_mastery';
    public const PRODUCT_SCORM_ACK = 'product_scorm_ack';

    private const FACTS = [
        self::TEAM_STRUCTURE => [
            'eventtype' => 'native_team_structure',
            'url' => '/local/ustar/route_team.php',
        ],
        self::ROLE_DEVELOPMENT => [
            'eventtype' => 'native_role_development',
            'url' => '/local/ustar/route_career.php',
        ],
        self::ROLE_SKILLS_CHECK => [
            'eventtype' => 'native_role_skills_check',
            'url' => '/local/ustar/route_role_quiz.php',
        ],
        self::TEAM_PROFILE_REVEAL => [
            'eventtype' => 'native_profile_reveal',
            'url' => '/local/ustar/route_profile_reveal.php',
        ],
        self::PRODUCT_MASTERY => [
            'eventtype' => 'native_product_mastery',
            'url' => '/local/ustar/catalog_exam.php',
        ],
        self::PRODUCT_SCORM_ACK => [
            'eventtype' => 'native_product_scorm_ack',
            'url' => '/local/ustar/scorm_launch.php?cmid=41&pointid=69',
        ],
    ];

    /** Native activities that an author can safely attach to another position route. */
    public static function authoring_options(): array {
        return [
            ['id' => self::TEAM_STRUCTURE, 'name' => 'Знакомство с командой и проверка'],
            ['id' => self::ROLE_DEVELOPMENT, 'name' => 'Развитие в своей должности'],
            ['id' => self::ROLE_SKILLS_CHECK, 'name' => 'Проверка навыков должности'],
            ['id' => self::TEAM_PROFILE_REVEAL, 'name' => 'Результат командного профиля'],
        ];
    }

    /** Resolve a native requirement through the employee's current route and position scope. */
    public static function availability(int $userid, string $factkey): array {
        global $DB;
        if (!isset(self::FACTS[$factkey]) || !accounts::participates($userid)) {
            return ['configured' => false, 'reachable' => false];
        }
        $positionid = people::position_id($userid);
        if ($positionid === '') {
            return ['configured' => false, 'reachable' => false];
        }
        $snapshot = route_model::read_only_snapshot($positionid, $userid);
        if (empty($snapshot['ok'])) {
            return ['configured' => false, 'reachable' => false];
        }
        $matches = [];
        $sequential = in_array($factkey, [self::TEAM_STRUCTURE, self::ROLE_DEVELOPMENT,
            self::ROLE_SKILLS_CHECK, self::TEAM_PROFILE_REVEAL], true);
        foreach ($snapshot['points'] ?? [] as $point) {
            $version = $DB->get_record('local_ustar_route_versions',
                ['id' => (int)$point['versionid'], 'pointid' => (int)$point['id'],
                    'status' => route_model::STATUS_PUBLISHED], '*', IGNORE_MISSING);
            if (!$version) { continue; }
            foreach (route_model::requirements_for_version($version) as $requirement) {
                if (($requirement['type'] ?? '') === 'native'
                        && ($requirement['sourcekey'] ?? '') === $factkey) {
                    // Catalog/SCORM completion may arrive before their route
                    // point opens; their existing evidence policy accepts it.
                    $matches[] = ['configured' => true, 'reachable' => !$sequential || empty($point['locked']),
                        'pointid' => (int)$point['id'], 'versionid' => (int)$version->id];
                    break;
                }
            }
        }
        if (count($matches) > 1) {
            throw new \moodle_exception('Нативная активность указана в нескольких точках одного маршрута. Исправьте маршрут.');
        }
        return $matches[0] ?? ['configured' => false, 'reachable' => false];
    }

    public static function url_for(string $factkey): string {
        $cfg = self::FACTS[$factkey] ?? null;

        if (!$cfg) {
            return '';
        }

        return (
            new \moodle_url(
                (string)$cfg['url']
            )
        )->out(false);
    }


    public static function fact(
        int $userid,
        int $pointid,
        int $routeversionid,
        string $factkey
    ): ?\stdClass {
        global $DB;

        $cfg = self::FACTS[$factkey] ?? null;

        if (
            !$cfg
            ||
            $routeversionid <= 0
        ) {
            return null;
        }

        return $DB->get_record(
            'local_ustar_workflow_events',
            [
                'entitytype' => 'route_native',
                'entityid' => $routeversionid,
                'eventtype' =>
                    (string)$cfg['eventtype'],
                'actorid' => $userid,
            ]
        ) ?: null;
    }


    /**
     * Record one fact for the CURRENT PUBLISHED version.
     *
     * Returns 0 while the route point is still draft.
     */
    public static function record(
        int $userid,
        string $factkey,
        array $details = []
    ): int {
        global $DB;

        $cfg = self::FACTS[$factkey] ?? null;

        if (!$cfg) {
            throw new \invalid_parameter_exception(
                'Unsupported native learning fact'
            );
        }

        if (
            $userid <= 1
            ||
            !accounts::participates($userid)
        ) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/ustar:use',
                'nopermissions',
                ''
            );
        }

        $availability = self::availability($userid, $factkey);
        if (empty($availability['configured'])) {
            // An unpublished draft has no completion fact.
            return 0;
        }
        if (empty($availability['reachable'])) {
            throw new \moodle_exception('Этот шаг ещё недоступен в вашем маршруте.');
        }
        $pointid = (int)$availability['pointid'];

        $point = $DB->get_record(
            'local_ustar_route_points',
            [
                'id' => $pointid,
                'active' => 1,
            ],
            '*',
            MUST_EXIST
        );

        /*
         * Never create a completion fact for a draft route version.
         * Direct preview pages remain safe before publication.
         */
        $version = $DB->get_record('local_ustar_route_versions',
            ['id' => (int)$availability['versionid'], 'pointid' => $pointid,
                'status' => route_model::STATUS_PUBLISHED], '*', MUST_EXIST);

        if (!$version) {
            return 0;
        }

        $positionid = people::position_id($userid);

        if (
            route_scope::available()
            &&
            !route_scope::point_applies(
                $pointid,
                $positionid
            )
        ) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/ustar:use',
                'nopermissions',
                ''
            );
        }

        $factory =
            \core\lock\lock_config::get_lock_factory(
                'local_ustar_native_learning'
            );

        $lockkey =
            'fact:' .
            $userid . ':' .
            (int)$version->id . ':' .
            $factkey;

        $lock = $factory->get_lock(
            $lockkey,
            10
        );

        if (!$lock) {
            throw new \moodle_exception(
                'Результат сейчас сохраняется. Повторите действие.'
            );
        }

        try {
            $current = self::availability($userid, $factkey);
            if (empty($current['reachable']) || (int)($current['pointid'] ?? 0) !== $pointid
                    || (int)($current['versionid'] ?? 0) !== (int)$version->id) {
                throw new \moodle_exception('Маршрут изменился. Откройте текущий шаг заново.');
            }
            $existing = self::fact(
                $userid,
                $pointid,
                (int)$version->id,
                $factkey
            );

            if ($existing) {
                return (int)$existing->id;
            }

            return (int)$DB->insert_record(
                'local_ustar_workflow_events',
                (object)[
                    'entitytype' => 'route_native',
                    'entityid' => (int)$version->id,
                    'eventtype' =>
                        (string)$cfg['eventtype'],
                    'actorid' => $userid,
                    'reason' => null,
                    'detailsjson' => json_encode(
                        array_merge(
                            [
                                'userid' => $userid,
                                'routeid' =>
                                    (int)$point->routeid,
                                'pointid' => $pointid,
                                'routeversionid' =>
                                    (int)$version->id,
                                'factkey' => $factkey,
                                'source' =>
                                    'native_route_activity',
                            ],
                            $details
                        ),
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    ),
                    'timecreated' => time(),
                ]
            );

        } finally {
            $lock->release();
        }
    }
}
