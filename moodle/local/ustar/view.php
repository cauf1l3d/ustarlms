<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER;


/*
 * USTAR_DOCX_INLINE_VIEWER_2706
 *
 * Lightweight native DOCX preview:
 * - paragraphs
 * - headings
 * - tables
 *
 * No external document service and no public file URL required.
 */
function local_ustar_docx_preview_2706(
    \stored_file $file
): string {

    if (
        !class_exists('ZipArchive')
        ||
        !class_exists('DOMDocument')
    ) {
        return '';
    }

    $tempdir = make_request_directory();

    $temppath =
        $tempdir
        . DIRECTORY_SEPARATOR
        . 'ustar-preview-'
        . sha1(
            $file->get_contenthash()
        )
        . '.docx';

    if (
        file_put_contents(
            $temppath,
            $file->get_content()
        )
        ===
        false
    ) {
        return '';
    }

    $zip = new \ZipArchive();

    if (
        $zip->open($temppath)
        !==
        true
    ) {
        return '';
    }

    $xml =
        $zip->getFromName(
            'word/document.xml'
        );

    $zip->close();

    if (!$xml) {
        return '';
    }

    $dom = new \DOMDocument();

    $loaded =
        @$dom->loadXML(
            $xml,
            LIBXML_NONET
            |
            LIBXML_NOERROR
            |
            LIBXML_NOWARNING
        );

    if (!$loaded) {
        return '';
    }

    $xpath =
        new \DOMXPath($dom);

    $xpath->registerNamespace(
        'w',
        'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
    );

    $body =
        $xpath->query('//w:body')
        ->item(0);

    if (!$body) {
        return '';
    }

    $out = [];
    $blocks = 0;

    $text_for_node =
        static function(
            \DOMNode $node
        ) use ($xpath): string {

            $parts = [];

            foreach (
                $xpath->query(
                    './/w:t',
                    $node
                ) as $textnode
            ) {
                $parts[] =
                    (string)$textnode->nodeValue;
            }

            return trim(
                implode('', $parts)
            );
        };


    foreach ($body->childNodes as $node) {

        if (
            $node->nodeType
            !==
            XML_ELEMENT_NODE
        ) {
            continue;
        }

        if (++$blocks > 600) {
            $out[] =
                '<p class="u-docx-preview__cut">'
                .
                'Предпросмотр сокращён: документ слишком большой.'
                .
                '</p>';

            break;
        }


        /*
         * Paragraph.
         */
        if (
            $node->localName
            ===
            'p'
        ) {

            $text =
                $text_for_node($node);

            if ($text === '') {
                continue;
            }

            $style = '';

            $stylenode =
                $xpath
                    ->query(
                        './w:pPr/w:pStyle',
                        $node
                    )
                    ->item(0);

            if (
                $stylenode
                &&
                $stylenode
                    ->attributes
                    ->getNamedItemNS(
                        'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
                        'val'
                    )
            ) {
                $style =
                    strtolower(
                        (string)$stylenode
                            ->attributes
                            ->getNamedItemNS(
                                'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
                                'val'
                            )
                            ->nodeValue
                    );
            }

            $escaped =
                htmlspecialchars(
                    $text,
                    ENT_QUOTES
                    |
                    ENT_SUBSTITUTE,
                    'UTF-8'
                );

            if (
                str_contains(
                    $style,
                    'heading1'
                )
                ||
                str_contains(
                    $style,
                    '1'
                )
            ) {
                $out[] =
                    '<h2>'
                    . $escaped
                    . '</h2>';

            } else if (
                str_contains(
                    $style,
                    'heading2'
                )
                ||
                str_contains(
                    $style,
                    '2'
                )
            ) {
                $out[] =
                    '<h3>'
                    . $escaped
                    . '</h3>';

            } else {
                $out[] =
                    '<p>'
                    . $escaped
                    . '</p>';
            }

            continue;
        }


        /*
         * Basic Word table.
         */
        if (
            $node->localName
            ===
            'tbl'
        ) {

            $rows = [];

            foreach (
                $xpath->query(
                    './w:tr',
                    $node
                ) as $rownode
            ) {

                $cells = [];

                foreach (
                    $xpath->query(
                        './w:tc',
                        $rownode
                    ) as $cellnode
                ) {
                    $celltext =
                        $text_for_node(
                            $cellnode
                        );

                    $cells[] =
                        '<td>'
                        .
                        htmlspecialchars(
                            $celltext,
                            ENT_QUOTES
                            |
                            ENT_SUBSTITUTE,
                            'UTF-8'
                        )
                        .
                        '</td>';
                }

                if ($cells) {
                    $rows[] =
                        '<tr>'
                        .
                        implode('', $cells)
                        .
                        '</tr>';
                }
            }

            if ($rows) {
                $out[] =
                    '<div class="u-docx-preview__tablewrap">'
                    .
                    '<table>'
                    .
                    implode('', $rows)
                    .
                    '</table>'
                    .
                    '</div>';
            }
        }
    }

    return implode(
        "\n",
        $out
    );
}



$contentid =
    required_param(
        'id',
        PARAM_INT
    );

$routepointid = optional_param('routepointid', 0, PARAM_INT);
$routeversionid = optional_param('routeversionid', 0, PARAM_INT);
$hasroutecontext = $routepointid > 0 && $routeversionid > 0;


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

    if ($hasroutecontext) {

        /*
         * Record completion against the exact route version
         * that launched this material.
         */
        \local_ustar\learning_events::record_route_studied(
            (int)$USER->id,
            $contentid,
            $routepointid,
            $routeversionid
        );


        /*
         * Immediately reconcile the employee route.
         * If the acknowledged video closed the current point,
         * continue directly to the next launchable point.
         */
        $position =
            \local_ustar\position_access::position_for_user(
                (int)$USER->id
            );

        $positionid =
            is_array($position)
                ? (string)($position['id'] ?? '')
                : '';

        if ($positionid !== '') {

            $route =
                \local_ustar\route_model::for_user(
                    $positionid,
                    (int)$USER->id
                );

            $current =
                $route['currentpoint'] ?? null;

            if (
                $current
                &&
                (int)($current['id'] ?? 0)
                    !==
                    $routepointid
                &&
                !empty($current['canlaunch'])
                &&
                !empty($current['launchurl'])
            ) {
                redirect(
                    (string)$current['launchurl'],
                    'Материал завершён',
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
            }
        }


        redirect(
            new moodle_url(
                '/local/ustar/route.php'
            ),
            'Материал завершён',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }


    /*
     * Normal Knowledge Library acknowledgement.
     */
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


$downloadurl =
    moodle_url::make_pluginfile_url(
        $context->id,
        'local_ustar',
        'content_version',
        $version->id,
        $file->get_filepath(),
        $file->get_filename(),
        true
    );


$mimetype =
    (string)$file->get_mimetype();


$filename =
    strtolower(
        (string)$file->get_filename()
    );

$isdocx =
    $mimetype
    ===
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ||
    str_ends_with(
        $filename,
        '.docx'
    );

$docxhtml =
    $isdocx
        ? local_ustar_docx_preview_2706(
            $file
        )
        : '';

$hasdocxpreview =
    $isdocx
    &&
    trim($docxhtml) !== '';


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
    $hasroutecontext
        ? new moodle_url(
            '/local/ustar/route.php'
        )
        : (
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
                )
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

$contentviewcss =
    __DIR__
    . '/content_view_2706.css';

$PAGE->requires->css(
    new moodle_url(
        '/local/ustar/content_view_2706.css',
        [
            'v' =>
                filemtime(
                    $contentviewcss
                ),
        ]
    )
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

    'hasdocxpreview' =>
        $hasdocxpreview,

    'docxhtml' =>
        $docxhtml,

    'downloadurl' =>
        $downloadurl->out(false),

    'isgeneric' =>
        !$ishtml
        &&
        !$ispdf
        &&
        !$isimage
        &&
        !$isvideo
        &&
        !$hasdocxpreview,

    'ackrequired' =>
        $ackrequired,

    'acked' =>
        $acked,

    'needsack' =>
        $hasroutecontext
        ||
        ($ackrequired && !$acked),

    'acktimeformatted' =>
        $acktimeformatted,

    'sesskey' =>
        sesskey(),

    'hasroutecontext' =>
        $hasroutecontext,

    'routepointid' =>
        $routepointid,

    'routeversionid' =>
        $routeversionid,
];


echo $output->header();

echo $output->render_from_template(
    'local_ustar/content_view',
    $data
);

echo $output->footer();
