<?php
// Disposable synthetic stage only; not included in the deploy payload.
define('CLI_SCRIPT',true);require('/stage/moodle/config.php');
if ($CFG->prefix!=='stage_' || getenv('USTAR_STAGE_PREFIX')!=='stage_') { throw new coding_exception('isolated Library fixture required'); }
$path='/artifacts/library-http-fixture.json';
if (($argv[1]??'')==='verify') {
    $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if ($DB->count_records('local_ustar_library_attempt',['assessmentid'=>$data['assessmentid']])!==0) { throw new coding_exception('GET created a Library attempt'); }
    echo "LIBRARY_GET_READ_ONLY=PASS\n";exit;
}
$id=(int)$DB->insert_record('local_ustar_library_assessment',(object)['sourcekey'=>'http-fixture','targettype'=>'topic','targetkey'=>'Fixture',
    'title'=>'HTTP Fixture check','questionsjson'=>json_encode([['text'=>'Fixture question','options'=>['A','B'],'correct'=>0]]),
    'questioncount'=>1,'passpercent'=>100,'maxattempts'=>3,'status'=>'published','revision'=>1,'inputhash'=>'','timecreated'=>time(),'timemodified'=>time(),'usermodified'=>0]);
file_put_contents($path,json_encode(['assessmentid'=>$id]));echo $id;
