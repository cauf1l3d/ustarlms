<?php
define('MOODLE_INTERNAL', true);
require_once(__DIR__.'/../../moodle/local/ustar/classes/scorm_route_launch.php');
$DB = new class {
    public array $modules;
    public string $forumtype='news';
    public bool $proven=true;
    public function __construct() {
        $this->modules=[10=>(object)['id'=>10,'instance'=>20,'modname'=>'scorm','completion'=>2],
            11=>(object)['id'=>11,'instance'=>21,'modname'=>'forum','completion'=>0]];
    }
    public function get_records_sql($sql,$params) { return $this->modules; }
    public function sql_like(...$args) { return 'detailsjson LIKE :coursejson'; }
    public function get_records_select(...$args) {
        return $this->proven ? [(object)['detailsjson'=>json_encode(['courseid'=>5,'cmid'=>10])]] : [];
    }
    public function get_record($table,$params,...$args) { return (object)['completionstate'=>1,'timemodified'=>1000]; }
    public function get_field($table,$field,$params) { return $this->forumtype; }
};
function expect($value,$label) {
    $actual=\local_ustar\scorm_route_launch::managed_course_completedat(5,9);
    if ($actual!==$value) { throw new RuntimeException($label.': expected '.$value.', got '.$actual); }
}
expect(1000,'A Moodle announcements forum does not prevent completion of the sole imported learning activity');
$DB->forumtype='general';expect(0,'An added discussion forum is another activity');
$DB->forumtype='news';$DB->modules[12]=(object)['id'=>12,'instance'=>22,'modname'=>'page','completion'=>0];
expect(0,'An arbitrary extra learning activity cannot be ignored');
unset($DB->modules[11],$DB->modules[12]);$DB->proven=false;expect(0,'An arbitrary course has no import provenance');
echo "PASS imported SCORM course completion boundary\n";
