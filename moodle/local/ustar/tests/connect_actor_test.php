<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
use local_ustar\connect\{actor_service,entry_service,identity,identity_service,opaque_token};

#[\PHPUnit\Framework\Attributes\CoversClass(actor_service::class)]
final class connect_actor_test extends \advanced_testcase {
    private \stdClass $employee;
    private string $browser;
    protected function setUp(): void {
        parent::setUp();$this->resetAfterTest(true);$this->preventResetByRollback();
        global $CFG;
        $CFG->ustar_connect_provider_read_enabled=true;
        $this->employee=$this->getDataGenerator()->create_user(['firstname'=>'Synthetic','lastname'=>'Actor']);
        $this->setUser($this->employee);
        $code=identity_service::issue('telegram','123456')['code'];
        identity_service::claim_verified($code,new identity('telegram','123456','77777'),hash('sha256',$code));
        identity_service::confirm($code);$this->browser=opaque_token::issue('ub1');
        $this->setGuestUser();
    }
    private function entry(): array {
        $sender=new identity('telegram','123456','77777');$l=entry_service::create_launch($sender,'tg1');
        return entry_service::exchange($sender,$l['launch'],hash('sha256',$l['launch']),time(),$this->browser,'tg1');
    }
    private function open(?array $entry=null): array {
        return actor_service::open(($entry??$this->entry())['session'],$this->browser,'tg1','telegram','123456');
    }
    private function actor_status(array $actor): array {
        return actor_service::status($actor['session'],$this->browser,'tg1','telegram','123456');
    }
    private function denied(callable $call,string $reason): void {
        try {$call();$this->fail('Expected '.$reason);}catch(\InvalidArgumentException $e){$this->assertSame($reason,$e->getMessage());}
    }
    public function test_default_activation_disabled_and_new_secret_has_separate_scope(): void {
        global $CFG,$DB;
        unset($CFG->ustar_connect_provider_read_enabled);$entry=$this->entry();
        $this->denied(fn()=>$this->open($entry),'actor_unavailable');
        $CFG->ustar_connect_provider_read_enabled=true;$actor=$this->open($entry);
        $this->assertStringStartsWith('ua1_',$actor['session']);$this->assertSame('actor_read',$actor['scope']);
        $this->assertEqualsWithDelta(time()+28800,$actor['expires_at'],1);$this->assertEqualsWithDelta(time()+900,$actor['idle_expires_at'],1);
        $row=$DB->get_record('local_ustar_conn_sessions',['scope'=>'actor_read'],'*',MUST_EXIST);
        $this->assertStringNotContainsString($actor['session'],json_encode($row));
        $this->denied(fn()=>$this->open($entry),'invalid_entry');
        $this->denied(fn()=>entry_service::status($actor['session'],$this->browser,'tg1','telegram','123456'),'invalid_opaque_token');
        $this->denied(fn()=>$this->actor_status($entry),'invalid_actor');
    }
    public function test_status_is_real_own_profile_without_password_email_or_role_grants(): void {
        $actor=$this->open();$view=$this->actor_status($actor);
        $this->assertSame($this->employee->username,$view['profile']['username']);
        $this->assertSame('Synthetic Actor',$view['profile']['fullname']);
        $this->assertSame(['profile'],$view['features']);$this->assertSame('active',$view['profile']['account_state']);
        foreach (['password','email','userid','token','roles','authstamp','scopestamp'] as $key) {
            $this->assertArrayNotHasKey($key,$view);$this->assertArrayNotHasKey($key,$view['profile']);
        }
    }
    public function test_wrong_browser_key_tenant_and_other_actor_fail_without_burning_owner(): void {
        $a=$this->open();
        foreach ([[$this->browser,'other','telegram','123456'],[$this->browser,'tg1','telegram','999'],
                [opaque_token::issue('ub1'),'tg1','telegram','123456']] as $args) {
            $this->denied(fn()=>actor_service::status($a['session'],...$args),'invalid_actor');
        }
        $this->assertSame('active',$this->actor_status($a)['profile']['account_state']);
    }
    public function test_pending_exchange_cannot_promote_after_approval_without_fresh_launch(): void {
        global $DB;
        employment::register_pending($this->employee->id);$entry=$this->entry();
        $this->denied(fn()=>$this->open($entry),'native_auth_required');
        $DB->set_field('local_ustar_employment','status','active',['userid'=>$this->employee->id]);
        $this->denied(fn()=>$this->open($entry),'native_auth_required');
        $this->assertSame('actor_read',$this->open()['scope']);
    }
    public function test_unlinked_and_stale_proofs_do_not_issue_actor(): void {
        global $DB;
        $entry=$this->entry();
        $DB->set_field('local_ustar_conn_sessions','authdate',time()-301,['tokenhash'=>opaque_token::digest($entry['session'],'ue1')]);
        $this->denied(fn()=>$this->open($entry),'native_auth_required');
        $sender=new identity('telegram','123456','99999');$l=entry_service::create_launch($sender,'tg1');
        $e=entry_service::exchange($sender,$l['launch'],hash('sha256',$l['launch']),time(),$this->browser,'tg1');
        $this->denied(fn()=>$this->open($e),'native_auth_required');
    }
    public function test_idle_and_absolute_bounds_are_enforced(): void {
        global $DB;
        $a=$this->open();$where=['tokenhash'=>opaque_token::digest($a['session'],'ua1')];
        $DB->set_field('local_ustar_conn_sessions','lastseenat',time()-899,$where);
        $this->assertSame('actor_read',$this->actor_status($a)['scope']);
        $DB->set_field('local_ustar_conn_sessions','lastseenat',time()-901,$where);
        $this->denied(fn()=>$this->actor_status($a),'invalid_actor');
        $b=$this->open();$DB->set_field('local_ustar_conn_sessions','expiresat',time()-1,
            ['tokenhash'=>opaque_token::digest($b['session'],'ua1')]);
        $this->denied(fn()=>$this->actor_status($b),'invalid_actor');
    }
    public function test_observed_suspension_is_persistently_revoked_after_reenable(): void {
        global $DB;
        $a=$this->open();$DB->set_field('user','suspended',1,['id'=>$this->employee->id]);
        $this->denied(fn()=>$this->actor_status($a),'invalid_actor');
        $DB->set_field('user','suspended',0,['id'=>$this->employee->id]);
        $this->denied(fn()=>$this->actor_status($a),'invalid_actor');
        $this->assertGreaterThan(0,(int)$DB->get_field('local_ustar_conn_sessions','revokedat',
            ['tokenhash'=>opaque_token::digest($a['session'],'ua1')]));
    }
    public function test_password_and_auth_method_changes_invalidate_session(): void {
        global $DB;
        $a=$this->open();$DB->set_field('user','password',hash_internal_user_password('Synthetic-Changed-Qx9!2026'),['id'=>$this->employee->id]);
        $this->denied(fn()=>$this->actor_status($a),'invalid_actor');
        $b=$this->open();$DB->set_field('user','auth','nologin',['id'=>$this->employee->id]);
        $this->denied(fn()=>$this->actor_status($b),'invalid_actor');
    }
    public function test_role_change_and_own_position_change_revoke_existing_actor(): void {
        global $DB;
        $a=$this->open();$ctx=\context_system::instance();$role=create_role('Actor fixture','actor_fixture','Synthetic');
        assign_capability('local/ustar:viewteam',CAP_ALLOW,$role,$ctx->id);role_assign($role,$this->employee->id,$ctx->id);
        accesslib_clear_all_caches(true);$this->setGuestUser();
        $this->denied(fn()=>$this->actor_status($a),'invalid_actor');
        $b=$this->open();
        $field=$DB->get_record('user_info_field',['shortname'=>'ustar_position'])?:
            $this->getDataGenerator()->create_custom_profile_field(['shortname'=>'ustar_position','name'=>'Position','datatype'=>'text']);
        $DB->insert_record('user_info_data',(object)['userid'=>$this->employee->id,'fieldid'=>$field->id,'data'=>'retail_seller','dataformat'=>0]);
        $this->denied(fn()=>$this->actor_status($b),'invalid_actor');
    }
    public function test_native_mfa_alternate_login_and_force_password_change_are_not_bypassed(): void {
        global $CFG;
        $entry=$this->entry();set_config('enabled',1,'tool_mfa');
        $this->denied(fn()=>$this->open($entry),'native_auth_required');
        set_config('enabled',0,'tool_mfa');$CFG->alternateloginurl='https://auth.example.invalid';
        $this->denied(fn()=>$this->open($entry),'native_auth_required');
        unset($CFG->alternateloginurl);set_user_preference('auth_forcepasswordchange',1,$this->employee->id);
        $this->denied(fn()=>$this->open($entry),'native_auth_required');
        unset_user_preference('auth_forcepasswordchange',$this->employee->id);
        $a=$this->open($entry);set_config('enabled',1,'tool_mfa');
        $this->denied(fn()=>$this->actor_status($a),'native_auth_required');
        set_config('enabled',0,'tool_mfa');$this->denied(fn()=>$this->actor_status($a),'invalid_actor');
    }
    public function test_link_revision_and_explicit_actor_logout_revoke(): void {
        $a=$this->open();actor_service::revoke($a['session'],$this->browser,'tg1','telegram','123456');
        $this->denied(fn()=>$this->actor_status($a),'invalid_actor');
        $b=$this->open();$this->setUser($this->employee);$links=identity_service::owned_links();identity_service::revoke($links[0]['id']);
        $this->setGuestUser();$this->denied(fn()=>$this->actor_status($b),'invalid_actor');
    }
    public function test_live_actor_sessions_are_bounded_and_privacy_erases_them(): void {
        global $DB;
        $sessions=[];for($i=0;$i<5;$i++){$sessions[]=$this->open();}
        $entry=$this->entry();$this->denied(fn()=>$this->open($entry),'rate_limited');
        actor_service::revoke($sessions[0]['session'],$this->browser,'tg1','telegram','123456');
        $this->assertSame('actor_read',$this->open($entry)['scope']);
        \local_ustar\privacy\provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $this->employee,'local_ustar',[\context_system::instance()->id]));
        $this->assertSame(0,$DB->count_records('local_ustar_conn_sessions',['userid'=>$this->employee->id]));
    }
}
