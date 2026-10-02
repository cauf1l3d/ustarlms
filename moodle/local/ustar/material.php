<?php

define('USTAR_MATERIAL_DETAIL_PAGE', true);

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB;

$id = required_param('id', PARAM_INT);

$content = $DB->get_record(
    'local_ustar_content',
    ['id' => $id],
    'id,parentid,type',
    MUST_EXIST
);

if ((string)$content->type === 'folder') {
    redirect(
        new moodle_url(
            '/local/ustar/materials.php',
            ['parent' => $id]
        )
    );
}

/*
 * materials.php remains the single business-logic source.
 * We only provide its selected material and real parent folder.
 */
$_GET['contentid'] = $id;
$_REQUEST['contentid'] = $id;

$_GET['parent'] = (int)($content->parentid ?? 0);
$_REQUEST['parent'] = (int)($content->parentid ?? 0);

require(__DIR__ . '/materials.php');
