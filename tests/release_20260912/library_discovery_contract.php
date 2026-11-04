<?php
/** Isolated audit probe. Executes real readers; synthetic DB/ACL dependencies, no Moodle bootstrap or network. */
namespace local_ustar {
    class employment { static function is_active($id){return true;} }
    class capabilities { const LEARNING_USE='use'; static function has($id,$cap){return true;} }
    class view_as { static function active(){return false;} }
    class structure { const NAME_STRUCTURE='org'; static function get($n){return ['departments'=>[['id'=>'retail']]];} }
    class content { static function user_scope($u){return ['departmentid'=>'retail'];} static function audience_allows_record($r,$u){return true;} const STATUS_PUBLISHED='published'; }
    class economy { static function available(){return false;} }
    class learning_events { static function library_for_user($u){return [];} }
    class feed_files { static function saved_for_library($u,$q,$p){return ['items'=>[],'hasnext'=>false];} }
    class catalog { static function can_manage($u){return false;} static function browse($p,$q){global $DB;return [['id'=>1,'isfolder'=>false,'title'=>'Restricted product']];} }
}
namespace {
    define('MOODLE_INTERNAL',true);define('SQL_PARAMS_NAMED',1);
    function is_siteadmin($u){return false;}
    function has_capability(...$a){return false;}
    function get_config($p,$k){global $market;return $k==='library_marketplace_departments' ? ($market?'["retail"]':'[]') : false;}
    function userdate($t,$f){return 'fixture';}
    function format_string($s){return $s;}
    function format_text($s,$f){return $s;}
    define('FORMAT_PLAIN',0);
    class context_system { static function instance(){return new self();} }
    class xmldb_table { function __construct($n){} }
    class moodle_url { function __construct(private $p,private $a=[]){} function out($x){return $this->p.'?'.http_build_query($this->a);} }
    class core_text { static function strtolower($s){return strtolower($s);} static function strpos($a,$b){return strpos($a,$b);} }
    class AuditDB {
        public array $reads=[]; public int $n=0; public bool $grants=false; public string $type='content';public string $policy='free';
        function count($m,$t){$k=$m.':'.$t;$this->reads[$k]=($this->reads[$k]??0)+1;}
        function get_manager(){return $this;} function table_exists($t){return true;}
        function record_exists($t,$p){$this->count('exists',$t);return true;}
        function offer($id){return (object)['id'=>$id,'itemid'=>$id,'itemtype'=>$this->type,'title'=>$id===1?'ONLY-OLDEST':'Item '.$id,'summary'=>'','visibility'=>'published','accesspolicy'=>$this->policy,'pricecoin'=>5,'timemodified'=>1];}
        function get_record($t,$p,...$rest){$this->count('get',$t);return match($t){'local_ustar_library_offer'=>$this->offer($p['id']??$p['itemid']),'local_ustar_content'=>(object)['id'=>$p['id'],'status'=>'published','type'=>'page'],'local_ustar_catalog'=>(object)['id'=>$p['id'],'active'=>1,'parentid'=>0],default=>throw new \Exception('Unexpected table '.$t)};}
        function get_records_list($t,$f,$ids){$this->count('list',$t);return array_combine($ids,array_map(fn($id)=>$this->offer($id),$ids));}
        function get_in_or_equal($ids,...$rest){return ['IN (...)',[]];}
        function get_records_select($t,$s,$p,$sort='',$fields='*',$offset=0,$limit=0){
            $this->count('select',$t);
            if($t==='local_ustar_library_offer'){return array_map(fn($i)=>$this->offer($i),range(1,$this->n));}
            if($t!=='local_ustar_library_grant')throw new \Exception('Unexpected select '.$t);
            if(!$this->grants)return [];
            if(isset($p['oid']))return [(object)['id'=>$p['oid'],'offerid'=>$p['oid']]];
            $ids=array_reverse(range(1,$this->n));if(isset($p['lastid']))$ids=array_values(array_filter($ids,fn($i)=>$i<$p['lastid']));if($limit)$ids=array_slice($ids,$offset,$limit);
            return array_map(fn($i)=>(object)['id'=>$i,'offerid'=>$i,'source'=>'leader','timecreated'=>$i],$ids);
        }
    }
    $root=dirname(__DIR__,2);
    foreach(['library_offer','library_access','library_store','library_grant','library_view','library_runtime'] as $f)require $root.'/moodle/local/ustar/classes/'.$f.'.php';
    $out=['scope'=>'real production reader methods with counting synthetic DB; DB calls, not actual PostgreSQL SQL count or latency'];
    $market=true;
    foreach([24,240,2400] as $n){$DB=new AuditDB();$DB->n=$n;$v=\local_ustar\library_store::storefront(2);$out['storefront'][]=['offers'=>$n,'rendered'=>count($v['items']),'db_calls'=>array_sum($DB->reads),'by_method_table'=>$DB->reads];}
    $DB=new AuditDB();$DB->n=501;$DB->grants=true;
    $v=\local_ustar\library_view::mine(2,'ONLY-OLDEST');
    $out['mine_501']=['active_grants'=>501,'searched_exact_title'=>'ONLY-OLDEST','found_exact'=>in_array('ONLY-OLDEST',array_column($v['materials'],'title')),'matched_count'=>$v['materialcount'],'db_calls'=>array_sum($DB->reads)];
    if(!$out['mine_501']['found_exact'])throw new \Exception('Oldest grant must remain searchable');
    $market=false;$DB=new AuditDB();$DB->n=1;$DB->type='catalog';$DB->policy='paid';
    $v=\local_ustar\library_view::catalog(2,0,0);
    $out['hidden_market_catalog']=['cards'=>$v['cards'],'can_view_marketplace'=>\local_ustar\library_access::can_view_marketplace(2),'can_view_offer_page'=>\local_ustar\library_access::can_view_offer_page(1,2)];
    if($v['cards'])throw new \Exception('Closed marketplace teaser must not be advertised');
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
}
