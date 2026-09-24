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

    /** Reject publishing an edited SCORM source until its matching Moodle runtime exists. */
    public static function runtime_ready(int $contentid): bool {
        global $DB;
        if (!self::available()) { return false; }
        $item = $DB->get_record('local_ustar_content', ['id' => $contentid], 'id,cmid,courseid');
        $blueprint = $DB->get_record('local_ustar_content_blueprints',
            ['contentid' => $contentid], 'id,kind,sourceversion,packagestatus');
        if (!$item || !$blueprint || (string)$blueprint->kind !== self::KIND_SCORM
                || (string)$blueprint->packagestatus !== 'imported'
                || (int)$item->cmid <= 0 || (int)$item->courseid <= 0) { return false; }
        $events = $DB->get_records('local_ustar_workflow_events', [
            'entitytype' => 'studio_scorm_runtime', 'entityid' => $contentid,
            'eventtype' => 'scorm_runtime_created',
        ], 'id DESC', 'id,detailsjson', 0, 1);
        $event = $events ? reset($events) : null;
        $data = $event ? json_decode((string)$event->detailsjson, true) : null;
        return is_array($data)
            && (int)($data['sourceversion'] ?? 0) === (int)$blueprint->sourceversion
            && (int)($data['cmid'] ?? 0) === (int)$item->cmid
            && (int)($data['courseid'] ?? 0) === (int)$item->courseid;
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
        $pages = $kind === self::KIND_SCORM ? self::scorm_pages($input['scormpages'] ?? []) : [];
        $expected = (int)($input['expectedmodified'] ?? 0);
        $creationtoken = (string)($input['creationtoken'] ?? '');
        if ($contentid === 0 && !preg_match('/^[a-f0-9]{32}$/', $creationtoken)) {
            throw new \invalid_parameter_exception('Форма создания устарела. Обновите страницу и повторите сохранение.');
        }
        $inputhash = hash('sha256', json_encode([$kind, $title, $summary, $body,
            (string)($input['outline'] ?? ''), $questions, $passscore, $pages], JSON_UNESCAPED_UNICODE));
        $now = time();
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar_content')
            ->get_lock($contentid ? 'studio:' . $contentid : 'studio:new:' . $actorid . ':' . $creationtoken, 10);
        if (!$lock) {
            throw new \moodle_exception('Материал сейчас редактируется в другой сессии. Повторите попытку.');
        }
        try {
            if ($contentid === 0) {
                $existing = $DB->get_record('local_ustar_workflow_events', [
                    'entitytype' => 'studio_material_create', 'eventtype' => 'created',
                    'actorid' => $actorid, 'reason' => $creationtoken,
                ]);
                if ($existing) {
                    $details = json_decode((string)$existing->detailsjson, true);
                    if (!is_array($details) || (string)($details['inputhash'] ?? '') !== $inputhash) {
                        throw new \invalid_parameter_exception('Эта форма уже создала другой материал. Откройте новую форму.');
                    }
                    $result = self::by_content((int)$existing->entityid, $actorid);
                    $result['replay'] = true;
                    return $result;
                }
            }
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
                if ((string)$content->status === content::STATUS_PUBLISHED) {
                    throw new \moodle_exception(
                        'Опубликованный материал нельзя менять на лету. '
                        . 'Сначала верните его в черновики, сохраните новую source-version и опубликуйте снова.'
                    );
                }
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
                'pages' => $pages,
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
            if ($creationtoken !== '' && $expected === 0) {
                $DB->insert_record('local_ustar_workflow_events', (object)[
                    'entitytype' => 'studio_material_create', 'entityid' => $contentid,
                    'eventtype' => 'created', 'actorid' => $actorid, 'reason' => $creationtoken,
                    'detailsjson' => json_encode(['inputhash' => $inputhash]),
                    'timecreated' => $now,
                ]);
            }
            $result = self::by_content($contentid, $actorid);
            $tx->allow_commit();
            return $result;
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
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
        if ((string)$item['status'] !== content::STATUS_DRAFT) {
            throw new \moodle_exception('Пакет опубликованного материала нельзя заменить. Сначала верните его в черновик.');
        }
        if (empty($upload['tmp_name']) || !is_uploaded_file((string)$upload['tmp_name'])) {
            throw new \invalid_parameter_exception('Выберите ZIP-файл SCORM.');
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || (int)($upload['size'] ?? 0) <= 0
                || (int)$upload['size'] > 100 * 1024 * 1024) {
            throw new \invalid_parameter_exception('SCORM ZIP должен быть не больше 100 МБ.');
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
        $uncompressed = 0;
        if ($zip->numFiles > 10000) {
            $zip->close();
            throw new \invalid_parameter_exception('Слишком много файлов в SCORM ZIP.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if (str_contains($entry, '..') || str_starts_with($entry, '/')
                    || str_contains($entry, '\\') || preg_match('/^[a-zA-Z]:/', $entry)) {
                $zip->close();
                throw new \invalid_parameter_exception('SCORM-пакет содержит небезопасный путь.');
            }
            $stat = $zip->statIndex($i);
            $uncompressed += (int)($stat['size'] ?? 0);
            if ($uncompressed > 500 * 1024 * 1024) {
                $zip->close();
                throw new \invalid_parameter_exception('Распакованный SCORM ZIP превышает 500 МБ.');
            }
            if (strtolower($entry) === 'imsmanifest.xml') {
                $manifest = true;
            }
        }
        $zip->close();
        if (!$manifest) {
            throw new \invalid_parameter_exception('В ZIP не найден imsmanifest.xml — это не SCORM-пакет.');
        }

        studio_scorm_runtime::import($contentid, $actorid, (string)$upload['tmp_name'],
            $filename, (int)$item['sourceversion']);
        return self::by_content($contentid, $actorid);
    }

    /** Build a single-SCO SCORM 1.2 package from the visual page editor. */
    public static function build_scorm(int $contentid, int $actorid): array {
        self::assert_manage($actorid);
        $item = self::by_content($contentid, $actorid);
        if ((string)$item['kind'] !== self::KIND_SCORM || !$item['pages']
                || (string)$item['status'] !== content::STATUS_DRAFT) {
            throw new \invalid_parameter_exception('Сначала сохраните страницы SCORM в черновике.');
        }
        if (!class_exists('\\ZipArchive')) {
            throw new \moodle_exception('Для сборки SCORM на сервере требуется ZipArchive.');
        }
        $path = tempnam(sys_get_temp_dir(), 'ustar-scorm-');
        if ($path === false) { throw new \moodle_exception('Не удалось создать временный пакет SCORM.'); }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \moodle_exception('Не удалось открыть временный пакет SCORM.');
            }
            try {
                $manifest = '<?xml version="1.0" encoding="UTF-8"?>'
                    . '<manifest identifier="USTAR-' . $contentid . '-v' . (int)$item['sourceversion'] . '" version="1.2"'
                    . ' xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2"'
                    . ' xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2">'
                    . '<metadata><schema>ADL SCORM</schema><schemaversion>1.2</schemaversion></metadata>'
                    . '<organizations default="USTAR"><organization identifier="USTAR">'
                    . '<title>' . htmlspecialchars((string)$item['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</title>'
                    . '<item identifier="USTAR-ITEM" identifierref="USTAR-RESOURCE">'
                    . '<title>' . htmlspecialchars((string)$item['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</title>'
                    . '</item></organization></organizations><resources>'
                    . '<resource identifier="USTAR-RESOURCE" type="webcontent" adlcp:scormtype="sco" href="index.html">'
                    . '<file href="index.html"/></resource></resources></manifest>';
                if (!$zip->addFromString('imsmanifest.xml', $manifest)
                        || !$zip->addFromString('index.html', studio_scorm_package::html($item))) {
                    throw new \moodle_exception('Не удалось записать страницы SCORM.');
                }
            } finally {
                $zip->close();
            }
            studio_scorm_runtime::import($contentid, $actorid, $path,
                'ustar-' . $contentid . '-v' . (int)$item['sourceversion'] . '.zip',
                (int)$item['sourceversion']);
            return self::by_content($contentid, $actorid);
        } finally {
            @unlink($path);
        }
    }

    /** @return array<int,array{title:string,body:string}> */
    private static function scorm_pages(mixed $input): array {
        if (!is_array($input) || count($input) > 30) {
            throw new \invalid_parameter_exception('SCORM допускает до 30 страниц.');
        }
        $pages = [];
        foreach ($input as $page) {
            if (!is_array($page)) { throw new \invalid_parameter_exception('Некорректная страница SCORM.'); }
            $title = trim(clean_param((string)($page['title'] ?? ''), PARAM_TEXT));
            $body = clean_text((string)($page['body'] ?? ''), FORMAT_HTML);
            if ($title === '' && trim(strip_tags($body)) === '') { continue; }
            if ($title === '' || trim(strip_tags($body)) === '') {
                throw new \invalid_parameter_exception('У каждой страницы SCORM должны быть название и содержание.');
            }
            if (strlen($body) > 100000) {
                throw new \invalid_parameter_exception('Страница SCORM превышает допустимый размер.');
            }
            $pages[] = ['title' => $title, 'body' => $body];
        }
        return $pages;
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
    public static function submit_assessment(int $contentid, int $userid, array $answers,
            int $expectedversion, bool $preview = false): array {
        global $DB;
        if ($preview && !self::can_manage($userid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:hrmanage', 'nopermissions', '');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar_content')
            ->get_lock('studio:' . $contentid, 10);
        if (!$lock) { throw new \moodle_exception('Аттестация обновляется. Повторите отправку.'); }
        try {
            $item = self::by_content($contentid, $userid);
            if ($item['kind'] !== self::KIND_ASSESSMENT) {
                throw new \invalid_parameter_exception('Это не аттестация.');
            }
            if (!$preview && $item['status'] !== content::STATUS_PUBLISHED) {
                throw new \moodle_exception('Аттестация ещё не опубликована.');
            }
            if ($expectedversion <= 0 || $expectedversion !== (int)$item['sourceversion']) {
                throw new \moodle_exception('Автор обновил аттестацию. Откройте новую версию перед отправкой.');
            }
            // Correct answers stay server-side, separate from the learner view.
            $blueprint = $DB->get_record('local_ustar_content_blueprints', ['contentid' => $contentid], '*', MUST_EXIST);
            $source = json_decode($blueprint->sourcejson, true, 512, JSON_THROW_ON_ERROR);
            $questions = $source['questions'] ?? [];
            $correct = 0;
            $normalized = [];
            foreach ($questions as $index => $question) {
                $options = array_values((array)($question['options'] ?? []));
                $submitted = $answers[$index] ?? null;
                $answerindex = filter_var($submitted, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => count($options)],
                ]);
                if ($answerindex !== false) {
                    $answer = (string)$options[$answerindex - 1];
                } else {
                    // Accept a form opened before this hotfix was deployed.
                    $answer = trim(clean_param((string)$submitted, PARAM_TEXT));
                    $answerindex = array_search($answer, $options, true);
                    $answerindex = $answerindex === false ? false : $answerindex + 1;
                }
                if ($answerindex === false || !isset($options[$answerindex - 1])) {
                    throw new \invalid_parameter_exception('Ответьте на все вопросы перед отправкой.');
                }
                // Keep the persisted representation stable for idempotency with
                // submissions made before the UI switched to option indexes.
                $normalized[$index] = $answer;
                if (hash_equals((string)$question['answer'], $answer)) { $correct++; }
            }
            if (!$questions) { throw new \moodle_exception('В аттестации нет вопросов.'); }
            $score = (int)round(100 * $correct / count($questions));
            $attemptkey = 'studio-assessment-v2:' . hash('sha256',
                $userid . ':' . $contentid . ':' . $expectedversion . ':' . json_encode($normalized));
            $result = [
                'contentid' => $contentid, 'userid' => $userid, 'score' => $score,
                'passed' => $score >= (int)$item['passscore'], 'correct' => $correct,
                'total' => count($questions), 'attemptkey' => $attemptkey,
                'sourceversion' => $expectedversion, 'sourcehash' => $blueprint->sourcehash,
                'submittedat' => time(), 'preview' => $preview,
            ];
            if ($preview) { return $result; }
            $existing = self::find_submission($contentid, $attemptkey);
            if ($existing) { return $existing; }
            $DB->insert_record('local_ustar_workflow_events', (object)[
                'entitytype' => 'studio_assessment', 'entityid' => $contentid,
                'eventtype' => 'studio_assessment_submitted', 'actorid' => $userid, 'reason' => null,
                'detailsjson' => json_encode($result + ['answers' => $normalized],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timecreated' => (int)$result['submittedat'],
            ]);
            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Latest persisted learner submission for one exact editable source version.
     * Preview results are never persisted, so they cannot appear here.
     *
     * @return array<string,mixed>|null
     */
    public static function latest_submission_for_user(
        int $contentid,
        int $userid,
        ?int $sourceversion = null
    ): ?array {
        global $DB;
        if ($contentid <= 0 || $userid <= 0 || !self::available()) {
            return null;
        }
        if ($sourceversion === null) {
            $sourceversion = (int)$DB->get_field(
                'local_ustar_content_blueprints',
                'sourceversion',
                ['contentid' => $contentid]
            );
        }
        if ($sourceversion <= 0) {
            return null;
        }

        $events = $DB->get_records('local_ustar_workflow_events', [
            'entitytype' => 'studio_assessment',
            'entityid' => $contentid,
            'eventtype' => 'studio_assessment_submitted',
            'actorid' => $userid,
        ], 'id DESC');

        foreach ($events as $event) {
            $data = json_decode((string)$event->detailsjson, true);
            if (!is_array($data) || (int)($data['sourceversion'] ?? 0) !== $sourceversion) {
                continue;
            }
            unset($data['answers']);
            return $data;
        }
        return null;
    }

    /**
     * Route completion fact for a Studio Assessment.
     *
     * Only a passed persisted submission of the requested source version is
     * completion evidence. A failed attempt remains visible to the route as a
     * failure but cannot close the point.
     *
     * @return array<string,mixed>|null
     */
    public static function completion_for_user(
        int $contentid,
        int $userid,
        ?int $sourceversion = null
    ): ?array {
        $submission = self::latest_submission_for_user($contentid, $userid, $sourceversion);
        return $submission && !empty($submission['passed']) ? $submission : null;
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
        $author = self::can_manage($viewerid);
        $questionslines = $author ? self::questions_to_lines($questions) : '';
        if (!$author) {
            foreach ($questions as &$question) { unset($question['answer']); }
            unset($question);
        }
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
            'pages' => is_array($source['pages'] ?? null) ? $source['pages'] : [],
            'questions' => $questions, 'questionslines' => $questionslines,
            'passscore' => (int)($source['passscore'] ?? 80),
            'packagestatus' => (string)($row->packagestatus ?? 'none'),
            'packagefilename' => (string)($row->packagefilename ?? ''), 'packageurl' => $packageurl,
            'editable_source' => $author,
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
            $options = array_values(array_filter(array_map(
                static fn(string $item): string => trim(clean_param($item, PARAM_TEXT)),
                $parts
            ), static fn(string $item): bool => $item !== ''));
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
