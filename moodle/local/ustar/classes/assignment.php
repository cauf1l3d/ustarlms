<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * USTAR position assignment engine.
 *
 * Converts the employee's current position into concrete Moodle
 * course access.
 *
 * Source priority:
 *
 *   Position
 *      -> required skills
 *          -> normalized evidence definitions
 *          -> legacy skill course idnumbers as fallback
 *
 * Important:
 * - historical completion is never deleted;
 * - Core v1 only ADDS required enrolments;
 * - old enrolments are not removed automatically yet.
 */
class assignment {

    /**
     * Position lookup.
     */
    public static function get_position(
        string $positionid
    ): ?array {

        $positionid = trim($positionid);

        if ($positionid === '') {
            return null;
        }

        $st = structure::get(
            structure::NAME_STRUCTURE
        );

        foreach (
            $st['positions'] ?? []
            as $position
        ) {
            if (
                ($position['id'] ?? '')
                ===
                $positionid
            ) {
                return $position;
            }
        }

        return null;
    }


    /**
     * Build a skill lookup from the current USTAR structure.
     */
    private static function skill_map(
        array $structure
    ): array {

        $map = [];

        foreach (
            $structure['skills'] ?? []
            as $skill
        ) {
            if (!empty($skill['id'])) {
                $map[$skill['id']] =
                    $skill;
            }
        }

        return $map;
    }


    /**
     * Choose one normalized evidence path for a skill.
     *
     * Current Core v1 rule:
     * - definitions already arrive ordered by sortorder/id;
     * - first path becomes the preferred path.
     *
     * Later the Admin editor can expose an explicit preferred path.
     */
    private static function evidence_courseids_for_skill(
        string $skillid,
        string $positionid
    ): array {

        $definitions =
            evidence::definitions_for_skill(
                $skillid,
                $positionid
            );

        if (!$definitions) {
            return [
                'configured' => false,
                'pathkey' => null,
                'courseids' => [],
            ];
        }


        $firstpath = null;
        $courseids = [];


        foreach ($definitions as $definition) {

            $pathkey =
                trim(
                    (string)(
                        $definition->pathkey
                        ?? ''
                    )
                );

            if ($pathkey === '') {
                $pathkey = 'default';
            }


            if ($firstpath === null) {
                $firstpath = $pathkey;
            }


            /*
             * Do not merge every alternative path.
             * Only the preferred/first path is used for automatic
             * enrolment in Core v1.
             */
            if ($pathkey !== $firstpath) {
                continue;
            }


            if (!empty($definition->courseid)) {
                $courseids[
                    (int)$definition->courseid
                ] = true;
            }
        }


        return [
            'configured' => true,
            'pathkey' => $firstpath,
            'courseids' =>
                array_map(
                    'intval',
                    array_keys($courseids)
                ),
        ];
    }


    /**
     * Resolve legacy skill['courses'] Moodle idnumbers.
     *
     * Used only while some skills have not yet been migrated
     * to normalized evidence.
     */
    private static function legacy_courseids_for_skill(
        array $skill
    ): array {
        global $DB;

        $idnumbers =
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn($value) =>
                                trim((string)$value),
                            $skill['courses']
                            ?? []
                        ),
                        static fn($value) =>
                            $value !== ''
                    )
                )
            );


        if (!$idnumbers) {
            return [];
        }


        [$insql, $params] =
            $DB->get_in_or_equal(
                $idnumbers,
                SQL_PARAMS_NAMED,
                'legacy'
            );


        return array_map(
            'intval',
            $DB->get_fieldset_select(
                'course',
                'id',
                "idnumber {$insql}",
                $params
            )
        );
    }


    /**
     * Calculate concrete Moodle courses required for a position.
     *
     * This method does NOT mutate anything.
     */
    public static function required_courses(
        string $positionid
    ): array {
        global $DB;

        $positionid =
            trim($positionid);

        $st =
            structure::get(
                structure::NAME_STRUCTURE
            );


        $position = null;

        foreach (
            $st['positions'] ?? []
            as $candidate
        ) {
            if (
                ($candidate['id'] ?? '')
                ===
                $positionid
            ) {
                $position = $candidate;
                break;
            }
        }


        if (!$position) {
            return [
                'ok' => false,
                'positionid' => $positionid,
                'position' => null,
                'skills' => [],
                'courseids' => [],
                'courses' => [],
                'error' => 'position_not_found',
            ];
        }


        $required =
            $st['matrix'][$positionid]
            ?? [];

        $skillmap =
            self::skill_map($st);

        $courseids = [];
        $skillrows = [];


        foreach (
            $required
            as $skillid => $requiredlevel
        ) {

            $skill =
                $skillmap[$skillid]
                ?? [
                    'id' => $skillid,
                    'name' => $skillid,
                    'category' => '',
                    'courses' => [],
                ];


            /*
             * New normalized model has priority.
             */
            $normalized =
                self::evidence_courseids_for_skill(
                    $skillid,
                    $positionid
                );


            $source = '';
            $ids = [];


            if ($normalized['configured']) {

                $source = 'evidence';
                $ids =
                    $normalized['courseids'];

            } else {

                /*
                 * Migration fallback.
                 */
                $source = 'legacy';

                $ids =
                    self::legacy_courseids_for_skill(
                        $skill
                    );
            }


            foreach ($ids as $courseid) {
                $courseids[(int)$courseid] =
                    true;
            }


            $skillrows[] = [
                'skillid' =>
                    $skillid,

                'name' =>
                    $skill['name']
                    ?? $skillid,

                'requiredLevel' =>
                    (int)$requiredlevel,

                'source' =>
                    $source,

                'pathkey' =>
                    $normalized['pathkey']
                    ?? null,

                'courseids' =>
                    array_values(
                        array_map(
                            'intval',
                            $ids
                        )
                    ),
            ];
        }


        $ids =
            array_values(
                array_map(
                    'intval',
                    array_keys($courseids)
                )
            );


        $courses = [];

        if ($ids) {

            [$insql, $params] =
                $DB->get_in_or_equal(
                    $ids,
                    SQL_PARAMS_NAMED,
                    'requiredcourse'
                );

            foreach (
                $DB->get_records_select(
                    'course',
                    "id {$insql}",
                    $params,
                    'fullname ASC',
                    'id,fullname,shortname,idnumber,visible'
                )
                as $course
            ) {

                $courses[] = [
                    'id' =>
                        (int)$course->id,

                    'name' =>
                        $course->fullname,

                    'shortname' =>
                        $course->shortname,

                    'idnumber' =>
                        trim(
                            (string)$course->idnumber
                        ),

                    'visible' =>
                        !empty($course->visible),
                ];
            }
        }


        $courses =
            \local_ustar\learning_route::apply_order(
                $positionid,
                $courses
            );


        return [
            'ok' => true,

            'positionid' =>
                $positionid,

            'position' =>
                $position,

            'skills' =>
                $skillrows,

            'courseids' =>
                $ids,

            'courses' =>
                $courses,

            'error' =>
                null,
        ];
    }


    /**
     * Build an enrolment plan for a user without changing Moodle.
     */
    public static function plan_user(
        int $userid
    ): array {
        global $DB;

        $user =
            $DB->get_record(
                'user',
                [
                    'id' => $userid,
                    'deleted' => 0,
                ],
                'id,username,firstname,lastname,suspended',
                MUST_EXIST
            );


        $positionid =
            people::position_id(
                $userid
            );


        if ($positionid === '') {
            return [
                'ok' => true,
                'userid' => $userid,
                'positionid' => '',
                'status' => 'position_missing',
                'required' => [],
                'alreadyEnrolled' => [],
                'toEnrol' => [],
                'missingManualInstance' => [],
            ];
        }


        $required =
            self::required_courses(
                $positionid
            );


        if (!$required['ok']) {
            return [
                'ok' => false,
                'userid' => $userid,
                'positionid' => $positionid,
                'status' => 'invalid_position',
                'required' => [],
                'alreadyEnrolled' => [],
                'toEnrol' => [],
                'missingManualInstance' => [],
            ];
        }


        /*
         * Suspended accounts retain historical enrolment data,
         * but no new enrolments are added while suspended.
         */
        if (!empty($user->suspended)) {
            return [
                'ok' => true,
                'userid' => $userid,
                'positionid' => $positionid,
                'status' => 'suspended',
                'required' => $required['courses'],
                'alreadyEnrolled' => [],
                'toEnrol' => [],
                'missingManualInstance' => [],
            ];
        }


        $already = [];
        $toenrol = [];
        $missingmanual = [];


        foreach (
            $required['courses']
            as $course
        ) {

            $courseid =
                (int)$course['id'];

            $context =
                \context_course::instance(
                    $courseid
                );


            if (
                is_enrolled(
                    $context,
                    $userid,
                    '',
                    true
                )
            ) {
                $already[] =
                    $course;

                continue;
            }


            $instance =
                $DB->get_record(
                    'enrol',
                    [
                        'courseid' =>
                            $courseid,

                        'enrol' =>
                            'manual',

                        'status' =>
                            ENROL_INSTANCE_ENABLED,
                    ],
                    '*',
                    IGNORE_MULTIPLE
                );


            if (!$instance) {
                $missingmanual[] =
                    $course;

                continue;
            }


            $toenrol[] =
                $course;
        }


        return [
            'ok' => true,

            'userid' =>
                $userid,

            'positionid' =>
                $positionid,

            'status' =>
                'ready',

            'required' =>
                $required['courses'],

            'skills' =>
                $required['skills'],

            'alreadyEnrolled' =>
                $already,

            'toEnrol' =>
                $toenrol,

            'missingManualInstance' =>
                $missingmanual,
        ];
    }


    /**
     * Apply current-position course access immediately.
     *
     * Core v1:
     * - only adds required enrolments;
     * - does not remove previous enrolments;
     * - does not touch completion history.
     */
    public static function sync_user(
        int $userid
    ): array {
        global $DB;

        $plan =
            self::plan_user(
                $userid
            );


        if (
            !$plan['ok']
            ||
            $plan['status']
                !== 'ready'
        ) {
            return $plan + [
                'enrolled' => [],
            ];
        }


        $manualplugin =
            enrol_get_plugin(
                'manual'
            );

        if (!$manualplugin) {
            throw new \coding_exception(
                'Manual enrolment plugin is unavailable'
            );
        }


        $studentrole =
            $DB->get_record(
                'role',
                [
                    'shortname' =>
                        'student',
                ],
                '*',
                MUST_EXIST
            );


        $enrolled = [];


        foreach (
            $plan['toEnrol']
            as $course
        ) {

            $courseid =
                (int)$course['id'];


            /*
             * Re-read the instance immediately before mutation.
             */
            $instance =
                $DB->get_record(
                    'enrol',
                    [
                        'courseid' =>
                            $courseid,

                        'enrol' =>
                            'manual',

                        'status' =>
                            ENROL_INSTANCE_ENABLED,
                    ],
                    '*',
                    IGNORE_MULTIPLE
                );


            if (!$instance) {
                continue;
            }


            $manualplugin->enrol_user(
                $instance,
                $userid,
                (int)$studentrole->id
            );


            $enrolled[] =
                $course;
        }


        $plan['enrolled'] =
            $enrolled;


        return $plan;
    }
}
