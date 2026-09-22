<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/**
 * Authoring layer for courses, SCORM learning packages and assessments inside
 * the existing USTAR material catalogue. Source text stays editable; an
 * imported arbitrary ZIP remains an immutable package attachment and is never
 * presented as editable source.
 */
final class material_studio {
    public const KIND_COURSE = 'course';
    public const KIND_SCORM = 'scorm';
    public const KIND_ASSESSMENT = 'assessment';
    public const FILEAREA_SCORM = 'material_scorm_package';

    public static function available(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table('local_ustar_content_blueprints'));
    }

    public static function can_manage(int $userid): bool {
        return capabilities::has($userid, capabilities::HR_WRITE);
    }

    /** @return array<int,array<string,mixed>> */
    public static function list_for_author(int $actorid): array {
        global $DB;
        self::assert_manage($actorid);
        if (!self::available()) { return []; }
        $sql = 'SELECT c.*, b.id AS blueprintid, b.kind AS blueprintkind, b.sourcejson, b.sourceversion,
                       b.packagestatus, b.packagefilename, b.timemodified AS blueprintedited
                  FROM {local_ustar_content} c
                  JOIN {local_ustar_content_blueprints} b ON b.contentid = c.id
              ORDER BY c.timemodified DESC, c.id DESC';
        $out = [];
        foreach ($DB->get_records_sql($sql) as $row) {
            $out[] = self::view_row($row, $actorid);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function save(int $contentid, array $input, int $actorid): array {
        global $DB;
        self::assert_manage($actorid);
        self::assert_available();
        $kind = clean_param((string)($input['kind'] ?? ''), PARAM_ALPHA);
        if (!in_array($kind, [self::KIND_COURSE, self::KIND_SCORM, self::KIND_ASSESSMENT], true)) {
            throw new \invalid_parameter_exception('Выберите тип учебного материала.');
        }
        $title = trim(clean_param((string)($input['title'] ?? ''), PARAM_TEXT));
        if ($title === '') {
            throw new \invalid_parameter_exception('Укажите название материала.');
        }
        $summary = trim(clean_param((string)($input['summary'] ?? ''), PARAM_TEXT));
        $body = clean_text((string)($input['body'] ?? ''), FORMAT_HTML);
        $questions = $kind === self::KIND_ASSESSMENT
            ? self::questions_from_lines((string)($input['questions'] ?? ''))
            : [];
        if ($kind === self::KIND_ASSESSMENT && !$questions) {
            throw new \invalid_parameter_exception('Добавьте хотя бы один вопрос аттестации.');
        }
        $passscore = min(100, max(1, (int)($input['passscore'] ?? 80)));
        $expected = (int)($input['expectedmodified'] ?? 0);
        $now = time();
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar_content')
            ->get_lock('studio:' . ($contentid ?: 'new'), 10);
        if (!$lock) {
            throw new \moodle_exception('Материал сейчас редактируется в другой сессии. Повторите попытку.');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $blueprint = false;
            if ($contentid > 0) {
                $content = $DB->get_record_sql(
                    'SELECT * FROM {local_ustar_content} WHERE id = :id FOR UPDATE',
                    ['id' => $contentid], MUST_EXIST
                );
                $blueprint = $DB->get_record_sql(
                    'SELECT * FROM {local_ustar_content_blueprints} WHERE contentid = :id FOR UPDATE',
                    ['id' => $contentid], MUST_EXIST
                );
                if ($expected <= 0 || (int)$content->timemodified !== $expected) {
                    throw new \moodle_exception('Материал уже изменён в другой сессии. Обновите форму.');
                }
                if ((string)$blueprint->kind !== $kind) {
                    throw new \invalid_parameter_exception('Тип опубликованного материала нельзя заменить. Создайте отдельный материал.');
                }
                $content->title = $title;
                $content->summary = $summary ?: null;
                $content->category = $kind === self::KIND_ASSESSMENT ? 'assessment' : 'learning';
                $content->timemodified = max($now, $expected + 1);
                $content->usermodified = $actorid;
                $DB->update_record('local_ustar_content', $content);
            } else {
                $contentid = (int)$DB->insert_record('local_ustar_content', (object)[
                    'parentid' => null, 'type' => $kind, 'title' => $title, 'summary' => $summary ?: null,
                    'category' => $kind === self::KIND_ASSESSMENT ? 'assessment' : 'learning',
                    'status' => content::STATUS_DRAFT, 'sourcekind' => content::SOURCE_EXTERNAL,
                    'courseid' => null, 'cmid' => null, 'externalurl' => '',
                    'owneruserid' => $actorid, 'ackrequired' => 0, 'publishedat' => null, 'sortorder' => 0,
                    'timecreated' => $now, 'timemodified' => $now, 'usermodified' => $actorid,
                ]);
                $content = $DB->get_record('local_ustar_content', ['id' => $contentid], '*', MUST_EXIST);
                $content->externalurl = (new \moodle_url('/local/ustar/material_player.php', ['id' => $contentid]))->out(false);
                $DB->update_record('local_ustar_content', $content);
                // New author-created material is private to the audience configured as
                // "all" until the author chooses narrower scopes in the existing editor.
                $DB->insert_record('local_ustar_content_access', (object)[
                    'contentid' => $contentid, 'scopetype' => 'all', 'scopeid' => null,
                    'active' => 1, 'timecreated' => $now, 'createdby' => $actorid,
                ]);
                $blueprint = (object)[
                    'contentid' => $contentid, 'kind' => $kind, 'sourceversion' => 0,
                    'packagestatus' => 'none', 'packagefilename' => null,
                ];
            }
            $source = [
                'body' => $body,
                'outline' => trim(clean_param((string)($input['outline'] ?? ''), PARAM_TEXT)),
                'passscore' => $passscore,
                'questions' => $questions,
            ];
            $blueprint->sourcejson = json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $blueprint->sourceversion = (int)$blueprint->sourceversion + 1;
            $blueprint->sourcehash = hash('sha256', (string)$blueprint->sourcejson);
            $blueprint->authorid = $actorid;
            $blueprint->timemodified = $now;
            if (empty($blueprint->id)) {
                $blueprint->timecreated = $now;
                $blueprint->id = (int)$DB->insert_record('local_ustar_content_blueprints', $blueprint);
            } else {
                $DB->update_record('local_ustar_content_blueprints', $blueprint);
            }
            self::audit($contentid, 'studio_material_saved', $actorid, [
                'kind' => $kind, 'sourceversion' => (int)$blueprint->sourceversion,
            ]);
            $tx->allow_commit();
            return self::by_content($contentid, $actorid);
        } finally {
            $lock->release();
        }
    }

    /**
     * Store a validated SCORM package. Source text remains separate and
     * editable; the imported archive is clearly marked as an attachment.
     */
    public static function upload_scorm_zip(int $contentid, int $actorid, array $upload): array {
        global $DB;
        self::assert_manage($actorid);
        self::assert_available();
        $item = self::by_content($contentid, $actorid);
        if ((string)$item['kind'] !== self::KIND_SCORM) {
            throw new \invalid_parameter_exception('ZIP можно прикрепить только к SCORM-материалу.');
        }
        if (empty($upload['tmp_name']) || !is_uploaded_file((string)$upload['tmp_name'])) {
            throw new \invalid_parameter_exception('Выберите ZIP-файл SCORM.');
        }
        $filename = clean_param((string)($upload['name'] ?? ''), PARAM_FILE);
        if (!str_ends_with(strtolower($filename), '.zip')) {
            throw new \invalid_parameter_exception('SCORM-пакет должен быть ZIP-файлом.');
        }
        if (!class_exists('\\ZipArchive')) {
            throw new \moodle_exception('На сервере недоступна проверка ZIP-пакета.');
        }
        $zip = new \ZipArchive();
        if ($zip->open((string)$upload['tmp_name']) !== true) {
            throw new \invalid_parameter_exception('Не удалось открыть ZIP-пакет.');
        }
        $manifest = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if (str_contains($entry, '..') || str_starts_with($entry, '/')) {
                $zip->close();
                throw new \invalid_parameter_exception('SCORM-пакет содержит небезопасный путь.');
            }
            if (strtolower(basename($entry)) === 'imsmanifest.xml') {
                $manifest = true;
            }
        }
        $zip->close();
        if (!$manifest) {
            throw new \invalid_parameter_exception('В ZIP не найден imsmanifest.xml — это не SCORM-пакет.');
        }

        $blueprint = $DB->get_record('local_ustar_content_blueprints', ['contentid' => $contentid], '*', MUST_EXIST);
        $fs = get_file_storage();
        $context = \context_system::instance();
        $fs->delete_area_files($context->id, 'local_ustar', self::FILEAREA_SCORM, (int)$blueprint->id);
        $fs->create_file_from_pathname((object)[
            'contextid' => $context->id, 'component' => 'local_ustar', 'filearea' => self::FILEAREA_SCORM,
            'itemid' => (int)$blueprint->id, 'filepath' => '/', 'filename' => $filename,
            'userid' => $actorid, 'mimetype' => 'application/zip',
        ], (string)$upload['tmp_name']);
        $blueprint->packagestatus = 'imported';
        $blueprint->packagefilename = $filename;
        $blueprint->timemodified = time();
        $blueprint->authorid = $actorid;
        $DB->update_record('local_ustar_content_blueprints', $blueprint);
        self::audit($contentid, 'studio_scorm_package_imported', $actorid, ['filename' => $filename]);
        return self::by_content($contentid, $actorid);
    }

    /** Archive rather than erase: route evidence and historical audit remain intact. */
    public static function delete(int $contentid, int $actorid, string $reason = ''): void {
        content_admin::delete($contentid, $actorid, $reason ?: 'Удалено из рабочего каталога материалов');
    }

    /** @return array<string,mixed> */
    public static function by_content(int $contentid, int $viewerid): array {
        global $DB;
        if (!self::available()) {
            throw new \moodle_exception('Контур материалов будет доступен после обновления базы данных.');
        }
        $row = $DB->get_record_sql(
            'SELECT c.*, b.id AS blueprintid, b.kind AS blueprintkind, b.sourcejson, b.sourceversion,
                    b.packagestatus, b.packagefilename, b.sourcehash, b.authorid
               FROM {local_ustar_content} c
               JOIN {local_ustar_content_blueprints} b ON b.contentid = c.id
              WHERE c.id = :id',
            ['id' => $contentid], MUST_EXIST
        );
        if (!self::can_manage($viewerid) && !content::can_access($contentid, $viewerid)) {
            throw new \required_capability_exception(
                \context_system::instance(), 'local/ustar:use', 'nopermissions', ''
            );
        }
        return self::view_row($row, $viewerid);
    }

    /** @return array<string,mixed> */
    public static function submit_assessment(int $contentid, int $userid, array $answers): array {
        global $DB;
        $item = self::by_content($contentid, $userid);
        if ((string)$item['kind'] !== self::KIND_ASSESSMENT) {
            throw new \invalid_parameter_exception('Это не аттестация.');
        }
        if ((string)$item['status'] !== content::STATUS_PUBLISHED && !self::can_manage($userid)) {
            throw new \moodle_exception('Аттестация ещё не опубликована.');
        }
        $questions = $item['questions'];
        $correct = 0;
        $normalized = [];
        foreach ($questions as $index => $question) {
            $answer = trim(clean_param((string)($answers[$index] ?? ''), PARAM_TEXT));
            $normalized[$index] = $answer;
            if ($answer !== '' && hash_equals((string)$question['answer'], $answer)) {
                $correct++;
            }
        }
        $score = $questions ? (int)round(100 * $correct / count($questions)) : 0;
        $passed = $score >= (int)$item['passscore'];
        $attemptkey = 'studio-assessment-v1:' . hash('sha256', $userid . ':' . $contentid . ':' . json_encode($normalized));
        $existing = self::find_submission($contentid, $attemptkey);
        if ($existing) {
            return $existing;
        }
        $result = [
            'contentid' => $contentid, 'userid' => $userid, 'score' => $score, 'passed' => $passed,
            'correct' => $correct, 'total' => count($questions), 'attemptkey' => $attemptkey, 'submittedat' => time(),
        ];
        $DB->insert_record('local_ustar_workflow_events', (object)[
            'entitytype' => 'studio_assessment', 'entityid' => $contentid, 'eventtype' => 'studio_assessment_submitted',
            'actorid' => $userid, 'reason' => null,
            'detailsjson' => json_encode($result + ['answers' => $normalized], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => (int)$result['submittedat'],
        ]);
        return $result;
    }

    /** The package is downloadable only to an author or an eligible material viewer. */
    public static function package_file(int $blueprintid, int $userid): ?\stored_file {
        global $DB;
        $blueprint = $DB->get_record('local_ustar_content_blueprints', ['id' => $blueprintid], '*', IGNORE_MISSING);
        if (!$blueprint) { return null; }
        if (!self::can_manage($userid) && !content::can_access((int)$blueprint->contentid, $userid)) {
            return null;
        }
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id, 'local_ustar', self::FILEAREA_SCORM, $blueprintid,
            'filename ASC, id ASC', false
        );
        return $files ? reset($files) : null;
    }

    /** @return array<string,mixed> */
    private static function view_row(\stdClass $row, int $viewerid): array {
        $source = json_decode((string)$row->sourcejson, true);
        $source = is_array($source) ? $source : [];
        $questions = is_array($source['questions'] ?? null) ? $source['questions'] : [];
        $blueprintid = (int)$row->blueprintid;
        $packageurl = '';
        if ((string)($row->packagestatus ?? '') === 'imported') {
            $file = self::package_file($blueprintid, $viewerid);
            if ($file) {
                $packageurl = \moodle_url::make_pluginfile_url(
                    \context_system::instance()->id, 'local_ustar', self::FILEAREA_SCORM, $blueprintid,
                    $file->get_filepath(), $file->get_filename(), true
                )->out(false);
            }
        }
        return [
            'id' => (int)$row->id, 'blueprintid' => $blueprintid,
            'kind' => (string)$row->blueprintkind, 'title' => format_string((string)$row->title),
            'summary' => (string)$row->summary, 'status' => (string)$row->status,
            'expectedmodified' => (int)$row->timemodified, 'sourceversion' => (int)$row->sourceversion,
            'body' => (string)($source['body'] ?? ''), 'outline' => (string)($source['outline'] ?? ''),
            'questions' => $questions, 'questionslines' => self::questions_to_lines($questions),
            'passscore' => (int)($source['passscore'] ?? 80),
            'packagestatus' => (string)($row->packagestatus ?? 'none'),
            'packagefilename' => (string)($row->packagefilename ?? ''), 'packageurl' => $packageurl,
            'editable_source' => true,
        ];
    }

    /** @return array<int,array<string,string>> */
    private static function questions_from_lines(string $lines): array {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $lines) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 4 || $parts[0] === '') { continue; }
            $answerindex = max(1, (int)array_pop($parts));
            $question = array_shift($parts);
            $options = array_values(array_filter($parts, static fn(string $item): bool => $item !== ''));
            if (count($options) < 2 || !isset($options[$answerindex - 1])) { continue; }
            $out[] = ['question' => clean_param($question, PARAM_TEXT), 'options' => $options,
                'answer' => $options[$answerindex - 1]];
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $questions */
    private static function questions_to_lines(array $questions): string {
        $out = [];
        foreach ($questions as $question) {
            $options = array_values($question['options'] ?? []);
            $answer = array_search((string)($question['answer'] ?? ''), $options, true);
            $out[] = implode(' | ', array_merge([(string)($question['question'] ?? '')], $options, [(string)(($answer === false ? 0 : $answer) + 1)]));
        }
        return implode("\n", $out);
    }

    /** @return array<string,mixed>|null */
    private static function find_submission(int $contentid, string $attemptkey): ?array {
        global $DB;
        $events = $DB->get_records('local_ustar_workflow_events', [
            'entitytype' => 'studio_assessment', 'entityid' => $contentid, 'eventtype' => 'studio_assessment_submitted',
        ], 'id DESC');
        foreach ($events as $event) {
            $data = json_decode((string)$event->detailsjson, true);
            if (is_array($data) && ($data['attemptkey'] ?? '') === $attemptkey) {
                unset($data['answers']);
                return $data;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $data */
    private static function audit(int $contentid, string $event, int $actorid, array $data): void {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ustar_workflow_events'))) { return; }
        $DB->insert_record('local_ustar_workflow_events', (object)[
            'entitytype' => 'studio_material', 'entityid' => $contentid, 'eventtype' => $event,
            'actorid' => $actorid, 'reason' => null,
            'detailsjson' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'timecreated' => time(),
        ]);
    }

    private static function assert_manage(int $actorid): void {
        if (!self::can_manage($actorid)) {
            throw new \required_capability_exception(
                \context_system::instance(), 'local/ustar:hrmanage', 'nopermissions', ''
            );
        }
    }

    private static function assert_available(): void {
        if (!self::available()) {
            throw new \moodle_exception('Студия материалов будет доступна после обновления базы данных.');
        }
    }
}
