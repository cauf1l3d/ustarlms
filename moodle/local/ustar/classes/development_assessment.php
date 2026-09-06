<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Private, versioned self-development profiles.
 *
 * These profiles are original USTAR reflection tools, not psychometric
 * diagnostics, employment evidence or licensed third-party methodologies.
 */
final class development_assessment {
    public const TEAM_PROFILE_KEY = 'team_profile_express';

    /** Create exactly one publishable original profile when the schema is installed. */
    public static function ensure_team_profile(int $actorid = 0): \stdClass {
        global $DB;
        $existing = $DB->get_record('local_ustar_dev_assess', ['assessmentkey' => self::TEAM_PROFILE_KEY]);
        if ($existing) {
            return $existing;
        }

        $now = time();
        $assessmentid = (int)$DB->insert_record('local_ustar_dev_assess', (object)[
            'assessmentkey' => self::TEAM_PROFILE_KEY,
            'title' => 'Экспресс-профиль командного взаимодействия',
            'summary' => 'Короткая саморефлексия о привычном вкладе в командную работу. Результат нужен только для личного развития и обсуждения по согласованной политике.',
            'sensitivity' => 'private',
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $actorid,
        ]);
        [$questions, $results] = self::team_profile_definition();
        $DB->insert_record('local_ustar_dev_assess_ver', (object)[
            'assessmentid' => $assessmentid,
            'versionno' => 1,
            'intro' => 'Выберите вариант, который чаще всего описывает ваше рабочее поведение. Здесь нет правильных ответов. Это не оценка пригодности, не кадровое решение и не методика Белбина.',
            'questionsjson' => json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'resultsjson' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'published',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $actorid,
        ]);
        return $DB->get_record('local_ustar_dev_assess', ['id' => $assessmentid], '*', MUST_EXIST);
    }

    /** @return array<int, array<string, mixed>> Catalog for human selectors; technical keys stay internal. */
    public static function catalog(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_dev_assess'))) {
            return [];
        }
        $items = [];
        foreach ($DB->get_records('local_ustar_dev_assess', ['active' => 1], 'title ASC') as $assessment) {
            $version = self::published_version((int)$assessment->id);
            if ($version) {
                $items[] = [
                    'key' => (string)$assessment->assessmentkey,
                    'title' => format_string((string)$assessment->title),
                    'summary' => format_text((string)$assessment->summary, FORMAT_PLAIN),
                    'sensitivity' => (string)$assessment->sensitivity,
                ];
            }
        }
        return $items;
    }

    /** @return array{assessment:\stdClass,version:\stdClass,questions:array,results:array}|null */
    public static function published(string $assessmentkey): ?array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_dev_assess'))) {
            return null;
        }
        $assessmentkey = clean_param($assessmentkey, PARAM_ALPHANUMEXT);
        $assessment = $DB->get_record('local_ustar_dev_assess', [
            'assessmentkey' => $assessmentkey,
            'active' => 1,
        ]);
        if (!$assessment || !($version = self::published_version((int)$assessment->id))) {
            return null;
        }
        $questions = json_decode((string)$version->questionsjson, true);
        $results = json_decode((string)$version->resultsjson, true);
        if (!is_array($questions) || !is_array($results) || !$questions) {
            return null;
        }
        return ['assessment' => $assessment, 'version' => $version, 'questions' => $questions, 'results' => $results];
    }

    /** A submitted attempt is enough for a route self-reflection requirement. */
    public static function completion_for_user(string $assessmentkey, int $userid): ?\stdClass {
        global $DB;
        $definition = self::published($assessmentkey);
        if (!$definition) {
            return null;
        }
        $attempts = $DB->get_records_select(
            'local_ustar_dev_assess_try',
            'assessmentid = :assessmentid AND userid = :userid AND status = :status',
            ['assessmentid' => (int)$definition['assessment']->id, 'userid' => $userid, 'status' => 'submitted'],
            'submittedat DESC, id DESC',
            '*',
            0,
            1
        );
        return $attempts ? reset($attempts) : null;
    }

    /** @return array<string, mixed>|null */
    public static function latest_for_user(string $assessmentkey, int $userid): ?array {
        $attempt = self::completion_for_user($assessmentkey, $userid);
        if (!$attempt) {
            return null;
        }
        $result = json_decode((string)$attempt->resultjson, true);
        if (!is_array($result)) {
            return null;
        }
        $result['submittedat'] = (int)$attempt->submittedat;
        $result['attemptid'] = (int)$attempt->id;
        return $result;
    }

    /**
     * Store a fully validated response exactly once for a user/request key.
     * Historical attempts are intentionally retained so changed profiles do not
     * overwrite an earlier employee reflection.
     *
     * @return array<string, mixed>
     */
    public static function submit(string $assessmentkey, int $userid, array $answers, string $idempotencykey, int $startedat = 0): array {
        global $DB;
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \invalid_parameter_exception('Сотрудник для сохранения результата не найден.');
        }
        $definition = self::published($assessmentkey);
        if (!$definition) {
            throw new \moodle_exception('Развивающий профиль недоступен.');
        }
        $idempotencykey = clean_param($idempotencykey, PARAM_ALPHANUMEXT);
        if ($idempotencykey === '') {
            throw new \invalid_parameter_exception('Нужен ключ безопасной отправки результата.');
        }
        $idempotencykey = \core_text::substr($idempotencykey, 0, 128);
        $existing = $DB->get_record('local_ustar_dev_assess_try', ['userid' => $userid, 'idempotencykey' => $idempotencykey]);
        if ($existing) {
            return self::attempt_result($existing);
        }

        $validated = [];
        $scores = [];
        foreach ($definition['questions'] as $question) {
            $questionkey = (string)($question['key'] ?? '');
            $selected = clean_param((string)($answers[$questionkey] ?? ''), PARAM_ALPHANUMEXT);
            $selectedoption = null;
            foreach (($question['options'] ?? []) as $option) {
                if ((string)($option['key'] ?? '') === $selected) {
                    $selectedoption = $option;
                    break;
                }
            }
            if (!$selectedoption) {
                throw new \invalid_parameter_exception('Ответьте на все вопросы перед сохранением результата.');
            }
            $profilekey = clean_param((string)($selectedoption['profile'] ?? ''), PARAM_ALPHANUMEXT);
            if ($profilekey === '') {
                throw new \invalid_parameter_exception('Вопрос профиля настроен неверно.');
            }
            $validated[$questionkey] = $selected;
            $scores[$profilekey] = (int)($scores[$profilekey] ?? 0) + 1;
        }
        $result = self::calculate_result($definition['results'], $scores);
        $now = time();
        try {
            $attemptid = (int)$DB->insert_record('local_ustar_dev_assess_try', (object)[
                'assessmentid' => (int)$definition['assessment']->id,
                'versionid' => (int)$definition['version']->id,
                'userid' => $userid,
                'idempotencykey' => $idempotencykey,
                'status' => 'submitted',
                'answersjson' => json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'resultjson' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'startedat' => $startedat > 0 ? $startedat : $now,
                'submittedat' => $now,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        } catch (\dml_write_exception $e) {
            $existing = $DB->get_record('local_ustar_dev_assess_try', ['userid' => $userid, 'idempotencykey' => $idempotencykey]);
            if (!$existing) {
                throw $e;
            }
            return self::attempt_result($existing);
        }
        return self::attempt_result($DB->get_record('local_ustar_dev_assess_try', ['id' => $attemptid], '*', MUST_EXIST));
    }

    /** `private` is employee + explicitly assigned HRD only; ordinary HR is excluded. */
    public static function can_view_private_result(int $viewerid, int $subjectid): bool {
        global $USER;
        if ($viewerid === $subjectid) {
            return true;
        }
        $context = \context_system::instance();
        return $viewerid === (int)$USER->id && (
            is_siteadmin($viewerid) || has_capability('local/ustar:developmentanalytics', $context)
        );
    }

    /** @return array{0:array,1:array} */
    private static function team_profile_definition(): array {
        $profiles = [
            'organizer' => [
                'title' => 'Организатор',
                'summary' => 'Вы часто превращаете договорённости в понятный план и помогаете команде не потерять темп.',
                'recommendation' => 'Полезно заранее называть следующий шаг, владельца и срок — и оставлять команде пространство для инициативы.',
            ],
            'practitioner' => [
                'title' => 'Практик',
                'summary' => 'Вы обычно быстрее других переводите обсуждение в конкретное действие и проверяете, что результат работает.',
                'recommendation' => 'Подключайте коллег к ранней проверке решения: так практичность станет общей, а не только вашей силой.',
            ],
            'analyst' => [
                'title' => 'Аналитик',
                'summary' => 'Вы склонны замечать риски, различать варианты и уточнять, на чём основано решение.',
                'recommendation' => 'Делитесь выводом коротко: риск, факт и предлагаемое действие — чтобы анализ помогал двигаться быстрее.',
            ],
            'collaborator' => [
                'title' => 'Связующий',
                'summary' => 'Вы замечаете людей и взаимосвязи, помогаете собрать разные точки зрения и поддержать договорённость.',
                'recommendation' => 'Фиксируйте итог обсуждения: так хорошая коммуникация превращается в совместное действие.',
            ],
        ];

        /*
         * Each situation has its own natural answers.
         * Every question still contains one response for each profile,
         * so scoring stays comparable without repetitive wording.
         */
        $items = [
            [
                'text' => 'Когда задача ещё неясна, я чаще всего…',
                'options' => [
                    'organizer' => 'разбиваю её на понятные шаги и определяю, с чего начать',
                    'analyst' => 'сначала уточняю вводные и проверяю, чего нам не хватает для решения',
                    'collaborator' => 'обсуждаю задачу с коллегами, чтобы собрать разные точки зрения',
                    'practitioner' => 'пробую небольшой практический шаг, чтобы быстрее понять ситуацию',
                ],
            ],
            [
                'text' => 'В командном обсуждении я обычно…',
                'options' => [
                    'collaborator' => 'слежу, чтобы участники услышали друг друга и пришли к общей договорённости',
                    'practitioner' => 'предлагаю перейти от обсуждения к конкретному действию',
                    'organizer' => 'фиксирую решения, ответственных и следующий шаг',
                    'analyst' => 'сравниваю аргументы и обращаю внимание на слабые места решения',
                ],
            ],
            [
                'text' => 'Если срок близко, я скорее…',
                'options' => [
                    'practitioner' => 'берусь за самое важное действие и двигаю результат вперёд',
                    'organizer' => 'пересобираю приоритеты и распределяю оставшуюся работу',
                    'analyst' => 'проверяю, где риск ошибки наиболее критичен',
                    'collaborator' => 'сверяюсь с коллегами и помогаю убрать зависшие договорённости',
                ],
            ],
            [
                'text' => 'Когда вижу риск, я…',
                'options' => [
                    'analyst' => 'разбираюсь, насколько он реален и к каким последствиям может привести',
                    'organizer' => 'сразу закладываю действие, которое снизит риск',
                    'practitioner' => 'проверяю проблему на практике и ищу рабочее решение',
                    'collaborator' => 'предупреждаю тех, кого риск затрагивает, и согласовываю дальнейшие действия',
                ],
            ],
            [
                'text' => 'После встречи мне важнее всего…',
                'options' => [
                    'organizer' => 'чтобы было понятно, кто, что и к какому сроку делает',
                    'collaborator' => 'чтобы участники одинаково поняли итог и сохранили договорённость',
                    'analyst' => 'чтобы решение опиралось на достаточные факты',
                    'practitioner' => 'чтобы можно было сразу перейти к выполнению',
                ],
            ],
            [
                'text' => 'В новой рабочей ситуации я…',
                'options' => [
                    'practitioner' => 'быстро включаюсь в реальную работу и осваиваюсь по ходу',
                    'analyst' => 'сначала изучаю правила, детали и возможные ошибки',
                    'collaborator' => 'знакомлюсь с людьми и выясняю, к кому по каким вопросам обращаться',
                    'organizer' => 'выстраиваю для себя порядок действий и ориентиры',
                ],
            ],
            [
                'text' => 'Когда мнения расходятся, я…',
                'options' => [
                    'collaborator' => 'помогаю сторонам понять причины разногласий и найти общее',
                    'analyst' => 'сравниваю аргументы и ищу решение, которое лучше подтверждается фактами',
                    'organizer' => 'возвращаю обсуждение к цели и предлагаю порядок принятия решения',
                    'practitioner' => 'предлагаю проверить варианты на практике и посмотреть на результат',
                ],
            ],
            [
                'text' => 'Мой привычный вклад в общий результат…',
                'options' => [
                    'organizer' => 'помочь команде удерживать порядок, сроки и следующий шаг',
                    'practitioner' => 'довести идею до конкретного работающего результата',
                    'collaborator' => 'соединить людей и сделать совместную работу понятнее',
                    'analyst' => 'заметить важные детали и помочь избежать слабого решения',
                ],
            ],
            [
                'text' => 'Перед запуском решения я…',
                'options' => [
                    'analyst' => 'проверяю ключевые допущения и возможные последствия',
                    'practitioner' => 'стараюсь протестировать решение в реальных условиях',
                    'organizer' => 'убеждаюсь, что понятны порядок запуска и ответственные',
                    'collaborator' => 'проверяю, что все вовлечённые знают, что происходит и что от них требуется',
                ],
            ],
            [
                'text' => 'Если команда теряет темп, я…',
                'options' => [
                    'organizer' => 'возвращаю внимание к приоритетам и следующим конкретным шагам',
                    'collaborator' => 'выясняю, где возникло непонимание или кому нужна помощь',
                    'practitioner' => 'сам начинаю двигать ближайшую задачу, чтобы вернуть динамику',
                    'analyst' => 'пытаюсь понять настоящую причину задержки, прежде чем менять план',
                ],
            ],
            [
                'text' => 'При работе с коллегой мне помогает…',
                'options' => [
                    'collaborator' => 'понять его точку зрения и договориться о способе взаимодействия',
                    'organizer' => 'заранее определить, кто за какую часть отвечает',
                    'analyst' => 'сверить факты и одинаково понимать условия задачи',
                    'practitioner' => 'быстро начать совместное действие и уточнять детали по ходу',
                ],
            ],
            [
                'text' => 'Лучший признак хорошей командной работы для меня…',
                'options' => [
                    'practitioner' => 'команда получает реальный результат, который работает',
                    'collaborator' => 'люди взаимодействуют без лишних конфликтов и помогают друг другу',
                    'organizer' => 'каждый понимает свою ответственность, а работа движется предсказуемо',
                    'analyst' => 'решения принимаются осмысленно и команда не повторяет очевидных ошибок',
                ],
            ],
        ];

        $questions = [];

        foreach ($items as $index => $item) {
            $options = [];
            $offset = $index % 4;
            $keys = array_keys($item['options']);
            $keys = array_merge(
                array_slice($keys, $offset),
                array_slice($keys, 0, $offset)
            );

            foreach ($keys as $optionindex => $profilekey) {
                $options[] = [
                    'key' => 'o' . ($optionindex + 1),
                    'text' => ucfirst((string)$item['options'][$profilekey]) . '.',
                    'profile' => $profilekey,
                ];
            }

            $questions[] = [
                'key' => 'q' . ($index + 1),
                'text' => (string)$item['text'],
                'options' => $options,
            ];
        }

        return [$questions, $profiles];
    }


    /**
     * Publish the improved wording as a new version.
     * Historical attempts keep their original version/result.
     */
    public static function ensure_team_profile_v2(int $actorid = 0): void {
        global $DB;

        $assessment = self::ensure_team_profile($actorid);

        $latest = $DB->get_record_sql(
            "SELECT *
               FROM {local_ustar_dev_assess_ver}
              WHERE assessmentid = :assessmentid
           ORDER BY versionno DESC, id DESC",
            ['assessmentid' => (int)$assessment->id],
            IGNORE_MULTIPLE
        );

        if ($latest && (int)$latest->versionno >= 2) {
            return;
        }

        [$questions, $results] =
            self::team_profile_definition();

        $now = time();

        /*
         * Only one current published definition should be offered
         * to new attempts. Historical rows are retained.
         */
        $DB->set_field(
            'local_ustar_dev_assess_ver',
            'status',
            'archived',
            [
                'assessmentid' => (int)$assessment->id,
                'status' => 'published',
            ]
        );

        $DB->insert_record(
            'local_ustar_dev_assess_ver',
            (object)[
                'assessmentid' => (int)$assessment->id,
                'versionno' => 2,
                'intro' =>
                    'Выберите вариант, который чаще всего описывает ваше реальное рабочее поведение. Здесь нет правильных или неправильных ответов. Это инструмент саморефлексии, а не кадровая оценка.',
                'questionsjson' =>
                    json_encode(
                        $questions,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    ),
                'resultsjson' =>
                    json_encode(
                        $results,
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    ),
                'status' => 'published',
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $actorid,
            ]
        );
    }

    private static function published_version(int $assessmentid): ?\stdClass {
        global $DB;
        $versions = $DB->get_records_select(
            'local_ustar_dev_assess_ver',
            'assessmentid = :assessmentid AND status = :status',
            ['assessmentid' => $assessmentid, 'status' => 'published'],
            'versionno DESC, id DESC',
            '*',
            0,
            1
        );
        return $versions ? reset($versions) : null;
    }

    /** @return array<string, mixed> */
    private static function calculate_result(array $profiles, array $scores): array {
        foreach ($profiles as $key => $_profile) {
            $scores[$key] = (int)($scores[$key] ?? 0);
        }
        arsort($scores, SORT_NUMERIC);
        $ranked = array_keys($scores);
        $primarykey = (string)$ranked[0];
        $secondarykey = (string)($ranked[1] ?? $primarykey);
        $primary = $profiles[$primarykey];
        $secondary = $profiles[$secondarykey];
        return [
            'primary' => ['key' => $primarykey, 'title' => (string)$primary['title'], 'summary' => (string)$primary['summary']],
            'secondary' => ['key' => $secondarykey, 'title' => (string)$secondary['title']],
            'recommendation' => (string)$primary['recommendation'],
            'scores' => $scores,
            'disclaimer' => 'Это авторский развивающий профиль USTAR, а не психодиагностика, кадровая оценка или методика Белбина.',
        ];
    }

    /** @return array<string, mixed> */
    private static function attempt_result(\stdClass $attempt): array {
        $result = json_decode((string)$attempt->resultjson, true);
        if (!is_array($result)) {
            throw new \moodle_exception('Сохранённый результат профиля повреждён.');
        }
        $result['submittedat'] = (int)$attempt->submittedat;
        $result['attemptid'] = (int)$attempt->id;
        return $result;
    }
}
