<?php
// Pure codec fixture; no Moodle bootstrap, database or production calls.
define('MOODLE_INTERNAL',true);
class invalid_parameter_exception extends Exception {}
require __DIR__.'/../../moodle/local/ustar/classes/course_studio_project.php';
use local_ustar\course_studio_project as codec;
function rejects(callable $f): void { try {$f();}catch(invalid_parameter_exception $e){return;}throw new Exception('Unsafe fixture accepted'); }
$p=json_decode('{"data":{"code":"SALES-01","title":"Продажи","pages":[{"id":"1","title":"Начало","html":"<p>Текст</p>"}]},"assets":{},"fonts":[],"theme":{"font":"Arial","headingFont":"Arial","fontSize":17,"headingSize":34,"lineHeight":1.65,"ink":"#2b2b2b","accent":"#ebc500","paper":"#ffffff","background":"#ededed"},"catalogue":{},"revision":0}');
codec::validate($p);
foreach (['<a href="jav&#97;script:alert(1)">x</a>','<img src="https://example.org">','<p style="background:u\\72l(x)">x</p>','<svg><a>x</a></svg>','<img srcset="https://example.org 2x">'] as $html){$bad=unserialize(serialize($p));$bad->data->pages[0]->html=$html;rejects(fn()=>codec::validate($bad));}
rejects(fn()=>codec::asset_type('<script>bad</script>','image/png'));
$path=getenv('USTAR_STUDIO_WORKSPACE');
if($path){$w=json_decode(file_get_contents($path));foreach($w->courses as $course){foreach($course->assets as $key=>$a){$b=base64_decode($a->data,true);codec::asset_type($b,$a->type);$course->assets->$key=(object)['type'=>$a->type,'hash'=>hash('sha256',$b),'size'=>strlen($b)];}codec::validate($course);echo $course->data->code." VALID\n";}}
echo "COURSE_STUDIO_PHP_CODEC_OK\n";
