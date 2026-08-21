<?php
require_once(__DIR__ . '/../../config.php');

require_login();
global $USER, $DB;

$context = context_system::instance();
require_capability('local/ustar:admin', $context);

$gameid = optional_param('id', 0, PARAM_INT);
$newmode = optional_param('new', 0, PARAM_BOOL);
$saved = optional_param('saved', 0, PARAM_BOOL);

$saveerrors = [];
$submittedcurrent = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();

    $gameid = optional_param('id', 0, PARAM_INT);
    $questions = [];
    $questionslots = [];
    $validateduploads = [];

    // RC10.6: discover submitted question cards from their field names.
    // Do not trust a hidden question count: an existing game's display counter used to
    // shadow that form value and caused only the first existing question to be processed.
    $submittedslots = [];
    foreach (array_keys($_POST) as $key) {
        if (preg_match('/^q_text_(\d{1,2})$/', (string)$key, $matches)) {
            $slot = (int)$matches[1];
            if ($slot >= 0 && $slot < 50) {
                $submittedslots[$slot] = true;
            }
        }
    }
    foreach (array_keys($_FILES) as $key) {
        if (preg_match('/^q_imagefile_(\d{1,2})$/', (string)$key, $matches)) {
            $slot = (int)$matches[1];
            if ($slot >= 0 && $slot < 50) {
                $submittedslots[$slot] = true;
            }
        }
    }
    ksort($submittedslots, SORT_NUMERIC);

    $title = trim((string)optional_param('title', '', PARAM_TEXT));
    $codeinput = trim((string)optional_param('code', '', PARAM_RAW));
    $type = trim((string)optional_param('type', 'quiz', PARAM_RAW));
    $department = trim((string)optional_param('department', '', PARAM_RAW));
    $description = (string)optional_param('description', '', PARAM_TEXT);
    $difficulty = max(1, min(5, optional_param('difficulty', 1, PARAM_INT)));
    $active = optional_param('active', 0, PARAM_BOOL);

    if ($title === '') {
        $saveerrors[] = 'Укажите название игры.';
    }

    $allowedtypes = ['quiz', 'image_quiz', 'trick_quiz', 'scenario'];
    if (!in_array($type, $allowedtypes, true)) {
        $saveerrors[] = 'Выбран неподдерживаемый тип игры.';
        $type = 'quiz';
    }

    $structureforsave = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
    $validdepartments = ['' => true];
    foreach (($structureforsave['departments'] ?? []) as $departmentrow) {
        $validdepartments[(string)($departmentrow['id'] ?? '')] = true;
    }
    if (!isset($validdepartments[$department])) {
        $saveerrors[] = 'Выбрано неизвестное подразделение. Обновите страницу и выберите аудиторию заново.';
        $department = '';
    }

    if ($codeinput === '') {
        $code = 'game_' . gmdate('Ymd_His') . '_' . substr(sha1((string)$USER->id . ':' . microtime(true)), 0, 6);
    } else {
        $code = clean_param($codeinput, PARAM_ALPHANUMEXT);
        if ($code === '' || $code !== $codeinput) {
            $saveerrors[] = 'Код игры может содержать только латинские буквы, цифры, дефис и подчёркивание. Либо оставьте поле пустым — код будет создан автоматически.';
        }
    }
    if ($code !== '') {
        $duplicate = $DB->get_record('local_ustar_games', ['code' => $code], 'id,code');
        if ($duplicate && (int)$duplicate->id !== $gameid) {
            $saveerrors[] = 'Код игры «' . $code . '» уже используется. Укажите другой код или оставьте поле пустым для автоматической генерации.';
        }
    }

    $allowedimages = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $maximagesize = 5 * 1024 * 1024;

    foreach (array_keys($submittedslots) as $i) {
        $text = trim((string)optional_param('q_text_' . $i, '', PARAM_TEXT));
        $questionid = optional_param('q_id_' . $i, 0, PARAM_INT);

        // Empty new cards are intentionally ignored. An existing question whose text is
        // cleared is omitted from the payload and will be unpublished by admin_save_game.
        if ($text === '') {
            continue;
        }

        $options = [
            trim((string)optional_param('q_o0_' . $i, '', PARAM_TEXT)),
            trim((string)optional_param('q_o1_' . $i, '', PARAM_TEXT)),
            trim((string)optional_param('q_o2_' . $i, '', PARAM_TEXT)),
            trim((string)optional_param('q_o3_' . $i, '', PARAM_TEXT)),
        ];
        foreach ($options as $optionindex => $optiontext) {
            if ($optiontext === '') {
                $saveerrors[] = 'Вопрос ' . ($i + 1) . ': заполните вариант ответа ' . chr(65 + $optionindex) . '.';
            }
        }

        $correctoption = optional_param('q_correct_' . $i, 0, PARAM_INT);
        if ($correctoption < 0 || $correctoption > 3) {
            $saveerrors[] = 'Вопрос ' . ($i + 1) . ': выберите правильный вариант A–D.';
            $correctoption = 0;
        }

        $submittedurl = trim((string)optional_param('q_image_' . $i, '', PARAM_RAW));
        if ($submittedurl !== '') {
            $ishttpurl = preg_match('~^https?://~i', $submittedurl) === 1
                && filter_var($submittedurl, FILTER_VALIDATE_URL) !== false;
            if (!$ishttpurl) {
                $saveerrors[] = 'Вопрос ' . ($i + 1) . ': URL изображения должен начинаться с http:// или https://.';
            }
        }

        $uploadkey = 'q_imagefile_' . $i;
        if (isset($_FILES[$uploadkey]) && is_array($_FILES[$uploadkey])) {
            $upload = $_FILES[$uploadkey];
            $uploaderror = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploaderror !== UPLOAD_ERR_NO_FILE) {
                if ($uploaderror !== UPLOAD_ERR_OK) {
                    $saveerrors[] = 'Вопрос ' . ($i + 1) . ': файл изображения не принят сервером (код загрузки ' . $uploaderror . ').';
                } else {
                    $uploadsize = (int)($upload['size'] ?? 0);
                    $tmpname = (string)($upload['tmp_name'] ?? '');
                    if ($uploadsize <= 0 || $uploadsize > $maximagesize) {
                        $saveerrors[] = 'Вопрос ' . ($i + 1) . ': изображение должно быть не больше 5 МБ.';
                    } else if ($tmpname === '' || !is_uploaded_file($tmpname)) {
                        $saveerrors[] = 'Вопрос ' . ($i + 1) . ': временный файл изображения недоступен.';
                    } else {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mimetype = (string)$finfo->file($tmpname);
                        if (!isset($allowedimages[$mimetype])) {
                            $saveerrors[] = 'Вопрос ' . ($i + 1) . ': допустимы только JPG, PNG, WEBP и GIF.';
                        } else {
                            $validateduploads[$i] = [
                                'tmp_name' => $tmpname,
                                'mimetype' => $mimetype,
                                'extension' => $allowedimages[$mimetype],
                                'source' => clean_param((string)($upload['name'] ?? ''), PARAM_FILE),
                            ];
                        }
                    }
                }
            }
        }

        $questionslots[] = $i;
        $questions[] = [
            'id' => $questionid,
            'question' => $text,
            'imageUrl' => $submittedurl,
            'options' => $options,
            'correctOption' => $correctoption,
            'explanation' => optional_param('q_explanation_' . $i, '', PARAM_TEXT),
            'xpReward' => optional_param('q_xp_' . $i, 25, PARAM_INT),
            'active' => optional_param('q_active_' . $i, 0, PARAM_BOOL),
        ];
    }

    $payloadtosave = [
        'id' => $gameid,
        'code' => $code,
        'title' => $title,
        'description' => $description,
        'type' => $type,
        'department' => $department,
        'difficulty' => $difficulty,
        'active' => $active,
        'questions' => $questions,
    ];

    if (!$saveerrors) {
        try {
            $result = \local_ustar\native_data::save_game($payloadtosave);
            $savedgameid = (int)($result['gameid'] ?? $gameid);
            $questionids = array_values(array_map('intval', $result['questionids'] ?? []));

            // Never silently accept a partial multi-question save.
            if (count($questionids) !== count($questions)) {
                throw new \coding_exception(
                    'USTAR Game Studio expected ' . count($questions) .
                    ' saved question ids, received ' . count($questionids)
                );
            }

            $fs = get_file_storage();
            foreach ($questionslots as $position => $slot) {
                $questionid = (int)($questionids[$position] ?? 0);
                if ($questionid <= 0) {
                    continue;
                }

                $deleteimage = optional_param('q_image_delete_' . $slot, 0, PARAM_BOOL);
                $submittedurl = trim((string)optional_param('q_image_' . $slot, '', PARAM_RAW));
                $hasupload = isset($validateduploads[$slot]);

                if ($hasupload) {
                    $upload = $validateduploads[$slot];
                    $fs->delete_area_files($context->id, 'local_ustar', 'game_question_image', $questionid);
                    $filename = 'question-' . $questionid . '.' . $upload['extension'];
                    $filerecord = [
                        'contextid' => $context->id,
                        'component' => 'local_ustar',
                        'filearea' => 'game_question_image',
                        'itemid' => $questionid,
                        'filepath' => '/',
                        'filename' => $filename,
                        'source' => $upload['source'],
                        'author' => fullname($USER),
                        'license' => 'allrightsreserved',
                    ];
                    $fs->create_file_from_pathname($filerecord, $upload['tmp_name']);
                    $imageurl = moodle_url::make_pluginfile_url(
                        $context->id,
                        'local_ustar',
                        'game_question_image',
                        $questionid,
                        '/',
                        $filename,
                        false
                    )->out(false);
                    $DB->set_field(
                        'local_ustar_questions',
                        'imageurl',
                        $imageurl,
                        ['id' => $questionid, 'gameid' => $savedgameid]
                    );
                    continue;
                }

                if ($deleteimage) {
                    $fs->delete_area_files($context->id, 'local_ustar', 'game_question_image', $questionid);
                    $DB->set_field(
                        'local_ustar_questions',
                        'imageurl',
                        '',
                        ['id' => $questionid, 'gameid' => $savedgameid]
                    );
                    continue;
                }

                $storedfiles = $fs->get_area_files(
                    $context->id,
                    'local_ustar',
                    'game_question_image',
                    $questionid,
                    'id',
                    false
                );
                if ($storedfiles && ($submittedurl === '' || strpos($submittedurl, '/pluginfile.php/') === false)) {
                    $fs->delete_area_files($context->id, 'local_ustar', 'game_question_image', $questionid);
                }
            }

            redirect(new moodle_url('/local/ustar/game_studio.php', [
                'id' => $savedgameid,
                'saved' => 1,
            ]));
        } catch (\invalid_parameter_exception $e) {
            // Convert service-layer validation to an editor error instead of Moodle's
            // generic "invalid parameter" page. The submitted text stays on screen.
            $saveerrors[] = 'Игра не сохранена: проверьте код, аудиторию и заполнение всех вариантов ответов.';
        }
    }

    $submittedcurrent = $payloadtosave;
    $newmode = $gameid <= 0;
}

$payload = \local_ustar\native_data::admin_games();
$games = array_values($payload['games'] ?? []);
if ($submittedcurrent === null && !$newmode && $gameid <= 0 && $games) {
    $gameid = (int)$games[0]['id'];
}

$current = null;
foreach ($games as &$game) {
    $game['selected'] = (int)$game['id'] === $gameid && $submittedcurrent === null;
    $game['url'] = (new moodle_url('/local/ustar/game_studio.php', ['id' => (int)$game['id']]))->out(false);
    $game['statuslabel'] = !empty($game['active']) ? 'Активна' : 'Выключена';
    // Sidebar display count only. Never reuse this as the number of submitted form cards.
    $game['questioncount'] = count($game['questions'] ?? []);
    if ($game['selected']) {
        $current = $game;
    }
}
unset($game);

if ($submittedcurrent !== null) {
    $current = $submittedcurrent;
}

if (!$current) {
    $current = [
        'id' => 0,
        'code' => '',
        'title' => '',
        'description' => '',
        'type' => 'quiz',
        'department' => '',
        'difficulty' => 1,
        'active' => true,
        'questions' => [],
    ];
}

$typeOptions = [];
foreach ([
    'quiz' => 'Quiz',
    'image_quiz' => 'Image quiz',
    'trick_quiz' => 'Trick quiz',
    'scenario' => 'Scenario',
] as $id => $label) {
    $typeOptions[] = ['id' => $id, 'label' => $label, 'selected' => $current['type'] === $id];
}

$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$departmentOptions = [[
    'id' => '', 'name' => 'Вся компания', 'selected' => empty($current['department']),
]];
foreach (($structure['departments'] ?? []) as $department) {
    $departmentOptions[] = [
        'id' => (string)$department['id'],
        'name' => (string)$department['name'],
        'selected' => (string)$department['id'] === (string)$current['department'],
    ];
}

$questionSlots = [];
$sourceQuestions = array_values($current['questions'] ?? []);
$slotCount = max(count($sourceQuestions) + 3, 5);
$slotCount = min(50, $slotCount);
$current['formslotcount'] = $slotCount;
for ($i = 0; $i < $slotCount; $i++) {
    $question = $sourceQuestions[$i] ?? [
        'id' => 0, 'question' => '', 'imageUrl' => '', 'options' => ['', '', '', ''],
        'correctOption' => 0, 'explanation' => '', 'xpReward' => 25, 'active' => true,
    ];
    $options = array_pad(array_values($question['options'] ?? []), 4, '');
    $correct = (int)($question['correctOption'] ?? 0);
    $questionSlots[] = [
        'index' => $i,
        'number' => $i + 1,
        'id' => (int)($question['id'] ?? 0),
        'text' => (string)($question['question'] ?? ''),
        'imageUrl' => (string)($question['imageUrl'] ?? ''),
        'hasImage' => trim((string)($question['imageUrl'] ?? '')) !== '',
        'o0' => (string)$options[0], 'o1' => (string)$options[1],
        'o2' => (string)$options[2], 'o3' => (string)$options[3],
        'correct0' => $correct === 0, 'correct1' => $correct === 1,
        'correct2' => $correct === 2, 'correct3' => $correct === 3,
        'explanation' => (string)($question['explanation'] ?? ''),
        'xpReward' => (int)($question['xpReward'] ?? 25),
        'active' => array_key_exists('active', $question) ? !empty($question['active']) : true,
        'existing' => !empty($question['id']),
    ];
}

$data = [
    'saved' => $saved,
    'haserrors' => !empty($saveerrors),
    'formerrors' => array_map(static fn(string $message): array => ['message' => $message], $saveerrors),
    'games' => $games,
    'hasgames' => !empty($games),
    'newurl' => (new moodle_url('/local/ustar/game_studio.php', ['new' => 1]))->out(false),
    'current' => $current,
    'isnew' => empty($current['id']),
    'typeoptions' => $typeOptions,
    'departmentoptions' => $departmentOptions,
    'questionslots' => $questionSlots,
    'formslotcount' => $slotCount,
    'sesskey' => sesskey(),
    'gameicon' => \local_ustar\ui::icon('game', 'u-feature-icon'),
    'playurl' => !empty($current['id']) ? (new moodle_url('/local/ustar/game.php', ['gameid' => (int)$current['id']]))->out(false) : '',
    'hasplayurl' => !empty($current['id']),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/game_studio.php', $newmode ? ['new' => 1] : ($gameid ? ['id' => $gameid] : [])));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Редактор игр | Центр управления USTAR');
$PAGE->set_heading('Центр управления USTAR');

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/game_studio', $data);
echo $output->footer();
