<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Mutations for USTAR Content Hub.
 *
 * Read/access logic remains in local_ustar\content.
 */
class content_admin {

    public static function categories(): array {
        return [
            'regulation' => 'Регламент',
            'instruction' => 'Инструкция',
            'policy' => 'Политика',
            'standard' => 'Стандарт',
            'template' => 'Шаблон',
            'reference' => 'Справочник',
            'video' => 'Видео',
            'learning' => 'Учебный материал',
            'assessment' => 'Аттестация',
            'other' => 'Прочее',
        ];
    }


    /**
     * Move a file/folder with optimistic concurrency and immutable audit.
     */
    public static function move(
        int $contentid,
        int $parentid,
        int $expectedmodified,
        int $actorid
    ): array {
        global $DB;

        self::require_manage($actorid);
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_ustar_content');
        // A single hierarchy lock also prevents two concurrent folder moves
        // from creating a cycle across different content rows.
        $lock = $lockfactory->get_lock('hierarchy', 10);
        if (!$lock) {
            throw new \moodle_exception('Не удалось получить блокировку материала. Повторите действие.');
        }

        try {
        $record = $DB->get_record('local_ustar_content', ['id' => $contentid], '*', MUST_EXIST);

        if ($expectedmodified <= 0 || (int)$record->timemodified !== $expectedmodified) {
            throw new \moodle_exception(
                'Материал уже изменён в другой сессии. Обновите страницу и повторите перенос.'
            );
        }
        if ($parentid === $contentid) {
            throw new \invalid_parameter_exception('Материал нельзя поместить внутрь самого себя');
        }

        if ($parentid > 0) {
            $cursor = $DB->get_record(
                'local_ustar_content',
                ['id' => $parentid, 'type' => 'folder'],
                'id,parentid,type',
                MUST_EXIST
            );
            if ((string)$record->type === 'folder') {
                $seen = [];
                while ($cursor) {
                    $cursorid = (int)$cursor->id;
                    if ($cursorid === $contentid) {
                        throw new \invalid_parameter_exception('Нельзя переместить папку внутрь её дочерней папки');
                    }
                    if (isset($seen[$cursorid])) {
                        throw new \invalid_parameter_exception('Обнаружена повреждённая циклическая структура папок');
                    }
                    $seen[$cursorid] = true;
                    $nextid = (int)($cursor->parentid ?? 0);
                    $cursor = $nextid > 0
                        ? $DB->get_record('local_ustar_content', ['id' => $nextid, 'type' => 'folder'], 'id,parentid,type')
                        : false;
                }
            }
        }

        $oldparentid = (int)($record->parentid ?? 0);
        if ($oldparentid === $parentid) {
            return ['contentid' => $contentid, 'oldparentid' => $oldparentid, 'newparentid' => $parentid, 'changed' => false];
        }

        $transaction = $DB->start_delegated_transaction();
        $record->parentid = $parentid > 0 ? $parentid : null;
        $record->timemodified = max(time(), $expectedmodified + 1);
        $record->usermodified = $actorid;
        $DB->update_record('local_ustar_content', $record);
        learning_events::record_content_move($actorid, $contentid, $oldparentid, $parentid, $expectedmodified);
        $transaction->allow_commit();

        return ['contentid' => $contentid, 'oldparentid' => $oldparentid, 'newparentid' => $parentid, 'changed' => true];
        } finally {
            $lock->release();
        }
    }


    private static function require_manage(
        int $actorid
    ): void {

        $context =
            \context_system::instance();

        if (
            !is_siteadmin($actorid)
            &&
            !has_capability(
                'local/ustar:hrmanage',
                $context,
                $actorid
            )
            &&
            !has_capability(
                'local/ustar:admin',
                $context,
                $actorid
            )
        ) {
            throw new \required_capability_exception(
                $context,
                'local/ustar:hrmanage',
                'nopermissions',
                ''
            );
        }
    }


    /**
     * Save metadata + dynamic access scopes.
     *
     * Saving does NOT publish the item.
     */
    public static function save(
        int $contentid,
        array $input,
        int $actorid
    ): array {
        global $DB;

        self::require_manage(
            $actorid
        );


        $record =
            $DB->get_record(
                'local_ustar_content',
                [
                    'id' =>
                        $contentid,
                ],
                '*',
                MUST_EXIST
            );


        $title =
            trim(
                clean_param(
                    (string)(
                        $input['title']
                        ?? ''
                    ),
                    PARAM_TEXT
                )
            );

        if ($title === '') {
            throw new \invalid_parameter_exception(
                'Название материала обязательно'
            );
        }


        $summary =
            trim(
                clean_param(
                    (string)(
                        $input['summary']
                        ?? ''
                    ),
                    PARAM_TEXT
                )
            );


        $category =
            clean_param(
                (string)(
                    $input['category']
                    ?? ''
                ),
                PARAM_ALPHANUMEXT
            );


        if (
            $category !== ''
            &&
            !isset(
                self::categories()[
                    $category
                ]
            )
        ) {
            throw new \invalid_parameter_exception(
                'Неизвестная категория'
            );
        }


        $ackrequired =
            !empty(
                $input['ackrequired']
            );


        /*
         * Optional file-manager folder. Folders are content rows with type=folder.
         * The parent relation is metadata only and never replaces Moodle file ACLs.
         */
        $parentid = (int)($input['parentid'] ?? 0);

        if ($parentid === $contentid) {
            throw new \invalid_parameter_exception('Материал нельзя поместить внутрь самого себя');
        }

        if ($parentid > 0) {
            $parent = $DB->get_record(
                'local_ustar_content',
                ['id' => $parentid, 'type' => 'folder'],
                'id,parentid,type',
                MUST_EXIST
            );

            // Prevent a folder from being moved under one of its descendants.
            if ((string)$record->type === 'folder') {
                $cursor = $parent;
                $seen = [];
                while ($cursor) {
                    $cursorid = (int)$cursor->id;
                    if ($cursorid === $contentid) {
                        throw new \invalid_parameter_exception('Нельзя создать циклическую структуру папок');
                    }
                    if (isset($seen[$cursorid])) {
                        throw new \invalid_parameter_exception('Обнаружена повреждённая циклическая структура папок');
                    }
                    $seen[$cursorid] = true;
                    $nextid = (int)($cursor->parentid ?? 0);
                    $cursor = $nextid > 0
                        ? $DB->get_record('local_ustar_content', ['id' => $nextid, 'type' => 'folder'], 'id,parentid,type')
                        : false;
                }
            }
        }


        /*
         * --------------------------------------------------------
         * ACCESS
         * --------------------------------------------------------
         *
         * mode=all
         *     entire company
         *
         * mode=custom
         *     selected departments and/or positions
         *
         * Empty custom access is valid while the item is draft.
         * Publish() will reject it.
         */

        $accessmode =
            clean_param(
                (string)(
                    $input['accessmode']
                    ?? 'custom'
                ),
                PARAM_ALPHA
            );


        if (
            !in_array(
                $accessmode,
                [
                    'all',
                    'custom',
                ],
                true
            )
        ) {
            throw new \invalid_parameter_exception(
                'Неизвестный режим доступа'
            );
        }


        $structure =
            structure::get(
                structure::NAME_STRUCTURE
            );


        $validpositions = [];

        foreach (
            $structure['positions'] ?? []
            as $position
        ) {
            $validpositions[
                $position['id']
            ] = true;
        }


        $validdepartments = [];

        foreach (
            $structure['departments'] ?? []
            as $department
        ) {
            $validdepartments[
                $department['id']
            ] = true;
        }


        $rules = [];


        if ($accessmode === 'all') {

            $rules[] = [
                'scopetype' => 'all',
                'scopeid' => null,
            ];

        } else {

            $positions =
                $input['positions']
                ?? [];

            if (!is_array($positions)) {
                $positions = [];
            }


            foreach (
                array_unique($positions)
                as $positionid
            ) {

                $positionid =
                    clean_param(
                        (string)$positionid,
                        PARAM_ALPHANUMEXT
                    );

                if (
                    $positionid !== ''
                    &&
                    isset(
                        $validpositions[
                            $positionid
                        ]
                    )
                ) {
                    $rules[] = [
                        'scopetype' =>
                            'position',

                        'scopeid' =>
                            $positionid,
                    ];
                }
            }


            $departments =
                $input['departments']
                ?? [];

            if (!is_array($departments)) {
                $departments = [];
            }


            foreach (
                array_unique($departments)
                as $departmentid
            ) {

                $departmentid =
                    clean_param(
                        (string)$departmentid,
                        PARAM_ALPHANUMEXT
                    );

                if (
                    $departmentid !== ''
                    &&
                    isset(
                        $validdepartments[
                            $departmentid
                        ]
                    )
                ) {
                    $rules[] = [
                        'scopetype' =>
                            'department',

                        'scopeid' =>
                            $departmentid,
                    ];
                }
            }
        }


        $lockfactory = \core\lock\lock_config::get_lock_factory('local_ustar_content');
        $lock = $lockfactory->get_lock('hierarchy', 10);
        if (!$lock) {
            throw new \moodle_exception('Не удалось получить блокировку материала. Повторите сохранение.');
        }

        try {
        $expectedmodified = (int)($input['expectedmodified'] ?? 0);
        $freshrecord = $DB->get_record('local_ustar_content', ['id' => $contentid], '*', MUST_EXIST);
        if ($expectedmodified <= 0 || (int)$freshrecord->timemodified !== $expectedmodified) {
            throw new \moodle_exception(
                'Материал уже изменён в другой сессии. Обновите страницу и повторите сохранение.'
            );
        }
        $record = $freshrecord;

        $transaction =
            $DB->start_delegated_transaction();


        $record->title =
            $title;

        $record->summary =
            $summary !== ''
                ? $summary
                : null;

        $record->category =
            $category !== ''
                ? $category
                : null;

        $record->ackrequired =
            $ackrequired
                ? 1
                : 0;

        $record->parentid = $parentid > 0 ? $parentid : null;

        $record->timemodified =
            max(time(), $expectedmodified + 1);

        $record->usermodified =
            $actorid;


        $DB->update_record(
            'local_ustar_content',
            $record
        );


        /*
         * Preserve access history by deactivating old rules.
         */
        $DB->set_field(
            'local_ustar_content_access',
            'active',
            0,
            [
                'contentid' =>
                    $contentid,

                'active' =>
                    1,
            ]
        );


        $now = time();


        foreach ($rules as $rule) {

            $DB->insert_record(
                'local_ustar_content_access',
                (object)[
                    'contentid' =>
                        $contentid,

                    'scopetype' =>
                        $rule['scopetype'],

                    'scopeid' =>
                        $rule['scopeid'],

                    'active' =>
                        1,

                    'timecreated' =>
                        $now,

                    'createdby' =>
                        $actorid,
                ]
            );
        }


        people::log_action(
            $actorid,
            null,
            'content_updated',
            [
                'contentid' =>
                    $contentid,

                'title' =>
                    $title,

                'category' =>
                    $category,

                'ackrequired' =>
                    $ackrequired,

                'accessmode' =>
                    $accessmode,

                'accessrules' =>
                    count($rules),
            ]
        );


        $transaction->allow_commit();


        return [
            'contentid' =>
                $contentid,

            'accessrules' =>
                count($rules),

            'status' =>
                $record->status,
        ];
        } finally {
            $lock->release();
        }
    }


    /**
     * Publish an existing catalog item.
     *
     * Safety rules:
     *
     * - at least one active access rule
     * - Moodle-backed source must still exist
     * - Moodle course/activity must currently be visible
     */
    public static function publish(
        int $contentid,
        int $actorid
    ): array {
        global $DB;

        self::require_manage(
            $actorid
        );


        $transaction =
            $DB->start_delegated_transaction();

        $record =
            $DB->get_record_sql(
                'SELECT *
                   FROM {local_ustar_content}
                  WHERE id = :contentid
                  FOR UPDATE',
                ['contentid' => $contentid],
                MUST_EXIST
            );


        $accesscount =
            $DB->count_records(
                'local_ustar_content_access',
                [
                    'contentid' =>
                        $contentid,

                    'active' =>
                        1,
                ]
            );


        if ($accesscount < 1) {
            throw new \moodle_exception(
                'Сначала настройте, кому доступен материал'
            );
        }


        if (
            $record->sourcekind
            ===
            content::SOURCE_MOODLE
        ) {

            $source =
                $DB->get_record_sql(
                    "
                        SELECT
                            cm.id,
                            cm.visible AS cmvisible,
                            c.id AS courseid,
                            c.visible AS coursevisible
                        FROM {course_modules} cm
                        JOIN {course} c
                          ON c.id = cm.course
                        WHERE cm.id = :cmid
                          AND c.id = :courseid
                    ",
                    [
                        'cmid' =>
                            $record->cmid,

                        'courseid' =>
                            $record->courseid,
                    ]
                );


            if (!$source) {
                throw new \moodle_exception(
                    'Исходная Moodle-активность больше не существует'
                );
            }


            if (empty($source->coursevisible)) {
                throw new \moodle_exception(
                    'Нельзя опубликовать: исходный Moodle-курс скрыт'
                );
            }


            if (empty($source->cmvisible)) {
                throw new \moodle_exception(
                    'Нельзя опубликовать: исходная Moodle-активность скрыта'
                );
            }
        }


        /*
         * USTAR-owned content is publishable only when its current
         * version has a real File API payload. A version row without
         * a file must never become visible to employees.
         */
        if (
            $record->sourcekind
            ===
            content::SOURCE_FILE
        ) {
            $currentversion =
                content::current_version(
                    $contentid
                );

            if (!$currentversion) {
                throw new \moodle_exception(
                    'У материала отсутствует текущая версия'
                );
            }

            $files =
                get_file_storage()->get_area_files(
                    \context_system::instance()->id,
                    'local_ustar',
                    'content_version',
                    (int)$currentversion->id,
                    'sortorder DESC, id ASC',
                    false
                );

            if (!$files) {
                throw new \moodle_exception(
                    'В текущей версии материала отсутствует файл'
                );
            }
        }

        if (
            $record->sourcekind
            ===
            content::SOURCE_EXTERNAL
            &&
            trim((string)$record->externalurl) === ''
        ) {
            throw new \moodle_exception(
                'У внешнего материала отсутствует URL'
            );
        }


        $now = time();

        $record->status =
            content::STATUS_PUBLISHED;

        $record->publishedat =
            $record->publishedat
                ?: $now;

        $record->timemodified =
            $now;

        $record->usermodified =
            $actorid;


        $DB->update_record(
            'local_ustar_content',
            $record
        );


        $DB->set_field_select(
            'local_ustar_content_versions',
            'status',
            content::STATUS_PUBLISHED,
            '
                contentid = :contentid
                AND iscurrent = 1
            ',
            [
                'contentid' =>
                    $contentid,
            ]
        );


        people::log_action(
            $actorid,
            null,
            'content_published',
            [
                'contentid' =>
                    $contentid,

                'accessrules' =>
                    $accesscount,
            ]
        );


        $transaction->allow_commit();


        return [
            'contentid' =>
                $contentid,

            'status' =>
                content::STATUS_PUBLISHED,
        ];
    }


    /**
     * Remove item from employee catalog without deleting it.
     */
    public static function unpublish(
        int $contentid,
        int $actorid
    ): array {
        global $DB;

        self::require_manage(
            $actorid
        );


        $transaction =
            $DB->start_delegated_transaction();

        $record =
            $DB->get_record_sql(
                'SELECT *
                   FROM {local_ustar_content}
                  WHERE id = :contentid
                  FOR UPDATE',
                ['contentid' => $contentid],
                MUST_EXIST
            );


        if ($record->status === content::STATUS_DRAFT) {
            $transaction->allow_commit();
            return [
                'contentid' => $contentid,
                'status' => content::STATUS_DRAFT,
            ];
        }

        if ($record->status !== content::STATUS_PUBLISHED) {
            throw new \moodle_exception(
                'В черновики можно вернуть только опубликованный материал'
            );
        }


        $record->status =
            content::STATUS_DRAFT;

        $record->timemodified =
            time();

        $record->usermodified =
            $actorid;


        $DB->update_record(
            'local_ustar_content',
            $record
        );


        $DB->set_field_select(
            'local_ustar_content_versions',
            'status',
            content::STATUS_DRAFT,
            '
                contentid = :contentid
                AND iscurrent = 1
            ',
            [
                'contentid' =>
                    $contentid,
            ]
        );


        people::log_action(
            $actorid,
            null,
            'content_unpublished',
            [
                'contentid' =>
                    $contentid,
            ]
        );


        $transaction->allow_commit();


        return [
            'contentid' =>
                $contentid,

            'status' =>
                content::STATUS_DRAFT,
        ];
    }

    /**
     * Copy a Moodle Resource into USTAR-owned File API storage.
     *
     * The original Moodle Resource is preserved unchanged.
     * Existing courseid/cmid are retained as provenance.
     *
     * Destination:
     *   context   = system
     *   component = local_ustar
     *   filearea  = content_version
     *   itemid    = current content version id
     */
    public static function migrate_moodle_resource(
        int $contentid,
        int $actorid
    ): array {
        global $DB;

        self::require_manage(
            $actorid
        );


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


        /*
         * Safe idempotency.
         */
        if (
            $content->sourcekind
            ===
            content::SOURCE_FILE
        ) {

            $version =
                content::current_version(
                    $contentid
                );

            return [
                'migrated' =>
                    false,

                'already' =>
                    true,

                'contentid' =>
                    $contentid,

                'versionid' =>
                    $version
                        ? (int)$version->id
                        : 0,
            ];
        }


        if (
            $content->sourcekind
            !==
            content::SOURCE_MOODLE
        ) {
            throw new \moodle_exception(
                'Материал не является Moodle-источником'
            );
        }


        $source =
            $DB->get_record_sql(
                "
                    SELECT
                        cm.id AS cmid,
                        cm.course,
                        cm.instance,
                        m.name AS modname
                    FROM {course_modules} cm
                    JOIN {modules} m
                      ON m.id = cm.module
                    WHERE cm.id = :cmid
                      AND cm.course = :courseid
                ",
                [
                    'cmid' =>
                        $content->cmid,

                    'courseid' =>
                        $content->courseid,
                ]
            );


        if (!$source) {
            throw new \moodle_exception(
                'Исходная Moodle-активность не найдена'
            );
        }


        if ($source->modname !== 'resource') {
            throw new \moodle_exception(
                'Перенос в USTAR File доступен только для Moodle Resource'
            );
        }


        $version =
            content::current_version(
                $contentid
            );


        if (!$version) {
            throw new \moodle_exception(
                'У материала отсутствует текущая версия'
            );
        }


        $sourcecontext =
            \context_module::instance(
                (int)$source->cmid
            );

        $targetcontext =
            \context_system::instance();


        $fs =
            get_file_storage();


        $sourcefiles =
            $fs->get_area_files(
                $sourcecontext->id,
                'mod_resource',
                'content',
                0,
                'sortorder DESC, id ASC',
                false
            );


        if (!$sourcefiles) {
            throw new \moodle_exception(
                'В Moodle Resource отсутствуют файлы'
            );
        }


        /*
         * Destination must be empty before the first migration.
         * We deliberately do not overwrite an existing USTAR version.
         */
        $targetfiles =
            $fs->get_area_files(
                $targetcontext->id,
                'local_ustar',
                'content_version',
                $version->id,
                'id ASC',
                false
            );


        if ($targetfiles) {
            throw new \moodle_exception(
                'Текущая USTAR-версия уже содержит файл'
            );
        }


        $copied = [];


        try {

            foreach ($sourcefiles as $sourcefile) {

                $filerecord = [
                    'contextid' =>
                        $targetcontext->id,

                    'component' =>
                        'local_ustar',

                    'filearea' =>
                        'content_version',

                    'itemid' =>
                        (int)$version->id,

                    'filepath' =>
                        $sourcefile->get_filepath(),

                    'filename' =>
                        $sourcefile->get_filename(),

                    'userid' =>
                        $actorid,

                    'timecreated' =>
                        time(),

                    'timemodified' =>
                        time(),
                ];


                $newfile =
                    $fs->create_file_from_storedfile(
                        $filerecord,
                        $sourcefile
                    );


                if (!$newfile) {
                    throw new \moodle_exception(
                        'Не удалось скопировать файл в USTAR'
                    );
                }


                $copied[] = [
                    'filename' =>
                        $newfile->get_filename(),

                    'mimetype' =>
                        $newfile->get_mimetype(),

                    'filesize' =>
                        $newfile->get_filesize(),

                    'contenthash' =>
                        $newfile->get_contenthash(),
                ];
            }


            /*
             * Switch runtime only after every file has been copied.
             */
            $content->sourcekind =
                content::SOURCE_FILE;

            $content->timemodified =
                time();

            $content->usermodified =
                $actorid;


            $DB->update_record(
                'local_ustar_content',
                $content
            );


            people::log_action(
                $actorid,
                null,
                'content_migrated_to_ustar',
                [
                    'contentid' =>
                        $contentid,

                    'versionid' =>
                        (int)$version->id,

                    'legacycourseid' =>
                        (int)$content->courseid,

                    'legacycmid' =>
                        (int)$content->cmid,

                    'files' =>
                        count($copied),
                ]
            );

        } catch (\Throwable $e) {

            /*
             * Avoid leaving a half-copied version.
             */
            $fs->delete_area_files(
                $targetcontext->id,
                'local_ustar',
                'content_version',
                $version->id
            );

            throw $e;
        }


        return [
            'migrated' =>
                true,

            'already' =>
                false,

            'contentid' =>
                $contentid,

            'versionid' =>
                (int)$version->id,

            'files' =>
                $copied,
        ];
    }


    /**
     * Create an empty USTAR-owned content item + v1.
     *
     * The physical file is saved immediately afterwards by
     * Moodle Forms/File API into content_version/<versionid>.
     *
     * New material always starts as draft and has no access rules.
     */
    public static function create_file_material(
        array $input,
        int $actorid
    ): array {
        global $DB;

        self::require_manage(
            $actorid
        );


        $title =
            trim(
                clean_param(
                    (string)(
                        $input['title']
                        ?? ''
                    ),
                    PARAM_TEXT
                )
            );

        if ($title === '') {
            throw new \invalid_parameter_exception(
                'Название материала обязательно'
            );
        }


        $summary =
            trim(
                clean_param(
                    (string)(
                        $input['summary']
                        ?? ''
                    ),
                    PARAM_TEXT
                )
            );


        $category =
            clean_param(
                (string)(
                    $input['category']
                    ?? ''
                ),
                PARAM_ALPHANUMEXT
            );


        if (
            $category !== ''
            &&
            !isset(
                self::categories()[
                    $category
                ]
            )
        ) {
            throw new \invalid_parameter_exception(
                'Неизвестная категория'
            );
        }


        $now =
            time();


        $transaction =
            $DB->start_delegated_transaction();


        $contentid =
            (int)$DB->insert_record(
                'local_ustar_content',
                (object)[
                    'type' =>
                        'document',

                    'title' =>
                        $title,

                    'summary' =>
                        $summary !== ''
                            ? $summary
                            : null,

                    'category' =>
                        $category !== ''
                            ? $category
                            : null,

                    'status' =>
                        content::STATUS_DRAFT,

                    'sourcekind' =>
                        content::SOURCE_FILE,

                    'courseid' =>
                        null,

                    'cmid' =>
                        null,

                    'externalurl' =>
                        null,

                    'owneruserid' =>
                        $actorid,

                    'ackrequired' =>
                        !empty(
                            $input['ackrequired']
                        )
                            ? 1
                            : 0,

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


        $versionid =
            (int)$DB->insert_record(
                'local_ustar_content_versions',
                (object)[
                    'contentid' =>
                        $contentid,

                    'versionno' =>
                        1,

                    'versionlabel' =>
                        'v1',

                    'changenote' =>
                        'Создано в USTAR Content Hub',

                    'effectivedate' =>
                        null,

                    'iscurrent' =>
                        1,

                    'status' =>
                        content::STATUS_DRAFT,

                    'timecreated' =>
                        $now,

                    'createdby' =>
                        $actorid,
                ]
            );


        people::log_action(
            $actorid,
            null,
            'content_created',
            [
                'contentid' =>
                    $contentid,

                'versionid' =>
                    $versionid,

                'sourcekind' =>
                    content::SOURCE_FILE,

                'title' =>
                    $title,
            ]
        );


        $transaction->allow_commit();


        return [
            'contentid' =>
                $contentid,

            'versionid' =>
                $versionid,
        ];
    }


    /**
     * Determine the user-facing material type from the actual
     * stored file and persist it in the catalog.
     */
    public static function finalize_file_material(
        int $contentid,
        \stored_file $file,
        int $actorid
    ): array {
        global $DB;

        self::require_manage(
            $actorid
        );


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
            $content->sourcekind
            !==
            content::SOURCE_FILE
        ) {
            throw new \moodle_exception(
                'Материал не является USTAR File'
            );
        }


        $mimetype =
            strtolower(
                trim(
                    (string)$file->get_mimetype()
                )
            );


        $extension =
            strtolower(
                pathinfo(
                    $file->get_filename(),
                    PATHINFO_EXTENSION
                )
            );


        if (
            $mimetype === 'text/html'
            ||
            in_array(
                $extension,
                [
                    'html',
                    'htm',
                ],
                true
            )
        ) {

            $type =
                'interactive';

        } else if (
            str_starts_with(
                $mimetype,
                'video/'
            )
            ||
            in_array(
                $extension,
                [
                    'mp4',
                    'webm',
                    'mov',
                    'm4v',
                ],
                true
            )
        ) {

            $type =
                'video';

        } else {

            $type =
                'document';
        }


        $content->type =
            $type;

        $content->timemodified =
            time();

        $content->usermodified =
            $actorid;


        $DB->update_record(
            'local_ustar_content',
            $content
        );


        return [
            'contentid' =>
                $contentid,

            'type' =>
                $type,

            'filename' =>
                $file->get_filename(),

            'mimetype' =>
                $mimetype,

            'filesize' =>
                $file->get_filesize(),
        ];
    }


    /**
     * Roll back a just-created draft if physical upload failed.
     */
    public static function discard_new_file_material(
        int $contentid,
        int $actorid
    ): void {
        global $DB;

        self::require_manage(
            $actorid
        );


        $content =
            $DB->get_record(
                'local_ustar_content',
                [
                    'id' =>
                        $contentid,
                ]
            );


        if (!$content) {
            return;
        }


        if (
            $content->sourcekind
                !==
                content::SOURCE_FILE
            ||
            $content->status
                !==
                content::STATUS_DRAFT
            ||
            !empty(
                $content->publishedat
            )
        ) {
            throw new \moodle_exception(
                'Удаление этого материала как незавершённого запрещено'
            );
        }


        $versions =
            $DB->get_records(
                'local_ustar_content_versions',
                [
                    'contentid' =>
                        $contentid,
                ]
            );


        $context =
            \context_system::instance();

        $fs =
            get_file_storage();


        foreach ($versions as $version) {

            $fs->delete_area_files(
                $context->id,
                'local_ustar',
                'content_version',
                $version->id
            );
        }


        $DB->delete_records(
            'local_ustar_content_ack',
            [
                'contentid' =>
                    $contentid,
            ]
        );

        $DB->delete_records(
            'local_ustar_content_access',
            [
                'contentid' =>
                    $contentid,
            ]
        );

        $DB->delete_records(
            'local_ustar_content_versions',
            [
                'contentid' =>
                    $contentid,
            ]
        );

        $DB->delete_records(
            'local_ustar_content',
            [
                'id' =>
                    $contentid,
            ]
        );
    }


    /**
     * Create the next USTAR file version as a non-current draft.
     *
     * Existing published current version remains live until the
     * new version is explicitly published.
     */
    public static function create_draft_file_version(
        int $contentid,
        int $actorid,
        string $changenote = ''
    ): array {
        global $DB;

        self::require_manage(
            $actorid
        );


        $transaction =
            $DB->start_delegated_transaction();


        /*
         * Serialize version-number allocation and pending-draft checks
         * per content item. Production is PostgreSQL; Moodle expands
         * the table placeholder before the row lock is executed.
         */
        $content =
            $DB->get_record_sql(
                'SELECT *
                   FROM {local_ustar_content}
                  WHERE id = :contentid
                  FOR UPDATE',
                [
                    'contentid' =>
                        $contentid,
                ],
                MUST_EXIST
            );


        if (
            $content->sourcekind
            !==
            content::SOURCE_FILE
        ) {
            throw new \moodle_exception(
                'Версии поддерживаются только для USTAR File'
            );
        }


        if (
            $content->status
            !==
            content::STATUS_PUBLISHED
        ) {
            throw new \moodle_exception(
                'Сначала опубликуйте текущую версию материала'
            );
        }


        $current =
            content::current_version(
                $contentid
            );


        if (
            !$current
            ||
            $current->status
                !==
                content::STATUS_PUBLISHED
        ) {
            throw new \moodle_exception(
                'Текущая опубликованная версия не найдена'
            );
        }


        /*
         * Only one pending draft at a time.
         */
        $pending =
            $DB->get_records_select(
                'local_ustar_content_versions',
                '
                    contentid = :contentid
                    AND iscurrent = 0
                    AND status = :draft
                ',
                [
                    'contentid' =>
                        $contentid,

                    'draft' =>
                        content::STATUS_DRAFT,
                ],
                'versionno DESC',
                '*',
                0,
                1
            );


        if ($pending) {
            throw new \moodle_exception(
                'У материала уже есть неопубликованная новая версия'
            );
        }


        $maxversion =
            (int)$DB->get_field_sql(
                '
                    SELECT MAX(versionno)
                      FROM {local_ustar_content_versions}
                     WHERE contentid = :contentid
                ',
                [
                    'contentid' =>
                        $contentid,
                ]
            );


        $versionno =
            max(
                1,
                $maxversion + 1
            );


        $changenote =
            trim(
                clean_param(
                    $changenote,
                    PARAM_TEXT
                )
            );


        $versionid =
            (int)$DB->insert_record(
                'local_ustar_content_versions',
                (object)[
                    'contentid' =>
                        $contentid,

                    'versionno' =>
                        $versionno,

                    'versionlabel' =>
                        'v'
                        .
                        $versionno,

                    'changenote' =>
                        $changenote !== ''
                            ? $changenote
                            : 'Новая версия',

                    'effectivedate' =>
                        null,

                    'iscurrent' =>
                        0,

                    'status' =>
                        content::STATUS_DRAFT,

                    'timecreated' =>
                        time(),

                    'createdby' =>
                        $actorid,
                ]
            );


        people::log_action(
            $actorid,
            null,
            'content_version_created',
            [
                'contentid' =>
                    $contentid,

                'versionid' =>
                    $versionid,

                'versionno' =>
                    $versionno,
            ]
        );


        $transaction->allow_commit();


        return [
            'contentid' =>
                $contentid,

            'versionid' =>
                $versionid,

            'versionno' =>
                $versionno,

            'versionlabel' =>
                'v'
                .
                $versionno,
        ];
    }


    /**
     * Delete an unpublished non-current draft version.
     */
    public static function discard_draft_file_version(
        int $versionid,
        int $actorid
    ): void {
        global $DB;

        self::require_manage(
            $actorid
        );


        $versionstub =
            $DB->get_record(
                'local_ustar_content_versions',
                ['id' => $versionid],
                'id,contentid',
                MUST_EXIST
            );

        $transaction =
            $DB->start_delegated_transaction();

        // Use the same lock order as publish_file_version(): parent first.
        $content =
            $DB->get_record_sql(
                'SELECT *
                   FROM {local_ustar_content}
                  WHERE id = :contentid
                  FOR UPDATE',
                ['contentid' => (int)$versionstub->contentid],
                MUST_EXIST
            );

        $version =
            $DB->get_record_sql(
                'SELECT *
                   FROM {local_ustar_content_versions}
                  WHERE id = :versionid
                  FOR UPDATE',
                ['versionid' => $versionid],
                MUST_EXIST
            );

        if (
            !empty($version->iscurrent)
            ||
            $version->status !== content::STATUS_DRAFT
        ) {
            throw new \moodle_exception(
                'Удалять можно только неопубликованный черновик версии'
            );
        }


        if (
            $content->sourcekind
            !==
            content::SOURCE_FILE
        ) {
            throw new \moodle_exception(
                'Материал не является USTAR File'
            );
        }


        $context =
            \context_system::instance();


        get_file_storage()
            ->delete_area_files(
                $context->id,
                'local_ustar',
                'content_version',
                $versionid
            );


        $DB->delete_records(
            'local_ustar_content_ack',
            [
                'versionid' =>
                    $versionid,
            ]
        );


        $DB->delete_records(
            'local_ustar_content_versions',
            [
                'id' =>
                    $versionid,
            ]
        );


        people::log_action(
            $actorid,
            null,
            'content_version_discarded',
            [
                'contentid' =>
                    (int)$content->id,

                'versionid' =>
                    $versionid,
            ]
        );

        $transaction->allow_commit();
    }


    /**
     * Atomically promote a draft version to current/published.
     *
     * Historical acknowledgements are deliberately preserved.
     */
    public static function publish_file_version(
        int $versionid,
        int $actorid
    ): array {
        global $DB;

        self::require_manage(
            $actorid
        );


        $versionstub =
            $DB->get_record(
                'local_ustar_content_versions',
                [
                    'id' =>
                        $versionid,
                ],
                'id,contentid',
                MUST_EXIST
            );


        $transaction =
            $DB->start_delegated_transaction();


        /*
         * Always lock the parent content row before the version row.
         * This serializes competing version promotions for one material.
         */
        $content =
            $DB->get_record_sql(
                'SELECT *
                   FROM {local_ustar_content}
                  WHERE id = :contentid
                  FOR UPDATE',
                [
                    'contentid' =>
                        (int)$versionstub->contentid,
                ],
                MUST_EXIST
            );


        $version =
            $DB->get_record_sql(
                'SELECT *
                   FROM {local_ustar_content_versions}
                  WHERE id = :versionid
                  FOR UPDATE',
                [
                    'versionid' =>
                        $versionid,
                ],
                MUST_EXIST
            );


        if (
            $content->sourcekind
            !==
            content::SOURCE_FILE
        ) {
            throw new \moodle_exception(
                'Версии доступны только для USTAR File'
            );
        }

        if ($content->status !== content::STATUS_PUBLISHED) {
            throw new \moodle_exception(
                'Нельзя опубликовать новую версию неопубликованного или архивного материала'
            );
        }


        /*
         * Idempotent success.
         */
        if (
            !empty($version->iscurrent)
            &&
            $version->status
                ===
                content::STATUS_PUBLISHED
        ) {
            $transaction->allow_commit();

            return [
                'contentid' =>
                    (int)$content->id,

                'versionid' =>
                    (int)$version->id,

                'status' =>
                    content::STATUS_PUBLISHED,
            ];
        }


        if (
            !empty($version->iscurrent)
            ||
            $version->status
                !==
                content::STATUS_DRAFT
        ) {
            throw new \moodle_exception(
                'Можно опубликовать только новую черновую версию'
            );
        }


        $accesscount =
            $DB->count_records(
                'local_ustar_content_access',
                [
                    'contentid' =>
                        $content->id,

                    'active' =>
                        1,
                ]
            );


        if ($accesscount < 1) {
            throw new \moodle_exception(
                'У материала отсутствуют активные правила доступа'
            );
        }


        $context =
            \context_system::instance();

        $fs =
            get_file_storage();


        $files =
            $fs->get_area_files(
                $context->id,
                'local_ustar',
                'content_version',
                $version->id,
                'sortorder DESC, id ASC',
                false
            );


        if (!$files) {
            throw new \moodle_exception(
                'В новой версии отсутствует файл'
            );
        }


        $file =
            reset($files);


        /*
         * Determine catalog type from the version that is ABOUT
         * to become current.
         */
        $mimetype =
            strtolower(
                trim(
                    (string)$file->get_mimetype()
                )
            );

        $extension =
            strtolower(
                pathinfo(
                    $file->get_filename(),
                    PATHINFO_EXTENSION
                )
            );


        if (
            $mimetype === 'text/html'
            ||
            in_array(
                $extension,
                [
                    'html',
                    'htm',
                ],
                true
            )
        ) {

            $type =
                'interactive';

        } else if (
            str_starts_with(
                $mimetype,
                'video/'
            )
            ||
            in_array(
                $extension,
                [
                    'mp4',
                    'webm',
                    'mov',
                    'm4v',
                ],
                true
            )
        ) {

            $type =
                'video';

        } else {

            $type =
                'document';
        }


        $oldcurrent =
            content::current_version(
                (int)$content->id
            );


        /*
         * Previous version remains published in history,
         * but is no longer current.
         */
        $DB->set_field(
            'local_ustar_content_versions',
            'iscurrent',
            0,
            [
                'contentid' =>
                    $content->id,

                'iscurrent' =>
                    1,
            ]
        );


        $version->iscurrent =
            1;

        $version->status =
            content::STATUS_PUBLISHED;


        $DB->update_record(
            'local_ustar_content_versions',
            $version
        );


        $now =
            time();


        $content->status =
            content::STATUS_PUBLISHED;

        $content->type =
            $type;

        $content->publishedat =
            $content->publishedat
                ?: $now;

        $content->timemodified =
            $now;

        $content->usermodified =
            $actorid;


        $DB->update_record(
            'local_ustar_content',
            $content
        );


        people::log_action(
            $actorid,
            null,
            'content_version_published',
            [
                'contentid' =>
                    (int)$content->id,

                'versionid' =>
                    (int)$version->id,

                'versionno' =>
                    (int)$version->versionno,

                'previousversionid' =>
                    $oldcurrent
                        ? (int)$oldcurrent->id
                        : 0,
            ]
        );


        $transaction->allow_commit();


        return [
            'contentid' =>
                (int)$content->id,

            'versionid' =>
                (int)$version->id,

            'versionlabel' =>
                $version->versionlabel,

            'status' =>
                content::STATUS_PUBLISHED,
        ];
    }


    /**
     * Archive a published material without deleting versions, files,
     * access history or acknowledgement history.
     */
    public static function archive(
        int $contentid,
        int $actorid
    ): array {
        global $DB;

        self::require_manage($actorid);

        $transaction = $DB->start_delegated_transaction();

        $record = $DB->get_record_sql(
            'SELECT *
               FROM {local_ustar_content}
              WHERE id = :contentid
              FOR UPDATE',
            ['contentid' => $contentid],
            MUST_EXIST
        );

        if ($record->status === content::STATUS_ARCHIVED) {
            $transaction->allow_commit();
            return [
                'contentid' => $contentid,
                'status' => content::STATUS_ARCHIVED,
            ];
        }

        if ($record->status !== content::STATUS_PUBLISHED) {
            throw new \moodle_exception(
                'Архивировать можно только опубликованный материал'
            );
        }

        $record->status = content::STATUS_ARCHIVED;
        $record->timemodified = time();
        $record->usermodified = $actorid;
        $DB->update_record('local_ustar_content', $record);

        people::log_action(
            $actorid,
            null,
            'content_archived',
            ['contentid' => $contentid]
        );

        $transaction->allow_commit();

        return [
            'contentid' => $contentid,
            'status' => content::STATUS_ARCHIVED,
        ];
    }


    /**
     * Restore an archived material to publication. publish() performs
     * the same live-source and access validation used for normal publish.
     */
    public static function restore_archived(
        int $contentid,
        int $actorid
    ): array {
        global $DB;

        self::require_manage($actorid);

        $record = $DB->get_record(
            'local_ustar_content',
            ['id' => $contentid],
            'id,status',
            MUST_EXIST
        );

        if ($record->status !== content::STATUS_ARCHIVED) {
            throw new \moodle_exception(
                'Восстановить можно только архивный материал'
            );
        }

        $result = self::publish($contentid, $actorid);

        people::log_action(
            $actorid,
            null,
            'content_restored',
            ['contentid' => $contentid]
        );

        return $result;
    }


}
