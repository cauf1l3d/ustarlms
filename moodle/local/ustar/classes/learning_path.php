<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * USTAR guided learning path.
 *
 * Moodle remains the source of truth for:
 * - activity order;
 * - completion tracking;
 * - pass/fail state;
 * - activity URLs.
 *
 * USTAR converts those facts into a guided employee experience:
 *
 *   completed -> current -> locked future steps.
 *
 * UI locking is intentionally separate from Moodle availability rules.
 * Hard restrictions can later be configured through Moodle availability.
 */
class learning_path {

    /**
     * Build a guided path for one Moodle course and user.
     */
    public static function for_user(
        int $courseid,
        int $userid
    ): array {
        global $DB, $CFG;

        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,fullname,shortname,idnumber,visible',
            MUST_EXIST
        );

        $modinfo = get_fast_modinfo(
            $course,
            $userid
        );


        /*
         * Collect actionable Moodle activities in real course order.
         */
        $all = [];

        foreach (
            $modinfo->get_sections()
            as $sectionnum => $cmids
        ) {

            $sectioninfo =
                $modinfo->get_section_info(
                    $sectionnum
                );

            $sectionname = '';

            if (
                $sectioninfo
                &&
                !empty($sectioninfo->name)
            ) {
                $sectionname =
                    format_string(
                        $sectioninfo->name,
                        true,
                        [
                            'context' =>
                                \context_course::instance(
                                    $courseid
                                ),
                        ]
                    );
            }


            foreach ($cmids as $cmid) {

                $cm =
                    $modinfo->get_cm(
                        $cmid
                    );


                /*
                 * Internal question-bank modules are not employee
                 * learning steps.
                 */
                if (
                    in_array(
                        $cm->modname,
                        [
                            'qbank',
                            'label',
                        ],
                        true
                    )
                ) {
                    continue;
                }


                /*
                 * A guided step must lead somewhere.
                 */
                if (!$cm->url) {
                    continue;
                }


                $all[] = [
                    'cmid' =>
                        (int)$cm->id,

                    'name' =>
                        format_string(
                            $cm->name,
                            true,
                            [
                                'context' =>
                                    \context_module::instance(
                                        $cm->id
                                    ),
                            ]
                        ),

                    'modname' =>
                        (string)$cm->modname,

                    'sectionnum' =>
                        (int)$sectionnum,

                    'sectionname' =>
                        $sectionname,

                    'tracked' =>
                        (int)$cm->completion
                        !==
                        COMPLETION_TRACKING_NONE,

                    'url' =>
                        clone $cm->url,
                ];
            }
        }


        /*
         * Preferred guided path = activities which Moodle explicitly
         * tracks for completion.
         */
        $steps =
            array_values(
                array_filter(
                    $all,
                    static fn(array $item) =>
                        !empty($item['tracked'])
                )
            );


        /*
         * Fallback for legacy courses without completion tracking.
         *
         * This path can be navigated but does not claim verified
         * completion.
         */
        $fallback = false;

        if (!$steps) {
            $steps = $all;
            $fallback = true;
        }


        $cmids =
            array_values(
                array_map(
                    static fn(array $step) =>
                        (int)$step['cmid'],
                    $steps
                )
            );


        /*
         * Load user completion in one query.
         */
        $states = [];

        if ($cmids && !$fallback) {

            [$insql, $params] =
                $DB->get_in_or_equal(
                    $cmids,
                    SQL_PARAMS_NAMED,
                    'pathcm'
                );

            $params['userid'] =
                $userid;

            foreach (
                $DB->get_records_select(
                    'course_modules_completion',
                    "
                        userid = :userid
                        AND coursemoduleid {$insql}
                    ",
                    $params,
                    '',
                    'coursemoduleid,completionstate,timemodified'
                )
                as $record
            ) {

                $states[
                    (int)$record->coursemoduleid
                ] = [
                    'state' =>
                        (int)$record->completionstate,

                    'timemodified' =>
                        (int)$record->timemodified,
                ];
            }
        }


        /*
         * Find first step that is not successfully satisfied.
         *
         * Moodle completion states:
         *
         * 0 incomplete
         * 1 complete
         * 2 complete + pass
         * 3 complete + fail
         *
         * State 3 remains the current step because employee must retry.
         */
        $currentindex = null;

        if ($fallback) {

            if ($steps) {
                $currentindex = 0;
            }

        } else {

            foreach (
                $steps
                as $index => $step
            ) {

                $state =
                    $states[
                        $step['cmid']
                    ]['state']
                    ?? 0;

                if (
                    !in_array(
                        $state,
                        [1, 2],
                        true
                    )
                ) {
                    $currentindex =
                        $index;

                    break;
                }
            }
        }


        $viewsteps = [];
        $done = 0;
        $failed = 0;


        foreach (
            $steps
            as $index => $step
        ) {

            $state =
                $states[
                    $step['cmid']
                ]['state']
                ?? 0;

            $isdone =
                !$fallback
                &&
                in_array(
                    $state,
                    [1, 2],
                    true
                );

            $isfailed =
                !$fallback
                &&
                $state === 3;

            $iscurrent =
                $currentindex !== null
                &&
                $index === $currentindex;

            $islocked =
                $currentindex !== null
                &&
                $index > $currentindex;

            if ($isdone) {
                $done++;
            }

            if ($isfailed) {
                $failed++;
            }


            /*
             * Preserve USTAR theme during development preview.
             * Once USTAR becomes the production theme this parameter
             * becomes harmless and can later be removed.
             */
            $url = clone $step['url'];
            $url->param(
                'theme',
                'ustar'
            );


            if ($isdone) {

                $status = 'done';
                $statuslabel = 'Завершено';

            } else if ($isfailed) {

                $status = 'failed';
                $statuslabel = 'Нужно повторить';

            } else if ($iscurrent) {

                $status = 'current';
                $statuslabel =
                    $fallback
                        ? 'Начать'
                        : 'Текущий этап';

            } else if ($islocked) {

                $status = 'locked';
                $statuslabel = 'После предыдущего';

            } else {

                $status = 'available';
                $statuslabel = 'Доступно';
            }


            $viewsteps[] = [
                'number' =>
                    $index + 1,

                'cmid' =>
                    $step['cmid'],

                'name' =>
                    $step['name'],

                'modname' =>
                    $step['modname'],

                'sectionname' =>
                    $step['sectionname'],

                'hassection' =>
                    $step['sectionname'] !== '',

                'state' =>
                    $state,

                'status' =>
                    $status,

                'statuslabel' =>
                    $statuslabel,

                'done' =>
                    $isdone,

                'failed' =>
                    $isfailed,

                'current' =>
                    $iscurrent,

                'locked' =>
                    $islocked,

                'available' =>
                    !$islocked,

                'url' =>
                    $url->out(false),

                'timemodified' =>
                    $states[
                        $step['cmid']
                    ]['timemodified']
                    ?? 0,
            ];
        }


        $total =
            count($viewsteps);

        $progress =
            (!$fallback && $total)
                ? (int)round(
                    $done
                    /
                    $total
                    *
                    100
                )
                : 0;


        $completed =
            !$fallback
            &&
            $total > 0
            &&
            $done === $total;


        $next = null;

        if (
            $currentindex !== null
            &&
            isset(
                $viewsteps[
                    $currentindex
                ]
            )
        ) {
            $next =
                $viewsteps[
                    $currentindex
                ];
        }


        return [
            'courseid' =>
                (int)$course->id,

            'name' =>
                $course->fullname,

            'shortname' =>
                $course->shortname,

            'idnumber' =>
                trim(
                    (string)$course->idnumber
                ),

            'published' =>
                !empty($course->visible),

            'draft' =>
                empty($course->visible),

            'fallback' =>
                $fallback,

            'steps' =>
                $viewsteps,

            'hassteps' =>
                !empty($viewsteps),

            'total' =>
                $total,

            'done' =>
                $done,

            'failed' =>
                $failed,

            'progress' =>
                $progress,

            'completed' =>
                $completed,

            'hasnext' =>
                $next !== null,

            'next' =>
                $next,
        ];
    }
}
