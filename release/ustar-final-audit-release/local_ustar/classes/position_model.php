<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * HR position-model editor.
 *
 * Structure JSON remains source of truth for:
 * - positions
 * - skills
 * - required levels
 *
 * local_ustar_skill_evidence remains source of truth for:
 * - Moodle learning/activity mappings
 * - assessment mappings
 * - validity periods
 */
class position_model {

    public static function save_matrix(
        string $positionid,
        array $levels,
        int $actorid
    ): array {

        $positionid = trim($positionid);

        $structure =
            structure::get(
                structure::NAME_STRUCTURE
            );

        $positions = [];
        foreach ($structure['positions'] ?? [] as $position) {
            $positions[$position['id']] = $position;
        }

        if (!isset($positions[$positionid])) {
            throw new \invalid_parameter_exception(
                'Неизвестная должность'
            );
        }

        $skills = [];
        foreach ($structure['skills'] ?? [] as $skill) {
            $skills[$skill['id']] = $skill;
        }

        $clean = [];

        foreach ($levels as $skillid => $level) {

            if (!isset($skills[$skillid])) {
                continue;
            }

            $level = (int)$level;

            if ($level < 1 || $level > 5) {
                throw new \invalid_parameter_exception(
                    'Уровень навыка должен быть от 1 до 5'
                );
            }

            $clean[$skillid] = $level;
        }

        $structure['matrix'][$positionid] =
            $clean;

        structure::save(
            structure::NAME_STRUCTURE,
            $structure
        );

        people::log_action(
            $actorid,
            null,
            'position_matrix_updated',
            [
                'positionid' => $positionid,
                'matrix' => $clean,
            ]
        );

        return self::sync_position(
            $positionid
        );
    }


    public static function add_evidence(
        array $input,
        int $actorid
    ): array {
        global $DB;

        $positionid =
            clean_param(
                trim(
                    (string)(
                        $input['positionid']
                        ?? ''
                    )
                ),
                PARAM_ALPHANUMEXT
            );

        $skillid =
            clean_param(
                trim(
                    (string)(
                        $input['skillid']
                        ?? ''
                    )
                ),
                PARAM_ALPHANUMEXT
            );

        $courseid =
            (int)(
                $input['courseid']
                ?? 0
            );

        $cmid =
            (int)(
                $input['cmid']
                ?? 0
            );

        $type =
            clean_param(
                trim(
                    (string)(
                        $input['evidencetype']
                        ?? ''
                    )
                ),
                PARAM_ALPHANUMEXT
            );

        $weight =
            max(
                1,
                min(
                    100,
                    (int)(
                        $input['weight']
                        ?? 100
                    )
                )
            );

        $required =
            !empty(
                $input['required']
            );

        $validdays =
            max(
                0,
                min(
                    3650,
                    (int)(
                        $input['validdays']
                        ?? 0
                    )
                )
            );

        $pathkey =
            clean_param(
                trim(
                    (string)(
                        $input['pathkey']
                        ?? 'main'
                    )
                ),
                PARAM_ALPHANUMEXT
            );

        if ($pathkey === '') {
            $pathkey = 'main';
        }


        $allowedtypes = [
            'learning',
            'assessment',
        ];

        if (!in_array($type, $allowedtypes, true)) {
            throw new \invalid_parameter_exception(
                'Этот тип подтверждения ещё не поддерживается движком Evidence'
            );
        }


        $structure =
            structure::get(
                structure::NAME_STRUCTURE
            );

        $positionexists = false;

        foreach ($structure['positions'] ?? [] as $position) {
            if ($position['id'] === $positionid) {
                $positionexists = true;
                break;
            }
        }

        if (!$positionexists) {
            throw new \invalid_parameter_exception(
                'Неизвестная должность'
            );
        }


        if (
            !isset(
                $structure['matrix'][$positionid][$skillid]
            )
        ) {
            throw new \invalid_parameter_exception(
                'Сначала добавьте навык в требования должности'
            );
        }


        $course =
            $DB->get_record(
                'course',
                ['id' => $courseid],
                'id,fullname',
                MUST_EXIST
            );

        $cm =
            $DB->get_record(
                'course_modules',
                [
                    'id' => $cmid,
                    'course' => $courseid,
                ],
                'id,course,module,instance,completion',
                MUST_EXIST
            );

        if ((int)$cm->completion === 0) {
            throw new \invalid_parameter_exception(
                'Для активности Moodle не включено отслеживание завершения'
            );
        }


        /*
         * Do not create the same active mapping twice.
         */
        $duplicate =
            $DB->record_exists(
                'local_ustar_skill_evidence',
                [
                    'skillid' => $skillid,
                    'positionid' => $positionid,
                    'courseid' => $courseid,
                    'cmid' => $cmid,
                    'evidencetype' => $type,
                    'pathkey' => $pathkey,
                    'active' => 1,
                ]
            );

        if ($duplicate) {
            throw new \invalid_parameter_exception(
                'Такая связь уже существует'
            );
        }


        $maxsort =
            (int)$DB->get_field_sql(
                "
                    SELECT COALESCE(MAX(sortorder), 0)
                    FROM {local_ustar_skill_evidence}
                    WHERE skillid = :skillid
                      AND positionid = :positionid
                ",
                [
                    'skillid' => $skillid,
                    'positionid' => $positionid,
                ]
            );


        $now = time();

        $id =
            (int)$DB->insert_record(
                'local_ustar_skill_evidence',
                (object)[
                    'skillid' => $skillid,
                    'positionid' => $positionid,
                    'pathkey' => $pathkey,

                    'courseid' => $courseid,
                    'cmid' => $cmid,

                    'evidencetype' => $type,

                    'weight' => $weight,
                    'required' => $required ? 1 : 0,
                    'validdays' => $validdays,

                    'sortorder' => $maxsort + 10,
                    'active' => 1,

                    'timecreated' => $now,
                    'timemodified' => $now,
                    'usermodified' => $actorid,
                ]
            );


        people::log_action(
            $actorid,
            null,
            'position_evidence_added',
            [
                'id' => $id,
                'positionid' => $positionid,
                'skillid' => $skillid,
                'courseid' => $courseid,
                'cmid' => $cmid,
                'type' => $type,
            ]
        );


        $sync =
            self::sync_position(
                $positionid
            );


        return [
            'id' => $id,
            'sync' => $sync,
        ];
    }


    public static function deactivate_evidence(
        int $id,
        string $positionid,
        int $actorid
    ): array {
        global $DB;

        $record =
            $DB->get_record(
                'local_ustar_skill_evidence',
                [
                    'id' => $id,
                    'positionid' => $positionid,
                    'active' => 1,
                ],
                '*',
                MUST_EXIST
            );

        $record->active = 0;
        $record->timemodified = time();
        $record->usermodified = $actorid;

        $DB->update_record(
            'local_ustar_skill_evidence',
            $record
        );

        people::log_action(
            $actorid,
            null,
            'position_evidence_removed',
            [
                'id' => $id,
                'positionid' => $positionid,
                'skillid' => $record->skillid,
            ]
        );

        return self::sync_position(
            $positionid
        );
    }


    /**
     * Reconcile all current active users of this position.
     *
     * Core v1 still only adds required Moodle enrolments.
     * Historical access is not automatically removed.
     */
    public static function sync_position(
        string $positionid
    ): array {
        global $DB;

        $sql = "
            SELECT u.id
              FROM {user} u
              JOIN {user_info_data} d
                ON d.userid = u.id
              JOIN {user_info_field} f
                ON f.id = d.fieldid
               AND f.shortname = 'ustar_position'
             WHERE u.deleted = 0
               AND u.suspended = 0
               AND TRIM(d.data) = :positionid
        ";

        $users =
            $DB->get_records_sql(
                $sql,
                [
                    'positionid' => $positionid,
                ]
            );

        $result = [
            'users' => 0,
            'enrolled' => 0,
            'errors' => [],
        ];

        foreach ($users as $user) {

            try {

                $sync =
                    assignment::sync_user(
                        (int)$user->id
                    );

                $result['users']++;

                $result['enrolled'] +=
                    count(
                        $sync['enrolled']
                        ?? []
                    );

            } catch (\Throwable $e) {

                $result['errors'][] = [
                    'userid' => (int)$user->id,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }
}
