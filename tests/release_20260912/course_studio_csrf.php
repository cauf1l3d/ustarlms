<?php
// Execute the actual controller against an isolated Moodle contract fixture; no DB/runtime.
$root = sys_get_temp_dir() . '/ustar-csrf-' . bin2hex(random_bytes(8));
mkdir($root . '/local/ustar', 0700, true);
copy(__DIR__ . '/../../moodle/local/ustar/course_studio_api.php', $root . '/local/ustar/course_studio_api.php');
file_put_contents($root . '/config.php', <<<'FIXTURE'
<?php
namespace core\session { class manager { public static $closed=false; public static function write_close() { self::$closed=true; } } }
namespace local_ustar { class course_studio { public static function require_author(...$args) {} } }
namespace {
class invalid_parameter_exception extends \Exception { public $debuginfo = ''; }
class required_capability_exception extends \Exception {}
const PARAM_RAW_TRIMMED = 1;
class context_system { public static function instance() { return new self; } }
class moodle_page { public $context=null; public function set_context($context) { $this->context=$context; } }
$PAGE = new moodle_page();
$USER = (object)['id'=>2];
$_SERVER['REQUEST_METHOD'] = 'POST';
$token = getenv('STUDIO_TEST_TOKEN');
if ($token !== 'missing') { $_SERVER['HTTP_X_MOODLE_SESSKEY'] = $token; }
function isloggedin() { return true; }
function isguestuser() { return false; }
function optional_param(...$args) { return getenv('STUDIO_TEST_ROUTE') ?: 'session'; }
function confirm_sesskey($key = null) {
    // Moodle falls back to required_param when the argument is empty.
    if (empty($key)) { throw new \Exception('missing required sesskey'); }
    return hash_equals('valid-token', $key);
}
register_shutdown_function(function() {
    global $PAGE;
    if (getenv('STUDIO_TEST_TOKEN') === 'valid-token') {
        if (!$PAGE->context) { echo 'PAGE_CONTEXT_MISSING'; }
        if (getenv('STUDIO_TEST_ROUTE') === 'prepare' && \core\session\manager::$closed) { echo 'SESSION_CLOSED_BEFORE_PREPARE'; }
    }
    echo "\nSTATUS=" . http_response_code(); });
}
FIXTURE);
try {
    foreach (['missing','', '0', 'wrong-token', 'valid-token', 'valid-token'] as $index=>$token) {
        $env = getenv(); $env['STUDIO_TEST_TOKEN'] = $token; $env['STUDIO_TEST_ROUTE'] = $index===5 ? 'prepare' : 'session';
        $process = proc_open([PHP_BINARY, '-n', $root . '/local/ustar/course_studio_api.php'],
            [1=>['pipe','w'],2=>['pipe','w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
        $expected = $token === 'valid-token' ? 400 : 403;
        if (str_contains($out,'PAGE_CONTEXT_MISSING') || str_contains($out,'SESSION_CLOSED_BEFORE_PREPARE') || $status !== 0 || !str_ends_with($out, 'STATUS=' . $expected)
                || ($expected === 403 && !str_contains($out, '"code":"session"'))) {
            throw new Exception('Token ' . var_export($token,true) . ' expected' . $expected . ': ' . $out . $err);
        }
    }
    echo "COURSE_STUDIO_CSRF_OK missing empty zero invalid tokens403 valid token passes guard PAGE context prepare session retained\n";
} finally {
    unlink($root . '/local/ustar/course_studio_api.php'); unlink($root . '/config.php');
    rmdir($root . '/local/ustar'); rmdir($root . '/local'); rmdir($root);
}
