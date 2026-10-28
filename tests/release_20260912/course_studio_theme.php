<?php
// Actual server ZIP builder; no Moodle bootstrap, database or File API assets.
define('MOODLE_INTERNAL',true);
class invalid_parameter_exception extends Exception {}
require __DIR__.'/../../moodle/local/ustar/classes/course_studio_project.php';
require __DIR__.'/../../moodle/local/ustar/classes/course_studio_package.php';
use local_ustar\course_studio_project as codec;
use local_ustar\course_studio_package as package;
function check(bool $ok,string $why): void { if (!$ok) { throw new RuntimeException($why); } }
function fixture(): stdClass {
    return json_decode('{"data":{"code":"COLORS","title":"Оформление","pages":[{"id":"one","title":"Экран","html":"<p>Материал</p>"}]},"assets":{},"fonts":[],"theme":{"font":"Arial","headingFont":"Arial","fontSize":17,"headingSize":34,"lineHeight":1.65,"ink":"#2b2b2b","accent":"#ebc500","paper":"#ffffff","background":"#ededed"},"catalogue":{},"revision":0}');
}
foreach (['navBackground','navText','navActive','navAccent'] as $key) {
    foreach (['red','#123',null,17,[],(object)[], '#fff;}body{background:url(https://example.org)}'] as $bad) {
        $p=fixture();$p->theme->$key=$bad;$refused=false;
        try { codec::validate($p); } catch (invalid_parameter_exception $e) { $refused=true; }
        check($refused,'Optional navigation color must reject invalid input: '.$key);
    }
}
foreach ([false,true] as $custom) {
    $p=fixture();$old=codec::encode($p);
    if ($custom) { foreach (['navBackground'=>'#112233','navText'=>'#ddeeff','navActive'=>'#fafafa','navAccent'=>'#aa5500'] as $k=>$v) { $p->theme->$k=$v; } }
    $before=codec::encode($p);$path=tempnam(sys_get_temp_dir(),'ustar-theme-');
    try {
        package::build($p,$path,2);$zip=new ZipArchive();check($zip->open($path)===true,'ZIP opens');
        $css=$zip->getFromName('style.css');$editor=json_decode($zip->getFromName('editor.json'));$zip->close();
        check(str_contains($css,'--nav-bg:'.($custom?'#112233':'#2b2b2b')),'Server SCORM uses configured menu background');
        check(str_contains($css,'--nav-active-ink:'.($custom?'#000000':'#ffffff')),'Active label remains readable');
        check(codec::encode($p)===$before,'Building must not migrate or rewrite the source');
        if ($custom) { check($editor->course->theme->navAccent==='#aa5500','Editable SCORM source retains palette'); }
        else { check(codec::encode($p)===$old,'Old manifest fingerprint stays exact'); }
    } finally { unlink($path); }
}
echo "COURSE_STUDIO_THEME_PHP_OK optional HEX strict legacy identity server ZIP palette\n";
