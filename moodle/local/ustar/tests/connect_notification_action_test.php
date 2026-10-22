<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

use local_ustar\connect\identity;
use local_ustar\connect\identity_service;

#[\PHPUnit\Framework\Attributes\CoversClass(identity_service::class)]
final class connect_notification_action_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp(); $this->resetAfterTest(true); $this->preventResetByRollback();
    }
    private function table(): void {
        global $DB;
        $this->assertTrue($DB->get_manager()->table_exists('local_ustar_conn_acktokens'), 'ACK action table missing');
    }
    private function delivery_fixture(string $state = 'sending', int $attempt = 1): array {
        global $DB, $CFG;
        $this->table();
        $user = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/ustar:use', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $user->id, \context_system::instance()->id); $this->setUser($user);
        $subject = (string)(70000 + $user->id); $sender = new identity('telegram', '123456', $subject);
        $c = identity_service::issue('telegram', '123456');
        identity_service::claim_verified($c['code'], $sender, hash('sha256', random_string(32)));
        $receipt = identity_service::confirm($c['code']);
        $link = $DB->get_record('local_ustar_conn_links', ['id' => $receipt['id']], '*', MUST_EXIST);
        $nid = target_core::notify(['userid' => $user->id, 'eventtype' => 'task_assigned', 'severity' => 'action',
            'subject' => 'Synthetic action notice', 'message' => 'Private fixture content',
            'idempotencykey' => random_string(32)]);
        $notice = $DB->get_record('local_ustar_notifications', ['id' => $nid], '*', MUST_EXIST);
        $did = $DB->insert_record('local_ustar_notify_delivery', (object)['notificationid' => $nid,
            'channel' => 'telegram', 'status' => $state, 'attempts' => $attempt, 'connectversion' => 1,
            'connlinkid' => $link->id, 'connrevision' => $link->revision, 'connexpires' => time()+3600,
            'timecreated' => time(), 'timemodified' => time()]);
        $CFG->ustar_connect_ack_enabled = true; $CFG->ustar_connect_ack_since = time()-60;
        $CFG->ustar_connect_notify_userids = [(int)$user->id];
        return ['notice' => $notice, 'delivery' => $DB->get_record('local_ustar_notify_delivery', ['id' => $did]),
            'link' => $link, 'uid' => (int)$user->id, 'sender' => $sender, 'role' => $role];
    }
    private function token_row(array $f, int $expires, int $attempt = 1): int {
        global $DB;
        return $DB->insert_record('local_ustar_conn_acktokens', (object)[
            'tokenhash' => hash('sha256', random_string(32)), 'notificationid' => $f['notice']->id,
            'deliveryid' => $f['delivery']->id, 'attempt' => $attempt, 'userid' => $f['uid'],
            'provider' => 'telegram', 'tenant' => '123456', 'subject' => $f['link']->subject,
            'linkid' => $f['link']->id, 'linkrevision' => $f['link']->revision,
            'timecreated' => time()-100, 'expires' => $expires]);
    }
    public function test_schema_and_metadata_lifecycle(): void {
        global $DB;
        $this->table();
        $this->assertCount(100, array_filter($DB->get_tables(), fn($t) => str_starts_with($t, 'local_ustar_')));
        $f = $this->delivery_fixture(); $other = $this->delivery_fixture();
        $this->token_row($f, time()+3600); $this->token_row($other, time()+3600);
        $provider = \local_ustar\privacy\provider::class;
        $contexts = $provider::get_contexts_for_userid($f['uid']);
        $this->assertContains((int)\context_system::instance()->id, array_map('intval', $contexts->get_contextids()));
        $approved = new \core_privacy\local\request\approved_contextlist(
            $DB->get_record('user', ['id' => $f['uid']]), 'local_ustar', [\context_system::instance()->id]);
        $provider::export_user_data($approved);
        $data = \core_privacy\local\request\writer::with_context(\context_system::instance())->get_data(['Connect']);
        $this->assertCount(1, $data->notification_actions);
        $this->assertObjectNotHasProperty('tokenhash', $data->notification_actions[0]);
        $provider::delete_data_for_user($approved);
        $this->assertSame(0, $DB->count_records('local_ustar_conn_acktokens', ['userid' => $f['uid']]));
        $this->assertSame(1, $DB->count_records('local_ustar_conn_acktokens', ['userid' => $other['uid']]));
        $this->assertTrue($DB->record_exists('local_ustar_notifications', ['id' => $f['notice']->id]));
    }
    public function test_user_discovery_bulk_and_context_erasure_preserve_history(): void {
        global $DB;
        $f=$this->delivery_fixture();$other=$this->delivery_fixture();
        $this->token_row($f,time()+3600);$this->token_row($other,time()+3600);
        $users=new \core_privacy\local\request\userlist(\context_system::instance(),'local_ustar');
        $provider=\local_ustar\privacy\provider::class;$provider::get_users_in_context($users);
        $this->assertContains($f['uid'],array_map('intval',$users->get_userids()));
        $this->assertContains($other['uid'],array_map('intval',$users->get_userids()));
        $approved=new \core_privacy\local\request\approved_userlist(\context_system::instance(),'local_ustar',[$f['uid']]);
        $provider::delete_data_for_users($approved);
        $this->assertSame(0,$DB->count_records('local_ustar_conn_acktokens',['userid'=>$f['uid']]));
        $this->assertSame(1,$DB->count_records('local_ustar_conn_acktokens',['userid'=>$other['uid']]));
        $provider::delete_data_for_all_users_in_context(\context_system::instance());
        $this->assertSame(0,$DB->count_records('local_ustar_conn_acktokens'));
        $this->assertTrue($DB->record_exists('local_ustar_notifications',['id'=>$f['notice']->id]));
        $this->assertTrue($DB->record_exists('local_ustar_notifications',['id'=>$other['notice']->id]));
    }
    public function test_prune_limits_and_preserves_canonical_receipt(): void {
        global $DB;
        $f = $this->delivery_fixture();
        target_core::acknowledge_notification((int)$f['notice']->id, $f['uid']);
        $before = $DB->get_record('local_ustar_notifications', ['id' => $f['notice']->id]);
        for ($i=1; $i<=501; $i++) { $this->token_row($f, time()-8*DAYSECS, $i); }
        $fresh = $this->token_row($f, time()+3600, 502);
        identity_service::prune_expired(500);
        $this->assertSame(2, $DB->count_records('local_ustar_conn_acktokens'));
        $this->assertTrue($DB->record_exists('local_ustar_conn_acktokens', ['id' => $fresh]));
        $this->assertEquals($before, $DB->get_record('local_ustar_notifications', ['id' => $f['notice']->id]));
    }
    private function action(array $f): array {
        $class = '\\local_ustar\\connect\\notification_action';
        $this->assertTrue(class_exists($class), 'Notification action service missing');
        return identity_service::atomic(fn() => $class::issue($f['notice'], $f['delivery'], $f['link'], time()));
    }
    private function ack(array $f, string $action, string $mid = '991', ?int $now = null): array {
        return \local_ustar\connect\notification_action::acknowledge($action, $f['sender'], $mid, $now ?? time());
    }
    private function unavailable(callable $call, string $reason = 'ack_unavailable'): void {
        try { $call(); $this->fail('Expected callback denial'); }
        catch (\InvalidArgumentException $error) { $this->assertSame($reason, $error->getMessage()); }
    }
    public function test_action_is_hashed_and_recipient_bound(): void {
        global $DB;
        $f = $this->delivery_fixture(); $action = $this->action($f);
        $this->assertSame(['action','expires'], array_keys($action));
        $this->assertSame(47, strlen($action['action']));
        $row = $DB->get_record('local_ustar_conn_acktokens', ['userid' => $f['uid']], '*', MUST_EXIST);
        $this->assertSame(64, strlen($row->tokenhash));
        $this->assertNotSame(\local_ustar\connect\opaque_token::digest($action['action'],'ua1'),$row->tokenhash);
        $this->assertStringNotContainsString($action['action'], json_encode($row));
        $wrong = $f; $wrong['sender'] = new identity('telegram','123456','99999');
        $this->unavailable(fn() => $this->ack($wrong, $action['action']));
        $wrong['sender'] = new identity('telegram','999999',$f['link']->subject);
        $this->unavailable(fn() => $this->ack($wrong, $action['action']));
        $this->unavailable(fn() => $this->ack($f, 'ua1_'.str_repeat('B',43)));
        $this->assertNull($DB->get_field('local_ustar_notifications','ackat',['id'=>$f['notice']->id]));
    }
    public function test_actor_read_and_ack_tokens_are_not_interchangeable(): void {
        global $CFG;
        $f=$this->delivery_fixture();$action=$this->action($f);
        $CFG->ustar_connect_provider_read_enabled=true;
        $browser=\local_ustar\connect\opaque_token::issue('ub1');
        $launch=\local_ustar\connect\entry_service::create_launch($f['sender'],'actor_key');
        $entry=\local_ustar\connect\entry_service::exchange($f['sender'],$launch['launch'],hash('sha256',$launch['launch']),time(),$browser,'actor_key');
        $actor=\local_ustar\connect\actor_service::open($entry['session'],$browser,'actor_key','telegram','123456');
        $this->unavailable(fn()=>$this->ack($f,$actor['session']));
        $this->unavailable(fn()=>\local_ustar\connect\actor_service::status($action['action'],$browser,'actor_key','telegram','123456'),'invalid_actor');
        $this->assertSame('acknowledged',$this->ack($f,$action['action'])['status']);
        $this->assertSame('actor_read',\local_ustar\connect\actor_service::status($actor['session'],$browser,'actor_key','telegram','123456')['scope']);
    }
    public function test_read_repeat_and_callback_before_finish_keep_first_ack(): void {
        global $DB;
        $f=$this->delivery_fixture(); $action=$this->action($f);
        communication::mark_notification($f['uid'], (int)$f['notice']->id);
        $before=$DB->get_record('local_ustar_notify_delivery',['id'=>$f['delivery']->id]);
        $first=$this->ack($f,$action['action']);
        $this->assertSame(['status'=>'acknowledged','acknowledged_at'=>$first['acknowledged_at'],'category'=>'tasks'],$first);
        $this->assertSame($first,$this->ack($f,$action['action']));
        $this->assertSame($first['acknowledged_at'],\local_ustar\connect\notification_acknowledgement::own((int)$f['notice']->id)['acknowledged_at']);
        $this->assertEquals($before,$DB->get_record('local_ustar_notify_delivery',['id'=>$f['delivery']->id]));
        $DB->set_field('local_ustar_notify_delivery','status','uncertain',['id'=>$before->id]);
        $this->assertSame($first,$this->ack($f,$action['action']));
    }
    public function test_current_link_attempt_and_message_fences(): void {
        global $DB;
        $f=$this->delivery_fixture();$action=$this->action($f);
        foreach (['failed','retry_wait','cancelled'] as $state) {
            $DB->set_field('local_ustar_notify_delivery','status',$state,['id'=>$f['delivery']->id]);
            $this->unavailable(fn()=>$this->ack($f,$action['action']));
        }
        $DB->set_field('local_ustar_notify_delivery','status','sending',['id'=>$f['delivery']->id]);
        $DB->set_field('local_ustar_notify_delivery','attempts',2,['id'=>$f['delivery']->id]);
        $this->unavailable(fn()=>$this->ack($f,$action['action']));
        $DB->set_field('local_ustar_notify_delivery','attempts',1,['id'=>$f['delivery']->id]);
        $DB->set_field('local_ustar_notify_delivery','providerref','991',['id'=>$f['delivery']->id]);
        $this->unavailable(fn()=>$this->ack($f,$action['action'],'992'));
        $DB->set_field('local_ustar_conn_links','revision',(int)$f['link']->revision+1,['id'=>$f['link']->id]);
        $this->unavailable(fn()=>$this->ack($f,$action['action']));
        $DB->set_field('local_ustar_conn_links','revision',$f['link']->revision,['id'=>$f['link']->id]);
        $DB->set_field('local_ustar_conn_links','state','revoked',['id'=>$f['link']->id]);
        $this->unavailable(fn()=>$this->ack($f,$action['action']));
        $this->assertNull($DB->get_field('local_ustar_notifications','ackat',['id'=>$f['notice']->id]));
    }
    public function test_optout_is_not_revoke_and_expiry_is_strict(): void {
        global $DB;
        $f=$this->delivery_fixture();$action=$this->action($f);
        set_user_preference('local_ustar_connect_notify_v1',json_encode(['enabled'=>false]),$f['uid']);
        $this->unavailable(fn()=>$this->ack($f,$action['action'],'991',$action['expires']));
        $this->assertSame('acknowledged',$this->ack($f,$action['action'])['status']);
        $DB->set_field('local_ustar_conn_links','state','revoked',['id'=>$f['link']->id]);
        $this->unavailable(fn()=>$this->ack($f,$action['action']));
    }
    public function test_account_capability_fence_and_kill_switch_are_current(): void {
        global $DB,$CFG;
        $f=$this->delivery_fixture();$action=$this->action($f);
        foreach (['deleted'=>1,'suspended'=>1,'confirmed'=>0] as $field=>$value) {
            $DB->set_field('user',$field,$value,['id'=>$f['uid']]);
            $this->unavailable(fn()=>$this->ack($f,$action['action']));
            $DB->set_field('user',$field,1-$value,['id'=>$f['uid']]);
        }
        $CFG->ustar_connect_notify_userids=[];
        $this->unavailable(fn()=>$this->ack($f,$action['action']));
        unset($CFG->ustar_connect_notify_userids);
        $this->unavailable(fn()=>$this->ack($f,$action['action']));
        $CFG->ustar_connect_notify_userids=[$f['uid']];
        assign_capability('local/ustar:use',CAP_PROHIBIT,$f['role'],\context_system::instance()->id);
        accesslib_clear_all_caches(true);
        // The worker facade has NO_MOODLE_COOKIES: the next callback is not
        // the recipient's existing $USER with its cached access array.
        $this->setUser(null);
        $this->assertFalse(has_capability('local/ustar:use',\context_system::instance(),$f['uid']));
        $this->unavailable(fn()=>$this->ack($f,$action['action']));
        $CFG->ustar_connect_ack_enabled=false;
        $this->unavailable(fn()=>$this->ack($f,$action['action']),'ack_disabled');
        $this->assertNull($DB->get_field('local_ustar_notifications','ackat',['id'=>$f['notice']->id]));
    }
    public function test_cutoff_and_pending_actor_do_not_issue_button(): void {
        global $CFG;
        $f=$this->delivery_fixture();$class='\\local_ustar\\connect\\notification_action';
        $this->assertTrue(class_exists($class));
        $CFG->ustar_connect_ack_since=time()+1;
        $this->assertNull(identity_service::atomic(fn()=>$class::issue($f['notice'],$f['delivery'],$f['link'],time())));
        $CFG->ustar_connect_ack_since=time()-60;
        employment::register_pending($f['uid']);
        $this->assertNull(identity_service::atomic(fn()=>$class::issue($f['notice'],$f['delivery'],$f['link'],time())));
    }

    private function dispatch(string $op, array $payload, string $kid = 'tg_ack'): array {
        $key = [];
        foreach (['tg_ack'=>['notification.ack'],
                'tg_v1'=>['notification.lease','notification.begin','notification.finish'],
                'tg_v2'=>['notification.lease','notification.begin_v2','notification.finish'],
                'tg_link'=>['link.claim'],'tg_actor'=>['actor.open','actor.status','actor.revoke']] as $id=>$ops) {
            $key[$id]=['secret'=>str_repeat('ab',32),'operations'=>$ops,'provider'=>'telegram','tenant'=>'123456'];
        }
        ksort($payload); $body=json_encode(['operation'=>$op,'payload'=>$payload],JSON_UNESCAPED_SLASHES);
        $at=(string)time();$nonce='rn1_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $sig=hash_hmac('sha256',implode("\n",['USTAR-CONNECT-BRIDGE-v1','POST','/local/ustar/connect_bridge.php',
            $kid,$at,$nonce,hash('sha256',$body)]),hex2bin(str_repeat('ab',32)));
        return \local_ustar\connect\bridge_service::dispatch($body,['X-Ustar-Key'=>$kid,'X-Ustar-Time'=>$at,
            'X-Ustar-Nonce'=>$nonce,'X-Ustar-Signature'=>$sig],$key);
    }
    public function test_ack_key_cannot_lease_link_or_open_actor(): void {
        foreach (['notification.lease','notification.begin','notification.begin_v2','notification.finish','link.claim','actor.open'] as $op) {
            $this->unavailable(fn()=>$this->dispatch($op,['probe'=>'fixed']),'forbidden_operation');
        }
    }
    public function test_old_sender_link_and_actor_keys_cannot_ack_or_negotiate(): void {
        foreach (['tg_v1','tg_v2','tg_link','tg_actor'] as $kid) {
            $this->unavailable(fn()=>$this->dispatch('notification.ack',['probe'=>'fixed'],$kid),'forbidden_operation');
        }
        $this->unavailable(fn()=>$this->dispatch('notification.begin',['probe'=>'fixed'],'tg_v2'),'forbidden_operation');
        $this->unavailable(fn()=>$this->dispatch('notification.begin_v2',['probe'=>'fixed'],'tg_v1'),'forbidden_operation');
    }
    public function test_scoped_ack_route_and_exact_payload(): void {
        $f=$this->delivery_fixture();$action=$this->action($f);
        $payload=['action'=>$action['action'],'message_id'=>'991','provider'=>'telegram','subject'=>$f['link']->subject,'tenant'=>'123456'];
        $this->assertSame('acknowledged',$this->dispatch('notification.ack',$payload)['status']);
        $this->unavailable(fn()=>$this->dispatch('notification.ack',$payload+['userid'=>(string)$f['uid']]),'invalid_payload');
        $bad=$payload;$bad['message_id']=true;
        $this->unavailable(fn()=>$this->dispatch('notification.ack',$bad),'invalid_payload');
    }
    public function test_disabled_ack_has_no_business_writes(): void {
        global $CFG,$DB;
        $f=$this->delivery_fixture();$action=$this->action($f);$before=$DB->get_record('local_ustar_notifications',['id'=>$f['notice']->id]);
        $CFG->ustar_connect_ack_enabled=false;
        $payload=['action'=>$action['action'],'message_id'=>'991','provider'=>'telegram','subject'=>$f['link']->subject,'tenant'=>'123456'];
        $this->unavailable(fn()=>$this->dispatch('notification.ack',$payload),'ack_disabled');
        $this->assertEquals($before,$DB->get_record('local_ustar_notifications',['id'=>$f['notice']->id]));
        $this->assertSame(1,$DB->count_records('local_ustar_conn_acktokens'));
    }

}
