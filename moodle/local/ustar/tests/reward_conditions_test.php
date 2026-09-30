<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
#[\PHPUnit\Framework\Attributes\CoversClass(reward_conditions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(reward_control::class)]
final class reward_conditions_test extends \advanced_testcase {
    protected function setUp(): void { parent::setUp();$this->resetAfterTest(true);$this->setAdminUser(); }
    public function test_person_period_and_source_filter_freeze_one_grant(): void {
        global $DB;
        $u=$this->getDataGenerator()->create_user();$v=$this->getDataGenerator()->create_user();$at=time()-10;
        $condition=['id'=>'fixture','title'=>'Person reward','kind'=>'task','scope'=>'person','scopeid'=>(string)$u->id,
            'resource'=>'','from'=>$at,'until'=>$at+5,'xp'=>35,'coins'=>2,'active'=>true];
        set_config('reward_rules_v1',json_encode([['at'=>$at,'rules'=>reward_control::defaults(),'conditions'=>[$condition]]]),'local_ustar');
        $this->assertSame(0,reward_control::amounts($u->id,'task','1',$at-1)['xp']);
        $this->assertSame(0,reward_control::amounts($v->id,'task','1',$at)['xp']);
        $this->assertSame(0,reward_control::amounts($u->id,'task','1',$at+6)['xp']);
        $this->assertTrue(reward_control::grant($u->id,'task','1',$at,'condition-fixture'));
        $this->assertFalse(reward_control::grant($u->id,'task','1',$at,'condition-fixture'));
        $this->assertSame(2,economy::balance($u->id));
        $this->assertSame(35,(int)$DB->get_field('local_ustar_reward_grants','xp',['eventkey'=>'condition-fixture']));
    }
    public function test_condition_validation_rejects_unknown_resource_and_learning_coins(): void {
        $u=$this->getDataGenerator()->create_user();$input=['title'=>'Fixture','kind'=>'course','scope'=>'person',
            'scopeid'=>(string)$u->id,'resource'=>'','from'=>time()+DAYSECS,'until'=>0,'xp'=>20,'coins'=>1];
        try { reward_conditions::validate($input);$this->fail('Learning minted coins'); }
        catch (\invalid_parameter_exception $e) { $this->assertStringContainsString('USCOIN',$e->getMessage()); }
        $input['coins']=0;$input['resource']='999999';$this->expectException(\invalid_parameter_exception::class);reward_conditions::validate($input);
    }
    public function test_new_condition_keeps_base_rules_and_stale_version_cannot_override(): void {
        global $DB;
        $actor=(int)get_admin()->id;$DB->set_field('user','username','emheadabdurahman',['id'=>$actor]);$this->setAdminUser();
        $input=['title'=>'Future','kind'=>'task','scope'=>'company','scopeid'=>'','resource'=>'','from'=>time()+DAYSECS,
            'until'=>0,'xp'=>20,'coins'=>1];$expected=hash('sha256',json_encode(reward_control::versions()));
        reward_control::save_condition($actor,$input,'','Fixture',$expected);
        $versions=reward_control::versions();$this->assertSame(reward_control::defaults(),$versions[0]['rules']);
        $this->assertCount(1,$versions[0]['conditions']);
        $this->expectException(\moodle_exception::class);reward_control::save_condition($actor,$input,'','Stale',$expected);
    }
    public function test_course_completion_uses_real_record_and_is_idempotent(): void {
        global $DB;
        $u=$this->getDataGenerator()->create_user();$course=$this->getDataGenerator()->create_course(['enablecompletion'=>1]);
        $this->getDataGenerator()->enrol_user($u->id,$course->id);set_config('completion_reward_startedat',time()-10,'local_ustar');
        $id=$DB->insert_record('course_completions',(object)['userid'=>$u->id,'course'=>$course->id,'timecompleted'=>time()]);
        reward_control::course_completion($u->id,$course->id);reward_control::course_completion($u->id,$course->id);
        $this->assertSame(1,$DB->count_records('local_ustar_reward_grants',['eventkey'=>'learning-course:'.$id]));
        $this->assertSame(100,(int)$DB->get_field('local_ustar_reward_grants','xp',['eventkey'=>'learning-course:'.$id]));
        $this->assertSame(0,economy::balance($u->id));
    }
}
