<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Canonical employee team hierarchy presentation.
 *
 * Data sources remain:
 * - explicit reporting lines when available;
 * - USTAR position / department structure;
 * - active Moodle users participating in USTAR.
 *
 * No reporting relationship is invented.
 */
final class team_presenter {

    public static function build(int $userid): array {
        $me = org::person($userid);
        self::decorate($me, 112);

        $structure = structure::get(structure::NAME_STRUCTURE);

        $positionmap = [];
        foreach (($structure['positions'] ?? []) as $position) {
            $positionmap[(string)$position['id']] = $position;
        }

        $myposition =
            $positionmap[(string)($me['positionid'] ?? '')]
            ?? [];

        $ishead =
            !empty($myposition['ishead'])
            || (
                class_exists('\\local_ustar\\organization_model')
                && organization_model::is_manager($userid)
            );

        /*
         * Explicit reporting chain has priority.
         * org::chain() returns top -> ... -> self.
         */
        $chain = org::chain($userid);
        $leaders = [];

        if (count($chain) > 1) {
            array_pop($chain); // remove self

            foreach ($chain as $person) {
                self::decorate($person);
                $leaders[] = $person;
            }

            $leaderslabel = 'Моя вертикаль управления';
            $leadersverified = true;

        } else if (!$ishead) {
            /*
             * No explicit reporting line:
             * show department leadership, but never call it
             * "direct manager".
             */
            $leaders = self::department_heads(
                (string)($me['departmentid'] ?? ''),
                $userid
            );

            $leaderslabel = 'Руководство подразделения';
            $leadersverified = false;

        } else {
            $leaderslabel = '';
            $leadersverified = false;
        }

        /*
         * Same-position horizon.
         * Self is rendered separately in the centre.
         */
        $peers = [];

        foreach (org::horizon($userid) as $person) {
            if ((int)$person['id'] === $userid) {
                continue;
            }

            self::decorate($person);
            $peers[] = $person;
        }

        /*
         * Real direct reports only when reporting lines exist.
         */
        $reports = org::direct_reports($userid);

        foreach ($reports as &$person) {
            self::decorate($person);
        }
        unset($person);

        /*
         * A department head without imported reporting lines still
         * needs a useful working view. Show department employees,
         * explicitly labelled as department scope rather than
         * fabricated direct reports.
         */
        $departmentpeople = [];

        if ($ishead && !$reports) {
            $departmentpeople = self::department_people(
                (string)($me['departmentid'] ?? ''),
                $userid,
                true
            );
        }

        return [
            'me' => $me,

            'leaders' => $leaders,
            'hasleaders' => !empty($leaders),
            'leaderslabel' => $leaderslabel,
            'leadersverified' => $leadersverified,
            'leadersfallback' =>
                !empty($leaders) && !$leadersverified,

            'peers' => $peers,
            'haspeers' => !empty($peers),

            'reports' => $reports,
            'hasreports' => !empty($reports),

            'departmentpeople' => $departmentpeople,
            'hasdepartmentpeople' =>
                !empty($departmentpeople),

            'ishead' => $ishead,

            'haslower' =>
                !empty($reports)
                || !empty($departmentpeople),
        ];
    }

    public static function avatar_url(
        int $userid,
        int $size = 96
    ): string {
        global $DB, $PAGE;

        $user = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0],
            'id,firstname,lastname,email,picture,imagealt',
            IGNORE_MISSING
        );

        if (!$user) {
            return '';
        }

        $picture = new \user_picture($user);
        $picture->size = $size;
        $picture->alttext = false;

        return $picture->get_url($PAGE)->out(false);
    }

    private static function decorate(
        array &$person,
        int $size = 84
    ): void {
        global $DB;

        $userid = (int)($person['id'] ?? 0);

        $person['avatarurl'] =
            $userid > 0
            ? self::avatar_url($userid, $size)
            : '';

        $person['hasavatar'] =
            $person['avatarurl'] !== '';

        $person['iscustomavatar'] =
            $userid > 0
            &&
            (int)$DB->get_field(
                'user',
                'picture',
                ['id' => $userid]
            ) > 0;
    }

    private static function department_heads(
        string $departmentid,
        int $excludeuserid
    ): array {
        global $DB;

        if ($departmentid === '') {
            return [];
        }

        $structure =
            structure::get(structure::NAME_STRUCTURE);

        $headpositions = [];

        foreach (($structure['positions'] ?? []) as $position) {
            if (
                (string)($position['department'] ?? '')
                    === $departmentid
                &&
                !empty($position['ishead'])
            ) {
                $headpositions[
                    (string)$position['id']
                ] = true;
            }
        }

        if (!$headpositions) {
            return [];
        }

        $sql =
            "SELECT u.id,u.firstname,u.lastname,d.data AS positionid
               FROM {user} u
               JOIN {user_info_data} d
                 ON d.userid=u.id
               JOIN {user_info_field} f
                 ON f.id=d.fieldid
                AND f.shortname='ustar_position'
              WHERE u.deleted=0
                AND u.suspended=0";

        $out = [];

        foreach ($DB->get_records_sql($sql) as $user) {
            if ((int)$user->id === $excludeuserid) {
                continue;
            }

            if (
                !isset(
                    $headpositions[
                        trim((string)$user->positionid)
                    ]
                )
            ) {
                continue;
            }

            if (!accounts::participates((int)$user->id)) {
                continue;
            }

            $person = org::person(
                (int)$user->id,
                fullname($user)
            );

            self::decorate($person);

            $out[] = $person;
        }

        usort(
            $out,
            static function(array $a, array $b): int {
                /*
                 * Presentation only.
                 * This does NOT create a reporting relationship.
                 */
                $weight = static function(string $position): int {
                    $position = \core_text::strtolower($position);

                    if (str_contains($position, 'директор')) {
                        return 10;
                    }

                    if (
                        str_contains($position, 'руковод')
                        ||
                        str_contains($position, 'мастер')
                    ) {
                        return 20;
                    }

                    return 30;
                };

                return [
                    $weight((string)$a['position']),
                    (string)$a['fullname'],
                ] <=> [
                    $weight((string)$b['position']),
                    (string)$b['fullname'],
                ];
            }
        );

        return $out;
    }

    private static function department_people(
        string $departmentid,
        int $excludeuserid,
        bool $excludeheads = false
    ): array {
        global $DB;

        if ($departmentid === '') {
            return [];
        }

        $structure =
            structure::get(structure::NAME_STRUCTURE);

        $positionmap = [];

        foreach (($structure['positions'] ?? []) as $position) {
            $positionmap[
                (string)$position['id']
            ] = $position;
        }

        $sql =
            "SELECT u.id,u.firstname,u.lastname,d.data AS positionid
               FROM {user} u
               JOIN {user_info_data} d
                 ON d.userid=u.id
               JOIN {user_info_field} f
                 ON f.id=d.fieldid
                AND f.shortname='ustar_position'
              WHERE u.deleted=0
                AND u.suspended=0";

        $out = [];

        foreach ($DB->get_records_sql($sql) as $user) {
            if ((int)$user->id === $excludeuserid) {
                continue;
            }

            if (!accounts::participates((int)$user->id)) {
                continue;
            }

            $positionid =
                trim((string)$user->positionid);

            $position =
                $positionmap[$positionid]
                ?? null;

            if (!$position) {
                continue;
            }

            if (
                (string)($position['department'] ?? '')
                    !== $departmentid
            ) {
                continue;
            }

            if (
                $excludeheads
                &&
                !empty($position['ishead'])
            ) {
                continue;
            }

            $person = org::person(
                (int)$user->id,
                fullname($user)
            );

            self::decorate($person, 72);

            $out[] = $person;
        }

        usort(
            $out,
            static fn(array $a, array $b): int =>
                [
                    (string)$a['position'],
                    (string)$a['fullname'],
                ]
                <=>
                [
                    (string)$b['position'],
                    (string)$b['fullname'],
                ]
        );

        return $out;
    }
}
