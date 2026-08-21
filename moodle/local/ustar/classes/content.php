<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * USTAR universal content catalog.
 *
 * One USTAR content item may represent:
 *
 *   - USTAR-owned file
 *   - existing Moodle activity
 *   - external resource
 *
 * Employee access is derived dynamically from:
 *
 *   user -> position -> department -> content access rules
 */
class content {

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const SOURCE_FILE = 'ustar_file';
    public const SOURCE_MOODLE = 'moodle_cm';
    public const SOURCE_EXTERNAL = 'external';


    /**
     * HR / Academy administrators may inspect all catalog items.
     */
    public static function is_elevated(
        int $userid
    ): bool {

        $context =
            \context_system::instance();

        return
            is_siteadmin($userid)
            ||
            has_capability(
                'local/ustar:admin',
                $context,
                $userid
            )
            ||
            has_capability(
                'local/ustar:hr',
                $context,
                $userid
            );
    }


    /**
     * Resolve USTAR employee scope.
     */
    public static function user_scope(
        int $userid
    ): array {

        $resolved =
            structure::resolve_user(
                $userid
            );


        $position =
            $resolved['position']
            ?? null;

        $department =
            $resolved['department']
            ?? null;


        $positionid = '';

        if (is_array($position)) {

            $positionid =
                trim(
                    (string)(
                        $position['id']
                        ?? ''
                    )
                );

        } else {

            $positionid =
                people::position_id(
                    $userid
                );
        }


        $departmentid = '';

        if (is_array($department)) {

            $departmentid =
                trim(
                    (string)(
                        $department['id']
                        ?? ''
                    )
                );

        } else if (is_string($department)) {

            $departmentid =
                trim(
                    $department
                );
        }


        /*
         * Position remains the canonical source of department
         * if resolve_user() returned no full department object.
         */
        if (
            $departmentid === ''
            &&
            is_array($position)
        ) {

            $departmentid =
                trim(
                    (string)(
                        $position['department']
                        ?? ''
                    )
                );
        }


        return [
            'positionid' =>
                $positionid,

            'departmentid' =>
                $departmentid,
        ];
    }


    /**
     * Can employee see a specific catalog record?
     *
     * Security rule:
     * no access rows = no employee access.
     *
     * That prevents accidental publication to the whole company.
     */
    public static function can_access_record(
        \stdClass $record,
        int $userid
    ): bool {
        global $DB;

        if (
            self::is_elevated(
                $userid
            )
        ) {
            return true;
        }


        if (
            $record->status
            !==
            self::STATUS_PUBLISHED
        ) {
            return false;
        }


        $rules =
            $DB->get_records(
                'local_ustar_content_access',
                [
                    'contentid' =>
                        $record->id,

                    'active' =>
                        1,
                ]
            );


        /*
         * Explicit deny-by-default.
         */
        if (!$rules) {
            return false;
        }


        $scope =
            self::user_scope(
                $userid
            );


        foreach ($rules as $rule) {

            $type =
                trim(
                    (string)$rule->scopetype
                );

            $id =
                trim(
                    (string)$rule->scopeid
                );


            if ($type === 'all') {
                return true;
            }


            if (
                $type === 'position'
                &&
                $id !== ''
                &&
                $id === $scope['positionid']
            ) {
                return true;
            }


            if (
                $type === 'department'
                &&
                $id !== ''
                &&
                $id === $scope['departmentid']
            ) {
                return true;
            }
        }


        return false;
    }


    public static function can_access(
        int $contentid,
        int $userid
    ): bool {
        global $DB;

        $record =
            $DB->get_record(
                'local_ustar_content',
                [
                    'id' =>
                        $contentid,
                ]
            );

        if (!$record) {
            return false;
        }

        return self::can_access_record(
            $record,
            $userid
        );
    }


    /**
     * Current version.
     */
    public static function current_version(
        int $contentid
    ): ?\stdClass {
        global $DB;

        $sql = "
            SELECT *
              FROM {local_ustar_content_versions}
             WHERE contentid = :contentid
               AND iscurrent = 1
          ORDER BY versionno DESC, id DESC
        ";

        $records =
            $DB->get_records_sql(
                $sql,
                [
                    'contentid' =>
                        $contentid,
                ],
                0,
                1
            );

        if (!$records) {
            return null;
        }

        return reset($records);
    }


    /**
     * Access check specifically for a versioned file.
     *
     * Employees only receive the current published version.
     * HR/admin may inspect historical versions.
     */
    public static function can_access_version(
        int $versionid,
        int $userid
    ): bool {
        global $DB;

        $version =
            $DB->get_record(
                'local_ustar_content_versions',
                [
                    'id' =>
                        $versionid,
                ]
            );

        if (!$version) {
            return false;
        }


        $content =
            $DB->get_record(
                'local_ustar_content',
                [
                    'id' =>
                        $version->contentid,
                ]
            );

        if (!$content) {
            return false;
        }


        if (
            self::is_elevated(
                $userid
            )
        ) {
            return true;
        }


        if (
            empty($version->iscurrent)
            ||
            $version->status
                !==
                self::STATUS_PUBLISHED
        ) {
            return false;
        }


        return self::can_access_record(
            $content,
            $userid
        );
    }


    /**
     * Employee catalog.
     */
    public static function list_for_user(
        int $userid,
        array $filters = []
    ): array {
        global $DB;

        $where = [];
        $params = [];


        if (
            !self::is_elevated(
                $userid
            )
        ) {

            $where[] =
                'status = :published';

            $params['published'] =
                self::STATUS_PUBLISHED;
        }


        $type =
            trim(
                (string)(
                    $filters['type']
                    ?? ''
                )
            );

        if ($type !== '') {

            $where[] =
                'type = :type';

            $params['type'] =
                $type;
        }


        $category =
            trim(
                (string)(
                    $filters['category']
                    ?? ''
                )
            );

        if ($category !== '') {

            $where[] =
                'category = :category';

            $params['category'] =
                $category;
        }


        $query =
            trim(
                (string)(
                    $filters['query']
                    ?? ''
                )
            );

        if ($query !== '') {

            $like =
                '%'
                .
                $DB->sql_like_escape(
                    $query
                )
                .
                '%';

            $where[] =
                '('
                .
                $DB->sql_like(
                    'title',
                    ':q1',
                    false
                )
                .
                ' OR '
                .
                $DB->sql_like(
                    'summary',
                    ':q2',
                    false
                )
                .
                ')';

            $params['q1'] = $like;
            $params['q2'] = $like;
        }


        $select =
            $where
                ? implode(
                    ' AND ',
                    $where
                )
                : '1 = 1';


        $records =
            $DB->get_records_select(
                'local_ustar_content',
                $select,
                $params,
                'sortorder ASC, publishedat DESC, title ASC',
                '*',
                0,
                1000
            );


        $result = [];


        foreach ($records as $record) {

            if (
                !self::can_access_record(
                    $record,
                    $userid
                )
            ) {
                continue;
            }


            $version =
                self::current_version(
                    (int)$record->id
                );


            $acked = false;

            if ($version) {

                $acked =
                    $DB->record_exists(
                        'local_ustar_content_ack',
                        [
                            'userid' =>
                                $userid,

                            'versionid' =>
                                $version->id,
                        ]
                    );
            }


            $result[] = [
                'id' =>
                    (int)$record->id,

                'type' =>
                    $record->type,

                'title' =>
                    $record->title,

                'summary' =>
                    (string)$record->summary,

                'category' =>
                    (string)$record->category,

                'status' =>
                    $record->status,

                'sourcekind' =>
                    $record->sourcekind,

                'ackrequired' =>
                    !empty(
                        $record->ackrequired
                    ),

                'acked' =>
                    $acked,

                'needsack' =>
                    !empty(
                        $record->ackrequired
                    )
                    &&
                    !$acked,

                'versionid' =>
                    $version
                        ? (int)$version->id
                        : 0,

                'versionlabel' =>
                    $version
                        ? (
                            $version->versionlabel
                            ?: 'v'
                                .
                                $version->versionno
                        )
                        : '',


                'publishedat' =>
                    (int)($record->publishedat ?? 0),

                'timemodified' =>
                    (int)($record->timemodified ?? 0),
            ];
        }


        return $result;
    }


    /**
     * Produce the runtime URL for an accessible content item.
     */
    public static function open_url(
        int $contentid,
        int $userid
    ): ?\moodle_url {
        global $DB;

        $record =
            $DB->get_record(
                'local_ustar_content',
                [
                    'id' =>
                        $contentid,
                ]
            );

        if (
            !$record
            ||
            !self::can_access_record(
                $record,
                $userid
            )
        ) {
            return null;
        }


        /*
         * Existing Moodle activity.
         */
        if (
            $record->sourcekind
            ===
            self::SOURCE_MOODLE
        ) {

            if (
                empty($record->cmid)
                ||
                empty($record->courseid)
            ) {
                return null;
            }


            $cm =
                $DB->get_record_sql(
                    "
                        SELECT
                            cm.id,
                            cm.course,
                            m.name AS modname
                        FROM {course_modules} cm
                        JOIN {modules} m
                          ON m.id = cm.module
                        WHERE cm.id = :cmid
                          AND cm.course = :courseid
                    ",
                    [
                        'cmid' =>
                            $record->cmid,

                        'courseid' =>
                            $record->courseid,
                    ]
                );


            if (!$cm) {
                return null;
            }


            return new \moodle_url(
                '/mod/'
                .
                $cm->modname
                .
                '/view.php',
                [
                    'id' =>
                        $cm->id,

                    'theme' =>
                        'ustar',
                ]
            );
        }


        /*
         * External resource.
         */
        if (
            $record->sourcekind
            ===
            self::SOURCE_EXTERNAL
        ) {

            $url =
                trim(
                    (string)$record->externalurl
                );

            if ($url === '') {
                return null;
            }

            return new \moodle_url(
                $url
            );
        }


        /*
         * USTAR-owned content.
         *
         * Never expose pluginfile as the user-facing navigation
         * target. The USTAR viewer owns presentation/runtime.
         */
        if (
            $record->sourcekind
            ===
            self::SOURCE_FILE
        ) {

            $version =
                self::current_version(
                    $contentid
                );

            if (!$version) {
                return null;
            }


            if (
                !self::can_access_version(
                    (int)$version->id,
                    $userid
                )
            ) {
                return null;
            }


            $context =
                \context_system::instance();

            $files =
                get_file_storage()
                    ->get_area_files(
                        $context->id,
                        'local_ustar',
                        'content_version',
                        $version->id,
                        'sortorder DESC, id ASC',
                        false
                    );


            if (!$files) {
                return null;
            }


            return new \moodle_url(
                '/local/ustar/view.php',
                [
                    'id' =>
                        $contentid,

                    'view' =>
                        'knowledge',

                    'theme' =>
                        'ustar',
                ]
            );
        }

        return null;
    }


    /**
     * Record version-specific acknowledgement.
     */
    public static function acknowledge(
        int $contentid,
        int $userid
    ): bool {
        global $DB;

        if (
            !self::can_access(
                $contentid,
                $userid
            )
        ) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/ustar:use',
                'nopermissions',
                ''
            );
        }


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


        if (
            empty(
                $content->ackrequired
            )
        ) {
            return true;
        }


        $version =
            self::current_version(
                $contentid
            );


        if (
            !$version
            ||
            empty($version->iscurrent)
            ||
            $version->status
                !==
                self::STATUS_PUBLISHED
        ) {
            throw new \moodle_exception(
                'Текущая опубликованная версия отсутствует'
            );
        }


        if (
            $DB->record_exists(
                'local_ustar_content_ack',
                [
                    'userid' =>
                        $userid,

                    'versionid' =>
                        $version->id,
                ]
            )
        ) {
            return true;
        }


        $now = time();


        $DB->insert_record(
            'local_ustar_content_ack',
            (object)[
                'contentid' =>
                    $contentid,

                'versionid' =>
                    $version->id,

                'userid' =>
                    $userid,

                'acktime' =>
                    $now,

                'method' =>
                    'manual',

                'timecreated' =>
                    $now,
            ]
        );


        return true;
    }


    /**
     * Scan real Moodle course activities without importing anything.
     *
     * This is the future "Найдено в Moodle" source for Control Center.
     */
    public static function discover_moodle(
        int $limit = 1000
    ): array {
        global $DB;

        $limit =
            max(
                1,
                min(
                    5000,
                    $limit
                )
            );


        $alreadyimported = [];

        $existing =
            $DB->get_records_select(
                'local_ustar_content',
                "
                    sourcekind = :source
                    AND cmid IS NOT NULL
                ",
                [
                    'source' =>
                        self::SOURCE_MOODLE,
                ],
                '',
                'id,cmid'
            );

        foreach ($existing as $item) {

            $alreadyimported[
                (int)$item->cmid
            ] = (int)$item->id;
        }


        $sql = "
            SELECT
                cm.id AS cmid,
                cm.course AS courseid,
                cm.instance,
                cm.visible AS cmvisible,
                cm.completion,
                m.name AS modname,
                c.fullname AS coursename,
                c.shortname AS courseshortname,
                c.visible AS coursevisible
            FROM {course_modules} cm
            JOIN {modules} m
              ON m.id = cm.module
            JOIN {course} c
              ON c.id = cm.course
            WHERE c.id <> :siteid
              AND m.name NOT IN ('qbank', 'label')
            ORDER BY
                c.fullname,
                cm.id
        ";


        $records =
            $DB->get_records_sql(
                $sql,
                [
                    'siteid' =>
                        SITEID,
                ],
                0,
                $limit
            );


        $typemap = [
            'resource' =>
                'document',

            'folder' =>
                'collection',

            'page' =>
                'article',

            'book' =>
                'article',

            'url' =>
                'link',

            'quiz' =>
                'quiz',

            'scorm' =>
                'scorm',

            'forum' =>
                'forum',

            'lesson' =>
                'lesson',

            'assign' =>
                'assignment',

            'h5pactivity' =>
                'interactive',
        ];


        $result = [];


        foreach ($records as $row) {

            $activityname = '';

            try {

                $activityname =
                    trim(
                        (string)$DB->get_field(
                            $row->modname,
                            'name',
                            [
                                'id' =>
                                    $row->instance,
                            ]
                        )
                    );

            } catch (\Throwable $e) {

                $activityname = '';
            }


            if ($activityname === '') {

                $activityname =
                    strtoupper(
                        $row->modname
                    )
                    .
                    ' #'
                    .
                    $row->cmid;
            }


            $result[] = [
                'cmid' =>
                    (int)$row->cmid,

                'courseid' =>
                    (int)$row->courseid,

                'coursename' =>
                    $row->coursename,

                'courseshortname' =>
                    $row->courseshortname,

                'coursevisible' =>
                    !empty(
                        $row->coursevisible
                    ),

                'activityvisible' =>
                    !empty(
                        $row->cmvisible
                    ),

                'completion' =>
                    (int)$row->completion,

                'modname' =>
                    $row->modname,

                'type' =>
                    $typemap[
                        $row->modname
                    ]
                    ??
                    'activity',

                'name' =>
                    $activityname,

                'imported' =>
                    isset(
                        $alreadyimported[
                            (int)$row->cmid
                        ]
                    ),

                'contentid' =>
                    $alreadyimported[
                        (int)$row->cmid
                    ]
                    ?? 0,
            ];
        }


        return $result;
    }

    /**
     * Register an existing Moodle activity in the USTAR catalog.
     *
     * IMPORTANT:
     *
     * - Moodle activity is NOT copied.
     * - completion / quiz / SCORM runtime remains Moodle.
     * - imported item starts as DRAFT.
     * - no access rules are created.
     *
     * Therefore importing cannot expose content to employees.
     */
    public static function import_moodle_cm(
        int $cmid,
        int $actorid
    ): array {
        global $DB;

        if ($cmid <= 0) {
            throw new \invalid_parameter_exception(
                'Invalid course module id'
            );
        }


        /*
         * Idempotency.
         */
        $existing =
            $DB->get_record(
                'local_ustar_content',
                [
                    'sourcekind' =>
                        self::SOURCE_MOODLE,

                    'cmid' =>
                        $cmid,
                ]
            );

        if ($existing) {

            return [
                'created' =>
                    false,

                'contentid' =>
                    (int)$existing->id,

                'cmid' =>
                    $cmid,

                'title' =>
                    $existing->title,
            ];
        }


        /*
         * Resolve the real Moodle activity.
         */
        $row =
            $DB->get_record_sql(
                "
                    SELECT
                        cm.id AS cmid,
                        cm.course AS courseid,
                        cm.instance,
                        cm.visible AS cmvisible,
                        cm.completion,
                        m.name AS modname,
                        c.fullname AS coursename,
                        c.visible AS coursevisible
                    FROM {course_modules} cm
                    JOIN {modules} m
                      ON m.id = cm.module
                    JOIN {course} c
                      ON c.id = cm.course
                    WHERE cm.id = :cmid
                      AND c.id <> :siteid
                ",
                [
                    'cmid' =>
                        $cmid,

                    'siteid' =>
                        SITEID,
                ]
            );


        if (!$row) {
            throw new \invalid_parameter_exception(
                'Moodle activity not found'
            );
        }


        if (
            in_array(
                $row->modname,
                [
                    'qbank',
                    'label',
                ],
                true
            )
        ) {
            throw new \invalid_parameter_exception(
                'This Moodle module type is not importable'
            );
        }


        /*
         * Human-facing USTAR content type.
         */
        $typemap = [

            'resource' =>
                'document',

            'folder' =>
                'collection',

            'page' =>
                'article',

            'book' =>
                'article',

            'url' =>
                'link',

            'quiz' =>
                'quiz',

            'scorm' =>
                'scorm',

            'forum' =>
                'forum',

            'lesson' =>
                'lesson',

            'assign' =>
                'assignment',

            'h5pactivity' =>
                'interactive',

            'data' =>
                'database',
        ];


        $type =
            $typemap[
                $row->modname
            ]
            ??
            'activity';


        /*
         * Resolve the activity title from its Moodle instance.
         */
        $title = '';

        try {

            $title =
                trim(
                    (string)$DB->get_field(
                        $row->modname,
                        'name',
                        [
                            'id' =>
                                $row->instance,
                        ]
                    )
                );

        } catch (\Throwable $e) {

            $title = '';
        }


        if ($title === '') {

            $title =
                strtoupper(
                    $row->modname
                )
                .
                ' #'
                .
                $cmid;
        }


        $now = time();

        $transaction =
            $DB->start_delegated_transaction();


        /*
         * Catalog entry.
         *
         * Intentionally:
         *
         *   status = draft
         *   category = NULL
         *   ackrequired = 0
         *
         * HR must explicitly curate and publish it later.
         */
        $contentid =
            (int)$DB->insert_record(
                'local_ustar_content',
                (object)[

                    'type' =>
                        $type,

                    'title' =>
                        $title,

                    'summary' =>
                        null,

                    'category' =>
                        null,

                    'status' =>
                        self::STATUS_DRAFT,

                    'sourcekind' =>
                        self::SOURCE_MOODLE,

                    'courseid' =>
                        (int)$row->courseid,

                    'cmid' =>
                        $cmid,

                    'externalurl' =>
                        null,

                    'owneruserid' =>
                        null,

                    'ackrequired' =>
                        0,

                    'publishedat' =>
                        null,

                    'sortorder' =>
                        0,

                    'timecreated' =>
                        $now,

                    'timemodified' =>
                        $now,

                    'usermodified' =>
                        $actorid,
                ]
            );


        /*
         * Moodle-backed content also receives catalog version v1.
         *
         * This is metadata versioning only; the Moodle activity
         * itself remains the runtime/source.
         */
        $DB->insert_record(
            'local_ustar_content_versions',
            (object)[

                'contentid' =>
                    $contentid,

                'versionno' =>
                    1,

                'versionlabel' =>
                    'v1',

                'changenote' =>
                    'Импортировано из Moodle',

                'effectivedate' =>
                    null,

                'iscurrent' =>
                    1,

                'status' =>
                    self::STATUS_DRAFT,

                'timecreated' =>
                    $now,

                'createdby' =>
                    $actorid,
            ]
        );


        people::log_action(
            $actorid,
            null,
            'content_moodle_imported',
            [
                'contentid' =>
                    $contentid,

                'courseid' =>
                    (int)$row->courseid,

                'cmid' =>
                    $cmid,

                'modname' =>
                    $row->modname,

                'type' =>
                    $type,

                'title' =>
                    $title,
            ]
        );


        $transaction->allow_commit();


        return [
            'created' =>
                true,

            'contentid' =>
                $contentid,

            'cmid' =>
                $cmid,

            'courseid' =>
                (int)$row->courseid,

            'modname' =>
                $row->modname,

            'type' =>
                $type,

            'title' =>
                $title,

            'coursevisible' =>
                !empty(
                    $row->coursevisible
                ),

            'activityvisible' =>
                !empty(
                    $row->cmvisible
                ),
        ];
    }


    /**
     * Import every currently discoverable Moodle activity
     * into the USTAR catalog as a draft.
     *
     * Safe to run repeatedly.
     */
    public static function import_all_moodle(
        int $actorid
    ): array {

        $discovered =
            self::discover_moodle(
                5000
            );


        $result = [

            'discovered' =>
                count(
                    $discovered
                ),

            'created' =>
                0,

            'existing' =>
                0,

            'errors' =>
                [],
        ];


        foreach ($discovered as $item) {

            try {

                $import =
                    self::import_moodle_cm(
                        (int)$item['cmid'],
                        $actorid
                    );


                if (
                    !empty(
                        $import['created']
                    )
                ) {

                    $result['created']++;

                } else {

                    $result['existing']++;
                }

            } catch (\Throwable $e) {

                $result['errors'][] = [

                    'cmid' =>
                        (int)$item['cmid'],

                    'name' =>
                        $item['name']
                        ?? '',

                    'message' =>
                        $e->getMessage(),
                ];
            }
        }


        return $result;
    }

}
