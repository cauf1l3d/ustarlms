<?php
// Execute the real launch endpoints; only Moodle/session boundaries are synthetic here.
$root = dirname(__DIR__, 2);
$mode = $argv[1] ?? '';
if ($mode === '') {
    foreach (['course', 'activity', 'course-activity', 'foreign', 'stale', 'future', 'preview', 'cached-course', 'cached-activity'] as $case) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case), $status);
        if ($status) { exit($status); }
    }
    echo "SCORM_ROUTE_DIRECT_ENDPOINTS=PASS\n";
    exit;
}
$tmp = sys_get_temp_dir() . '/ustar-scorm-launch-' . bin2hex(random_bytes(8));
mkdir($tmp . '/local/ustar', 0700, true);
mkdir($tmp . '/local/ustar/classes', 0700);
copy($root . '/moodle/local/ustar/classes/scorm_route_launch.php', $tmp . '/local/ustar/classes/scorm_route_launch.php');
mkdir($tmp . '/mod/scorm', 0700, true);
file_put_contents($tmp . '/mod/scorm/locallib.php', '<?php');
file_put_contents($tmp . '/mod/scorm/lib.php', '<?php');
file_put_contents($tmp . '/completionlib.php', '<?php');
$bootstrap = <<<'PHP'
<?php
namespace {
define('MOODLE_INTERNAL', true); define('PARAM_INT', 1); define('MUST_EXIST', 2);
define('SCORM_UPDATE_EVERYTIME', 3); define('TOCFULLURL', 4);
define('SCORM_FORCEATTEMPT_ALWAYS', 1);
class moodle_exception extends \Exception {}
class invalid_parameter_exception extends moodle_exception {}
class required_capability_exception extends moodle_exception {}
class Redirected extends \Exception {public function __construct(public $url) {parent::__construct($url->out(false));}}
class moodle_url {
    public function __construct(public $path, public $params = []) {}
    public function out($escape = true) {return $this->path . ($this->params ? '?' . http_build_query($this->params) : '');}
}
class context_system {static function instance() {return new self();}}
class context_module {static function instance($id) {return new self();}}
function require_login(...$args) {}
function require_capability(...$args) {}
function required_param($name, $type) {return ['cmid'=>501, 'courseid'=>50, 'pointid'=>70, 'versionid'=>8][$name];}
function optional_param($name, $default, $type) {return $name === 'versionid' ? 8 : $default;}
function redirect($url) {throw new Redirected($url);}
function get_course($id) {return (object)['id'=>$id];}
function get_coursemodule_from_id(...$args) {return (object)['id'=>501, 'course'=>50, 'instance'=>60];}
function get_fast_modinfo($course, $userid) {
    return new class {
        public function get_sections() {return [0=>[500], 1=>[501]];}
        public function get_cms() {return [500=>(object)['id'=>500,'modname'=>'forum','uservisible'=>true,'completion'=>0],
            501=>(object)['id'=>501,'modname'=>'scorm','uservisible'=>true,'completion'=>2]];}
        public function get_cm($id) {return $this->get_cms()[$id];}
    };
}
class completion_info {
    public function __construct($course) {}
    public function get_data($cm, $wholecourse = false, $userid = 0) {return (object)['completionstate'=>0];}
}
function scorm_get_toc(...$args) {return (object)['sco'=>(object)['id'=>701], 'incomplete'=>true];}
function scorm_require_available(...$args) {}
function scorm_view(...$args) {}
function scorm_parse(...$args) {}
$CFG = (object)['dirroot'=>__DIR__, 'libdir'=>__DIR__];
$USER = (object)['id'=>10]; $SESSION = new \stdClass();
$DB = new class {
    public function get_field_sql($sql, $params) {return 2;}
    public function get_record($table, $params, ...$args) {
        if ($table === 'scorm') {return (object)['id'=>60,'course'=>50,'launch'=>701,'updatefreq'=>0,'forcenewattempt'=>0];}
        throw new \Exception('Unexpected DB read ' . $table);
    }
};
spl_autoload_register(function($name) {
    if (str_starts_with(CASE_NAME, 'cached-') && $name === 'local_ustar\\scorm_route_launch') {return;}
    $prefix = 'local_ustar\\';
    if (str_starts_with($name, $prefix)) {
        $path = SOURCE_ROOT . '/moodle/local/ustar/classes/' . substr($name, strlen($prefix)) . '.php';
        if (is_file($path)) {require_once($path);}
    }
});
}
namespace local_ustar {
class view_as {static function assert_writable() {if (CASE_NAME === 'preview') {throw new \moodle_exception('preview');}}}
class employment {static function learning_allowed($id) {return true;}}
class adaptation_service {static function learning_blocked($id) {return false;} static function route_card($id) {return ['blocked'=>false];}}
class structure {static function resolve_user($id) {return ['position'=>['id'=>'warehouse']];}}
class position_access {static function position_for_user($id) {return ['id'=>'warehouse'];}}
class route_model {
    static function for_user($positionid, $userid) {return ['currentpoint'=>['id'=>CASE_NAME === 'future' ? 71 : 70,'canlaunch'=>true]];}
    static function current_published_version($id) {return (object)['id'=>CASE_NAME === 'stale' ? 9 : 8];}
    static function requirements_for_version($version) {
        return str_contains(CASE_NAME, 'course')
            ? [['type'=>'course', 'sourceid'=>50]] : [['type'=>'cm', 'sourceid'=>CASE_NAME === 'foreign' ? 502 : 501]];
    }
}
}
PHP;
file_put_contents($tmp . '/config.php', $bootstrap);
$iscourse = in_array($mode, ['course', 'cached-course'], true);
$endpoint = $iscourse ? 'course_launch.php' : 'scorm_launch.php';
copy($root . '/moodle/local/ustar/' . $endpoint, $tmp . '/local/ustar/' . $endpoint);
define('SOURCE_ROOT', $root); define('CASE_NAME', $mode);
try {
    try {
        require($tmp . '/local/ustar/' . $endpoint);
        throw new Exception('Endpoint neither launched nor rejected');
    } catch (Redirected $r) {
        if (in_array($mode, ['foreign', 'preview'], true)) {throw new Exception('Unauthorised launch');}
        $expected = in_array($mode, ['stale', 'future'], true) ? '/local/ustar/route.php'
            : ($iscourse ? '/local/ustar/scorm_launch.php' : '/mod/scorm/player.php');
        if ($r->url->path !== $expected) {throw new Exception($mode . ': expected ' . $expected . ', got ' . $r->url->path);}
        if ($expected === '/mod/scorm/player.php') {
            if (($r->url->params['cm'] ?? 0) !== 501 || ($r->url->params['scoid'] ?? 0) !== 701
                    || ($SESSION->ustar_scorm_route['versionid'] ?? 0) !== 8
                    || ($SESSION->ustar_scorm_route['attemptbefore'] ?? 0) !== 2) {
                throw new Exception('Player target or route attempt provenance lost');
            }
        }
    } catch (moodle_exception $e) {
        if (!in_array($mode, ['foreign', 'preview', 'stale'], true)) {throw $e;}
    }
} finally {
    foreach (['local/ustar/' . $endpoint, 'local/ustar/classes/scorm_route_launch.php', 'mod/scorm/locallib.php', 'mod/scorm/lib.php', 'completionlib.php', 'config.php'] as $file) {unlink($tmp . '/' . $file);}
    foreach (['local/ustar/classes','local/ustar','local','mod/scorm','mod',''] as $dir) {rmdir($tmp . '/' . $dir);}
}
