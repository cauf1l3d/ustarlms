<?php
namespace {
    define('MOODLE_INTERNAL',true);define('IGNORE_MISSING',0);define('DAYSECS',86400);
    function format_string($s){return $s;}
    function sesskey(){return 'test';}
    function get_coursemodule_from_id(...$args){return (object)['name'=>'Ассортимент и товароведение','deletioninprogress'=>0];}
    class moodle_url {function __construct($p,$args=[]){}function out($e=false){return '/local/ustar/route_next.php';}}
}
namespace local_ustar {
    class structure {const NAME_STRUCTURE='structure';static $name='Продавец-кассир';static function get($key){return ['positions'=>[['id'=>'actual_seller','name'=>self::$name]]];}}
    class route_scope {static $applies=true;static function available(){return true;}static function parent_for_position($p){return (object)['id'=>49];}static function point_applies(...$args){return self::$applies;}}
    class route_model {
        static $published=true;static $cmid=41;static $ack=true;
        static function get_route($p){return null;}
        static function current_published_version($id){return self::$published?(object)['id'=>7]:null;}
        static function requirements_for_version($v){return array_merge([['type'=>'cm','sourceid'=>self::$cmid,'required'=>true]],self::$ack?[['type'=>'native','sourcekey'=>'product_scorm_ack','required'=>true]]:[]);}
        static function read_only_snapshot($p,$u){return ['points'=>[['id'=>69,'title'=>'Ассортимент и товароведение','status'=>'locked','done'=>false,'current'=>false]]];}
    }
}
namespace {
    /* LOAD_SOURCES */
    $files=['local/ustar/classes/consultant_career.php','local/ustar/classes/evidence.php','local/ustar/classes/career_learning.php'];
    if(!isset($sources)){foreach($files as $f){$sources[$f]=file_get_contents(($argv[1]??dirname(__DIR__,2).'/moodle').'/'.$f);}}
    foreach($files as $f){eval(substr($sources[$f],5));}
    class FixtureDB {
        public $explicit=[];public $state=0;public $course=10;public $defs=[];
        function get_records_select(...$args){return $this->explicit;}
        function get_records($t,...$args){if($t==='local_ustar_route_points')return [(object)['id'=>69]];if($t==='local_ustar_skill_evidence')return $this->defs;throw new Exception($t);}
        function get_record_sql(...$args){return (object)['id'=>41,'course'=>$this->course,'instance'=>1,'completion'=>2,'modname'=>'scorm'];}
        function get_record($t,...$args){if($t==='course_modules_completion')return (object)['completionstate'=>$this->state,'timemodified'=>time()];throw new Exception($t);}
        function get_field(...$args){return 'Ассортимент и товароведение';}
    }
    $DB=new FixtureDB();$definition=(object)['id'=>1,'skillid'=>'product_know','positionid'=>'retail_seller','pathkey'=>'retail_product','courseid'=>10,'cmid'=>41,'evidencetype'=>'learning','weight'=>100,'required'=>1,'validdays'=>0];$DB->defs=[$definition];
    $n=0;function check($ok,$why){global $n;if(!$ok)throw new Exception($why);$n++;}
    use local_ustar\evidence as E;use local_ustar\route_model as R;use local_ustar\consultant_career as C;
    check(C::is_consultant('Продавец-кассир'),'cashier consultant equivalence');
    check(!C::is_consultant('Старший консультант'),'no accidental grade for senior');
    $v=E::evaluate_skill('product_know','actual_seller',9);
    check($v['configured']&&!$v['satisfied'],'source configured without granting completion');
    check($v['bestpath']['items'][0]['activityname']==='Ассортимент и товароведение','canonical material title');
    $DB->state=1;check(E::evaluate_skill('product_know','actual_seller',9)['satisfied'],'persisted Moodle completion confirms skill');
    $DB->state=0;check(!E::evaluate_skill('product_know','actual_seller',9)['satisfied'],'incomplete remains incomplete');
    check(E::definitions_for_skill('sales','actual_seller')===[],'other skills untouched');
    check(E::definitions_for_skill('product_know',null)===[],'no global alias');
    $DB->explicit=[(object)['id'=>99]];check(E::definitions_for_skill('product_know','actual_seller')[0]->id===99,'explicit mapping wins');$DB->explicit=[];
    local_ustar\structure::$name='Менеджер корпоративных продаж';check(E::definitions_for_skill('product_know','actual_seller')===[],'corporate unaffected');local_ustar\structure::$name='Продавец-кассир';
    local_ustar\route_scope::$applies=false;check(E::definitions_for_skill('product_know','actual_seller')===[],'route scope enforced');local_ustar\route_scope::$applies=true;
    R::$published=false;check(E::definitions_for_skill('product_know','actual_seller')===[],'draft cannot configure evidence');R::$published=true;
    R::$cmid=99;check(E::definitions_for_skill('product_know','actual_seller')===[],'different CM cannot inherit old evidence');R::$cmid=41;
    R::$ack=false;check(E::definitions_for_skill('product_know','actual_seller')===[],'only product route qualifies');R::$ack=true;
    $DB->course=11;check(!E::evaluate_skill('product_know','actual_seller',9)['satisfied'],'course mismatch rejected');$DB->course=10;
    $DB->defs=[$definition,(object)array_merge((array)$definition,['id'=>2,'cmid'=>65])];check(E::definitions_for_skill('product_know','actual_seller')===[],'multi-material evidence cannot be weakened');$DB->defs=[$definition];
    $v=local_ustar\career_learning::build('actual_seller',9);check($v['skills']['product_know'][0]['label']==='Ассортимент и товароведение','published route label shown');check($v['skills']['product_know'][0]['url']==='','future course not unlocked');
    echo "PASS domain assertions=$n\n";
}
