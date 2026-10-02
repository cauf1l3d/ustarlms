<?php
require_once(__DIR__ . '/../../config.php');

require_login();
if (!\local_ustar\material_studio::can_manage((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(),
        'local/ustar:hrmanage', 'nopermissions', '');
}
$id = required_param('id', PARAM_INT);
$item = \local_ustar\material_studio::by_content($id, (int)$USER->id);
if ((string)$item['kind'] !== \local_ustar\material_studio::KIND_SCORM || !$item['pages']) {
    throw new invalid_parameter_exception('Сначала сохраните страницы SCORM.');
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
// Preview runs in an opaque origin. Its completion button cannot write
// Moodle SCORM attempts, grades or USTAR route evidence.
header('Content-Security-Policy: sandbox allow-scripts');
echo \local_ustar\studio_scorm_package::html($item);
