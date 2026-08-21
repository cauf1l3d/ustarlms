<?php

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

global $DB, $USER;

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


$versionid =
    required_param(
        'versionid',
        PARAM_INT
    );


$action =
    required_param(
        'action',
        PARAM_ALPHANUMEXT
    );


$version =
    $DB->get_record(
        'local_ustar_content_versions',
        [
            'id' =>
                $versionid,
        ],
        '*',
        MUST_EXIST
    );


try {

    if ($action === 'publish') {

        \local_ustar\content_admin::publish_file_version(
            $versionid,
            (int)$USER->id
        );


        redirect(
            new moodle_url(
                '/local/ustar/materials.php',
                [
                    'contentid' =>
                        $version->contentid,

                    'status' =>
                        'all',

                    'theme' =>
                        'ustar',
                ]
            ),
            'Новая версия опубликована',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }


    if ($action === 'discard') {

        \local_ustar\content_admin::discard_draft_file_version(
            $versionid,
            (int)$USER->id
        );


        redirect(
            new moodle_url(
                '/local/ustar/materials.php',
                [
                    'contentid' =>
                        $version->contentid,

                    'status' =>
                        'all',

                    'theme' =>
                        'ustar',
                ]
            ),
            'Черновик версии удалён',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }


    throw new invalid_parameter_exception(
        'Неизвестное действие'
    );


} catch (\Throwable $e) {

    \core\notification::error(
        $e->getMessage()
    );


    redirect(
        new moodle_url(
            '/local/ustar/materials.php',
            [
                'contentid' =>
                    $version->contentid,

                'status' =>
                    'all',

                'theme' =>
                    'ustar',
            ]
        )
    );
}
