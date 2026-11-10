<?php
namespace core\lock { class lock_config { static function get_lock_factory($x){return new self();} function get_lock(...$x){return $this;} function release(){} } }
namespace local_ustar {
 class library_offer {static function for_subject(...$x){return null;}}
 class library_access { static function eligible($u){return true;} static function require_actor($u){if($u!==10)throw new \RuntimeException('actor');} static function fail($s){throw new \RuntimeException($s);} }
}
namespace {
 class core_text {static function strlen($s){return strlen($s);}}
 function clean_param($v,$t){return $v;}
 define('PARAM_TEXT',1);
 define('MOODLE_INTERNAL',true);define('MUST_EXIST',2);define('SQL_PARAMS_NAMED',2);
 class DBfake {
 public $assessment;public $attempt;public $writes=0;
 function __construct(){ $this->assessment=(object)['id'=>1,'targettype'=>'topic','status'=>'published','maxattempts'=>3,'questioncount'=>2,'passpercent'=>40,'revision'=>2];
 $this->attempt=(object)['id'=>7,'assessmentid'=>1,'userid'=>10,'status'=>'started','versionno'=>1,'passpercent'=>100,'maxattempts'=>3,'questionsjson'=>json_encode([['text'=>'A','options'=>['yes','no'],'correct'=>0],['text'=>'B','options'=>['yes','no'],'correct'=>0]]),'answersjson'=>'','submissionhash'=>'']; }
 function get_record_sql(...$x){return $this->attempt;}
 function start_delegated_transaction(){return new class {function allow_commit(){}function rollback($e){throw $e;}};}
 function get_record($t,$a,...$x){return str_ends_with($t,'attempt')?$this->attempt:$this->assessment;}
 function update_record($t,$o){$this->writes++;foreach((array)$o as $k=>$v)$this->attempt->$k=$v;}
 function count_records(...$x){return 1;}
 }
 require __DIR__.'/../../moodle/local/ustar/classes/library_assessment.php';
 $fail=0;
 foreach(['spoofed actor'=>function(){global $DB;try{\local_ustar\library_assessment::submit(7,99,['q0'=>0,'q1'=>1]);}catch(\Throwable $e){return $e->getMessage()==='actor';}return false;},
 'snapshot threshold'=>function(){global $DB;$r=\local_ustar\library_assessment::submit(7,10,['q0'=>0,'q1'=>1]);return !$r['passed'];}] as $name=>$test){$DB=new DBfake();try{$ok=$test();}catch(\Throwable $e){$ok=false;}echo ($ok?'PASS ':'FAIL ').$name."\n";$fail+=!$ok;}
 exit($fail?1:0);
}
