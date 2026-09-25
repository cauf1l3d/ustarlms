<?php
namespace {
    define('MOODLE_INTERNAL', true); define('IGNORE_MISSING', 0); define('DEBUG_DEVELOPER', 1);
    class moodle_exception extends \Exception { public $errorcode; public function __construct($code) {$this->errorcode=$code;parent::__construct($code);} }
    class moodle_url {
        private $path; private $params;
        function __construct($path, $params=[]) {$this->path=parse_url($path,PHP_URL_PATH);parse_str(parse_url($path,PHP_URL_QUERY)??'', $existing);$this->params=$params+$existing;}
        function get_path(){return $this->path;}
        function get_param($k){return $this->params[$k]??null;}
        function out($escape=true){return $this->path.($this->params?'?'.http_build_query($this->params):'');}
    }
    function debugging($s,$l) {}
    function format_string($s) {return $s;}
    function sesskey() {return 'test-session';}
    function get_coursemodule_from_id($type,$id,...$args) {return $id===31?(object)['name'=>'Реальный материал','deletioninprogress'=>0]:false;}
    class question_attempt { function __construct(public $text='',public $right=false) {} function get_question(){return (object)['questiontext'=>$this->text,'rightanswer'=>$this->right];} function get_qt_field_name($key){return 'q1:1_'.$key;} }
    class question_display_options {}
    class qtype_truefalse_renderer {
        function formulation_and_controls(question_attempt $qa,question_display_options $options){return '<input value="1"><label for="q1:1_answertrue" class="ms-1">Верно</label><input value="0"><label for="q1:1_answerfalse" class="ms-1">Неверно</label>';}
        function correct_response(question_attempt $qa){return 'original';}
    }
}
namespace local_ustar {
    class view_as {static $active=false;static function assert_writable(){if(self::$active)throw new \moodle_exception('preview');}}
    class structure {static function resolve_user($id){return ['position'=>['id'=>'retail']];}}
    class adaptation_service {static $blocked=false;static function route_card($id){return ['blocked'=>self::$blocked];}}
    class route_model {
        static $route=[];static $versions=[];static $writes=0;
        static function for_user($pid,$uid){self::$writes++;return self::$route;}
        static function read_only_snapshot($pid,$uid){return self::$route;}
        static function current_published_version($id){return self::$versions[$id]??null;}
        static function requirements_for_version($v){return $v->requirements;}
    }
}
namespace {
    /* LOAD_SOURCES */
    if (!isset($sources)) {
        $root=$argv[1]??dirname(__DIR__,2).'/moodle';
        foreach (['local/ustar/classes/route_continue.php','local/ustar/classes/grading_commit.php','local/ustar/classes/career_learning.php','theme/ustar/classes/output/qtype_truefalse_renderer.php'] as $file) {$sources[$file]=file_get_contents($root.'/'.$file);}
    }
    foreach (['local/ustar/classes/route_continue.php','local/ustar/classes/grading_commit.php','local/ustar/classes/career_learning.php','theme/ustar/classes/output/qtype_truefalse_renderer.php'] as $file) {eval(substr($sources[$file],5));}
    $count=0;
    function check($ok,$message){global $count;if(!$ok)throw new \Exception($message);$count++;}
    function rejects(callable $action,$label){try{$action();}catch(\Throwable $e){check(true,$label);return;}throw new \Exception('Expected rejection: '.$label);}
    use local_ustar\route_continue as Flow;
    use local_ustar\route_model as Model;
    $route=['currentpoint'=>['canlaunch'=>true,'launchurl'=>'/local/ustar/activity_launch.php?cmid=32']];
    check(Flow::destination($route,'',31)->get_param('cmid')==32,'advance Page to next material');
    check(Flow::destination($route,'',32)->get_param('continueerror')==1,'same CM loop blocked');
    $route['currentpoint']['launchurl']='/mod/page/view.php?id=31';
    check(Flow::destination($route,'',31)->get_param('continueerror')==1,'direct CM loop blocked');
    $route['currentpoint']['launchurl']='/local/ustar/route_profile_reveal.php';
    check(Flow::destination($route,'/local/ustar/route_profile_reveal.php')->get_param('continueerror')==1,'native loop blocked');
    check(Flow::destination($route,'/local/ustar/route_career.php')->get_path()==='/local/ustar/route_profile_reveal.php','native next');
    $route['currentpoint']['canlaunch']=false;
    check(Flow::destination($route)->get_path()==='/local/ustar/route.php','pending grading is not bypassed');
    check(Flow::destination([])->get_path()==='/local/ustar/route.php','route completed fallback');
    local_ustar\adaptation_service::$blocked=true;Model::$writes=0;
    check(Flow::next_url(9)->get_path()==='/local/ustar/route.php'&&Model::$writes===0,'adaptation blocking prevents reconciliation');
    local_ustar\adaptation_service::$blocked=false;local_ustar\view_as::$active=true;
    rejects(fn()=>Flow::next_url(9),'preview cannot continue');local_ustar\view_as::$active=false;
    Model::$versions=[1=>(object)['requirements'=>[['type'=>'native','sourcekey'=>'career'],['type'=>'cm','sourceid'=>31],['type'=>'skill','sourcekey'=>'sales']]]];
    Model::$route=['points'=>[['id'=>1,'title'=>'Точка','current'=>true,'locked'=>false,'done'=>false,'status'=>'current']]];
    Flow::assert_native_reachable(9,'career');check(true,'native current allowed');
    rejects(fn()=>Flow::assert_native_reachable(9,'other'),'foreign native blocked');
    Model::$route['points'][0]['locked']=true;
    rejects(fn()=>Flow::assert_native_reachable(9,'career'),'future native blocked');
    Model::$route['points'][0]['locked']=false;
    Flow::assert_reachable_cm(Model::$route,31);check(true,'Page in route allowed');
    rejects(fn()=>Flow::assert_reachable_cm(Model::$route,99),'foreign CM rejected');
    $data=local_ustar\career_learning::build('retail',9);
    check($data['points'][0]['materials'][0]['label']==='Реальный материал','real CM name');
    check($data['points'][0]['number']===1,'snapshot has no number field');
    check(isset($data['skills']['sales'])&&!isset($data['skills']['invented']),'only explicit skill links');
    check(str_contains($data['points'][0]['url'],'route_next.php'),'current uses resolver');
    Model::$route['points'][0]['current']=false;Model::$route['points'][0]['locked']=true;Model::$route['points'][0]['status']='locked';
    check(local_ustar\career_learning::build('retail',9)['points'][0]['url']==='','no future material launch');
    check(local_ustar\career_learning::retail('Торговый зал','Продавец-кассир'),'retail audience');
    check(!local_ustar\career_learning::retail('Логистика','Водитель'),'no retail grades on unrelated role');
    $renderer=new \theme_ustar\output\qtype_truefalse_renderer();
    $qa=new question_attempt('Можно ли сидеть на диване, если в зоне видимости покупатель?',false);
    $html=$renderer->formulation_and_controls($qa,new question_display_options());
    check(str_contains($html,'>Можно</label>')&&str_contains($html,'>Нельзя</label>'),'permission choices');
    check(str_contains($html,'value="1"')&&str_contains($html,'value="0"'),'response identities unchanged');
    check($renderer->correct_response($qa)==='Правильный ответ: нельзя','correct response wording');
    check(str_contains($renderer->formulation_and_controls(new question_attempt('Другой вопрос'),new question_display_options()),'>Верно</label>'),'unrelated questions unchanged');
    $DB=new class {public $active=false;function is_transaction_started(){return $this->active;}};
    $tx=new class {public $error=null;function allow_commit(){if($this->error)throw $this->error;}};
    check(!local_ustar\grading_commit::finish($tx,fn()=>true),'normal commit');
    $tx->error=new moodle_exception('Message was not sent.');
    check(local_ustar\grading_commit::finish($tx,fn()=>true),'verified post-commit message failure is separate');
    rejects(fn()=>local_ustar\grading_commit::finish($tx,fn()=>false),'failed verification must not show success');
    $DB->active=true;
    rejects(fn()=>local_ustar\grading_commit::finish($tx,fn()=>true),'active transaction never swallowed');
    $DB->active=false;$tx->error=new \RuntimeException('SQL failed');
    rejects(fn()=>local_ustar\grading_commit::finish($tx,fn()=>true),'SQL failure propagated');
    $tx->error=new moodle_exception('Other observer failure');
    rejects(fn()=>local_ustar\grading_commit::finish($tx,fn()=>true),'unrelated failure propagated');
    echo "PASS domain assertions=$count\n";
}
