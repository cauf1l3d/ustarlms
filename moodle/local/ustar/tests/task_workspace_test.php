<?php
namespace local_ustar;
use local_ustar\task_workspace\{service, policy, calendar, worker};
defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(policy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(calendar::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(worker::class)]
final class task_workspace_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp(); $this->resetAfterTest(true); $this->setAdminUser();
    }
    private function employee(): \stdClass { return $this->getDataGenerator()->create_user(); }
    private function grant(int $id, array $caps): void {
        $ctx = \context_system::instance();
        $role = create_role('Workspace fixture', 'tw_' . random_string(8), 'Synthetic test');
        foreach ($caps as $cap) { assign_capability($cap, CAP_ALLOW, $role, $ctx->id); }
        role_assign($role, $id, $ctx->id); accesslib_clear_all_caches(true);
    }
    private function input(array $extra = []): array {
        return $extra + ['kind' => 'task', 'title' => 'Fixture work', 'date' => gmdate('Y-m-d'),
            'clock' => '17:00', 'policy' => policy::validate(['timezone' => 'UTC', 'mode' => 'calendar', 'weekdays' => [1,2,3,4,5,6,7]])];
    }
    private function task(\stdClass $user, array $extra = []): int {
        return service::create((int)get_admin()->id, (int)$user->id, $this->input($extra));
    }
    private function version(int $id): int { global $DB; return (int)$DB->get_field('local_ustar_learning_tasks', 'version', ['id' => $id]); }

    public function test_business_clock_skips_weekends_exclusions_and_dst(): void {
        $p = policy::validate(['timezone' => 'Europe/Berlin', 'excludedates' => ['2026-03-30']]);
        $from = calendar::timestamp('2026-03-27', '17:00', $p['timezone']);
        $end = calendar::add_minutes($from, 120, $p);
        $this->assertSame(calendar::timestamp('2026-03-31', '10:00', $p['timezone']), $end);
        $p['mode'] = 'calendar'; $this->assertSame($from + 7200, calendar::add_minutes($from,120,$p));
    }
    public function test_invalid_calendar_date_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        calendar::timestamp('2026-02-30','09:00','UTC');
    }
    public function test_private_notes_do_not_enter_workspace_counts_or_details(): void {
        $user = $this->employee(); $id = $this->task($user);
        $this->setUser($user); $note = learning_tasks::create_note($user->id, 'Private fixture', 'Secret');
        $this->assertSame(1, service::rows($user->id,'mine')['total']);
        $this->assertSame(1, service::summary($user->id,'mine')['active']);
        $this->setAdminUser();
        $this->expectException(\required_capability_exception::class);
        learning_tasks::view($note['id'],get_admin()->id);
    }
    public function test_report_draft_submit_review_and_kpi_are_distinct(): void {
        global $DB; $user = $this->employee();
        $id = $this->task($user, ['date'=>gmdate('Y-m-d',time()-DAYSECS)]);
        $this->setUser($user);
        service::report($id,$user->id,1,[],'Draft result',false);
        $this->assertSame('assigned',$DB->get_field('local_ustar_learning_tasks','status',['id'=>$id]));
        service::report($id,$user->id,2,[],'Confirmed result',true);
        $this->assertSame('in_review',$DB->get_field('local_ustar_learning_tasks','status',['id'=>$id]));
        $this->assertSame(0,service::summary($user->id,'mine')['late']);
        $rows = service::analytics($user->id,'mine',time()-3*DAYSECS,time()+DAYSECS);
        $this->assertSame(0,(int)$rows[0]['acceptedweight']);
        $this->setAdminUser(); learning_tasks::transition($id,get_admin()->id,'approve',3);
        $rows = service::analytics(get_admin()->id,'team',time()-3*DAYSECS,time()+DAYSECS);
        $this->assertSame(10,(int)$rows[0]['acceptedweight']);
        $this->assertSame(2,$DB->count_records('local_ustar_task_reports',['taskid'=>$id]));
    }
    public function test_required_photo_cannot_be_bypassed_by_legacy_transition(): void {
        $user = $this->employee(); $id = $this->task($user,['requirephoto'=>true]); $this->setUser($user);
        $this->expectException(\invalid_parameter_exception::class);
        learning_tasks::transition($id,$user->id,'submit',1,'Claim without photo');
    }
    public function test_final_report_requires_photo_when_configured(): void {
        $user = $this->employee(); $id = $this->task($user,['requirephoto'=>true]); $this->setUser($user);
        $this->expectException(\invalid_parameter_exception::class);
        service::report($id,$user->id,1,[],'No photo',true);
    }
    public function test_stale_report_version_is_rejected(): void {
        $user = $this->employee(); $id = $this->task($user); $this->setUser($user);
        service::report($id,$user->id,1,[],'Draft',false);
        $this->expectException(\moodle_exception::class);
        service::report($id,$user->id,1,[],'Stale send',true);
    }
    public function test_actor_cannot_impersonate_creator(): void {
        $user = $this->employee(); $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        service::create(get_admin()->id,$user->id,$this->input());
    }
    public function test_template_versions_are_pinned_to_existing_tasks(): void {
        $user = $this->employee(); $admin = (int)get_admin()->id;
        $tpl = service::save_template($admin,0,0,'Original',[['key'=>'ready','label'=>'Ready','type'=>'check','required'=>true]],10,false);
        $id = $this->task($user,['kind'=>'checklist','templateid'=>$tpl]);
        service::save_template($admin,$tpl,1,'Changed',[['key'=>'new','label'=>'New','type'=>'number','required'=>true]],20,true);
        $detail = service::detail($id,$admin);
        $this->assertSame('ready',$detail['fields'][0]['key']); $this->assertSame(10,$detail['kpiweight']);
        $this->setUser($user); service::report($id,$user->id,1,['ready'=>1],'',true);
    }
    public function test_manager_change_revokes_old_read_and_approval(): void {
        global $DB;
        $old=$this->employee(); $new=$this->employee(); $employee=$this->employee();
        foreach([$old,$new] as $m){$this->grant($m->id,['local/ustar:viewteam']);}
        $managerplace=$DB->insert_record('local_ustar_staff_places',(object)[
            'placecode'=>'tw_manager','positionid'=>'retail_director','departmentid'=>'retail']);
        $employeeplace=$DB->insert_record('local_ustar_staff_places',(object)[
            'placecode'=>'tw_employee','positionid'=>'retail_seller','departmentid'=>'retail','managerplaceid'=>$managerplace]);
        $assignment=$DB->insert_record('local_ustar_assignments',(object)[
            'userid'=>$old->id,'staffplaceid'=>$managerplace,'assignmenttype'=>'primary']);
        $DB->insert_record('local_ustar_assignments',(object)[
            'userid'=>$employee->id,'staffplaceid'=>$employeeplace,'assignmenttype'=>'primary']);
        $this->setUser($old); $id=service::create($old->id,$employee->id,$this->input());
        $this->setUser($employee); service::report($id,$employee->id,1,[],'Ready',true);
        $DB->set_field('local_ustar_assignments','userid',$new->id,['id'=>$assignment]);
        $task=$DB->get_record('local_ustar_learning_tasks',['id'=>$id]);
        $this->assertFalse(service::can_view_record($task,$old->id));
        $this->assertTrue(service::can_review_record($task,$new->id));
        $this->setUser($new); learning_tasks::transition($id,$new->id,'approve',2);
    }
    public function test_series_generation_is_idempotent(): void {
        global $DB; $user=$this->employee();
        $id=$this->task($user,['repeat'=>true,'enddate'=>gmdate('Y-m-d',time()+3*DAYSECS)]);
        $sid=(int)service::meta($id)->seriesid;
        $first=worker::run(); $this->assertSame(0,$first['errors']); $this->assertSame(3,$first['generated']);
        $this->assertSame(4,$DB->count_records('local_ustar_task_meta',['seriesid'=>$sid]));
        $this->assertSame(0,worker::run()['generated']);
        $this->assertSame(4,$DB->count_records('local_ustar_task_meta',['seriesid'=>$sid]));
    }
    public function test_escalation_outbox_is_idempotent_and_review_is_separate(): void {
        global $DB; $user=$this->employee();
        $hrd=$this->employee(); $this->grant($hrd->id,['local/ustar:hr','local/ustar:taskescalation']);
        $p=policy::validate(['timezone'=>'UTC','mode'=>'calendar','levels'=>[['recipient'=>'hrd','minutes'=>1]]]);
        $id=$this->task($user,['date'=>gmdate('Y-m-d',time()-DAYSECS),'policy'=>$p]);
        $first=worker::check($id); $this->assertGreaterThan(0,$first['notified']);
        $this->assertSame(0,worker::check($id)['notified']);
        $execution=$DB->count_records('local_ustar_task_escalations',['taskid'=>$id,'lane'=>'execution']);
        $this->setUser($user); service::report($id,$user->id,1,[],'Submitted late',true);
        $DB->set_field('local_ustar_task_meta','reviewdueat',time()-120,['taskid'=>$id]);
        $this->assertGreaterThan(0,worker::check($id)['notified']);
        $this->assertSame($execution,$DB->count_records('local_ustar_task_escalations',['taskid'=>$id,'lane'=>'execution']));
        $this->assertGreaterThan(0,$DB->count_records('local_ustar_task_escalations',['taskid'=>$id,'lane'=>'review']));
    }
}
