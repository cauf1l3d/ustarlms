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
            'organizer' => ['title' => 'Организатор', 'summary' => 'Вы часто превращаете договорённости в понятный план и помогаете команде не потерять темп.', 'recommendation' => 'Полезно заранее называть следующий шаг, владельца и срок — и оставлять команде пространство для инициативы.'],
            'practitioner' => ['title' => 'Практик', 'summary' => 'Вы обычно быстрее других переводите обсуждение в конкретное действие и проверяете, что результат работает.', 'recommendation' => 'Подключайте коллег к ранней проверке решения: так практичность станет общей, а не только вашей силой.'],
            'analyst' => ['title' => 'Аналитик', 'summary' => 'Вы склонны замечать риски, различать варианты и уточнять, на чём основано решение.', 'recommendation' => 'Делитесь выводом коротко: риск, факт и предлагаемое действие — чтобы анализ помогал двигаться быстрее.'],
            'collaborator' => ['title' => 'Связующий', 'summary' => 'Вы замечаете людей и взаимосвязи, помогаете собрать разные точки зрения и поддержать договорённость.', 'recommendation' => 'Фиксируйте итог обсуждения: так хорошая коммуникация превращается в совместное действие.'],
        ];
        $prompts = [
            'Когда задача ещё неясна, я чаще всего…', 'В командном обсуждении я обычно…',
            'Если срок близко, я скорее…', 'Когда вижу риск, я…',
            'После встречи мне важнее всего…', 'В новой рабочей ситуации я…',
            'Когда мнения расходятся, я…', 'Мой привычный вклад в общий результат…',
            'Перед запуском решения я…', 'Если команда теряет темп, я…',
            'При работе с коллегой мне помогает…', 'Лучший признак хорошей командной работы для меня…',
        ];
        $actions = [
            'собираю план и распределяю следующий шаг', 'берусь за проверку решения на практике',
            'уточняю факты и возможные последствия', 'связываю участников и проясняю договорённость',
        ];
        $keys = array_keys($profiles);
        $questions = [];
        foreach ($prompts as $index => $prompt) {
            $options = [];
            foreach ($keys as $offset => $profilekey) {
                $rotated = ($offset + $index) % count($keys);
                $options[] = [
                    'key' => 'o' . ($offset + 1),
                    'text' => ucfirst($actions[$rotated]) . '.',
                    'profile' => $keys[$rotated],
                ];
            }
            $questions[] = ['key' => 'q' . ($index + 1), 'text' => $prompt, 'options' => $options];
        }
        return [$questions, $profiles];
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
