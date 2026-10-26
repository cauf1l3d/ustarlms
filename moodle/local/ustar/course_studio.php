<?php
require_once(__DIR__ . '/../../config.php');
require_login();
\local_ustar\course_studio::require_author((int)$USER->id);
$base = $CFG->wwwroot . '/local/ustar/';
$config = ['api'=>$base.'course_studio_api.php','sesskey'=>sesskey(),'userId'=>(string)$USER->id,
    'cache'=>'ustar-studio-'.substr(hash('sha256',$CFG->wwwroot),0,16).'-'.$USER->id,
    'material'=>$base.'material.php'];
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' blob: data:; font-src 'self' blob: data:; connect-src 'self'; frame-src 'self' blob:; object-src 'none'; base-uri 'none'; frame-ancestors 'self'");
echo strtr(file_get_contents(__DIR__.'/course_studio/editor.html'),[
    '__USTAR_CONFIG__'=>json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES),
    '__USTAR_ASSETS__'=>s($base.'course_studio'), '__USTAR_LOGIN__'=>s($CFG->wwwroot.'/login/index.php'),
    '__USTAR_MATERIALS__'=>s($base.'materials.php'),
]);
