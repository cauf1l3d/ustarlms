<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();require_once(__DIR__.'/fixtures/library_fixture.php');
#[\PHPUnit\Framework\Attributes\CoversClass(library_assessment::class)]
final class library_assessment_test extends \advanced_testcase {
    use library_fixture;
    protected function setUp(): void {parent::setUp();$this->resetAfterTest(true);$this->setAdminUser();}
    public function test_resume_and_identical_resubmit_are_idempotent_and_award_nothing(): void {
        global $DB;$u=$this->employee();$id=$this->definition();$this->setUser($u);
        $a=library_assessment::get_or_start($id,$u->id);$b=library_assessment::get_or_start($id,$u->id);
        $this->assertSame((int)$a['attempt']->id,(int)$b['attempt']->id);$this->assertFalse($b['isfresh']);
        $r=library_assessment::submit($a['attempt']->id,$u->id,['q0'=>0,'q1'=>0]);
        $this->assertTrue($r['passed']);$this->assertSame($r,library_assessment::submit($a['attempt']->id,$u->id,['q0'=>0,'q1'=>0]));
        foreach (['coin_ledger','evidence_rec','route_progress','completion_cycle'] as $table) {
            $this->assertSame(0,$DB->count_records('local_ustar_'.$table,['userid'=>$u->id]),$table.' remains untouched');
        }
    }
    public function test_threshold_and_question_snapshot_survive_bank_edit(): void {
        global $DB;$u=$this->employee();$id=$this->definition(2,100);$this->setUser($u);$a=library_assessment::get_or_start($id,$u->id);
        $DB->update_record('local_ustar_library_assessment',(object)['id'=>$id,'passpercent'=>40,'revision'=>2,
            'questionsjson'=>json_encode([['text'=>'Other','options'=>['no','yes'],'correct'=>1]])]);
        $r=library_assessment::submit($a['attempt']->id,$u->id,['q0'=>0,'q1'=>1]);$this->assertFalse($r['passed']);$this->assertSame(50,$r['percent']);
    }
    public function test_changed_answers_cannot_rewrite_submitted_result(): void {
        $u=$this->employee();$id=$this->definition(1);$this->setUser($u);$a=library_assessment::get_or_start($id,$u->id);
        library_assessment::submit($a['attempt']->id,$u->id,['q0'=>0]);$this->expectException(\moodle_exception::class);
        library_assessment::submit($a['attempt']->id,$u->id,['q0'=>1]);
    }
    public function test_max_attempts_are_enforced_under_the_command_lock(): void {
        $u=$this->employee();$id=$this->definition(1,100,1);$this->setUser($u);$a=library_assessment::get_or_start($id,$u->id);
        library_assessment::submit($a['attempt']->id,$u->id,['q0'=>1]);$this->expectException(\moodle_exception::class);library_assessment::get_or_start($id,$u->id);
    }
    public function test_submission_rechecks_access_after_grant_revocation(): void {
        global $DB;$u=$this->employee();$id=$this->definition(1);$o=$this->offer();
        $DB->update_record('local_ustar_library_offer',(object)['id'=>$o->id,'itemtype'=>'assessment','itemid'=>$id]);$g=$this->grant($o,$u);
        $this->setUser($u);$a=library_assessment::get_or_start($id,$u->id);$DB->set_field('local_ustar_library_grant','revokedat',time(),['id'=>$g]);
        $this->expectException(\moodle_exception::class);library_assessment::submit($a['attempt']->id,$u->id,['q0'=>0]);
    }
    public function test_start_cannot_impersonate_another_employee(): void {
        $u=$this->employee();$id=$this->definition();$this->expectException(\required_capability_exception::class);library_assessment::get_or_start($id,$u->id);
    }
    public function test_bank_validator_rejects_duplicate_options_and_out_of_range_answers(): void {
        $this->expectException(\moodle_exception::class);library_assessment::questions('[{"text":"Q","options":["A","A"],"correct":5}]');
    }
    public function test_editor_removes_trailing_slots_without_changing_correct_option(): void {
        $json=library_assessment::editor_questions([['text'=>'Q','options'=>['True','False','',''],'correct'=>'1']]);
        $q=json_decode($json,true)[0];$this->assertSame(['True','False'],$q['options']);$this->assertSame(1,$q['correct']);
        $this->expectException(\moodle_exception::class);
        library_assessment::editor_questions([['text'=>'Q','options'=>['','True','False'],'correct'=>'1']]);
    }
}
