<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER, $OUTPUT;

$context = context_system::instance();

$canmanage =
    is_siteadmin((int)$USER->id)
    || has_capability('local/ustar:hrmanage', $context)
    || has_capability('local/ustar:admin', $context);

if (!$canmanage) {
    throw new required_capability_exception(
        $context,
        'local/ustar:hrmanage',
        'nopermissions',
        ''
    );
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/material_bulk.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Массовая загрузка | Центр управления USTAR');
$PAGE->set_heading('Центр управления USTAR');

$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);

$departmentoptions = [];
foreach ($structure['departments'] ?? [] as $department) {
    $id = trim((string)($department['id'] ?? ''));
    if ($id === '') {
        continue;
    }
    $departmentoptions[$id] = (string)($department['name'] ?? $id);
}

$positionoptions = [];
foreach ($structure['positions'] ?? [] as $position) {
    $id = trim((string)($position['id'] ?? ''));
    if ($id === '') {
        continue;
    }
    $label = (string)($position['name'] ?? $id);
    $departmentid = trim((string)($position['department'] ?? ''));
    if ($departmentid !== '' && isset($departmentoptions[$departmentid])) {
        $label .= ' · ' . $departmentoptions[$departmentid];
    }
    $positionoptions[$id] = $label;
}

$mform = new \local_ustar\form\material_bulk(
    null,
    [
        'categories' => \local_ustar\content_admin::categories(),
        'departments' => $departmentoptions,
        'positions' => $positionoptions,
    ]
);

$draftitemid = file_get_submitted_draft_itemid('files');
$mform->set_data((object)['files' => $draftitemid]);

if ($mform->is_cancelled()) {
    redirect(
        new moodle_url('/local/ustar/materials.php', ['theme' => 'ustar'])
    );
}

$report = null;

if ($data = $mform->get_data()) {
    require_sesskey();

    $usercontext = context_user::instance((int)$USER->id);
    $fs = get_file_storage();

    $draftfiles = $fs->get_area_files(
        $usercontext->id,
        'user',
        'draft',
        (int)$data->files,
        'filename ASC, id ASC',
        false
    );

    $success = [];
    $skipped = [];
    $failed = [];

    foreach ($draftfiles as $draftfile) {
        if ($draftfile->is_directory()) {
            continue;
        }

        $filename = (string)$draftfile->get_filename();
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $title = trim((string)preg_replace('/[_\-]+/u', ' ', $basename));
        $title = trim((string)preg_replace('/\s+/u', ' ', $title));
        $title = clean_param($title !== '' ? $title : $filename, PARAM_TEXT);

        if (!empty($data->skipduplicates)) {
            $duplicate = $DB->record_exists(
                'local_ustar_content',
                [
                    'title' => $title,
                    'sourcekind' => \local_ustar\content::SOURCE_FILE,
                ]
            );

            if ($duplicate) {
                $skipped[] = [
                    'filename' => $filename,
                    'reason' => 'Уже есть USTAR File с таким названием',
                ];
                continue;
            }
        }

        $created = null;

        try {
            $created = \local_ustar\content_admin::create_file_material(
                [
                    'title' => $title,
                    'summary' => (string)($data->summary ?? ''),
                    'category' => (string)($data->category ?? ''),
                    'ackrequired' => !empty($data->ackrequired),
                ],
                (int)$USER->id
            );

            $newfile = $fs->create_file_from_storedfile(
                [
                    'contextid' => $context->id,
                    'component' => 'local_ustar',
                    'filearea' => 'content_version',
                    'itemid' => (int)$created['versionid'],
                    'filepath' => '/',
                    'filename' => $filename,
                    'userid' => (int)$USER->id,
                    'timecreated' => time(),
                    'timemodified' => time(),
                ],
                $draftfile
            );

            \local_ustar\content_admin::finalize_file_material(
                (int)$created['contentid'],
                $newfile,
                (int)$USER->id
            );

            \local_ustar\content_admin::save(
                (int)$created['contentid'],
                [
                    'title' => $title,
                    'summary' => (string)($data->summary ?? ''),
                    'category' => (string)($data->category ?? ''),
                    'ackrequired' => !empty($data->ackrequired),
                    'expectedmodified' => (int)$DB->get_field(
                        'local_ustar_content',
                        'timemodified',
                        ['id' => (int)$created['contentid']],
                        MUST_EXIST
                    ),
                    'accessmode' => (string)($data->accessmode ?? 'custom'),
                    'positions' => is_array($data->positions ?? null) ? $data->positions : [],
                    'departments' => is_array($data->departments ?? null) ? $data->departments : [],
                ],
                (int)$USER->id
            );

            $published = false;
            if (!empty($data->publishnow)) {
                \local_ustar\content_admin::publish(
                    (int)$created['contentid'],
                    (int)$USER->id
                );
                $published = true;
            }

            $success[] = [
                'contentid' => (int)$created['contentid'],
                'filename' => $filename,
                'title' => $title,
                'published' => $published,
                'url' => (new moodle_url(
                    '/local/ustar/materials.php',
                    [
                        'contentid' => (int)$created['contentid'],
                        'status' => 'all',
                        'theme' => 'ustar',
                    ]
                ))->out(false),
            ];

        } catch (\Throwable $e) {
            if ($created && !empty($created['contentid'])) {
                try {
                    \local_ustar\content_admin::discard_new_file_material(
                        (int)$created['contentid'],
                        (int)$USER->id
                    );
                } catch (\Throwable $cleanup) {
                    // Preserve original failure in the report.
                }
            }

            $failed[] = [
                'filename' => $filename,
                'reason' => $e->getMessage(),
            ];
        }
    }

    // Draft upload area is temporary; imported files now live in content_version.
    $fs->delete_area_files(
        $usercontext->id,
        'user',
        'draft',
        (int)$data->files
    );

    $report = [
        'success' => $success,
        'skipped' => $skipped,
        'failed' => $failed,
        'successcount' => count($success),
        'skippedcount' => count($skipped),
        'failedcount' => count($failed),
        'totalcount' => count($success) + count($skipped) + count($failed),
    ];

    // Reset the file manager after processing so browser refresh does not re-import drafts.
    $draftitemid = file_get_unused_draft_itemid();
    $mform->set_data((object)['files' => $draftitemid]);
}

echo $OUTPUT->header();
?>

<div class="u-create-material u-material-bulk">

    <header class="u-create-material__head">
        <a
            href="<?= (new moodle_url('/local/ustar/materials.php', ['theme' => 'ustar']))->out(false) ?>"
            class="u-create-material__back"
        >
            ← Материалы
        </a>

        <p class="u-pagehead__eyebrow">USTAR Content</p>
        <h1>Массовая загрузка</h1>
        <p>
            Перетащите нормативные документы одним пакетом. Каждый файл станет отдельным материалом USTAR с собственной версией v1.
        </p>
    </header>

    <?php if ($report !== null): ?>
    <section class="u-bulk-report">
        <div class="u-bulk-report__metrics">
            <div><strong><?= (int)$report['successcount'] ?></strong><span>создано</span></div>
            <div><strong><?= (int)$report['skippedcount'] ?></strong><span>пропущено</span></div>
            <div><strong><?= (int)$report['failedcount'] ?></strong><span>ошибок</span></div>
        </div>

        <?php if (!empty($report['success'])): ?>
        <div class="u-bulk-report__group">
            <h2>Создано</h2>
            <?php foreach ($report['success'] as $row): ?>
                <a class="u-bulk-report__row" href="<?= s($row['url']) ?>">
                    <span><?= s($row['title']) ?></span>
                    <small><?= $row['published'] ? 'Опубликован' : 'Черновик' ?></small>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($report['skipped'])): ?>
        <div class="u-bulk-report__group">
            <h2>Пропущено</h2>
            <?php foreach ($report['skipped'] as $row): ?>
                <div class="u-bulk-report__row">
                    <span><?= s($row['filename']) ?></span>
                    <small><?= s($row['reason']) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($report['failed'])): ?>
        <div class="u-bulk-report__group u-bulk-report__group--error">
            <h2>Не удалось импортировать</h2>
            <?php foreach ($report['failed'] as $row): ?>
                <div class="u-bulk-report__row">
                    <span><?= s($row['filename']) ?></span>
                    <small><?= s($row['reason']) ?></small>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <div class="u-create-material__card">
        <?php $mform->display(); ?>
    </div>

    <aside class="u-create-material__note">
        <strong>Рекомендуемый режим для первой загрузки нормативной базы: черновики.</strong>
        <span>
            После импорта быстро проверьте названия и аудиторию. Для документов, обязательных к ознакомлению, включайте подтверждение и публикуйте только после проверки доступа.
        </span>
    </aside>

</div>

<?php
echo $OUTPUT->footer();
