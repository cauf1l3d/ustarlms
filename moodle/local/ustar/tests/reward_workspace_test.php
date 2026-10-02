<?php
namespace local_ustar;
use local_ustar\task_workspace\{recipients,home_cards,service};
defined('MOODLE_INTERNAL') || die();
#[\PHPUnit\Framework\Attributes\CoversClass(reward_control::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(recipients::class)]
final class reward_workspace_test extends \advanced_testcase {
    protected function setUp(): void { parent::setUp(); $this->resetAfterTest(true); $this->setAdminUser(); }
    private function owner(): int {
        global $DB;
        $id=(int)get_admin()->id; $DB->set_field('user','username','emheadabdurahman',['id'=>$id]);
        $this->setAdminUser(); return $id;
    }
    private function employee(): \stdClass { return $this->getDataGenerator()->create_user(); }
    private function policy(array $changes, int $at): void {
        $rules=reward_control::defaults(); foreach ($changes as $kind=>$rule) { $rules[$kind]=$rule; }
        set_config('reward_rules_v1',json_encode([['at'=>$at,'rules'=>$rules,'actor'=>get_admin()->id,'reason'=>'Fixture']]),'local_ustar');
    }
    private function grant_cap(int $userid,string $cap): void {
        $role=create_role('Fixture','rw_'.random_string(8),'Fixture'); $ctx=\context_system::instance();
        assign_capability($cap,CAP_ALLOW,$role,$ctx->id); role_assign($role,$userid,$ctx->id); accesslib_clear_all_caches(true);
    }
    public function test_old_events_keep_old_rule_and_new_events_use_configured_rule(): void {
        $at=time()-10; $this->policy(['route'=>['xp'=>22,'coins'=>3]],$at);
        $this->assertSame(10,reward_control::rules($at-1)['route']['xp']);
        $this->assertSame(22,reward_control::rules($at)['route']['xp']);
    }
    public function test_zero_coin_reward_is_idempotent_and_xp_is_frozen(): void {
        global $DB;
        $u=$this->employee(); $at=time()-1;
        $this->policy(['task'=>['xp'=>25,'coins'=>0]],$at-1);
        $this->assertTrue(reward_control::grant($u->id,'task','1',$at,'test-work:1'));
        $this->policy(['task'=>['xp'=>900,'coins'=>1]],time());
        $this->assertFalse(reward_control::grant($u->id,'task','1',time(),'test-work:1'));
        $this->assertSame(25,(int)$DB->get_field('local_ustar_reward_grants','xp',['eventkey'=>'test-work:1']));
        $this->assertSame(0,economy::balance($u->id));
    }
    public function test_only_named_admin_can_control_rewards(): void {
        $this->assertFalse(reward_control::can_manage((int)get_admin()->id));
        $id=$this->owner(); $this->assertTrue(reward_control::can_manage($id));
        $this->expectException(\required_capability_exception::class);
        $u=$this->employee(); $this->setUser($u); reward_control::assert_manager($id);
    }
    public function test_reset_preserves_learning_and_coin_history_and_retries_once(): void {
        global $DB;
        $actor=$this->owner(); $u=$this->employee();
        economy::post($u->id,7,'fixture','fixture-coin');
        $this->policy(['task'=>['xp'=>30,'coins'=>0]],time()-20);
        reward_control::grant($u->id,'task','12',time()-10,'test-work:12');
        $before=reward_control::preview($actor,$u->id);
        $this->assertSame(30,$before['xp']);
        $token=str_repeat('a',32);
        reward_control::reset($actor,$u->id,['xp','coins'],'Fixture reset',$before,$token);
        reward_control::reset($actor,$u->id,['xp','coins'],'Retry',$before,$token);
        $this->assertSame(0,reward_control::xp($u->id)['xp']);
        $this->assertSame(0,economy::balance($u->id));
        $this->assertSame(2,$DB->count_records('local_ustar_coin_ledger',['userid'=>$u->id]));
        $this->assertSame(1,$DB->count_records('local_ustar_reward_grants',['userid'=>$u->id]));
        $this->assertSame(1,$DB->count_records('local_ustar_reward_resets',['userid'=>$u->id]));
    }
    public function test_changed_balance_rejects_reset_without_touching_other_indicators(): void {
        $actor=$this->owner(); $u=$this->employee(); economy::post($u->id,7,'fixture','fixture-coin');
        $before=reward_control::preview($actor,$u->id); economy::post($u->id,2,'fixture','new-coin');
        try { reward_control::reset($actor,$u->id,['xp','coins'],'Fixture',$before,str_repeat('b',32)); $this->fail('Stale preview accepted'); }
        catch (\moodle_exception $e) { $this->assertSame(9,economy::balance($u->id)); $this->assertEquals(0,get_user_preferences('ustar_xp_reset_at',0,$u->id)); }
    }
    public function test_coin_lock_checks_expected_amount(): void {
        $actor=$this->owner(); $u=$this->employee(); economy::post($u->id,8,'fixture','fixture-coin');
        $this->expectException(\moodle_exception::class);
        economy::spend($u->id,5,'admin_reset','reset-example','reset','1','Fixture',$actor,5);
    }
    public function test_real_employee_picker_filters_assignment_and_rejects_stale_selection(): void {
        global $DB;
        $u=$this->employee(); $admin=(int)get_admin()->id;
        $place=$DB->insert_record('local_ustar_staff_places',(object)['placecode'=>'rw_employee','positionid'=>'retail_seller','departmentid'=>'retail']);
        $DB->insert_record('local_ustar_assignments',(object)['userid'=>$u->id,'staffplaceid'=>$place,'assignmenttype'=>'primary']);
        $o=recipients::options($admin,'retail','retail_seller');
        $this->assertArrayHasKey($u->id,$o['people']);
        $this->assertSame([],recipients::options($admin,'hr','retail_seller')['people']);
        $this->assertSame([],recipients::options($u->id,'retail','retail_seller')['people']);
        recipients::validate($admin,$u->id,'retail','retail_seller');
        $this->expectException(\invalid_parameter_exception::class);
        recipients::validate($admin,$u->id,'hr','retail_seller');
    }
    public function test_grading_capability_does_not_grant_registration_approval(): void {
        $hr=$this->employee(); $this->grant_cap($hr->id,'local/ustar:gradeassessments');
        $this->assertTrue(hr_access::can_grade($hr->id));
        $this->assertFalse(has_capability('local/ustar:approveregistration',\context_system::instance(),$hr->id));
        $this->assertFalse(reward_control::can_manage($hr->id));
    }
    public function test_quick_flows_keep_required_fields_and_kpi_requires_acceptance(): void {
        global $DB;
        $u=$this->employee(); $admin=(int)get_admin()->id;
        $tpl=service::save_template($admin,0,0,'Opening',[['key'=>'ready','label'=>'Check readiness','type'=>'check','required'=>true]],15,false);
        $id=service::create($admin,$u->id,['kind'=>'checklist','title'=>'Open','description'=>'Test','date'=>date('Y-m-d',time()-DAYSECS),'clock'=>'09:00','templateid'=>$tpl]);
        $this->setUser($u);
        $html=home_cards::render($u->id);
        $this->assertStringContainsString('answer_ready',$html);
        $this->assertStringContainsString('Мои задачи',$html);
        $this->assertStringContainsString('Мои чек-листы',$html);
        $this->assertSame(0,reward_control::kpi($u->id));
        service::report($id,$u->id,1,['ready'=>1],'Ready',true);
        $this->assertSame(0,reward_control::kpi($u->id));
        $this->setAdminUser(); learning_tasks::transition($id,$admin,'approve',2);
        $this->assertSame(15,reward_control::kpi($u->id));
    }
}
