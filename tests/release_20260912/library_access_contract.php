<?php
namespace {
    define('MOODLE_INTERNAL', true); define('SQL_PARAMS_NAMED', 2); define('MUST_EXIST', 2);
    class moodle_exception extends \Exception {}
    class required_capability_exception extends \Exception {}
    class context_system { public static function instance() { return new self(); } }
    class xmldb_table { public function __construct(public $name) {} }
    function is_siteadmin($id) { return false; }
    function has_capability(...$args) { return false; }
    $eligible = true; $audience = true;
}
namespace local_ustar {
    class capabilities { const LEARNING_USE='use'; public static function has(...$args) { return true; } }
    class employment { public static function is_active($id) { global $eligible;return $eligible; } }
    class content {
        const STATUS_PUBLISHED='published';
        public static function can_access(...$args) { global $audience;return $audience; }
        public static function can_access_record(...$args) { return self::can_access(); }
        public static function can_discover_record(...$args) { return self::can_access(); }
        public static function audience_allows_record(...$args) { return self::can_access(); }
        public static function is_elevated(...$args) { return false; }
    }
    class catalog { public static function can_manage($id) { return false; } }
    class accounts { public static function is_business_account($id) { return true; } }
    class route_model { public static function current_published_version($pointid) { return null; } }
    class catalog_mastery { const POINT_ID=70;public static function has_access($uid) { return false; } }
}
namespace {
    $DB = new class {
        public array $offers=[];public array $grants=[];public array $catalog=[];public int $grantqueries=0;
        public function get_manager() { return new class { public function table_exists($table) { return true; } }; }
        public function get_record($table,$params,...$args) {
            if ($table==='local_ustar_library_offer') {return $this->offers[$params['id']]??false;}
            if ($table==='local_ustar_content') {return (object)['id'=>$params['id'],'status'=>'published','sourcekind'=>'ustar_file','type'=>'document','cmid'=>0];}
            if ($table==='user') {return (object)['id'=>$params['id'],'deleted'=>0,'suspended'=>0];}
            if ($table==='local_ustar_catalog') {return $this->catalog[$params['id']]??false;}
            throw new \RuntimeException('Unexpected table '.$table);
        }
        public function get_records_select($table,$where,$params,...$args) {
            if ($table!=='local_ustar_library_grant') {throw new \RuntimeException('Unexpected select '.$table);}
            $this->grantqueries++;
            return array_filter($this->grants,static function($g) use($where,$params) {
                if (($params['oid']??$params['offerid']??$g->offerid)!==$g->offerid || $g->userid!==($params['uid']??$params['userid']??0)) {return false;}
                if ($g->revokedat || ($g->validto && $g->validto<=time())) {return false;}
                if (str_contains($where,'validfrom') && $g->validfrom>time()) {return false;}
                return true;
            });
        }
        public function record_exists($table,...$args) { return $table==='user'; }
        public function get_in_or_equal($ids,...$args) { return ['IN (:of0)',['of0'=>$ids[0]]]; }
    };
    require __DIR__.'/../../moodle/local/ustar/classes/library_access.php';
    function offer($policy='paid',$price=3,$visibility='published',$type='content') {
        global $DB;
        $o=(object)['id'=>1,'itemtype'=>$type,'itemid'=>4,'accesspolicy'=>$policy,'pricecoin'=>$price,'visibility'=>$visibility,'gatepointid'=>88];
        $DB->offers=[1=>$o];return $o;
    }
    $grant=(object)['id'=>9,'offerid'=>1,'userid'=>10,'source'=>'purchase','revokedat'=>0,'validfrom'=>0,'validto'=>0];
    $failures=[];$cases=0;
    function check($name,callable $test) {global $failures,$cases;$cases++;try{$test();echo "PASS $name\n";}catch(\Throwable $e){$failures[]=$name;echo "FAIL $name: ".$e->getMessage()."\n";}}
    function expect($condition,$message) { if (!$condition) {throw new \RuntimeException($message);} }
    check('bulk respects inactive employment despite a grant',function() use($grant) {
        global $DB,$eligible;$o=offer();$DB->grants=[$grant];$eligible=false;
        expect(!\local_ustar\library_access::resolve_many([$o],10)[1]['canopen'],'bulk grant bypassed employment');$eligible=true;
    });$eligible=true;
    check('bulk respects audience despite a grant',function() use($grant) {
        global $DB,$audience;$o=offer();$DB->grants=[$grant];$audience=false;
        expect(!\local_ustar\library_access::resolve_many([$o],10)[1]['canview'],'bulk grant bypassed content ACL');$audience=true;
    });$audience=true;
    check('bulk respects unpublished offer despite a grant',function() use($grant) {
        global $DB;$o=offer('paid',3,'draft');$DB->grants=[$grant];
        expect(!\local_ustar\library_access::resolve_many([$o],10)[1]['canopen'],'bulk grant bypassed publication');
    });
    check('zero price preserves the gated policy',function() {
        global $DB;$DB->grants=[];offer('gated',0);
        expect(!\local_ustar\library_access::resolve(1,10)['canopen'],'zero price bypassed the route gate');
    });
    check('future grant does not open a paid body',function() use($grant) {
        global $DB;$o=offer();$future=clone $grant;$future->validfrom=time()+86400;$DB->grants=[$future];
        expect(!\local_ustar\library_access::resolve(1,10)['canopen'],'future grant applied before validfrom');
    });
    check('catalog ancestry cycles fail closed',function() {
        global $DB;$o=offer('free',0,'published','catalog');$DB->grants=[];
        $DB->catalog=[4=>(object)['id'=>4,'parentid'=>5,'active'=>1],5=>(object)['id'=>5,'parentid'=>4,'active'=>1]];
        expect(!\local_ustar\library_access::resolve(1,10)['canopen'],'cycle was accepted as a reachable subject');
    });
    if ($failures) {fwrite(STDERR,count($failures)."/$cases access contracts failed\n");exit(1);}
    echo "PASS $cases Library access contracts\n";
}
