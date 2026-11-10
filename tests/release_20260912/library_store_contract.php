<?php
namespace {
    define('MOODLE_INTERNAL',true);define('MUST_EXIST',2);
    class moodle_exception extends \Exception {}
    class required_capability_exception extends \Exception {}
    class context_system { public static function instance() { return new self; } }
    class moodle_url { public function __construct(...$args) {} public function out(...$args) { return '/fixture'; } }
    function get_config($plugin,$key) { return match($key) {'library_store_enabled'=>1,'library_pricemin'=>1,'library_pricemax'=>25,default=>null}; }
    function format_string($text) { return $text; } function format_text($text,...$args) { return $text; }
    function sesskey() { return 'fixture'; }
}
namespace local_ustar {
    class library_access {
        const POLICY_PAID='paid'; const REASON_PURCHASE='purchase_required';
        public static function eligible($userid) { return true; }
        public static function can_view_marketplace($userid) { return true; }
        public static function require_actor($id) { if($id!==10) {throw new \required_capability_exception('real actor');} }
        public static function active_grant($offer,$userid) { return null; }
        public static function resolve(...$args) {return ['canview'=>true,'canopen'=>false,'reason'=>'purchase_required'];}
        public static function subject_accessible(...$args) { return true; }
        public static function is_authoring(...$args) { return false; }
    }
    class economy {
        public static int $coins=5; public static int $debits=0;
        public static function available() {return true;} public static function balance($uid){return self::$coins;}
        public static function spend($uid,$amount,...$args){self::$debits++;self::$coins-=$amount;return true;}
    }
    class library_offer { public static function locked($id,callable $operation) {global $DB;return $operation($DB->offer);} }
    class library_grant {const SOURCE_PURCHASE='purchase';public static function issue(...$args){return 8;}public static function purchase(...$args){return 8;}}
}
namespace {
    $DB=new class {
        public object $offer;
        public function __construct(){ $this->offer=(object)['id'=>1,'visibility'=>'published','accesspolicy'=>'paid','pricecoin'=>10,'timemodified'=>100,'title'=>'Fixture','itemtype'=>'content','itemid'=>4]; }
        public function start_delegated_transaction(){return new class {public function allow_commit(){}public function rollback($error){throw $error;}};}
        public function get_record_sql(...$args){return $this->offer;}
        public function get_record($table,$params,...$args){return false;}
        public function record_exists(...$args){return false;}
    };
    require __DIR__.'/../../moodle/local/ustar/classes/library_store.php';
    $failed=0;
    try {
        $r=\local_ustar\library_store::purchase(1,10,10,100,str_repeat('a',32));
        if($r['ok']||$r['reason']!=='insufficient_balance')throw new \RuntimeException('wrong business refusal');
        echo "PASS insufficient balance is a normal refusal with no debit\n";
    }catch(\Throwable $e){$failed++;echo 'FAIL insufficient balance: '.get_class($e).' '.$e->getMessage()."\n";}
    \local_ustar\economy::$coins=40;
    try {
        \local_ustar\library_store::purchase(1,99,10,100,str_repeat('b',32));
        $failed++;echo "FAIL spoofed actor was accepted\n";
    }catch(\required_capability_exception $e){echo "PASS spoofed actor denied\n";}catch(\Throwable $e){$failed++;echo 'FAIL wrong actor failure: '.get_class($e)."\n";}
    \local_ustar\economy::$coins=40;\local_ustar\economy::$debits=0;
    try {
        $r=\local_ustar\library_store::purchase(1,10,3,90,str_repeat('c',32));
        if($r['ok']||$r['reason']!=='offer_changed'||\local_ustar\economy::$debits)throw new \RuntimeException('stale quote was charged');
        echo "PASS stale quote refuses before wallet debit\n";
    }catch(\Throwable $e){$failed++;echo 'FAIL stale quote: '.$e->getMessage()."\n";}
    exit($failed ? 1 : 0);
}
