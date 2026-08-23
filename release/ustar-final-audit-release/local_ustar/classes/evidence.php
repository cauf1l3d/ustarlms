<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * USTAR competency evidence engine.
 *
 * Definitions live in local_ustar_skill_evidence.
 * User facts remain in Moodle:
 *
 * - course_modules_completion
 * - quiz / SCORM completion configuration
 * - enrolments
 *
 * Important:
 * Evidence completion is NOT automatically equivalent to
 * demonstrated human competence.
 */
class evidence {

    /**
     * Active evidence definitions applicable to a skill/position.
     *
     * positionid = NULL:
     *   shared evidence valid for every position using the skill.
     *
     * positionid = concrete id:
     *   evidence valid only for that role.
     */
    public static function definitions_for_skill(
        string $skillid,
        ?string $positionid = null
    ): array {
        global $DB;

        $skillid = trim($skillid);

        if ($skillid === '') {
            return [];
        }

        $params = [
            'skillid' => $skillid,
            'active' => 1,
        ];

        if ($positionid !== null && $positionid !== '') {
            $params['positionid'] = $positionid;

            $where = "
                skillid = :skillid
                AND active = :active
                AND (
                    positionid IS NULL
                    OR positionid = ''
                    OR positionid = :positionid
                )
            ";
        } else {
            $where = "
                skillid = :skillid
                AND active = :active
                AND (
                    positionid IS NULL
                    OR positionid = ''
                )
            ";
        }

        return array_values(
            $DB->get_records_select(
                'local_ustar_skill_evidence',
                $where,
                $params,
                'sortorder ASC, id ASC'
            )
        );
    }


    /**
     * Evaluate one evidence definition for one Moodle user.
     */
    public static function evaluate_definition(
        \stdClass $definition,
        int $userid
    ): array {
        global $DB;

        $result = [
            'id' => (int)$definition->id,

            'skillid' =>
                (string)$definition->skillid,

            'positionid' =>
                $definition->positionid !== null
                    ? (string)$definition->positionid
                    : null,

            'pathkey' =>
                $definition->pathkey !== null
                    ? (string)$definition->pathkey
                    : null,

            'type' =>
                (string)$definition->evidencetype,

            'weight' =>
                max(
                    0,
                    (int)$definition->weight
                ),

            'required' =>
                !empty($definition->required),

            'courseid' =>
                $definition->courseid !== null
                    ? (int)$definition->courseid
                    : null,

            'cmid' =>
                $definition->cmid !== null
                    ? (int)$definition->cmid
                    : null,

            'validdays' =>
                max(
                    0,
                    (int)$definition->validdays
                ),

            'configured' => false,
            'completed' => false,
            'satisfied' => false,
            'passed' => null,
            'progress' => 0,
            'status' => 'unavailable',
            'completionstate' => 0,
            'completedat' => 0,
            'expiresat' => 0,
            'expired' => false,

            'activityname' => '',
            'modname' => '',
        ];

        /*
         * Only learning completion and assessment pass/fail currently have
         * implemented runtime evaluators. The schema reserves additional
         * evidence types for future workflows, but treating those labels as
         * ordinary Moodle completion would falsely claim practice, manager
         * review, checklist or certification evidence.
         */
        $supportedtypes = [
            'learning',
            'assessment',
        ];

        if (!in_array((string)$definition->evidencetype, $supportedtypes, true)) {
            $result['status'] = 'unsupported_type';
            return $result;
        }


        /*
         * Activity-level evidence.
         */
        if (!empty($definition->cmid)) {

            $sql = "
                SELECT
                    cm.id,
                    cm.course,
                    cm.instance,
                    cm.completion,
                    m.name AS modname
                FROM {course_modules} cm
                JOIN {modules} m
                  ON m.id = cm.module
                WHERE cm.id = :cmid
                  AND cm.deletioninprogress = 0
            ";

            $cm = $DB->get_record_sql(
                $sql,
                [
                    'cmid' =>
                        (int)$definition->cmid,
                ]
            );

            if (!$cm) {
                $result['status'] =
                    'source_missing';

                return $result;
            }


            /*
             * Protect against an accidental mapping where cmid
             * belongs to a different course than courseid.
             */
            if (
                !empty($definition->courseid)
                &&
                (int)$definition->courseid
                    !== (int)$cm->course
            ) {
                $result['status'] =
                    'source_mismatch';

                return $result;
            }


            $result['configured'] = true;
            $result['courseid'] =
                (int)$cm->course;
            $result['modname'] =
                (string)$cm->modname;


            /*
             * Resolve a human-readable activity name.
             */
            $tablename = match (
                (string)$cm->modname
            ) {
                'quiz' => 'quiz',
                'page' => 'page',
                'scorm' => 'scorm',
                'lesson' => 'lesson',
                'resource' => 'resource',
                'forum' => 'forum',
                default => null,
            };

            if ($tablename) {

                $name =
                    $DB->get_field(
                        $tablename,
                        'name',
                        [
                            'id' =>
                                (int)$cm->instance,
                        ]
                    );

                if ($name !== false) {
                    $result['activityname'] =
                        (string)$name;
                }
            }


            /*
             * Moodle completion states:
             *
             * 0 = incomplete
             * 1 = complete
             * 2 = complete/pass
             * 3 = complete/fail
             */
            $completion = $DB->get_record(
                'course_modules_completion',
                ['coursemoduleid' => (int)$cm->id, 'userid' => $userid],
                'completionstate,timemodified',
                IGNORE_MISSING
            );
            $state = $completion ? (int)$completion->completionstate : 0;
            $completedat = $completion ? (int)$completion->timemodified : 0;
            $result['completionstate'] = $state;
            $result['completedat'] = $completedat;


            if ($state === 0) {
                $result['status'] =
                    'pending';

                return $result;
            }


            $result['completed'] = true;
            $result['progress'] = 100;
            if ($result['validdays'] > 0 && $completedat > 0) {
                $result['expiresat'] = $completedat + ($result['validdays'] * DAYSECS);
                if ($result['expiresat'] < time()) {
                    $result['expired'] = true;
                    $result['satisfied'] = false;
                    $result['status'] = 'expired';
                    return $result;
                }
            }


            /*
             * Assessment evidence preserves Moodle pass/fail
             * when the activity exposes it.
             */
            if (
                $definition->evidencetype
                    === 'assessment'
            ) {

                if ($state === 3) {
                    $result['passed'] = false;
                    $result['satisfied'] = false;
                    $result['status'] = 'failed';

                    return $result;
                }

                if ($state === 2) {
                    $result['passed'] = true;
                    $result['satisfied'] = true;
                    $result['status'] = 'passed';

                    return $result;
                }

                /*
                 * State 1 only proves that Moodle marked the activity
                 * complete. It does not prove a passing assessment result,
                 * so the evidence must remain unsatisfied.
                 */
                $result['passed'] = null;
                $result['satisfied'] = false;
                $result['status'] =
                    'completed_ungraded';

                return $result;
            }


            /*
             * Learning evidence is satisfied by completion.
             *
             * If Moodle exposes completion-fail (state 3), content
             * was completed but the learning requirement itself is
             * still considered completed. Assessment remains separate.
             */
            $result['satisfied'] = true;
            $result['status'] = 'completed';

            return $result;
        }


        /*
         * Course-level source.
         *
         * Supported, although our first production mappings use
         * activity-level evidence.
         */
        if (!empty($definition->courseid)) {

            /*
             * Course completion has no single pass/fail result. Assessment
             * evidence therefore requires an activity-level source whose
             * Moodle completion state can preserve pass/fail semantics.
             */
            if ((string)$definition->evidencetype === 'assessment') {
                $result['status'] = 'unsupported_source';
                return $result;
            }

            $courseid =
                (int)$definition->courseid;

            if (
                !$DB->record_exists(
                    'course',
                    [
                        'id' => $courseid,
                    ]
                )
            ) {
                $result['status'] =
                    'source_missing';

                return $result;
            }

            $result['configured'] = true;

            $tracked =
                $DB->get_records_select(
                    'course_modules',
                    "
                        course = :course
                        AND deletioninprogress = 0
                        AND completion <> 0
                    ",
                    [
                        'course' => $courseid,
                    ],
                    '',
                    'id'
                );

            if (!$tracked) {
                $result['status'] =
                    'not_tracked';

                return $result;
            }


            $ids =
                array_map(
                    'intval',
                    array_keys($tracked)
                );

            [$insql, $params] =
                $DB->get_in_or_equal(
                    $ids,
                    SQL_PARAMS_NAMED,
                    'cm'
                );

            $params['userid'] =
                $userid;

            $states =
                $DB->get_records_select(
                    'course_modules_completion',
                    "
                        userid = :userid
                        AND coursemoduleid {$insql}
                    ",
                    $params,
                    '',
                    'coursemoduleid,completionstate,timemodified'
                );


            $done = 0;
            $completedat = 0;

            foreach ($ids as $cmid) {

                $state =
                    isset($states[$cmid])
                        ? (int)$states[$cmid]
                            ->completionstate
                        : 0;

                if ($state > 0) {
                    $done++;
                    if (isset($states[$cmid])) {
                        $completedat = max($completedat, (int)$states[$cmid]->timemodified);
                    }
                }
            }


            $progress =
                (int)round(
                    $done
                    /
                    count($ids)
                    *
                    100
                );

            $result['progress'] =
                $progress;

            $result['completed'] =
                $progress >= 100;

            $result['satisfied'] =
                $progress >= 100;

            $result['status'] =
                $progress >= 100
                    ? 'completed'
                    : (
                        $progress > 0
                            ? 'active'
                            : 'pending'
                    );
            $result['completedat'] = $completedat;
            if ($progress >= 100 && $result['validdays'] > 0 && $completedat > 0) {
                $result['expiresat'] = $completedat + ($result['validdays'] * DAYSECS);
                if ($result['expiresat'] < time()) {
                    $result['expired'] = true;
                    $result['satisfied'] = false;
                    $result['status'] = 'expired';
                }
            }

            return $result;
        }


        $result['status'] =
            'source_missing';

        return $result;
    }


    /**
     * Evaluate all available paths for one skill.
     *
     * Alternative path semantics:
     *
     * path A: all required evidence in A
     * OR
     * path B: all required evidence in B
     *
     * The best path is surfaced to the UI.
     */
    public static function evaluate_skill(
        string $skillid,
        ?string $positionid,
        int $userid
    ): array {

        $definitions =
            self::definitions_for_skill(
                $skillid,
                $positionid
            );

        if (!$definitions) {
            return [
                'skillid' => $skillid,
                'configured' => false,
                'pathcount' => 0,
                'paths' => [],
                'bestpath' => null,
                'progress' => null,
                'satisfied' => false,
            ];
        }


        $groups = [];

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

            $groups[$pathkey][] =
                $definition;
        }


        $paths = [];

        foreach (
            $groups
            as $pathkey => $defs
        ) {

            $items = [];

            $requiredcount = 0;
            $requiredsatisfied = 0;

            $weightedtotal = 0;
            $weightedprogress = 0;


            foreach ($defs as $definition) {

                $item =
                    self::evaluate_definition(
                        $definition,
                        $userid
                    );

                $items[] = $item;


                if ($item['required']) {
                    $requiredcount++;

                    if ($item['satisfied']) {
                        $requiredsatisfied++;
                    }
                }


                $weight =
                    max(
                        0,
                        (int)$item['weight']
                    );

                $weightedtotal +=
                    $weight;

                $weightedprogress +=
                    $item['progress']
                    *
                    $weight;
            }


            $progress =
                $weightedtotal > 0
                    ? (int)round(
                        $weightedprogress
                        /
                        $weightedtotal
                    )
                    : 0;


            $satisfied =
                $requiredcount > 0
                    ? (
                        $requiredsatisfied
                        ===
                        $requiredcount
                    )
                    : $progress >= 100;


            $paths[] = [
                'pathkey' => $pathkey,
                'items' => $items,

                'requiredcount' =>
                    $requiredcount,

                'requiredsatisfied' =>
                    $requiredsatisfied,

                'progress' =>
                    $progress,

                'satisfied' =>
                    $satisfied,
            ];
        }


        usort(
            $paths,
            static function (
                array $a,
                array $b
            ): int {

                /*
                 * A satisfied path always wins.
                 */
                if (
                    $a['satisfied']
                    !==
                    $b['satisfied']
                ) {
                    return
                        $a['satisfied']
                            ? -1
                            : 1;
                }

                return
                    $b['progress']
                    <=>
                    $a['progress'];
            }
        );


        $best =
            $paths[0]
            ?? null;


        return [
            'skillid' => $skillid,
            'configured' => true,

            'pathcount' =>
                count($paths),

            'paths' =>
                $paths,

            'bestpath' =>
                $best,

            'progress' =>
                $best['progress']
                ?? 0,

            'satisfied' =>
                $best['satisfied']
                ?? false,
        ];
    }
}
