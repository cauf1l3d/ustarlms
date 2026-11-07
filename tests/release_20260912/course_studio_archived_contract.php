<?php
define('MOODLE_INTERNAL',true);
class invalid_parameter_exception extends Exception {}
require __DIR__.'/../../moodle/local/ustar/classes/course_studio_material.php';
use local_ustar\course_studio_material as Bridge;
$archived=(object)['type'=>'scorm','status'=>'archived','modname'=>'scorm'];
if (!Bridge::supported($archived)) { throw new Exception('Archived SCORM must expose author editing-copy entry'); }
foreach (['assessment','quiz','folder'] as $type) {
    if (Bridge::supported((object)['type'=>$type,'status'=>'archived'])) { throw new Exception('Non-course converted'); }
}
$original=clone $archived;
$GLOBALS['DB']=new class($archived) {
    public function __construct(private object $row) {}
    public function get_record_sql($sql,$params) { return clone $this->row; }
};
$record=new ReflectionMethod(Bridge::class,'record');
$row=$record->invoke(null,17);
if ($row!=$original || $archived!=$original) { throw new Exception('Reading source changed archive state'); }
echo "COURSE_STUDIO_ARCHIVED=PASS author projection/source state/non-course refusal\n";
