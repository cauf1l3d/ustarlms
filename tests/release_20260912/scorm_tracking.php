<?php
/** Run the authorised launch's enrolment repair without a Moodle installation. */
define('MOODLE_INTERNAL', true);
define('MUST_EXIST', 1);
define('CAP_ALLOW', 1);
define('CAP_PREVENT', -1);
define('CAP_PROHIBIT', -1000);
define('ENROL_INSTANCE_ENABLED', 0);
define('ENROL_USER_ACTIVE', 0);
class moodle_exception extends Exception {}
class context_module { public static function instance($id) { return (object)['id'=>$id]; } }
function has_capability($cap, $context, $userid) { global $allowed; return $allowed; }
function get_user_roles(...$args) { return [(object)['roleid'=>7]]; }
function role_context_capabilities($role, $context, $cap) {
    global $deny;
    return $role === 5 ? [$cap=>CAP_ALLOW] : ($deny ? [$cap=>$deny] : []);
}
function enrol_get_instances(...$args) { global $instance; return [$instance]; }
function enrol_get_plugin($name) { global $plugin; return $plugin; }
function accesslib_clear_all_caches(...$args) {}
function reload_all_capabilities() {}
$CFG = (object)['libdir'=>sys_get_temp_dir()];
$USER = (object)['id'=>132];
$allowed = false;
$deny = 0;
$instance = (object)['id'=>21,'enrol'=>'manual','status'=>0,'enrolperiod'=>0,'roleid'=>7];
$enrolment = (object)['id'=>30,'status'=>0,'timestart'=>123,'timeend'=>time()+86400];
$calls = [];
$plugin = new class {
    public function enrol_user($instance,$userid,$roleid,$start,$end,$status) {
        global $allowed,$calls;
        $calls[] = compact('userid','roleid','start','end','status');
        $allowed = true;
    }
};
$DB = new class {
    public function get_record($table,$params,...$args) {
        global $enrolment;
        return match($table) {
            'user'=>(object)['id'=>132,'deleted'=>0,'suspended'=>0],
            'course'=>(object)['id'=>29],
            'user_enrolments'=>$enrolment,
            default=>throw new Exception('Unexpected table '.$table),
        };
    }
    public function get_field($table,$field,$params,...$args) {
        if ($table==='role' && $params===['shortname'=>'student']) { return 5; }
        throw new Exception('Unexpected role lookup');
    }
    public function start_delegated_transaction() {
        return new class {
            public function allow_commit() {}
            public function rollback($error) { throw $error; }
        };
    }
};
$tmp = sys_get_temp_dir().'/ustar-tracking-fixture-'.bin2hex(random_bytes(5));
mkdir($tmp,0700);file_put_contents($tmp.'/enrollib.php','<?php');$CFG->libdir=$tmp;
require_once(__DIR__.'/../../moodle/local/ustar/classes/scorm_route_launch.php');
try {
    if (!method_exists(\local_ustar\scorm_route_launch::class,'ensure_tracking')) {
        throw new RuntimeException('Route launch still accepts enrolment without mod/scorm:savetrack');
    }
    $cm=(object)['id'=>104,'course'=>29,'instance'=>20];
    \local_ustar\scorm_route_launch::ensure_tracking($cm,$USER);
    if (!$allowed || $calls!==[['userid'=>132,'roleid'=>5,'start'=>123,'end'=>$enrolment->timeend,'status'=>0]]) {
        throw new RuntimeException('The scoped Student role must preserve the active enrolment period');
    }
    \local_ustar\scorm_route_launch::ensure_tracking($cm,$USER);
    if (count($calls)!==1) { throw new RuntimeException('A repeated launch must not change enrolment again'); }
    foreach ([CAP_PREVENT,CAP_PROHIBIT] as $permission) {
        $allowed=false;$deny=$permission;
        try { \local_ustar\scorm_route_launch::ensure_tracking($cm,$USER);throw new RuntimeException('Explicit deny ignored'); }
        catch (moodle_exception $expected) {}
        if (count($calls)!==1) { throw new RuntimeException('A denied user must not receive a role'); }
    }
    $deny=0;$instance->status=1;
    try { \local_ustar\scorm_route_launch::ensure_tracking($cm,$USER);throw new RuntimeException('Disabled instance ignored'); }
    catch (moodle_exception $expected) {}
    if ($instance->status!==1 || count($calls)!==1) { throw new RuntimeException('Disabled enrolment must stay disabled'); }
    echo "PASS SCORM tracking: scoped Student role, unchanged period, replay, explicit denials, disabled enrolment\n";
} finally {
    unlink($tmp.'/enrollib.php');rmdir($tmp);
}
