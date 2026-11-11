<?php
namespace local_ustar { class material_studio { static function can_manage($id){return true;} } }
namespace {
define('MOODLE_INTERNAL',true);$USER=(object)['id'=>10];
require dirname(__DIR__,3).'/moodle/local/ustar/classes/course_studio.php';
class StudioProbeDB {
    public int $reads=0;public int $bytes=0;
    function __construct(private int $count){}
    function get_records_sql($sql,$params){
        $this->reads++;$rows=[];
        for($i=0;$i<$this->count;$i++){
            $json=json_encode(['visualproject'=>['data'=>['code'=>'course-'.$i,'title'=>'Course'],'padding'=>str_repeat('a',65536)],'fingerprint'=>'fixture']);
            $this->bytes+=strlen($json);$rows[]=(object)['sourcejson'=>$json,'contentid'=>$i+1,'sourceversion'=>1,'contentmodified'=>1,'status'=>'draft','timemodified'=>1,'cmid'=>0];
        }return $rows;
    }
}
$out=['scope'=>'real course_studio::get with synthetic authorized actor and DB; returned JSON bytes, not PostgreSQL latency or production memory'];
foreach([100,1000] as $n){$DB=new StudioProbeDB($n);$r=\local_ustar\course_studio::get('course-'.($n-1),10);$out['samples'][]=['projects'=>$n,'db_calls'=>$DB->reads,'sourcejson_bytes_loaded'=>$DB->bytes,'returned_projects'=>1];}
echo json_encode($out,JSON_PRETTY_PRINT)."\n";
}
