<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use local_ustar\structure;

abstract class base extends external_api {

    /**
     * Common guard for every external function.
     */
    protected static function guard(): void {
        $context = \context_system::instance();

        self::validate_context($context);

        require_capability(
            'local/ustar:use',
            $context
        );
    }


    /**
     * Detailed activity-level progress for a Moodle course.
     *
     * USTAR intentionally calculates learning progress from tracked
     * course activities, rather than relying only on the course-level
     * enablecompletion flag.
     *
     * This matters for legacy/live Moodle courses where individual
     * activities already have completion tracking even though the
     * course-level completion configuration is inconsistent.
     */
    protected static function course_progress_details(
        int $courseid,
        int $userid
    ): array {
        global $DB;

        $course = get_course($courseid);

        $modinfo = get_fast_modinfo(
            $course,
            $userid
        );

        /*
         * Read persisted Moodle activity-completion states in one
         * query instead of performing an N+1 query for every module.
         */
        $sql = "
            SELECT
                cmc.id,
                cmc.coursemoduleid,
                cmc.completionstate,
                cmc.timemodified
              FROM {course_modules_completion} cmc
              JOIN {course_modules} cm
                ON cm.id = cmc.coursemoduleid
             WHERE cm.course = :courseid
               AND cmc.userid = :userid
        ";

        $completionrows = $DB->get_records_sql(
            $sql,
            [
                'courseid' => $courseid,
                'userid' => $userid,
            ]
        );

        $completionbycm = [];

        foreach ($completionrows as $row) {
            $completionbycm[
                (int)$row->coursemoduleid
            ] = $row;
        }

        $tracked = 0;
        $done = 0;
        $failed = 0;

        $lastactivity = 0;

        $nextactivity = null;
        $firstactivity = null;

        /*
         * get_sections() gives course-module IDs in actual course
         * sequence order, which is what we need for "next action".
         */
        foreach ($modinfo->get_sections() as $sectioncmids) {

            foreach ($sectioncmids as $cmid) {

                $cm = $modinfo->get_cm($cmid);

                /*
                 * Do not offer inaccessible activities as actions.
                 */
                if (!$cm->uservisible) {
                    continue;
                }

                /*
                 * Labels/subsections and similar modules may not
                 * provide an actionable URL.
                 */
                if ($cm->url && $firstactivity === null) {
                    $firstactivity = [
                        'cmid' => (int)$cm->id,
                        'name' => $cm->name,
                        'type' => $cm->modname,
                        'url' => $cm->url->out(false),
                    ];
                }

                if (
                    (int)$cm->completion ===
                    COMPLETION_TRACKING_NONE
                ) {
                    continue;
                }

                $tracked++;

                $state = COMPLETION_INCOMPLETE;
                $timemodified = 0;

                if (
                    isset(
                        $completionbycm[
                            (int)$cm->id
                        ]
                    )
                ) {
                    $row =
                        $completionbycm[
                            (int)$cm->id
                        ];

                    $state =
                        (int)$row->completionstate;

                    $timemodified =
                        (int)$row->timemodified;
                }

                if (
                    in_array(
                        $state,
                        [
                            COMPLETION_COMPLETE,
                            COMPLETION_COMPLETE_PASS,
                        ],
                        true
                    )
                ) {
                    $done++;

                    if ($timemodified > $lastactivity) {
                        $lastactivity =
                            $timemodified;
                    }

                    continue;
                }

                if (
                    defined('COMPLETION_COMPLETE_FAIL') &&
                    $state === COMPLETION_COMPLETE_FAIL
                ) {
                    $failed++;
                }

                /*
                 * First incomplete tracked activity in actual Moodle
                 * sequence becomes the canonical USTAR next action.
                 */
                if (
                    $nextactivity === null &&
                    $cm->url
                ) {
                    $nextactivity = [
                        'cmid' => (int)$cm->id,
                        'name' => $cm->name,
                        'type' => $cm->modname,
                        'url' => $cm->url->out(false),
                    ];
                }
            }
        }

        $progress = $tracked > 0
            ? (int)round(
                ($done / $tracked) * 100
            )
            : 0;

        if ($tracked > 0 && $done >= $tracked) {
            $status = 'done';

        } else if ($done > 0 || $failed > 0) {
            $status = 'active';

        } else {
            $status = 'new';
        }

        /*
         * For a course with no tracked activities we can still provide
         * a meaningful opening action without inventing progress.
         */
        if ($nextactivity === null && $tracked === 0) {
            $nextactivity = $firstactivity;
        }

        return [
            'progress' => $progress,

            'tracked' => $tracked,
            'done' => $done,
            'failed' => $failed,

            'hasProgress' => $tracked > 0,

            'status' => $status,

            'nextActivity' => $nextactivity,

            'lastActivity' => $lastactivity,
        ];
    }


    /**
     * Backward-compatible percentage helper.
     *
     * Existing USTAR consumers can keep calling course_progress()
     * while new Moodle-native UI uses the detailed fields.
     */
    protected static function course_progress(
        int $courseid,
        int $userid
    ): int {
        $details =
            self::course_progress_details(
                $courseid,
                $userid
            );

        return $details['progress'];
    }


    /**
     * Courses available to a USTAR user.
     *
     * Sources:
     *  - actual Moodle enrolment;
     *  - courses linked through Position -> Skills.
     *
     * The UI can therefore distinguish real current enrolment from
     * position-required learning without duplicating Moodle data.
     */
    public static function user_courses(
        int $userid
    ): array {
        global $DB;

        $resolved =
            structure::resolve_user(
                $userid
            );

        $structure =
            $resolved['structure'];

        $position =
            $resolved['position'];

        $skillids = $position
            ? structure::skills_for_position(
                $structure,
                $position['id']
            )
            : [];

        $idnumbers =
            structure::courses_for_skills(
                $structure,
                $skillids
            );


        /*
         * Actual Moodle enrolments.
         */
        $enrolled =
            enrol_get_users_courses(
                $userid,
                true,
                implode(',', [
                    'id',
                    'fullname',
                    'shortname',
                    'idnumber',
                    'category',
                ])
            );


        /*
         * courseid => course object
         */
        $courses = [];

        /*
         * courseid => source metadata
         */
        $sources = [];


        foreach ($enrolled as $course) {

            $courseid =
                (int)$course->id;

            $courses[$courseid] =
                $course;

            $sources[$courseid] = [
                'enrolled' => true,
                'positionRequired' => false,
            ];
        }


        /*
         * Position-linked courses can appear even before the scheduled
         * enrolment-sync task has enrolled the user.
         */
        if ($idnumbers) {

            [$insql, $params] =
                $DB->get_in_or_equal(
                    $idnumbers
                );

            $linked =
                $DB->get_records_select(
                    'course',
                    "idnumber {$insql}",
                    $params,
                    '',
                    implode(',', [
                        'id',
                        'fullname',
                        'shortname',
                        'idnumber',
                        'category',
                        'visible',
                    ])
                );

            foreach ($linked as $course) {

                if (!$course->visible) {
                    continue;
                }

                $courseid =
                    (int)$course->id;

                $courses[$courseid] =
                    $course;

                if (!isset($sources[$courseid])) {
                    $sources[$courseid] = [
                        'enrolled' => false,
                        'positionRequired' => true,
                    ];
                } else {
                    $sources[$courseid][
                        'positionRequired'
                    ] = true;
                }
            }
        }


        /*
         * Resolve category names in one DB request.
         */
        $categoryids = [];

        foreach ($courses as $course) {
            $categoryids[] =
                (int)$course->category;
        }

        $categoryids =
            array_values(
                array_unique(
                    array_filter(
                        $categoryids
                    )
                )
            );

        $categories = [];

        if ($categoryids) {

            foreach (
                $DB->get_records_list(
                    'course_categories',
                    'id',
                    $categoryids,
                    '',
                    'id,name'
                ) as $category
            ) {
                $categories[
                    (int)$category->id
                ] = $category->name;
            }
        }


        $result = [];


        foreach ($courses as $course) {

            $courseid =
                (int)$course->id;

            /*
             * Map course back to USTAR skills.
             */
            $courseskills = [];

            foreach (
                $structure['skills'] ?? []
                as $skill
            ) {

                if (
                    in_array(
                        (string)$course->idnumber,
                        $skill['courses'] ?? [],
                        true
                    )
                ) {
                    $courseskills[] = [
                        'id' =>
                            $skill['id'],

                        'name' =>
                            $skill['name'],
                    ];
                }
            }


            $details =
                self::course_progress_details(
                    $courseid,
                    $userid
                );


            $courseurl =
                (
                    new \moodle_url(
                        '/course/view.php',
                        ['id' => $courseid]
                    )
                )->out(false);


            $result[] = [
                'id' =>
                    $courseid,

                'name' =>
                    $course->fullname,

                'shortname' =>
                    $course->shortname,

                'idnumber' =>
                    (string)$course->idnumber,

                'category' =>
                    $categories[
                        (int)$course->category
                    ] ?? '',

                /*
                 * Legacy field retained for old frontend consumers.
                 */
                'skills' =>
                    array_values(
                        array_column(
                            $courseskills,
                            'name'
                        )
                    ),

                /*
                 * Rich field for native Moodle USTAR screens.
                 */
                'skillDetails' =>
                    $courseskills,

                'progress' =>
                    $details['progress'],

                'tracked' =>
                    $details['tracked'],

                'done' =>
                    $details['done'],

                'failed' =>
                    $details['failed'],

                'hasProgress' =>
                    $details['hasProgress'],

                'status' =>
                    $details['status'],

                'nextActivity' =>
                    $details['nextActivity'],

                'lastActivity' =>
                    $details['lastActivity'],

                'enrolled' =>
                    $sources[$courseid][
                        'enrolled'
                    ] ?? false,

                'positionRequired' =>
                    $sources[$courseid][
                        'positionRequired'
                    ] ?? false,

                'url' =>
                    $courseurl,
            ];
        }


        if ($position) {
            $result = \local_ustar\learning_route::apply_order(
                (string)$position['id'],
                $result
            );
        }

        return $result;
    }
}
