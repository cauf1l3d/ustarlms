<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER, $OUTPUT;

$context =
    context_system::instance();


$contentid =
    required_param(
        'id',
        PARAM_INT
    );


$canmanage =
    is_siteadmin(
        (int)$USER->id
    )
    ||
    has_capability(
        'local/ustar:hrmanage',
        $context
    )
    ||
    has_capability(
        'local/ustar:admin',
        $context
    );


if (!$canmanage) {
    throw new required_capability_exception(
        $context,
        'local/ustar:hrmanage',
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
    $content->sourcekind
    !==
    \local_ustar\content::SOURCE_FILE
) {
    throw new moodle_exception(
        'Новые версии доступны только для USTAR File'
    );
}


$current =
    \local_ustar\content::current_version(
        $contentid
    );


if (!$current) {
    throw new moodle_exception(
        'Текущая версия отсутствует'
    );
}


$PAGE->set_context(
    $context
);

$PAGE->set_url(
    new moodle_url(
        '/local/ustar/material_version.php',
        [
            'id' =>
                $contentid,
        ]
    )
);

$PAGE->set_pagelayout(
    'ustar'
);

$PAGE->set_title(
    'Новая версия | Центр управления USTAR'
);

$PAGE->set_heading(
    'Центр управления USTAR'
);


$mform =
    new \local_ustar\form\material_version(
        new moodle_url(
            '/local/ustar/material_version.php',
            [
                'id' =>
                    $contentid,

                'theme' =>
                    'ustar',
            ]
        ),
        [
            'contentid' =>
                $contentid,
        ]
    );


if ($mform->is_cancelled()) {

    redirect(
        new moodle_url(
            '/local/ustar/materials.php',
            [
                'contentid' =>
                    $contentid,

                'theme' =>
                    'ustar',
            ]
        )
    );
}


if ($data = $mform->get_data()) {

    $created =
        null;


    try {

        $created =
            \local_ustar\content_admin::create_draft_file_version(
                $contentid,
                (int)$USER->id,
                (string)$data->changenote
            );


        $storedfile =
            $mform->save_stored_file(
                'contentfile',
                $context->id,
                'local_ustar',
                'content_version',
                $created['versionid'],
                '/',
                null,
                false,
                (int)$USER->id
            );


        if (!$storedfile) {
            throw new moodle_exception(
                'Не удалось сохранить файл новой версии'
            );
        }


        redirect(
            new moodle_url(
                '/local/ustar/materials.php',
                [
                    'contentid' =>
                        $contentid,

                    'status' =>
                        'all',

                    'theme' =>
                        'ustar',
                ]
            ),
            'Новая версия создана как черновик',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );


    } catch (\Throwable $e) {

        if (
            $created
            &&
            !empty(
                $created['versionid']
            )
        ) {

            try {

                \local_ustar\content_admin::discard_draft_file_version(
                    (int)$created['versionid'],
                    (int)$USER->id
                );

            } catch (\Throwable $cleanup) {
                // Preserve original exception.
            }
        }


        \core\notification::error(
            $e->getMessage()
        );
    }
}


echo $OUTPUT->header();
?>

<div class="u-create-material">

    <header class="u-create-material__head">

        <a
            href="<?=
                (new moodle_url(
                    '/local/ustar/materials.php',
                    [
                        'contentid' =>
                            $contentid,

                        'theme' =>
                            'ustar',
                    ]
                ))->out(false)
            ?>"
            class="u-create-material__back"
        >
            ← Материал
        </a>

        <p class="u-pagehead__eyebrow">
            USTAR Versioning
        </p>

        <h1>
            Новая версия
        </h1>

        <p>
            <?= s($content->title) ?>
            · текущая <?= s($current->versionlabel) ?>
        </p>

    </header>


    <div class="u-create-material__card">

        <?php
        $mform->display();
        ?>

    </div>


    <aside class="u-create-material__note">

        <strong>
            Текущая версия останется доступна сотрудникам.
        </strong>

        <span>
            Новый файл станет активным только после отдельной
            публикации новой версии.
        </span>

    </aside>

</div>

<?php
echo $OUTPUT->footer();
