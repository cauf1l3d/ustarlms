<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Compliance / acknowledgement reporting for USTAR Content.
 *
 * Audience is calculated dynamically from current active
 * content access rules:
 *
 *   all
 *   department
 *   position
 *
 * Acknowledgement is checked only against the current version.
 */
class content_ack_report {

    /**
     * Build current audience and acknowledgement state.
     */
    public static function report(
        int $contentid
    ): array {
        global $DB;

        $content =
            $DB->get_record(
                'local_ustar_content',
                [
                    'id' =>
                        $contentid,
                ],
                '*',
                MUST_EXIST
            );


        $version =
            content::current_version(
                $contentid
            );


        if (!$version) {
            throw new \moodle_exception(
                'У материала отсутствует текущая версия'
            );
        }


        $rules =
            $DB->get_records(
                'local_ustar_content_access',
                [
                    'contentid' =>
                        $contentid,

                    'active' =>
                        1,
                ],
                'id ASC'
            );


        /*
         * No rules = no audience.
         */
        if (!$rules) {

            return [
                'contentid' =>
                    $contentid,

                'title' =>
                    $content->title,

                'versionid' =>
                    (int)$version->id,

                'versionlabel' =>
                    $version->versionlabel
                    ?: 'v'
                        .
                        $version->versionno,

                'ackrequired' =>
                    !empty(
                        $content->ackrequired
                    ),

                'total' =>
                    0,

                'acknowledged' =>
                    0,

                'pending' =>
                    0,

                'percent' =>
                    0,

                'people' =>
                    [],
            ];
        }


        /*
         * Structure maps.
         */
        $structure =
            structure::get(
                structure::NAME_STRUCTURE
            );


        $positions = [];

        foreach (
            $structure['positions'] ?? []
            as $position
        ) {

            $positions[
                $position['id']
            ] = $position;
        }


        $departments = [];

        foreach (
            $structure['departments'] ?? []
            as $department
        ) {

            $departments[
                $department['id']
            ] = $department;
        }


        /*
         * Compile access rules once.
         */
        $allowall = false;
        $allowedpositions = [];
        $alloweddepartments = [];


        foreach ($rules as $rule) {

            $type =
                trim(
                    (string)$rule->scopetype
                );

            $scopeid =
                trim(
                    (string)$rule->scopeid
                );


            if ($type === 'all') {

                $allowall = true;

            } else if (
                $type === 'position'
                &&
                $scopeid !== ''
            ) {

                $allowedpositions[
                    $scopeid
                ] = true;

            } else if (
                $type === 'department'
                &&
                $scopeid !== ''
            ) {

                $alloweddepartments[
                    $scopeid
                ] = true;
            }
        }


        /*
         * Current-version acknowledgements.
         */
        $ackrecords =
            $DB->get_records(
                'local_ustar_content_ack',
                [
                    'contentid' =>
                        $contentid,

                    'versionid' =>
                        $version->id,
                ]
            );


        $acksbyuser = [];

        foreach ($ackrecords as $ack) {

            $acksbyuser[
                (int)$ack->userid
            ] = $ack;
        }


        /*
         * Candidate employee accounts.
         *
         * We deliberately require a valid USTAR position.
         * This keeps service/system accounts out of compliance
         * statistics.
         */
        $users =
            $DB->get_records_select(
                'user',
                '
                    deleted = 0
                    AND suspended = 0
                    AND username <> :guest
                ',
                [
                    'guest' =>
                        'guest',
                ],
                'lastname ASC, firstname ASC, id ASC',
                '
                    id,
                    username,
                    firstname,
                    lastname,
                    email,
                    suspended,
                    deleted
                '
            );


        $people = [];


        foreach ($users as $user) {

            $userid =
                (int)$user->id;


            /*
             * Operational denominators include only explicit workforce
             * employees. This never changes the user's access permissions.
             */
            if (!accounts::participates($userid)) {
                continue;
            }


            $positionid =
                trim(
                    (string)people::position_id(
                        $userid
                    )
                );


            /*
             * Only real employees with a valid USTAR position.
             */
            if (
                $positionid === ''
                ||
                !isset(
                    $positions[
                        $positionid
                    ]
                )
            ) {
                continue;
            }


            $position =
                $positions[
                    $positionid
                ];


            $departmentid =
                trim(
                    (string)(
                        $position['department']
                        ?? ''
                    )
                );


            $allowed =
                $allowall
                ||
                isset(
                    $allowedpositions[
                        $positionid
                    ]
                )
                ||
                (
                    $departmentid !== ''
                    &&
                    isset(
                        $alloweddepartments[
                            $departmentid
                        ]
                    )
                );


            if (!$allowed) {
                continue;
            }


            $ack =
                $acksbyuser[
                    $userid
                ]
                ?? null;


            $acked =
                !empty($ack);


            $people[] = [

                'userid' =>
                    $userid,

                'username' =>
                    $user->username,

                'fullname' =>
                    fullname(
                        $user
                    ),

                'email' =>
                    $user->email,

                'positionid' =>
                    $positionid,

                'position' =>
                    $position['name']
                    ?? $positionid,

                'departmentid' =>
                    $departmentid,

                'department' =>
                    $departments[
                        $departmentid
                    ]['name']
                    ??
                    $departmentid,

                'acked' =>
                    $acked,

                'pending' =>
                    !$acked,

                'acktime' =>
                    $ack
                        ? (int)$ack->acktime
                        : 0,

                'ackid' =>
                    $ack
                        ? (int)$ack->id
                        : 0,


                'ackmethod' =>
                    $ack
                        ? (string)$ack->method
                        : '',
            ];
        }


        /*
         * Pending first, then alphabetical.
         */
        usort(
            $people,
            static function(
                array $a,
                array $b
            ): int {

                if (
                    $a['acked']
                    !==
                    $b['acked']
                ) {
                    return $a['acked']
                        ? 1
                        : -1;
                }


                return strcasecmp(
                    $a['fullname'],
                    $b['fullname']
                );
            }
        );


        $total =
            count(
                $people
            );


        $acknowledged =
            count(
                array_filter(
                    $people,
                    static fn(array $person): bool =>
                        !empty(
                            $person['acked']
                        )
                )
            );


        $pending =
            max(
                0,
                $total
                -
                $acknowledged
            );


        $percent =
            $total > 0
                ? (int)round(
                    (
                        $acknowledged
                        /
                        $total
                    )
                    *
                    100
                )
                : 0;


        return [

            'contentid' =>
                $contentid,

            'title' =>
                $content->title,

            'versionid' =>
                (int)$version->id,

            'versionlabel' =>
                $version->versionlabel
                ?: 'v'
                    .
                    $version->versionno,

            'ackrequired' =>
                !empty(
                    $content->ackrequired
                ),

            'total' =>
                $total,

            'acknowledged' =>
                $acknowledged,

            'pending' =>
                $pending,

            'percent' =>
                $percent,

            'people' =>
                $people,
        ];
    }
}
