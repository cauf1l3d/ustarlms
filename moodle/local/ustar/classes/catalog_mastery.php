<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * TARGET product-knowledge mastery.
 *
 * Source of truth:
 * - active local_ustar_catalog data;
 * - exact product/photo cards from the existing cm41 SCORM;
 * - immutable workflow events for attempt snapshots;
 * - native_learning PRODUCT_MASTERY for route completion.
 */
final class catalog_mastery {
    public const POINT_ID = 70;
    public const SCORM_CMID = 41;
    public const QUESTION_COUNT = 25;
    public const PASS_PERCENT = 80;
    public const MAX_ATTEMPTS = 3;

    private const ENTITY = 'catalog_exam';
    private const EVENT_STARTED = 'catalog_started';
    private const EVENT_SUBMITTED = 'catalog_submitted';

    public static function has_access(int $userid): bool {
        global $DB;

        if ($userid <= 0) {
            return false;
        }

        if (is_siteadmin($userid)) {
            return true;
        }

        if (
            has_capability(
                'local/ustar:managecatalog',
                \context_system::instance(),
                $userid,
                false
            )
        ) {
            return true;
        }

        /*
         * Permanent Catalog unlock:
         * any completed version of canonical point70
         * keeps the working reference library available.
         */
        if (
            $DB->record_exists(
                'local_ustar_route_progress',
                [
                    'userid' => $userid,
                    'pointid' => 70,
                    'status' => 'complete',
                ]
            )
        ) {
            return true;
        }

        /*
         * Compatibility for anyone who may already have completed
         * the retired 2720 prototype before cutover.
         */
        return $DB->record_exists(
            'local_ustar_workflow_events',
            [
                'entitytype' => 'route_native',
                'eventtype' => 'native_product_mastery',
                'actorid' => $userid,
            ]
        );
    }

    public static function state(
        int $userid,
        string $positionid
    ): array {
        $passed = self::has_access($userid);
        $versionid = self::published_version_id();

        $route = route_model::for_user(
            $positionid,
            $userid
        );

        $current = $route['currentpoint'] ?? null;
        $currentid = (int)($current['id'] ?? 0);

        $attempts = $versionid > 0
            ? self::submitted_attempts(
                $userid,
                $versionid
            )
            : [];

        $count = count($attempts);

        return [
            'passed' => $passed,
            'versionid' => $versionid,
            'attempts' => $count,
            'remaining' => max(
                0,
                self::MAX_ATTEMPTS - $count
            ),
            'currentid' => $currentid,
            'canstart' =>
                !$passed
                && $versionid > 0
                && $currentid === self::POINT_ID
                && $count < self::MAX_ATTEMPTS,
            'needscorm' =>
                !$passed
                && $currentid === 69,
            'blocked' =>
                !$passed
                && $count >= self::MAX_ATTEMPTS,
            'routeok' => !empty($route['ok']),
            'launchurl' =>
                (string)(
                    $current['launchurl']
                    ?? ''
                ),
        ];
    }

    public static function get_or_start(
        int $userid,
        string $positionid
    ): array {
        global $DB;

        $state = self::state(
            $userid,
            $positionid
        );

        if (!$state['canstart']) {
            return [];
        }

        $versionid = (int)$state['versionid'];

        $factory =
            \core\lock\lock_config::get_lock_factory(
                'local_ustar'
            );

        $lock = $factory->get_lock(
            'catalog-exam-start:'
                . $userid
                . ':'
                . $versionid,
            10
        );

        if (!$lock) {
            throw new \moodle_exception(
                'Не удалось открыть товарную аттестацию.'
            );
        }

        try {
            $existing =
                self::open_start(
                    $userid,
                    $versionid
                );

            if ($existing) {
                return self::attempt_view(
                    $existing
                );
            }

            $snapshot =
                self::generate_snapshot(
                    $versionid
                );

            if (
                count($snapshot['questions'])
                !== self::QUESTION_COUNT
            ) {
                throw new \moodle_exception(
                    'Недостаточно данных каталога для формирования аттестации.'
                );
            }

            $now = time();

            $eventid = (int)$DB->insert_record(
                'local_ustar_workflow_events',
                (object)[
                    'entitytype' =>
                        self::ENTITY,
                    'entityid' =>
                        $versionid,
                    'eventtype' =>
                        self::EVENT_STARTED,
                    'actorid' =>
                        $userid,
                    'reason' =>
                        'Товарная аттестация «Мастер-Чемодан»',
                    'detailsjson' =>
                        json_encode(
                            [
                                'pointid' =>
                                    self::POINT_ID,
                                'versionid' =>
                                    $versionid,
                                'seed' =>
                                    $snapshot['seed'],
                                'cataloghash' =>
                                    $snapshot['cataloghash'],
                                'questions' =>
                                    $snapshot['questions'],
                                'startedat' =>
                                    $now,
                            ],
                            JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                        ),
                    'timecreated' => $now,
                ]
            );

            return self::attempt_view(
                $DB->get_record(
                    'local_ustar_workflow_events',
                    ['id' => $eventid],
                    '*',
                    MUST_EXIST
                )
            );

        } finally {
            $lock->release();
        }
    }

    public static function submit(
        int $userid,
        string $positionid,
        int $startid,
        array $answers
    ): array {
        global $DB;

        if ($startid <= 0) {
            throw new \invalid_parameter_exception(
                'Попытка не найдена.'
            );
        }

        $versionid = self::published_version_id();

        if ($versionid <= 0) {
            throw new \moodle_exception(
                'Аттестация пока не опубликована.'
            );
        }

        $factory =
            \core\lock\lock_config::get_lock_factory(
                'local_ustar'
            );

        $lock = $factory->get_lock(
            'catalog-exam-submit:'
                . $userid
                . ':'
                . $startid,
            10
        );

        if (!$lock) {
            throw new \moodle_exception(
                'Не удалось сохранить результат аттестации.'
            );
        }

        try {
            $old =
                self::submission_for_start(
                    $userid,
                    $versionid,
                    $startid
                );

            if ($old) {
                return self::submission_result(
                    $old
                );
            }

            $state = self::state(
                $userid,
                $positionid
            );

            if (!$state['canstart']) {
                throw new \moodle_exception(
                    'Эта попытка сейчас недоступна.'
                );
            }

            $start = $DB->get_record(
                'local_ustar_workflow_events',
                [
                    'id' => $startid,
                    'entitytype' =>
                        self::ENTITY,
                    'entityid' =>
                        $versionid,
                    'eventtype' =>
                        self::EVENT_STARTED,
                    'actorid' =>
                        $userid,
                ],
                '*',
                MUST_EXIST
            );

            $details =
                json_decode(
                    (string)$start->detailsjson,
                    true
                );

            if (
                !is_array($details)
                || !is_array(
                    $details['questions']
                    ?? null
                )
            ) {
                throw new \moodle_exception(
                    'Снимок попытки повреждён.'
                );
            }

            $questions =
                $details['questions'];

            $correct = 0;
            $cleananswers = [];

            foreach ($questions as $question) {
                $key =
                    (string)(
                        $question['key']
                        ?? ''
                    );

                $selected =
                    clean_param(
                        (string)(
                            $answers[$key]
                            ?? ''
                        ),
                        PARAM_ALPHANUMEXT
                    );

                $valid = [];

                foreach (
                    ($question['options'] ?? [])
                    as $option
                ) {
                    $valid[] =
                        (string)$option['key'];
                }

                if (
                    $selected === ''
                    || !in_array(
                        $selected,
                        $valid,
                        true
                    )
                ) {
                    throw new \invalid_parameter_exception(
                        'Ответьте на все 25 вопросов.'
                    );
                }

                $cleananswers[$key] =
                    $selected;

                if (
                    hash_equals(
                        (string)$question['correct'],
                        $selected
                    )
                ) {
                    $correct++;
                }
            }

            $total = count($questions);

            $percent =
                $total > 0
                ? (int)round(
                    ($correct / $total) * 100
                )
                : 0;

            $passed =
                $percent >= self::PASS_PERCENT;

            $attemptno =
                count(
                    self::submitted_attempts(
                        $userid,
                        $versionid
                    )
                ) + 1;

            if (
                $attemptno > self::MAX_ATTEMPTS
            ) {
                throw new \moodle_exception(
                    'Лимит попыток исчерпан.'
                );
            }

            $now = time();

            $resultdetails = [
                'pointid' =>
                    self::POINT_ID,
                'versionid' =>
                    $versionid,
                'startid' =>
                    $startid,
                'attemptno' =>
                    $attemptno,
                'cataloghash' =>
                    (string)(
                        $details['cataloghash']
                        ?? ''
                    ),
                'score' =>
                    $correct,
                'total' =>
                    $total,
                'percent' =>
                    $percent,
                'threshold' =>
                    self::PASS_PERCENT,
                'passed' =>
                    $passed,
                'answers' =>
                    $cleananswers,
                'submittedat' =>
                    $now,
            ];

            $transaction =
                $DB->start_delegated_transaction();

            $submissionid =
                (int)$DB->insert_record(
                    'local_ustar_workflow_events',
                    (object)[
                        'entitytype' =>
                            self::ENTITY,
                        'entityid' =>
                            $versionid,
                        'eventtype' =>
                            self::EVENT_SUBMITTED,
                        'actorid' =>
                            $userid,
                        'reason' =>
                            $passed
                                ? 'Мастер-Чемодан: сдано'
                                : 'Мастер-Чемодан: требуется повтор',
                        'detailsjson' =>
                            json_encode(
                                $resultdetails,
                                JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                            ),
                        'timecreated' =>
                            $now,
                    ]
                );

            if ($passed) {
                $nativeid =
                    native_learning::record(
                        $userid,
                        native_learning::PRODUCT_MASTERY,
                        [
                            'attemptid' =>
                                $submissionid,
                            'attemptno' =>
                                $attemptno,
                            'score' =>
                                $correct,
                            'total' =>
                                $total,
                            'percent' =>
                                $percent,
                            'threshold' =>
                                self::PASS_PERCENT,
                            'cataloghash' =>
                                (string)(
                                    $details['cataloghash']
                                    ?? ''
                                ),
                            'achievement' =>
                                'master_case',
                        ]
                    );

                if ($nativeid <= 0) {
                    throw new \moodle_exception(
                        'Не удалось подтвердить завершение точки маршрута.'
                    );
                }

                if (
                    !$DB->record_exists(
                        'local_ustar_workflow_events',
                        [
                            'entitytype' =>
                                'achievement',
                            'entityid' =>
                                self::POINT_ID,
                            'eventtype' =>
                                'master_case_awarded',
                            'actorid' =>
                                $userid,
                        ]
                    )
                ) {
                    $DB->insert_record(
                        'local_ustar_workflow_events',
                        (object)[
                            'entitytype' =>
                                'achievement',
                            'entityid' =>
                                self::POINT_ID,
                            'eventtype' =>
                                'master_case_awarded',
                            'actorid' =>
                                $userid,
                            'reason' =>
                                'Мастер-Чемодан',
                            'detailsjson' =>
                                json_encode(
                                    [
                                        'key' =>
                                            'master_case',
                                        'title' =>
                                            'Мастер-Чемодан',
                                        'source' =>
                                            'catalog_mastery',
                                        'attemptid' =>
                                            $submissionid,
                                    ],
                                    JSON_UNESCAPED_UNICODE
                                    | JSON_UNESCAPED_SLASHES
                                ),
                            'timecreated' =>
                                $now,
                        ]
                    );
                }
            }

            if (
                !$passed
                && $attemptno
                    >= self::MAX_ATTEMPTS
                && !$DB->record_exists(
                    'local_ustar_workflow_events',
                    [
                        'entitytype' =>
                            'route_escalation',
                        'entityid' =>
                            self::POINT_ID,
                        'eventtype' =>
                            'attempts_exhausted',
                        'actorid' =>
                            $userid,
                    ]
                )
            ) {
                $DB->insert_record(
                    'local_ustar_workflow_events',
                    (object)[
                        'entitytype' =>
                            'route_escalation',
                        'entityid' =>
                            self::POINT_ID,
                        'eventtype' =>
                            'attempts_exhausted',
                        'actorid' =>
                            $userid,
                        'reason' =>
                            'Три неуспешные попытки товарной аттестации',
                        'detailsjson' =>
                            json_encode(
                                [
                                    'userid' =>
                                        $userid,
                                    'pointid' =>
                                        self::POINT_ID,
                                    'versionid' =>
                                        $versionid,
                                    'attempts' =>
                                        $attemptno,
                                    'source' =>
                                        'catalog_mastery',
                                ],
                                JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                            ),
                        'timecreated' =>
                            $now,
                    ]
                );
            }

            $transaction->allow_commit();

            if ($passed) {
                route_model::for_user(
                    $positionid,
                    $userid
                );
            }

            return [
                'passed' => $passed,
                'score' => $correct,
                'total' => $total,
                'percent' => $percent,
                'attemptno' => $attemptno,
                'remaining' => max(
                    0,
                    self::MAX_ATTEMPTS
                        - $attemptno
                ),
            ];

        } finally {
            $lock->release();
        }
    }

    private static function published_version_id(): int {
        global $DB;

        $rows = $DB->get_records_select(
            'local_ustar_route_versions',
            'pointid = :pointid AND status = :status',
            [
                'pointid' =>
                    self::POINT_ID,
                'status' =>
                    route_model::STATUS_PUBLISHED,
            ],
            'versionno DESC, id DESC',
            'id',
            0,
            1
        );

        if (!$rows) {
            return 0;
        }

        return (int)reset($rows)->id;
    }

    private static function submitted_attempts(
        int $userid,
        int $versionid
    ): array {
        global $DB;

        return array_values(
            $DB->get_records(
                'local_ustar_workflow_events',
                [
                    'entitytype' =>
                        self::ENTITY,
                    'entityid' =>
                        $versionid,
                    'eventtype' =>
                        self::EVENT_SUBMITTED,
                    'actorid' =>
                        $userid,
                ],
                'timecreated ASC, id ASC'
            )
        );
    }

    private static function open_start(
        int $userid,
        int $versionid
    ): ?\stdClass {
        global $DB;

        $submitted = [];

        foreach (
            self::submitted_attempts(
                $userid,
                $versionid
            ) as $event
        ) {
            $details =
                json_decode(
                    (string)$event->detailsjson,
                    true
                );

            if (
                is_array($details)
                && !empty($details['startid'])
            ) {
                $submitted[
                    (int)$details['startid']
                ] = true;
            }
        }

        $starts =
            $DB->get_records(
                'local_ustar_workflow_events',
                [
                    'entitytype' =>
                        self::ENTITY,
                    'entityid' =>
                        $versionid,
                    'eventtype' =>
                        self::EVENT_STARTED,
                    'actorid' =>
                        $userid,
                ],
                'timecreated DESC, id DESC'
            );

        foreach ($starts as $start) {
            if (
                empty(
                    $submitted[
                        (int)$start->id
                    ]
                )
            ) {
                return $start;
            }
        }

        return null;
    }

    private static function submission_for_start(
        int $userid,
        int $versionid,
        int $startid
    ): ?\stdClass {
        foreach (
            self::submitted_attempts(
                $userid,
                $versionid
            ) as $event
        ) {
            $details =
                json_decode(
                    (string)$event->detailsjson,
                    true
                );

            if (
                is_array($details)
                && (int)(
                    $details['startid']
                    ?? 0
                ) === $startid
            ) {
                return $event;
            }
        }

        return null;
    }

    private static function submission_result(
        \stdClass $event
    ): array {
        $details =
            json_decode(
                (string)$event->detailsjson,
                true
            );

        if (!is_array($details)) {
            throw new \moodle_exception(
                'Сохранённый результат повреждён.'
            );
        }

        return [
            'passed' =>
                !empty($details['passed']),
            'score' =>
                (int)(
                    $details['score']
                    ?? 0
                ),
            'total' =>
                (int)(
                    $details['total']
                    ?? self::QUESTION_COUNT
                ),
            'percent' =>
                (int)(
                    $details['percent']
                    ?? 0
                ),
            'attemptno' =>
                (int)(
                    $details['attemptno']
                    ?? 0
                ),
            'remaining' =>
                max(
                    0,
                    self::MAX_ATTEMPTS
                    - (int)(
                        $details['attemptno']
                        ?? 0
                    )
                ),
        ];
    }

    private static function attempt_view(
        \stdClass $event
    ): array {
        $details =
            json_decode(
                (string)$event->detailsjson,
                true
            );

        if (
            !is_array($details)
            || !is_array(
                $details['questions']
                ?? null
            )
        ) {
            return [];
        }

        $questions = [];
        $number = 0;

        foreach (
            $details['questions']
            as $question
        ) {
            $number++;

            unset($question['correct']);

            foreach ($question['options'] as &$option) {
                $option['fieldname'] =
                    'answers['
                    . (string)$question['key']
                    . ']';
            }
            unset($option);

            $question['number'] =
                $number;

            $question['first'] =
                $number === 1;

            $question['last'] =
                $number ===
                count(
                    $details['questions']
                );

            $questions[] =
                $question;
        }

        return [
            'id' =>
                (int)$event->id,
            'questions' =>
                $questions,
            'count' =>
                count($questions),
            'cataloghash' =>
                (string)(
                    $details['cataloghash']
                    ?? ''
                ),
        ];
    }

    private static function generate_snapshot(
        int $versionid
    ): array {
        global $DB;

        $all =
            $DB->get_records(
                'local_ustar_catalog',
                ['active' => 1],
                'id ASC'
            );

        $products = [];
        $subgroups = [];
        $groups = [];

        foreach ($all as $row) {
            if (
                (string)$row->itemtype
                === catalog::TYPE_PRODUCT
            ) {
                $products[
                    (int)$row->id
                ] = $row;
            } else if (
                (string)$row->itemtype
                === catalog::TYPE_SUBGROUP
            ) {
                $subgroups[
                    (int)$row->id
                ] = $row;
            } else if (
                (string)$row->itemtype
                === catalog::TYPE_GROUP
            ) {
                $groups[
                    (int)$row->id
                ] = $row;
            }
        }

        if (count($products) < 25) {
            throw new \moodle_exception(
                'В каталоге недостаточно товарных карточек.'
            );
        }

        $catalogsource = [];

        foreach ($products as $product) {
            $catalogsource[] = [
                'id' =>
                    (int)$product->id,
                'parentid' =>
                    (int)$product->parentid,
                'title' =>
                    (string)$product->title,
                'summary' =>
                    (string)$product->summary,
                'description' =>
                    (string)$product->description,
                'attributesjson' =>
                    (string)$product->attributesjson,
            ];
        }

        $scorm =
            self::scorm_cards();

        $cataloghash =
            hash(
                'sha256',
                json_encode(
                    [
                        'products' =>
                            $catalogsource,
                        'scormhash' =>
                            (string)(
                                $scorm['hash']
                                ?? ''
                            ),
                    ],
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                )
            );

        $bygroup = [];

        foreach ($products as $product) {
            $subgroup =
                $subgroups[
                    (int)$product->parentid
                ] ?? null;

            if (!$subgroup) {
                continue;
            }

            $groupid =
                (int)$subgroup->parentid;

            if (
                !isset(
                    $groups[$groupid]
                )
            ) {
                continue;
            }

            $bygroup[$groupid][] =
                $product;
        }

        foreach ($bygroup as &$list) {
            shuffle($list);
        }
        unset($list);

        $questions = [];
        $used = [];

        /*
         * 20 catalog questions:
         * four per active top-level group where possible.
         */
        $types = [
            'category',
            'characteristic',
            'scenario',
            'compare',
        ];

        foreach ($bygroup as $groupid => $list) {
            $taken = 0;

            foreach ($list as $product) {
                if ($taken >= 4) {
                    break;
                }

                $type =
                    $types[
                        $taken
                        % count($types)
                    ];

                $question =
                    self::product_question(
                        $product,
                        $type,
                        $products,
                        $subgroups,
                        $groups
                    );

                if ($question) {
                    $questions[] =
                        $question;
                    $used[
                        (int)$product->id
                    ] = true;
                    $taken++;
                }
            }
        }

        /*
         * Current dataset has five populated top groups.
         * Generic fallback keeps the assessment valid if catalog changes.
         */
        if (count($questions) < 20) {
            $remaining =
                array_values($products);

            shuffle($remaining);

            foreach ($remaining as $product) {
                if (
                    count($questions)
                    >= 20
                ) {
                    break;
                }

                if (
                    !empty(
                        $used[
                            (int)$product->id
                        ]
                    )
                ) {
                    continue;
                }

                $type =
                    $types[
                        count($questions)
                        % count($types)
                    ];

                $question =
                    self::product_question(
                        $product,
                        $type,
                        $products,
                        $subgroups,
                        $groups
                    );

                if ($question) {
                    $questions[] =
                        $question;
                    $used[
                        (int)$product->id
                    ] = true;
                }
            }
        }

        /*
         * Up to five exact photo questions from the existing cm41 SCORM.
         * The photo and its answer come from the same product card,
         * so no filename/title guessing is used.
         */
        $cards =
            $scorm['cards']
            ?? [];

        shuffle($cards);

        $imagecount = 0;

        foreach ($cards as $card) {
            if (
                count($questions)
                >= self::QUESTION_COUNT
            ) {
                break;
            }

            if (
                empty($card['imageurl'])
                || trim(
                    (string)$card['title']
                ) === ''
            ) {
                continue;
            }

            $pool = [];

            foreach ($cards as $candidate) {
                if (
                    trim(
                        (string)$candidate['title']
                    ) !== ''
                ) {
                    $pool[] =
                        (string)$candidate['title'];
                }
            }

            $options =
                self::options(
                    (string)$card['title'],
                    $pool
                );

            if (count($options) !== 4) {
                continue;
            }

            $questions[] = [
                'kind' =>
                    'photo',
                'difficulty' =>
                    'medium',
                'difficultylabel' =>
                    'СРЕДНИЙ',
                'text' =>
                    'Какой товар показан на фотографии?',
                'imageurl' =>
                    (string)$card['imageurl'],
                'hasimage' =>
                    true,
                'context' =>
                    self::clean_text(
                        (string)(
                            $card['spec']
                            ?? ''
                        ),
                        180
                    ),
                'options' =>
                    $options['options'],
                'correct' =>
                    $options['correct'],
                'source' =>
                    'cm41_scorm',
            ];

            $imagecount++;

            if ($imagecount >= 5) {
                break;
            }
        }

        /*
         * If SCORM package changes and fewer than five exact photos can
         * be resolved, fill only from real catalog data.
         */
        if (
            count($questions)
            < self::QUESTION_COUNT
        ) {
            $remaining =
                array_values($products);

            shuffle($remaining);

            foreach ($remaining as $product) {
                if (
                    count($questions)
                    >= self::QUESTION_COUNT
                ) {
                    break;
                }

                $question =
                    self::product_question(
                        $product,
                        'characteristic',
                        $products,
                        $subgroups,
                        $groups
                    );

                if ($question) {
                    $questions[] =
                        $question;
                }
            }
        }

        /*
         * Guarantee up to five exact SCORM photo questions in the
         * final 25-question attempt.
         *
         * The catalog part may already have filled all 25 slots, so
         * photo questions replace generic characteristic questions
         * rather than being silently discarded by array_slice().
         */
        $photopool = [];

        foreach ($cards as $card) {
            if (
                empty($card['imageurl'])
                || trim((string)($card['title'] ?? '')) === ''
            ) {
                continue;
            }

            $titles = [];

            foreach ($cards as $candidate) {
                $candidateTitle =
                    trim((string)($candidate['title'] ?? ''));

                if ($candidateTitle !== '') {
                    $titles[] = $candidateTitle;
                }
            }

            $photooptions =
                self::options(
                    trim((string)$card['title']),
                    $titles
                );

            if (
                count($photooptions['options']) !== 4
                || $photooptions['correct'] === ''
            ) {
                continue;
            }

            $photopool[] = [
                'kind' => 'photo',
                'difficulty' => 'medium',
                'difficultylabel' => 'СРЕДНИЙ',
                'text' => 'Какой товар показан на фотографии?',
                'imageurl' => (string)$card['imageurl'],
                'hasimage' => true,
                'context' => self::clean_text(
                    (string)($card['spec'] ?? ''),
                    180
                ),
                'options' => $photooptions['options'],
                'correct' => $photooptions['correct'],
                'source' => 'cm41_scorm',
            ];
        }

        shuffle($photopool);

        $photos =
            array_slice(
                $photopool,
                0,
                min(5, count($photopool))
            );

        if ($photos) {
            $needremove = count($photos);

            /*
             * Preserve category/scenario/compare coverage first.
             * Replace redundant characteristic questions.
             */
            for (
                $i = count($questions) - 1;
                $i >= 0 && $needremove > 0;
                $i--
            ) {
                if (
                    (string)($questions[$i]['kind'] ?? '')
                    === 'characteristic'
                ) {
                    array_splice($questions, $i, 1);
                    $needremove--;
                }
            }

            /*
             * Defensive fallback if future catalog composition contains
             * fewer characteristic questions.
             */
            while (
                $needremove > 0
                && count($questions) > 0
            ) {
                array_pop($questions);
                $needremove--;
            }

            $questions =
                array_merge(
                    $questions,
                    $photos
                );
        }

        $questions =
            array_slice(
                $questions,
                0,
                self::QUESTION_COUNT
            );

        shuffle($questions);

        $number = 0;

        foreach ($questions as &$question) {
            $number++;
            $question['key'] =
                'q' . $number;
        }
        unset($question);

        return [
            'seed' =>
                bin2hex(
                    random_bytes(8)
                ),
            'versionid' =>
                $versionid,
            'cataloghash' =>
                $cataloghash,
            'questions' =>
                $questions,
        ];
    }

    private static function product_question(
        \stdClass $product,
        string $type,
        array $products,
        array $subgroups,
        array $groups
    ): ?array {
        $subgroup =
            $subgroups[
                (int)$product->parentid
            ] ?? null;

        if (!$subgroup) {
            return null;
        }

        $group =
            $groups[
                (int)$subgroup->parentid
            ] ?? null;

        if (!$group) {
            return null;
        }

        $title =
            self::clean_text(
                (string)$product->title,
                120
            );

        if ($title === '') {
            return null;
        }

        if ($type === 'category') {
            $pool = [];

            foreach ($subgroups as $candidate) {
                $candidateTitle =
                    self::clean_text(
                        (string)$candidate->title,
                        100
                    );

                if ($candidateTitle !== '') {
                    $pool[] =
                        $candidateTitle;
                }
            }

            $correct =
                self::clean_text(
                    (string)$subgroup->title,
                    100
                );

            $options =
                self::options(
                    $correct,
                    $pool
                );

            if (
                count($options['options'])
                !== 4
            ) {
                return null;
            }

            return [
                'kind' =>
                    'category',
                'difficulty' =>
                    'easy',
                'difficultylabel' =>
                    'БАЗОВЫЙ',
                'text' =>
                    'К какой товарной категории относится «'
                    . $title
                    . '»?',
                'hasimage' =>
                    false,
                'imageurl' =>
                    '',
                'context' =>
                    'Раздел: '
                    . self::clean_text(
                        (string)$group->title,
                        80
                    ),
                'options' =>
                    $options['options'],
                'correct' =>
                    $options['correct'],
                'source' =>
                    'catalog:'
                    . (int)$product->id,
            ];
        }

        $description =
            self::characteristic(
                $product
            );

        if ($description === '') {
            return null;
        }

        $pool = [];

        foreach ($products as $candidate) {
            if (
                (int)$candidate->id
                === (int)$product->id
            ) {
                continue;
            }

            if (
                (int)$candidate->parentid
                === (int)$product->parentid
            ) {
                $pool[] =
                    self::clean_text(
                        (string)$candidate->title,
                        120
                    );
            }
        }

        if (count($pool) < 3) {
            foreach ($products as $candidate) {
                $candidateSub =
                    $subgroups[
                        (int)$candidate->parentid
                    ] ?? null;

                if (
                    !$candidateSub
                    || (int)$candidateSub->parentid
                        !== (int)$group->id
                    || (int)$candidate->id
                        === (int)$product->id
                ) {
                    continue;
                }

                $pool[] =
                    self::clean_text(
                        (string)$candidate->title,
                        120
                    );
            }
        }

        if (count($pool) < 3) {
            foreach ($products as $candidate) {
                if (
                    (int)$candidate->id
                    !== (int)$product->id
                ) {
                    $pool[] =
                        self::clean_text(
                            (string)$candidate->title,
                            120
                        );
                }
            }
        }

        $options =
            self::options(
                $title,
                $pool
            );

        if (
            count($options['options'])
            !== 4
        ) {
            return null;
        }

        if ($type === 'scenario') {
            $text =
                'Клиент описывает задачу следующими условиями. '
                . 'Какой товар из ассортимента подходит лучше всего?';

            $difficulty =
                'hard';
            $difficultylabel =
                'СЛОЖНЫЙ';

        } else if ($type === 'compare') {
            $text =
                'Какой товар в этой категории точнее всего соответствует указанной особенности?';

            $difficulty =
                'expert';
            $difficultylabel =
                'ЭКСПЕРТНЫЙ';

        } else {
            $text =
                'Какой товар соответствует этой характеристике?';

            $difficulty =
                'medium';
            $difficultylabel =
                'СРЕДНИЙ';
        }

        return [
            'kind' =>
                $type,
            'difficulty' =>
                $difficulty,
            'difficultylabel' =>
                $difficultylabel,
            'text' =>
                $text,
            'hasimage' =>
                false,
            'imageurl' =>
                '',
            'context' =>
                self::clean_text(
                    $description,
                    280
                ),
            'options' =>
                $options['options'],
            'correct' =>
                $options['correct'],
            'source' =>
                'catalog:'
                . (int)$product->id,
        ];
    }

    private static function characteristic(
        \stdClass $product
    ): string {
        $attrs =
            json_decode(
                (string)$product->attributesjson,
                true
            );

        if (is_array($attrs)) {
            foreach ($attrs as $key => $value) {
                if (
                    str_starts_with(
                        (string)$key,
                        '_'
                    )
                ) {
                    continue;
                }

                $value =
                    self::clean_text(
                        (string)$value,
                        320
                    );

                if ($value !== '') {
                    return $value;
                }
            }
        }

        $description =
            self::clean_text(
                (string)$product->description,
                320
            );

        if ($description !== '') {
            return $description;
        }

        $summary =
            self::clean_text(
                (string)$product->summary,
                320
            );

        if (
            preg_match(
                '/^Товарные знания:/ui',
                $summary
            )
        ) {
            return '';
        }

        return $summary;
    }

    private static function options(
        string $correct,
        array $pool
    ): array {
        $correct =
            trim($correct);

        $unique = [];

        foreach ($pool as $value) {
            $value = trim(
                (string)$value
            );

            if (
                $value === ''
                || $value === $correct
            ) {
                continue;
            }

            $unique[$value] = true;
        }

        $values =
            array_keys($unique);

        shuffle($values);

        $values =
            array_slice(
                $values,
                0,
                3
            );

        if (count($values) < 3) {
            return [
                'options' => [],
                'correct' => '',
            ];
        }

        $values[] = $correct;
        shuffle($values);

        $options = [];
        $correctkey = '';

        foreach (
            array_values($values)
            as $index => $value
        ) {
            $key =
                'o' . ($index + 1);

            $options[] = [
                'key' => $key,
                'text' => $value,
            ];

            if ($value === $correct) {
                $correctkey = $key;
            }
        }

        return [
            'options' =>
                $options,
            'correct' =>
                $correctkey,
        ];
    }

    private static function scorm_cards(): array {
        global $DB;

        $cm = $DB->get_record(
            'course_modules',
            ['id' => self::SCORM_CMID],
            'id,course,instance',
            IGNORE_MISSING
        );

        if (!$cm) {
            return [
                'hash' => '',
                'cards' => [],
            ];
        }

        $context =
            \context_module::instance(
                (int)$cm->id
            );

        $index =
            $DB->get_record_sql(
                "SELECT *
                   FROM {files}
                  WHERE contextid = :contextid
                    AND component = :component
                    AND filearea = :filearea
                    AND filename = :filename
                    AND filesize > 0
               ORDER BY itemid DESC, id DESC",
                [
                    'contextid' =>
                        $context->id,
                    'component' =>
                        'mod_scorm',
                    'filearea' =>
                        'content',
                    'filename' =>
                        'index.html',
                ],
                IGNORE_MULTIPLE
            );

        if (!$index) {
            return [
                'hash' => '',
                'cards' => [],
            ];
        }

        $fs =
            get_file_storage();

        $stored =
            $fs->get_file_by_hash(
                (string)$index->pathnamehash
            );

        if (!$stored) {
            return [
                'hash' => '',
                'cards' => [],
            ];
        }

        $html =
            $stored->get_content();

        $pattern =
            '~<div class="product">'
            . '.*?<img[^>]+src="([^"]+)"[^>]*>'
            . '.*?<div class="prod-name">(.*?)</div>'
            . '.*?<div class="prod-spec">(.*?)</div>'
            . '.*?<div class="prod-benefit">'
            . '.*?</span>(.*?)</div>'
            . '.*?</div>\s*</div>~si';

        preg_match_all(
            $pattern,
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $cards = [];

        foreach ($matches as $match) {
            $path =
                html_entity_decode(
                    (string)$match[1],
                    ENT_QUOTES
                    | ENT_HTML5,
                    'UTF-8'
                );

            $path =
                ltrim(
                    $path,
                    '/'
                );

            $dir =
                dirname($path);

            $filepath =
                $dir === '.'
                ? '/'
                : '/'
                    . trim(
                        $dir,
                        '/'
                    )
                    . '/';

            $filename =
                basename($path);

            $filerow =
                $DB->get_record(
                    'files',
                    [
                        'contextid' =>
                            $context->id,
                        'component' =>
                            'mod_scorm',
                        'filearea' =>
                            'content',
                        'itemid' =>
                            (int)$index->itemid,
                        'filepath' =>
                            $filepath,
                        'filename' =>
                            $filename,
                    ],
                    '*',
                    IGNORE_MISSING
                );

            $imageurl = '';

            if (
                $filerow
                && (int)$filerow->filesize > 0
            ) {
                $imageurl =
                    \moodle_url::make_pluginfile_url(
                        $context->id,
                        'mod_scorm',
                        'content',
                        (int)$index->itemid,
                        $filepath,
                        $filename,
                        false
                    )->out(false);
            }

            $title =
                self::clean_text(
                    (string)$match[2],
                    120
                );

            if ($title === '') {
                continue;
            }

            $cards[] = [
                'title' =>
                    $title,
                'spec' =>
                    self::clean_text(
                        (string)$match[3],
                        260
                    ),
                'benefit' =>
                    self::clean_text(
                        (string)$match[4],
                        260
                    ),
                'imageurl' =>
                    $imageurl,
                'imagefile' =>
                    $filename,
            ];
        }

        return [
            'hash' =>
                (string)$index->contenthash,
            'cards' =>
                $cards,
        ];
    }

    private static function clean_text(
        string $value,
        int $limit
    ): string {
        $value =
            html_entity_decode(
                strip_tags($value),
                ENT_QUOTES
                | ENT_HTML5,
                'UTF-8'
            );

        $value =
            preg_replace(
                '/\s+/u',
                ' ',
                $value
            );

        $value =
            trim(
                (string)$value
            );

        return \core_text::substr(
            $value,
            0,
            $limit
        );
    }
}
