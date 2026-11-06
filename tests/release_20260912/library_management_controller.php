<?php
// Exact production controller under a small synthetic Moodle bootstrap; real writer validation.
namespace core\lock {
class lock_config { static function get_lock_factory($n) { return new class { function get_lock(...$a) { return new class { function release() {} }; } }; } }
}
namespace local_ustar {
class library_access {
 static function is_authoring(...$a) { return true; }
 static function can_grant(...$a) { return $GLOBALS['case']==='status'; }
 static function can_assess(...$a) { return true; }
 static function is_admin(...$a) { return false; }
 static function require_actor(...$a) {}
 static function resolve_many(...$a){return [];}
 static function can_target($a,$b) { return $b===3; }
 static function fail($s) { throw new \moodle_exception($s); }
}
class capabilities { const HR_WRITE='hr';static function has(...$a){return false;} }
class library_store { static function price_bounds(){return ['valid'=>true,'min'=>1,'max'=>25];} static function enabled(){return false;} }
class view_as { static function active(){return false;} }
class structure { const NAME_STRUCTURE='structure'; static function get(...$a){return [];} }
class access_context { static function scope(...$a){return ['allowed'=>true,'userids'=>[3]];} }
class route_model { static function current_published_version(...$a){return null;} }
}
namespace {
define('MOODLE_INTERNAL',true);define('MUST_EXIST',2);define('SQL_PARAMS_NAMED',2);
foreach(['PARAM_INT','PARAM_ALPHA','PARAM_TEXT','PARAM_ALPHANUM','PARAM_BOOL','PARAM_ALPHANUMEXT'] as $c)define($c,1);
class moodle_exception extends \Exception {}
class core_text { static function strlen($s){return mb_strlen($s);} static function strtolower($s){return mb_strtolower($s);} }
class context_system { static function instance(){return new self;} }
function require_login(){}function require_sesskey(){}function sesskey(){return 'fixture';}
function clean_param($s,$p){return strip_tags($s);}function get_config(...$a){return 0;}
function fullname($u){return $u->firstname.' '.$u->lastname;}function userdate($t,$format){return date('d.m.Y',$t);}
function optional_param($k,$v,$t){return $_POST[$k]??$_GET[$k]??$v;}
function required_param($k,$t){if(!isset($_POST[$k]))throw new moodle_exception('missing '.$k);return $_POST[$k];}
function redirect(...$a){throw new \RuntimeException('Unexpected redirect');}
class moodle_url { function __construct(private $url,private $params=[]){} function out($e=true){return $this->url.($this->params?'?'.http_build_query($this->params):'');} }
class NullPage { public $requires;function __construct(){$this->requires=$this;}function __call($n,$a){} }
class Output {function header(){return '';}function footer(){return '';}function render_from_template($n,$data){$GLOBALS['rendered']=$data;return '';} }
class DBFixture {
 public $offer; public $bank;
 function __construct(){
  $this->offer=(object)['id'=>7,'itemtype'=>'content','itemid'=>501,'title'=>'Original offer','summary'=>'Original summary','accesspolicy'=>'paid','visibility'=>'draft','pricecoin'=>10,'gatepointid'=>0,'timemodified'=>44];
  $this->bank=(object)['id'=>8,'title'=>'Original bank','targettype'=>'topic','targetkey'=>'Topic','status'=>'draft','revision'=>4,'questionsjson'=>json_encode([['text'=>'Old A','options'=>['yes','no'],'correct'=>0],['text'=>'Old B','options'=>['yes','no'],'correct'=>1]]),'questioncount'=>2,'passpercent'=>100,'maxattempts'=>3];
 }
 function get_record($table,$where,...$a){
  if($table==='local_ustar_library_offer')return clone $this->offer;
  if($table==='local_ustar_library_assessment')return clone $this->bank;
  if($table==='local_ustar_content')return (object)['id'=>501,'title'=>'Z oldest source','type'=>'page','sourcekind'=>'internal'];
  if($table==='user')return (object)['id'=>3,'firstname'=>'Allowed','lastname'=>'Person'];
  throw new \RuntimeException('unexpected table '.$table);
 }
 function get_records($table,$where=[],$sort='',$fields='*',$start=0,$limit=0){
  if($table==='local_ustar_content'){$r=[];for($i=1;$i<=501;$i++)$r[$i]=(object)['id'=>$i,'title'=>$i===501?'Z oldest source':'A source '.$i,'type'=>'page','sourcekind'=>'internal'];return $limit?array_slice($r,$start,$limit,true):$r;}
  return [];
 }
 function get_records_select($table,$where,$params=[],$sort='',$fields='*',$start=0,$limit=0){
  if($table==='local_ustar_content')return $this->get_records($table,[],'title,id','id,title',$start,$limit);
  if($table==='local_ustar_library_grant')return [101=>(object)['id'=>101,'userid'=>3,'offerid'=>7,'source'=>'assignment','grantedby'=>2,'validfrom'=>time()+3600,'validto'=>0,'revokedat'=>0,'refundedat'=>0,'timecreated'=>time()],100=>(object)['id'=>100,'userid'=>3,'offerid'=>7,'source'=>'assignment','grantedby'=>2,'validfrom'=>0,'validto'=>10,'revokedat'=>0,'refundedat'=>0,'timecreated'=>time()]];
  return [];
 }
 function sql_like($field,$param,...$a){return $field.' LIKE '.$param;}
 function sql_like_escape($value){return addcslashes($value,'%_');}
 function get_records_sql($sql,$params=[],$offset=0,$limit=0){
  if($offset)return [];
  if(str_contains($sql,'{user} u WHERE'))return [3=>$this->get_record('user',['id'=>3])];
  if(str_contains($sql,'{local_ustar_library_grant}'))return $this->get_records_select('local_ustar_library_grant','');
  throw new \RuntimeException('unexpected SQL');
 }
 function get_records_list($table,$field,$values){$out=[];foreach($values as $id)$out[$id]=$this->get_record($table,['id'=>$id]);return $out;}
 function get_field(...$a){return 0;}
 function get_in_or_equal($ids,...$a){return ['IN (:person)',['person'=>3]];}
 function start_delegated_transaction(){return new class {function allow_commit(){} function rollback($e){throw $e;}};}
}
$case=$argv[1]??'bank';$root=dirname(__DIR__,2);$DB=new DBFixture;$USER=(object)['id'=>2];$PAGE=new NullPage;$OUTPUT=new Output;
spl_autoload_register(static function($name)use($root){if(str_starts_with($name,'local_ustar\\')){ $file=$root.'/moodle/local/ustar/classes/'.substr($name,12).'.php';if(is_file($file))require_once $file; }});
$_GET=[];$_POST=[];$_SERVER['REQUEST_METHOD']='POST';
$questions=[];for($i=0;$i<6;$i++)$questions[$i]=['text'=>'Typed question '.$i,'options'=>['Yes '.$i,'No '.$i,'','','',''],'correct'=>$i%2];
if(in_array($case,['bank','stale','nocorrect'],true)){
 $_GET=$case==='stale'?['check'=>8]:[];
 $_POST=['action'=>'savecheck','id'=>$case==='stale'?8:0,'expectedrevision'=>$case==='stale'?3:0,'title'=>'Typed six-question bank','targetref'=>'topic','topic'=>'Typed topic','questioncount'=>6,'passpercent'=>$case==='stale'?90:101,'maxattempts'=>4,'status'=>'draft','questions'=>$questions];
 if($case==='nocorrect'){$_POST['passpercent']=90;unset($_POST['questions'][5]['correct']);}
}else if($case==='offer'){
 $_GET=['edit'=>7];$_POST=['action'=>'save','id'=>7,'expectedmodified'=>43,'subject'=>'content:501','title'=>'Typed offer','summary'=>'Typed summary','accesspolicy'=>'paid','visibility'=>'draft','pricecoin'=>26,'gatepointid'=>0];
}else{$_GET=['edit'=>7];$_SERVER['REQUEST_METHOD']='GET';}
$temp=sys_get_temp_dir().'/ustar-controller-'.bin2hex(random_bytes(8));mkdir($temp.'/local/ustar',0700,true);file_put_contents($temp.'/config.php','<?php');copy($root.'/moodle/local/ustar/library_manage.php',$temp.'/local/ustar/library_manage.php');
try{
 require($temp.'/local/ustar/library_manage.php');$d=$GLOBALS['rendered'];
 $assert=static function($yes,$reason){if(!$yes)throw new \RuntimeException($reason);};
 if(in_array($case,['bank','stale','nocorrect'],true)){
  $assert($d['checktitle']==='Typed six-question bank','Save refusal must preserve typed bank title');
  $assert(count($d['questionfields'])===6,'Save refusal must preserve all six question rows');
  $assert($d['questionfields'][5]['text']==='Typed question 5','Last question text lost');
  if($case==='nocorrect')$assert(!array_filter($d['questionfields'][5]['options'],static fn($o)=>$o['checked']),'An absent correct choice must not become the first answer');
  else $assert($d['questionfields'][5]['options'][1]['checked'],'Correct answer shifted');
  if($case==='stale')$assert((int)$d['checkrevision']===3,'Stale token must not be replaced by current revision');
 }else if($case==='offer'){
  $assert($d['title']==='Typed offer'&&$d['summary']==='Typed summary','Save refusal must preserve offer inputs');
  $assert((int)$d['expectedmodified']===43,'Offer stale token must remain unchanged');
 }else if($case==='malformed'){
  $v=\local_ustar\library_manage_form::offer(null,['accesspolicy'=>['bad'],'visibility'=>['bad']]);
  $assert($v['accesspolicy']===''&&$v['visibility']==='','Malformed enum input must not become a valid policy');
 }else if($case==='selected'){
  $selected=array_values(array_filter($d['subjects'],static fn($s)=>!empty($s['selected'])));
  $assert(count($selected)===1&&$selected[0]['value']==='content:501','Current binding beyond500 must stay selected');
 }else if($case==='status'){
  foreach($d['grants'] as $g)$assert($g['status']!=='Действует','Future/expired grant must not appear active');
  $assert(count($d['grants'])===2,'History fixture missing');
 }
 echo 'LIBRARY_MANAGEMENT_CONTROLLER '.$case.":PASS\n";
} catch(\Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage()."\n");exit(1);}
finally{unlink($temp.'/local/ustar/library_manage.php');unlink($temp.'/config.php');rmdir($temp.'/local/ustar');rmdir($temp.'/local');rmdir($temp);}
}
