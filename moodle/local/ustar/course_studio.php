<?php
require_once(__DIR__ . '/../../config.php');
require_login();
\local_ustar\course_studio::require_author((int)$USER->id);
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/ustar/course_studio.php', ['materialid' => optional_param('materialid', 0, PARAM_INT)]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Студия курсов | Академия USTAR');
$PAGE->set_heading('Студия курсов');
$PAGE->add_body_class('u-course-studio-page');
$base = $CFG->wwwroot . '/local/ustar/';
$materialid=max(0,optional_param('materialid',0,PARAM_INT));
$config = ['materialId'=>$materialid,'api'=>$base.'course_studio_api.php','sesskey'=>sesskey(),'userId'=>(string)$USER->id,
    'cache'=>'ustar-studio-'.substr(hash('sha256',$CFG->wwwroot),0,16).'-'.$USER->id,
    'material'=>$base.'material.php'];
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' blob: data:; font-src 'self' blob: data:; connect-src 'self'; frame-src 'self' blob:; object-src 'none'; base-uri 'none'; frame-ancestors 'self'");
$version=(string)filemtime(__DIR__.'/course_studio/style.css');
$PAGE->requires->css(new moodle_url('/local/ustar/course_studio/style.css', ['v' => $version]));
echo $OUTPUT->header();
if (\local_ustar\view_as::active()) {
    echo $OUTPUT->notification('Для редактирования завершите просмотр от лица сотрудника.', 'info');
    echo html_writer::link(new moodle_url('/local/ustar/materials.php'), 'Вернуться к материалам', ['class' => 'u-btn']);
    echo $OUTPUT->footer();
    exit;
}
// One editor markup for the native page and isolated browser verification.
// Only its body fragment is rendered: Moodle owns the page, navigation and landmarks.
$html=file_get_contents(__DIR__.'/course_studio/editor.html');
if (!preg_match('~<body[^>]*>(.*)</body>~s', $html, $fragment)) {
    throw new coding_exception('Studio editor fragment is missing.');
}
echo strtr($fragment[1],[
    '__USTAR_CONFIG__'=>json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES),
    '__USTAR_ASSETS__'=>s($base.'course_studio'), '__USTAR_LOGIN__'=>s($CFG->wwwroot.'/login/index.php'),
    '__USTAR_MATERIALS__'=>s($base.'materials.php'),
    '__USTAR_VERSION__'=>s($version),
]);
echo $OUTPUT->footer();
