<?php
namespace {
    define('MOODLE_INTERNAL',true);define('CONTEXT_SYSTEM',10);define('SQL_PARAMS_NAMED',2);define('MUST_EXIST',2);define('FORMAT_PLAIN',2);define('DAYSECS',86400);
    class moodle_exception extends \Exception {function __construct(...$args){parent::__construct($args[3]??$args[0]??'');}}
    class required_capability_exception extends \Exception {}
    class context_system {public $id=1;public $contextlevel=CONTEXT_SYSTEM;static function instance(){return new self;}}
    class xmldb_table {function __construct(...$a){}}
    class moodle_url {function __construct(...$a){}function out(...$a){return '/fixture';}static function make_pluginfile_url(...$a){return new self;}}
    $config=[];$admin=false;$editor=false;$active=true;$preview=false;$department='sales';$events=[];$queries=0;$storagehits=0;$sent=0;
    $USER=(object)['id'=>10];
    function get_config($plugin,$key){global $config;return $config[$key]??false;}
    function set_config($key,$value,$plugin){global $config;$config[$key]=$value;}
    function is_siteadmin(...$a){global $admin;return $admin;}
    function has_capability($name,...$a){global $admin;return $admin && $name==='local/ustar:admin';}
    function require_login(...$a){}
    function format_string($s){return $s;}function format_text($s,...$a){return $s;}
    function send_stored_file(...$a){global $sent;$sent++;}
    function get_file_storage(){global $storagehits;$storagehits++;return new class {
        function get_area_files(...$a){return [new class {function get_filepath(){return '/';}function get_filename(){return 'source.docx';}}];}
        function get_file(...$a){return new class {function is_directory(){return false;}function get_mimetype(){return 'image/png';}};}
    };}
    $DB=new class {
        function record_exists($table,...$a){return $table==='user';}
        function get_field($table,$field,$params){global $config;return $config[$params['name']]??false;}
        function get_manager(){return new class {function table_exists(...$a){return true;}};}
        function get_record($table,$params,...$a){if($table==='local_ustar_catalog')return (object)['id'=>$params['id'],'active'=>1,'parentid'=>0];return false;}
        function get_records_select(...$a){global $queries;$queries++;return [];}
        function start_delegated_transaction(){return new class {function allow_commit(){}function rollback($e){throw $e;}};}
    };
}
namespace core\lock {class lock_config {static function get_lock_factory($name){return new class {function get_lock(...$a){return new class {function release(){}};}};}}}
namespace local_ustar {
    class chat_files {const AREA='chat';}
    class feed_files {const AREA='feed';const SAVED_AREA='saved';}
    class task_files {const ATTACHMENT='taskfile';const RESULT='result';}
    class capabilities {const LEARNING_USE='use';const CATALOG_WRITE='catalog';static function has($uid,$cap){global $editor;return $cap===self::CATALOG_WRITE?$editor:true;}}
    class employment {static function is_active($uid){global $active;return $active;}}
    class view_as {static function active(){global $preview;return $preview;}static function assert_writable(){if(self::active())throw new \moodle_exception('preview');}}
    class structure {const NAME_STRUCTURE='structure';static function get($key){return ['departments'=>[['id'=>'sales','name'=>'Продажи'],['id'=>'warehouse','name'=>'Склад']]];}}
    class content {static function user_scope($uid){global $department;return ['departmentid'=>$department];}}
    class economy {static function available(){return false;}}
    class library_offer {static function audit($id,$event,$actor,$details){global $events;$events[]=[$event,$actor,$details];}}
    class library_runtime {static function catalog_allowed(...$a){return true;}}
}
namespace {
    require __DIR__.'/../../moodle/local/ustar/classes/library_access.php';
    require __DIR__.'/../../moodle/local/ustar/classes/library_store.php';
    require __DIR__.'/../../moodle/local/ustar/classes/catalog.php';
    require __DIR__.'/../../moodle/local/ustar/lib.php';
    $cases=0;$failed=0;
    function check($why,$condition){global $cases,$failed;$cases++;if(!$condition){$failed++;fwrite(STDERR,"FAIL $why\n");}}
    function denied(callable $fn){try{$fn();return false;}catch(\moodle_exception|\required_capability_exception $e){return true;}}
    use local_ustar\library_access as Access;use local_ustar\catalog as Catalog;
    check('marketplace hidden by default',!Access::can_view_marketplace(10));
    $queries=0;$store=\local_ustar\library_store::storefront(10);check('hidden service leaks no rows or counts',$store['total']===0 && !$store['items'] && $queries===0);
    $purchase=\local_ustar\library_store::purchase(1,10);check('direct purchase denied',$purchase['reason']==='marketplace_hidden');
    check('unowned offer detail denied',!Access::can_view_offer_page(1,10));
    $config['library_marketplace_departments']='["sales"]';check('selected department visible',Access::can_view_marketplace(10));
    $department='warehouse';check('foreign department hidden',!Access::can_view_marketplace(10));
    $department='';check('unresolved employee department hidden',!Access::can_view_marketplace(10));
    $department='deleted';$config['library_marketplace_departments']='["deleted"]';check('deleted configured department hidden',!Access::can_view_marketplace(10));
    $department='sales';foreach(['invalid','{"sales":true}','[1,null]'] as $bad){$config['library_marketplace_departments']=$bad;check('malformed allowlist fails closed',!Access::can_view_marketplace(10));}
    $config['library_marketplace_departments']='["sales"]';$active=false;check('inactive employee denied',!Access::can_view_marketplace(10));$active=true;
    $config=[];$admin=true;check('administrator can configure and preview',Access::can_view_marketplace(10));
    $preview=true;check('admin preview does not bypass hidden department',!Access::can_view_marketplace(10));$preview=false;
    Access::save_marketplace_departments(['sales','sales'],10,0);check('admin stores canonical unique department IDs',Access::marketplace_departments()===['sales']);
    $revision=(int)$config['library_settings_revision'];check('change has audit receipt',count($events)===1 && $revision>0);
    check('stale settings rejected',denied(fn()=>Access::save_marketplace_departments(['warehouse'],10,0)));
    check('unknown department rejected',denied(fn()=>Access::save_marketplace_departments(['forged'],10,$revision)));
    $admin=false;check('staff cannot change department access',denied(fn()=>Access::save_marketplace_departments(['warehouse'],10,$revision)));
    $storagehits=0;check('employee source URL not disclosed',Catalog::file_url(22,Catalog::FILEAREA_SOURCE,true)==='');
    check('employee source filename not disclosed',Catalog::source_filename(22)==='');check('source metadata not read for staff',$storagehits===0);
    check('direct source file denied',!local_ustar_pluginfile(null,null,context_system::instance(),'catalog_source',[22,'source.docx'],true));
    check('source file never sent',$sent===0);
    check('product image remains usable',local_ustar_pluginfile(null,null,context_system::instance(),'catalog_image',[22,'image.png'],false));
    $editor=true;check('editor source assets retained',Catalog::can_download_source(10) && Catalog::file_url(22,Catalog::FILEAREA_SOURCE,true)!=='');
    $preview=true;check('editor preview cannot download source',!Catalog::can_download_source(10));
    if($failed){fwrite(STDERR,"$failed/$cases failed\n");exit(1);}echo "PASS Marketplace/source contracts=$cases\n";
}
