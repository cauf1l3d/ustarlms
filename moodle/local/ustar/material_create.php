<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER, $OUTPUT;

$context =
    context_system::instance();


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


$PAGE->set_context(
    $context
);

$PAGE->set_url(
    new moodle_url(
        '/local/ustar/material_create.php'
    )
);

$PAGE->set_pagelayout(
    'ustar'
);

$PAGE->set_title(
    'Новый материал | Центр управления USTAR'
);

$PAGE->set_heading(
    'Центр управления USTAR'
);


$mform =
    new \local_ustar\form\material_create(
        null,
        [
            'categories' =>
                \local_ustar\content_admin::categories(),
        ]
    );


if ($mform->is_cancelled()) {

    redirect(
        new moodle_url(
            '/local/ustar/materials.php',
            [
                'theme' =>
                    'ustar',
            ]
        )
    );
}


if ($data = $mform->get_data()) {

    $created = null;


    try {

        /*
         * First create the catalog/version IDs because the version ID
         * is the permanent File API itemid.
         */
        $created =
            \local_ustar\content_admin::create_file_material(
                [
                    'title' =>
                        $data->title,

                    'summary' =>
                        $data->summary,

                    'category' =>
                        $data->category,

                    'ackrequired' =>
                        !empty(
                            $data->ackrequired
                        ),
                ],
                (int)$USER->id
            );


        /*
         * Moodle moves/copies the selected draft file into our
         * permanent File API bucket.
         */
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
                'Не удалось сохранить загруженный файл'
            );
        }


        \local_ustar\content_admin::finalize_file_material(
            (int)$created['contentid'],
            $storedfile,
            (int)$USER->id
        );


        redirect(
            new moodle_url(
                '/local/ustar/materials.php',
                [
                    'contentid' =>
                        (int)$created['contentid'],

                    'status' =>
                        'all',

                    'theme' =>
                        'ustar',
                ]
            ),
            'Материал создан как черновик',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );


    } catch (\Throwable $e) {

        if (
            $created
            &&
            !empty(
                $created['contentid']
            )
        ) {

            try {

                \local_ustar\content_admin::discard_new_file_material(
                    (int)$created['contentid'],
                    (int)$USER->id
                );

            } catch (\Throwable $cleanup) {
                // Preserve the original upload exception.
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
                    ['theme' => 'ustar']
                ))->out(false)
            ?>"
            class="u-create-material__back"
        >
            ← Материалы
        </a>

        <p class="u-pagehead__eyebrow">
            USTAR Content
        </p>

        <h1>
            Новый материал
        </h1>

        <p>
            Загрузите файл напрямую в USTAR.
            Moodle-курс для него не создаётся.
        </p>

    </header>


    <div class="u-create-material__card">

        <?php
        $mform->display();
        ?>

    </div>


    <aside class="u-create-material__note">

        <strong>
            После создания материал останется черновиком.
        </strong>

        <span>
            На следующем экране вы настроите доступ
            по должностям или подразделениям и отдельно
            опубликуете его.
        </span>

    </aside>

</div>

<?php
echo $OUTPUT->footer();
