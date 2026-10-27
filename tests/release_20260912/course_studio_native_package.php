<?php
define('MOODLE_INTERNAL',true);
require(__DIR__.'/../../moodle/local/ustar/classes/studio_scorm_package.php');
require(__DIR__.'/../../moodle/local/ustar/classes/course_studio_material.php');
final class stored_file {
    public function __construct(private string $bytes) {}
    public function copy_content_to($path) { return file_put_contents($path,$this->bytes)!==false; }
}
$pages=[['title'=>'Начало','body'=>'<p>Текст курса</p>']];
$html=\local_ustar\studio_scorm_package::html(['title'=>'Первоначальное имя & правила','pages'=>$pages]);
function native_zip(string $html): stored_file {
    $path=tempnam(sys_get_temp_dir(),'ustar-native-fixture-');$z=new ZipArchive();$z->open($path,ZipArchive::OVERWRITE);$z->addFromString('index.html',$html);$z->close();
    try { return new stored_file(file_get_contents($path)); } finally { unlink($path); }
}
$check=new ReflectionMethod(\local_ustar\course_studio_material::class,'native_package');
if(!$check->invoke(null,native_zip($html),(object)['title'=>'Новое имя'],$pages))throw new RuntimeException('renaming metadata must preserve the native editable source');
if($check->invoke(null,native_zip(str_replace('Текст курса','Другой текст',$html)),(object)['title'=>'Новое имя'],$pages))throw new RuntimeException('a different package must never be replaced with stale blueprint text');
if($check->invoke(null,native_zip(str_replace('</body>','<script>bad()</script></body>',$html)),(object)['title'=>'Новое имя'],$pages))throw new RuntimeException('only exact trusted generator output may use the native source adapter');
echo "COURSE_STUDIO_NATIVE_PACKAGE_OK renamed metadata accepted exact source untrusted stale mismatch refused\n";
