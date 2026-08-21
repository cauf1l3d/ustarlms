<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER;

$contentid =
    required_param(
        'id',
        PARAM_INT
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
    !\local_ustar\content::can_access_record(
        $content,
        (int)$USER->id
    )
) {
    throw new required_capability_exception(
        context_system::instance(),
        'local/ustar:use',
        'nopermissions',
        ''
    );
}


/*
 * Viewer currently owns USTAR File content.
 */
if (
    $content->sourcekind
    !==
    \local_ustar\content::SOURCE_FILE
) {

    $url =
        \local_ustar\content::open_url(
            $contentid,
            (int)$USER->id
        );

    if (!$url) {
        throw new moodle_exception(
            'Материал сейчас невозможно открыть'
        );
    }

    redirect($url);
}


$version =
    \local_ustar\content::current_version(
        $contentid
    );


if (!$version) {
    throw new moodle_exception(
        'У материала отсутствует текущая версия'
    );
}


if (
    !\local_ustar\content::can_access_version(
        (int)$version->id,
        (int)$USER->id
    )
) {
    throw new required_capability_exception(
        context_system::instance(),
        'local/ustar:use',
        'nopermissions',
        ''
    );
}


/*
 * ------------------------------------------------------------
 * USTAR_CONTENT_ACK_ACTION
 * ------------------------------------------------------------
 *
 * Acknowledgement is always version-specific.
 * POST -> redirect prevents accidental form resubmission.
 */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    require_sesskey();
    \local_ustar\view_as::assert_writable();


    $action =
        required_param(
            'action',
            PARAM_ALPHANUMEXT
        );


    $postedcontentid =
        required_param(
            'contentid',
            PARAM_INT
        );


    if (
        $action !== 'acknowledge'
        ||
        $postedcontentid !== $contentid
    ) {
        throw new invalid_parameter_exception(
            'Некорректное действие'
        );
    }


    \local_ustar\content::acknowledge(
        $contentid,
        (int)$USER->id
    );


    redirect(
        new moodle_url(
            '/local/ustar/view.php',
            [
                'id' =>
                    $contentid,

                'view' =>
                    'knowledge',

                'theme' =>
                    'ustar',
            ]
        ),
        'Ознакомление подтверждено',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}


/*
 * Current employee acknowledgement.
 */
$ackrequired =
    !empty(
        $content->ackrequired
    );


$ackrecord = null;


if ($ackrequired) {

    $ackrecord =
        $DB->get_record(
            'local_ustar_content_ack',
            [
                'userid' =>
                    (int)$USER->id,

                'versionid' =>
                    (int)$version->id,
            ]
        );
}


$acked =
    !empty(
        $ackrecord
    );


$acktimeformatted = '';


if ($ackrecord) {

    $acktimeformatted =
        userdate(
            (int)$ackrecord->acktime,
            get_string(
                'strftimedatetimeshort',
                'langconfig'
            )
        );
}


$context =
    context_system::instance();


$files =
    get_file_storage()
        ->get_area_files(
            $context->id,
            'local_ustar',
            'content_version',
            $version->id,
            'sortorder DESC, id ASC',
            false
        );


if (!$files) {
    throw new moodle_exception(
        'Файл материала отсутствует'
    );
}


$file =
    reset($files);


$fileurl =
    moodle_url::make_pluginfile_url(
        $context->id,
        'local_ustar',
        'content_version',
        $version->id,
        $file->get_filepath(),
        $file->get_filename(),
        false
    );


$mimetype =
    (string)$file->get_mimetype();


$ishtml =
    $mimetype === 'text/html';

$ispdf =
    $mimetype === 'application/pdf';

$isimage =
    str_starts_with(
        $mimetype,
        'image/'
    )
    &&
    $mimetype !== 'image/svg+xml';

$isvideo =
    str_starts_with(
        $mimetype,
        'video/'
    );


$iselevated =
    \local_ustar\content::is_elevated(
        (int)$USER->id
    );


$returnurl =
    $iselevated
        ? new moodle_url(
            '/local/ustar/materials.php',
            [
                'contentid' =>
                    $contentid,
            ]
        )
        : new moodle_url(
            '/local/ustar/knowledge.php',
            [
                'view' =>
                    'knowledge',
            ]
        );


$PAGE->set_context(
    $context
);

$PAGE->set_url(
    new moodle_url(
        '/local/ustar/view.php',
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
    format_string(
        $content->title
    )
    .
    ' | USTAR'
);

$PAGE->set_heading(
    'USTAR Academy'
);


$output =
    $PAGE->get_renderer(
        'local_ustar'
    );


$data = [

    'id' =>
        $contentid,

    'title' =>
        format_string(
            $content->title
        ),

    'category' =>
        (string)$content->category,

    'filename' =>
        $file->get_filename(),

    'mimetype' =>
        $mimetype,

    'filesize' =>
        display_size(
            $file->get_filesize()
        ),

    'versionlabel' =>
        $version->versionlabel
        ?: 'v'
            .
            $version->versionno,

    'fileurl' =>
        $fileurl->out(false),

    'returnurl' =>
        $returnurl->out(false),

    'ishtml' =>
        $ishtml,

    'ispdf' =>
        $ispdf,

    'isimage' =>
        $isimage,

    'isvideo' =>
        $isvideo,

    'isgeneric' =>
        !$ishtml
        &&
        !$ispdf
        &&
        !$isimage
        &&
        !$isvideo,

    'ackrequired' =>
        $ackrequired,

    'acked' =>
        $acked,

    'needsack' =>
        $ackrequired
        &&
        !$acked,

    'acktimeformatted' =>
        $acktimeformatted,

    'sesskey' =>
        sesskey(),
];


echo $output->header();

echo $output->render_from_template(
    'local_ustar/content_view',
    $data
);

echo $output->footer();
