<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
use local_ustar\connect\{identity,identity_service,notification_preferences,notification_delivery};

#[\PHPUnit\Framework\Attributes\CoversClass(notification_preferences::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(notification_delivery::class)]
final class connect_notification_test extends \advanced_testcase {
    private \stdClass $employee;
    private int $taskid;
    private int $sequence = 0;
    protected function setUp(): void {
        parent::setUp(); $this->resetAfterTest(true); $this->preventResetByRollback();
        global $CFG;
        $CFG->ustar_connect_channels = ['telegram'=>['tenant'=>'123456','username'=>'ustarcorp_bot']];
        $CFG->ustar_connect_notifications_enabled = true;
        $CFG->ustar_connect_notify_since = time()-1;
        unset($CFG->ustar_connect_notify_userids);
        $this->employee = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $this->taskid = \local_ustar\task_workspace\service::create((int)get_admin()->id,(int)$this->employee->id,
            ['kind'=>'task','title'=>'PRIVATE TASK TITLE','date'=>gmdate('Y-m-d',time()+DAYSECS),'clock'=>'17:00',
             'policy'=>\local_ustar\task_workspace\policy::validate(['timezone'=>'UTC','mode'=>'calendar'])]);
        $this->setUser($this->employee);
        $c=identity_service::issue('telegram','123456');
        identity_service::claim_verified($c['code'],new identity('telegram','123456','77777'),hash('sha256',$c['code']));
        identity_service::confirm($c['code']);
    }
    private function prefs(array $extra=[]): array {
        return notification_preferences::save_own($extra+['enabled'=>true,'categories'=>['tasks'],
            'min_severity'=>'normal','quiet_start'=>'00:00','quiet_end'=>'00:00','timezone'=>'UTC','interval_minutes'=>1]);
    }
    private function notice(): int {
        global $DB;
        $key='task-revised:'.$this->taskid.':'.(++$this->sequence);
        workflow_notifications::enqueue((int)$this->employee->id,'task_revised','PRIVATE SUBJECT','PRIVATE MESSAGE',
            '/local/ustar/tasks.php?tab=assigned',$key);
        return (int)$DB->get_field('local_ustar_notifications','id',['idempotencykey'=>$key],MUST_EXIST);
    }
    private function lease(?int $now=null): ?array {
        return notification_delivery::lease('tg_delivery','telegram','123456',$now);
    }
    private function begin(array $lease,?int $now=null): array {
        return notification_delivery::begin($lease['delivery'],$lease['lease'],'tg_delivery','telegram','123456',$now);
    }
    private function finish(array $lease,string $outcome='provider_accepted',string $ref='99',string $reason='',int $retry=0): array {
        return notification_delivery::finish($lease['delivery'],$lease['lease'],$outcome,$ref,$reason,$retry,
            'tg_delivery','telegram','123456');
    }
    private function denied(callable $call,string $reason): void {
        try {$call();$this->fail('Expected '.$reason);}catch(\InvalidArgumentException $e){$this->assertSame($reason,$e->getMessage());}
    }
    private function grant(array $caps): int {
        $context=\context_system::instance();$role=create_role('Notification fixture','notify_'.random_string(8),'Synthetic');
        foreach ($caps as $cap) {assign_capability($cap,CAP_ALLOW,$role,$context->id);}
        role_assign($role,$this->employee->id,$context->id);accesslib_clear_all_caches(true);
        // Role changes precede the owner's next HTTP request in the real adapter.
        $this->setUser($this->employee);
        return $role;
    }
    public function test_pilot_allowlist_excludes_other_recipients_before_enqueue(): void {
        global $CFG, $DB;
        $this->prefs();
        $CFG->ustar_connect_notify_userids = [(int)$this->employee->id + 1000];
        $this->notice();
        $this->assertSame(0, $DB->count_records('local_ustar_notify_delivery', ['channel'=>'telegram']));
        $CFG->ustar_connect_notify_userids = [(int)$this->employee->id];
        $this->notice();
        $this->assertNotNull($this->lease());
    }
    public function test_pilot_removed_after_lease_cannot_send(): void {
        global $CFG;
        $this->prefs();
        $CFG->ustar_connect_notify_userids = [(int)$this->employee->id];
        $this->notice(); $lease = $this->lease();
        $CFG->ustar_connect_notify_userids = [];
        $this->assertSame(['status'=>'cancelled'], $this->begin($lease));
    }
    public function test_malformed_pilot_allowlist_fails_closed(): void {
        global $CFG, $DB;
        $this->prefs();
        foreach ([null, false, 'all', [(string)$this->employee->id], [(int)$this->employee->id, 0]] as $value) {
            $CFG->ustar_connect_notify_userids = $value;
            $this->notice();
        }
        $this->assertSame(0, $DB->count_records('local_ustar_notify_delivery', ['channel'=>'telegram']));
    }
    public function test_default_disabled_new_only_and_legacy_bitrix_untouched(): void {
        global $DB,$CFG;
        $this->assertFalse(notification_preferences::for_user((int)$this->employee->id)['enabled']);
        $old=$this->notice();$this->assertSame(0,$DB->count_records('local_ustar_notify_delivery',['channel'=>'telegram']));
        $this->prefs();$id=$this->notice();
        $this->assertFalse($DB->record_exists('local_ustar_notify_delivery',['notificationid'=>$old,'channel'=>'telegram']));
        $row=$DB->get_record('local_ustar_notify_delivery',['notificationid'=>$id,'channel'=>'telegram'],'*',MUST_EXIST);
        $this->assertSame(1,(int)$row->connectversion);
        $legacy=$DB->insert_record('local_ustar_notify_delivery',(object)['notificationid'=>$id,'channel'=>'bitrix',
            'status'=>'pending','attempts'=>0,'timecreated'=>time(),'timemodified'=>time()]);
        $this->lease();$this->assertSame('pending',$DB->get_field('local_ustar_notify_delivery','status',['id'=>$legacy]));
        $CFG->ustar_connect_notifications_enabled=false;
        $this->notice();$this->assertSame(1,$DB->count_records('local_ustar_notify_delivery',['channel'=>'telegram']));
    }
    public function test_lease_is_exclusive_hash_only_and_begin_returns_no_private_content(): void {
        global $DB;
        $this->prefs();$this->notice();$lease=$this->lease();
        $this->assertNotNull($lease);$this->assertNull($this->lease());
        $row=$DB->get_record('local_ustar_notify_delivery',['id'=>$lease['delivery']]);
        $this->assertStringNotContainsString($lease['lease'],json_encode($row));
        $packet=$this->begin($lease);
        $this->assertSame(['status','subject','category','severity','occurred_at','send_before'],array_keys($packet));
        $this->assertSame('77777',$packet['subject']);$this->assertSame('tasks',$packet['category']);
        $this->assertStringNotContainsString('PRIVATE',json_encode($packet));
        $this->assertArrayNotHasKey('userid',$packet);$this->assertArrayNotHasKey('message',$packet);
        $this->denied(fn()=>$this->begin($lease),'invalid_delivery');
    }
    public function test_receipt_is_idempotent_and_not_human_ack(): void {
        global $DB;
        $this->prefs();$nid=$this->notice();$lease=$this->lease();$this->begin($lease);
        $result=$this->finish($lease);$this->assertSame('provider_accepted',$result['status']);
        $this->assertSame($result,$this->finish($lease));
        $this->assertSame('unread',$DB->get_field('local_ustar_notifications','status',['id'=>$nid]));
        $this->denied(fn()=>$this->finish($lease,'provider_accepted','100'),'receipt_conflict');
    }
    public function test_key_tenant_and_lease_are_fenced(): void {
        $this->prefs();$this->notice();$l=$this->lease();
        $this->denied(fn()=>notification_delivery::begin($l['delivery'],$l['lease'],'other','telegram','123456'),'invalid_delivery');
        $this->denied(fn()=>notification_delivery::begin($l['delivery'],$l['lease'],'tg_delivery','telegram','999'),'invalid_delivery');
        $this->denied(fn()=>notification_delivery::begin($l['delivery'],'ud1_'.str_repeat('A',43),'tg_delivery','telegram','123456'),'invalid_delivery');
        $this->assertSame('77777',$this->begin($l)['subject']);
    }
    public function test_opt_out_after_lease_cancels_before_external_send(): void {
        global $DB;
        $this->prefs();$this->notice();$l=$this->lease();$this->prefs(['enabled'=>false]);
        $this->assertSame('cancelled',$this->begin($l)['status']);
        $this->assertSame('cancelled',$DB->get_field('local_ustar_notify_delivery','status',['id'=>$l['delivery']]));
    }
    public function test_revoked_link_and_changed_task_scope_cancel(): void {
        global $DB;
        $this->prefs();$this->notice();$l=$this->lease();
        $DB->set_field('local_ustar_learning_tasks','assigneeid',$this->getDataGenerator()->create_user()->id,['id'=>$this->taskid]);
        $this->assertSame('cancelled',$this->begin($l)['status']);
        $DB->set_field('local_ustar_learning_tasks','assigneeid',$this->employee->id,['id'=>$this->taskid]);
        $this->notice();$l=$this->lease();$links=identity_service::owned_links();identity_service::revoke($links[0]['id']);
        $this->assertSame('cancelled',$this->begin($l)['status']);
    }
    public function test_suspension_and_domain_resolution_cancel(): void {
        global $DB;
        $this->prefs();$nid=$this->notice();$l=$this->lease();
        $DB->set_field('user','suspended',1,['id'=>$this->employee->id]);
        $this->assertSame('cancelled',$this->begin($l)['status']);
        $DB->set_field('user','suspended',0,['id'=>$this->employee->id]);
        $this->notice();$l=$this->lease();
        $DB->set_field('local_ustar_notifications','status','acknowledged',['id'=>(int)$DB->get_field('local_ustar_notify_delivery','notificationid',['id'=>$l['delivery']])]);
        $this->assertSame('cancelled',$this->begin($l)['status']);
    }
    public function test_expired_unbegun_lease_is_reclaimed_but_sending_is_uncertain(): void {
        global $DB;
        $this->prefs();$this->notice();$l=$this->lease();
        $DB->set_field('local_ustar_notify_delivery','connleaseuntil',time()-1,['id'=>$l['delivery']]);
        $again=$this->lease();$this->assertSame($l['delivery'],$again['delivery']);$this->assertNotSame($l['lease'],$again['lease']);
        $this->begin($again);
        $DB->set_field('local_ustar_notify_delivery','connleaseuntil',time()-1,['id'=>$again['delivery']]);
        $this->assertNull($this->lease());
        $this->assertSame('uncertain',$DB->get_field('local_ustar_notify_delivery','status',['id'=>$l['delivery']]));
        $this->assertSame('provider_accepted',$this->finish($again)['status']);
    }
    public function test_rate_limit_is_durable_and_uncertain_never_auto_retries(): void {
        global $DB;
        $this->prefs();$this->notice();$l=$this->lease();$this->begin($l);
        $this->finish($l,'retry_wait','','rate_limited',120);$this->assertNull($this->lease());
        $DB->set_field('local_ustar_notify_delivery','nextattempt',time()-1,['id'=>$l['delivery']]);
        $DB->set_field('local_ustar_notify_delivery','connbegun',time()-121,['id'=>$l['delivery']]);
        $again=$this->lease();$this->assertNotNull($again);$this->begin($again);
        $this->finish($again,'uncertain','','transport_unavailable');
        $this->assertNull($this->lease());$this->assertSame('uncertain',$DB->get_field('local_ustar_notify_delivery','status',['id'=>$l['delivery']]));
    }
    public function test_attempt_cap_and_expiry_are_terminal(): void {
        global $DB;
        $this->prefs();$this->notice();$l=$this->lease();$this->begin($l);
        $DB->set_field('local_ustar_notify_delivery','attempts',5,['id'=>$l['delivery']]);
        $this->assertSame('failed',$this->finish($l,'retry_wait','','rate_limited',60)['status']);
        $this->notice();$l=$this->lease(time()+61);$this->assertNotNull($l);
        $DB->set_field('local_ustar_notify_delivery','connexpires',time()-1,['id'=>$l['delivery']]);
        $this->assertSame('cancelled',$this->begin($l)['status']);
    }
    public function test_preferences_validation_quiet_hours_and_cooldown(): void {
        $this->prefs();$this->notice();
        $quiet=$this->prefs(['quiet_start'=>'22:00','quiet_end'=>'08:00','timezone'=>'Europe/Moscow']);
        $this->assertSame('Europe/Moscow',$quiet['timezone']);
        $this->assertFalse(notification_preferences::allowed_now($quiet,strtotime('2026-10-19 20:00:00 UTC')));
        $this->assertTrue(notification_preferences::allowed_now($quiet,strtotime('2026-10-19 06:00:00 UTC')));
        foreach ([['interval_minutes'=>0],['timezone'=>'Not/AZone'],['categories'=>['all']],['quiet_start'=>'25:00']] as $bad) {
            $this->denied(fn()=>$this->prefs($bad),'invalid_preferences');
        }
        $this->prefs();$l=$this->lease();$this->begin($l);$this->finish($l);$this->notice();
        $this->assertNull($this->lease());$this->assertNotNull($this->lease(time()+61));
    }
    public function test_unknown_event_and_disabled_cutover_do_not_create_queue(): void {
        global $DB,$CFG;
        $this->prefs();workflow_notifications::enqueue($this->employee->id,'unmapped_event','Subject','Body','/','unknown-event');
        $CFG->ustar_connect_notify_since=time()+100;$this->notice();
        $this->assertSame(0,$DB->count_records('local_ustar_notify_delivery',['channel'=>'telegram']));
    }
    public function test_settings_are_owned_https_csrf_and_not_a_role_grant(): void {
        global $DB,$USER;
        $prefs=$this->prefs();
        $this->denied(fn()=>\local_ustar\connect\link_management::handle('POST',true,
            ['action'=>'preferences','sesskey'=>'wrong','preferences'=>$prefs]),'invalid_sesskey');
        $this->denied(fn()=>\local_ustar\connect\link_management::handle('POST',false,
            ['action'=>'preferences','sesskey'=>sesskey(),'preferences'=>$prefs]),'https_required');
        $other=$this->getDataGenerator()->create_user();$this->setUser($other);
        $this->denied(fn()=>\local_ustar\connect\link_management::handle('POST',true,
            ['action'=>'preferences','sesskey'=>sesskey(),'preferences'=>$prefs+['userid'=>$this->employee->id]]),'invalid_preferences');
        $view=\local_ustar\connect\link_management::handle('POST',true,
            ['action'=>'preferences','sesskey'=>sesskey(),'preferences'=>array_replace($prefs,['enabled'=>false])]);
        $this->assertTrue($view['preferences_saved']);
        $this->assertTrue(notification_preferences::for_user((int)$this->employee->id)['enabled']);
        $this->assertFalse(notification_preferences::for_user((int)$other->id)['enabled']);
        employment::register_pending((int)$other->id);notification_preferences::save_own($prefs);
        $this->assertSame(0,identity_service::resolve_active_user(new identity('telegram','123456','99999')));
        $this->setGuestUser();$this->denied(fn()=>notification_preferences::save_own($prefs),'unauthenticated');
    }
    public function test_own_summary_hides_leases_and_privacy_erases_only_managed_delivery(): void {
        global $DB;
        $this->prefs();$id=$this->notice();$this->lease();
        $summary=notification_delivery::own_summary();$this->assertCount(1,$summary);
        $this->assertArrayNotHasKey('connleasehash',$summary[0]);$this->assertArrayNotHasKey('userid',$summary[0]);
        $legacy=$DB->insert_record('local_ustar_notify_delivery',(object)['notificationid'=>$id,'channel'=>'bitrix',
            'status'=>'pending','attempts'=>0,'timecreated'=>time(),'timemodified'=>time()]);
        \local_ustar\privacy\provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $this->employee,'local_ustar',[\context_system::instance()->id]));
        $this->assertSame(0,$DB->count_records('local_ustar_notify_delivery',['connectversion'=>1]));
        $this->assertTrue($DB->record_exists('local_ustar_notify_delivery',['id'=>$legacy]));
        $this->assertFalse($DB->record_exists('user_preferences',['userid'=>$this->employee->id,'name'=>notification_preferences::NAME]));
        $this->assertTrue($DB->record_exists('local_ustar_notifications',['id'=>$id]));
    }
    public function test_canonical_and_delivery_insert_share_business_rollback(): void {
        global $DB;
        $this->prefs();$tx=$DB->start_delegated_transaction();$id=$this->notice();
        $this->assertTrue($DB->record_exists('local_ustar_notify_delivery',['notificationid'=>$id,'connectversion'=>1]));
        try {$tx->rollback(new \RuntimeException('fixture rollback'));}catch(\RuntimeException $expected) {}
        $this->assertFalse($DB->record_exists('local_ustar_notifications',['id'=>$id]));
        $this->assertFalse($DB->record_exists('local_ustar_notify_delivery',['notificationid'=>$id]));
    }
    public function test_category_and_severity_are_rechecked_at_begin_and_dst_is_real(): void {
        $this->prefs();$this->notice();$l=$this->lease();$this->prefs(['min_severity'=>'critical']);
        $this->assertSame('cancelled',$this->begin($l)['status']);
        $this->prefs();$this->notice();$l=$this->lease();$this->prefs(['categories'=>['feed']]);
        $this->assertSame('cancelled',$this->begin($l)['status']);
        $p=$this->prefs(['quiet_start'=>'22:00','quiet_end'=>'08:00','timezone'=>'Europe/Berlin']);
        $now=strtotime('2026-10-25 00:30 UTC');
        $next=notification_preferences::next_allowed($p,$now);
        $this->assertSame(strtotime('2026-10-25 07:00 UTC'),$next);
    }
    public function test_provider_cooldown_survives_a_new_queue_item_and_worker(): void {
        $this->prefs();$this->notice();$l=$this->lease();$this->begin($l);
        $this->finish($l,'retry_wait','','rate_limited',120);$this->notice();
        $this->assertNull($this->lease(time()+61));
        $this->assertNotNull($this->lease(time()+121));
    }
    public function test_reminder_is_bound_to_actual_current_schedule(): void {
        global $DB;
        $this->prefs();
        $DB->set_field('local_ustar_learning_tasks','dueat',time()+1800,['id'=>$this->taskid]);
        \local_ustar\task_workspace\worker::check($this->taskid);
        $l=$this->lease();$this->assertNotNull($l);
        $DB->set_field('local_ustar_learning_tasks','dueat',time()+7200,['id'=>$this->taskid]);
        $this->assertSame('cancelled',$this->begin($l)['status']);
    }
    public function test_actual_registration_writer_and_current_hrd_capabilities(): void {
        global $DB;
        accounts::ensure_profile_field();
        $role=$this->grant(['local/ustar:hrmanage','local/ustar:approveregistration']);
        $this->assertTrue(has_capability('local/ustar:approveregistration',\context_system::instance(),$this->employee->id));
        $this->assertTrue(has_capability('local/ustar:hrmanage',\context_system::instance(),$this->employee->id));
        $this->prefs(['categories'=>['registration']]);
        $uid=registration_service::register(['username'=>'notify_registered','password'=>'Fixture-Only-Qx9!2026',
            'email'=>'notify-registered@example.invalid','firstname'=>'Synthetic','lastname'=>'Register','departmentid'=>'retail']);
        $this->assertGreaterThan(0,$uid);
        $canonical=$DB->get_record('local_ustar_notifications',['userid'=>$this->employee->id,'eventtype'=>'registration_requested']);
        $this->assertNotFalse($canonical,'Canonical HRD recipient must exist before transport');
        $this->assertTrue(\local_ustar\connect\notification_policy::permitted($canonical),'Current canonical recipient policy');
        $this->assertTrue($DB->record_exists('local_ustar_notify_delivery',['notificationid'=>$canonical->id,'connectversion'=>1]),'Writer must enqueue atomically');
        // The private facade has NO_MOODLE_COOKIES, not the HRD browser session.
        $this->setGuestUser();$l=$this->lease();$this->assertNotNull($l);
        role_unassign($role,$this->employee->id,\context_system::instance()->id);accesslib_clear_all_caches(true);
        $this->assertSame('cancelled',$this->begin($l)['status']);
    }
    public function test_feed_audience_is_rechecked_without_exporting_post(): void {
        global $DB;
        $this->grant(['local/ustar:use']);$this->prefs(['categories'=>['feed']]);
        $this->setAdminUser();$post=feed_service::create((int)get_admin()->id,'person',(string)get_admin()->id,
            ['all'],'PRIVATE FEED BODY',true,0,[],bin2hex(random_bytes(16)));
        $this->setUser($this->employee);
        workflow_notifications::enqueue($this->employee->id,'feed_comment','PRIVATE','PRIVATE',
            '/local/ustar/feed.php?postid='.$post,'feed_comment:'.$post.':'.$this->employee->id.':1');
        $l=$this->lease();$this->assertNotNull($l);
        $DB->delete_records('local_ustar_feed_audience',['postid'=>$post]);
        $this->assertSame('cancelled',$this->begin($l)['status']);
    }
    public function test_own_grade_decision_must_still_match_canonical_request(): void {
        global $DB;
        $this->prefs(['categories'=>['grades']]);
        $request=$DB->insert_record('local_ustar_grade_requests',(object)['userid'=>$this->employee->id,
            'fromgrade'=>'G1','tograde'=>'G2','routeid'=>1,'requirementsjson'=>'{}','managerid'=>get_admin()->id,
            'status'=>'approved','requestkey'=>'fixture-grade','requestedat'=>time(),'timecreated'=>time(),'timemodified'=>time()]);
        workflow_notifications::enqueue($this->employee->id,'grade_approved','PRIVATE','PRIVATE',
            '/local/ustar/grades.php','grade-decision:'.$request);
        $l=$this->lease();$this->assertNotNull($l);
        $DB->set_field('local_ustar_grade_requests','status','rejected',['id'=>$request]);
        $this->assertSame('cancelled',$this->begin($l)['status']);
    }
    public function test_v1_keys_unchanged_and_v2_optin_is_exact(): void {
        global $CFG,$DB;
        $this->assertTrue(method_exists(notification_delivery::class,'begin_v2'),'Versioned begin is missing');
        $this->grant(['local/ustar:use']);$this->prefs();$this->notice();$lease=$this->lease();
        $before=$DB->get_record('local_ustar_notify_delivery',['id'=>$lease['delivery']]);
        $CFG->ustar_connect_ack_enabled=false;
        $this->denied(fn()=>notification_delivery::begin_v2($lease['delivery'],$lease['lease'],
            'tg_delivery','telegram','123456'),'ack_disabled');
        $this->assertEquals($before,$DB->get_record('local_ustar_notify_delivery',['id'=>$lease['delivery']]));
        $CFG->ustar_connect_ack_enabled=true;$CFG->ustar_connect_ack_since=time()-60;
        $CFG->ustar_connect_notify_userids=[(int)$this->employee->id];
        $packet=notification_delivery::begin_v2($lease['delivery'],$lease['lease'],'tg_delivery','telegram','123456');
        $this->assertSame(['status','subject','category','severity','occurred_at','send_before','version','ack'],array_keys($packet));
        $this->assertSame(2,$packet['version']);$this->assertSame(['action','expires'],array_keys($packet['ack']));
        $row=$DB->get_record('local_ustar_conn_acktokens',['deliveryid'=>$lease['delivery']],'*',MUST_EXIST);
        $this->assertStringNotContainsString($packet['ack']['action'],json_encode($row));
        $this->assertSame('unread',$DB->get_field('local_ustar_notifications','status',['id'=>$row->notificationid]));
    }

}
