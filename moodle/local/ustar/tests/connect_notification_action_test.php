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
        $this->assertContains(\context_system::instance()->id, $contexts->get_contextids());
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
}
