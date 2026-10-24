<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

use local_ustar\connect\{identity, identity_service, entry_service, opaque_token, signup_request, signup_service};

/** Synthetic canonical registration; never calls a bot or production endpoint. */
#[\PHPUnit\Framework\Attributes\CoversClass(registration_service::class)]
final class connect_signup_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp(); $this->resetAfterTest(true); $this->preventResetByRollback();
        $this->setGuestUser();
        accounts::ensure_profile_field();
        global $DB;
        if (!$DB->record_exists('user_info_field', ['shortname' => 'ustar_position'])) {
            $this->getDataGenerator()->create_custom_profile_field([
                'shortname' => 'ustar_position', 'name' => 'Position', 'datatype' => 'text']);
        }
    }
    private function form(string $name = 'connect_signup'): array {
        return ['username' => $name, 'password' => 'Fixture-Only-Qx9!2026',
            'email' => $name . '@example.invalid', 'firstname' => 'Synthetic',
            'lastname' => 'Connect', 'departmentid' => 'retail'];
    }
    private function entry(string $subject = '77777'): array {
        $sender = new identity('telegram', '123456', $subject);
        $launch = entry_service::create_launch($sender, 'tg1');
        $browser = opaque_token::issue('ub1');
        $entry = entry_service::exchange($sender, $launch['launch'], hash('sha256', $launch['launch']), time(), $browser, 'tg1');
        return ['session' => $entry['session'], 'browser' => $browser, 'command' => opaque_token::issue('ur1')];
    }
    private function submit(array $entry, ?array $form = null): array {
        return signup_service::submit($entry['session'], $entry['browser'], $entry['command'], $form ?? $this->form(),
            'tg1', 'telegram', '123456', str_repeat('12', 32));
    }
    private function signup_status(array $entry): array {
        return signup_service::status($entry['session'], $entry['browser'], 'tg1', 'telegram', '123456');
    }
    private function denied(callable $call, string $reason): void {
        try { $call(); $this->fail('Expected ' . $reason); }
        catch (\InvalidArgumentException $error) { $this->assertSame($reason, $error->getMessage()); }
    }
    private function reviewer(array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();
        $role = create_role('Connect fixture', 'connect_fixture_' . random_string(8), 'Synthetic only');
        foreach ($capabilities as $cap) { assign_capability($cap, CAP_ALLOW, $role, $context->id); }
        role_assign($role, $user->id, $context->id);
        accesslib_clear_all_caches(true);
        return $user;
    }
    public function test_canonical_completion_sees_pending_user_and_request_before_commit(): void {
        global $DB;
        $seen = null;
        $userid = registration_service::register($this->form(), function(int $uid, int $requestid) use (&$seen): void {
            global $DB;
            $this->assertTrue($DB->is_transaction_started());
            $this->assertSame(employment::PENDING, employment::resolve($uid)['status']);
            $request = $DB->get_record('local_ustar_staff_requests', ['id' => $requestid], '*', MUST_EXIST);
            $this->assertSame($uid, (int)$request->employeeid);
            $seen = $uid;
        });
        $this->assertSame($userid, $seen, 'Canonical completion was not called');
        $this->assertFalse($DB->is_transaction_started());
    }
    public function test_completion_failure_rolls_back_canonical_account_and_request(): void {
        global $DB;
        $this->reviewer(['local/ustar:hrmanage', 'local/ustar:approveregistration']);
        $users = $DB->count_records('user');
        $requests = $DB->count_records('local_ustar_staff_requests');
        $notices = $DB->count_records('local_ustar_notifications');
        try {
            registration_service::register($this->form(), function(int $uid, int $requestid): void {
                throw new \RuntimeException('synthetic_completion_failure');
            });
            $this->fail('Canonical completion must participate in rollback');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic_completion_failure', $error->getMessage());
        }
        $this->assertSame($users, $DB->count_records('user'));
        $this->assertSame($requests, $DB->count_records('local_ustar_staff_requests'));
        $this->assertSame($notices, $DB->count_records('local_ustar_notifications'));
    }
    public function test_signup_creates_pending_identity_once_and_replay_does_not_resend_notifications(): void {
        global $DB;
        $hrd = $this->reviewer(['local/ustar:hrmanage', 'local/ustar:approveregistration']);
        $entry = $this->entry();
        $result = $this->submit($entry);
        $again = $this->submit($entry);
        $this->assertSame('registered', $result['outcome']);
        $this->assertFalse($result['replayed']); $this->assertTrue($again['replayed']);
        $this->assertSame($result['registration'], $again['registration']);
        $this->assertSame('pending', $result['registration']['status']);
        $user = $DB->get_record('user', ['username' => 'connect_signup'], '*', MUST_EXIST);
        $this->assertSame(employment::PENDING, employment::resolve((int)$user->id)['status']);
        $this->assertFalse(employment::learning_allowed((int)$user->id));
        $this->assertSame(0, identity_service::resolve_active_user(new identity('telegram', '123456', '77777')));
        $this->assertSame('pending_approval', $this->signup_status($entry)['account_state']);
        foreach (['local_ustar_conn_links', 'local_ustar_conn_signups', 'local_ustar_staff_requests'] as $table) {
            $this->assertSame(1, $DB->count_records($table));
        }
        $this->assertSame(1, $DB->count_records('local_ustar_notifications', ['userid' => $hrd->id, 'eventtype' => 'registration_requested']));
        $notice = $DB->get_record('local_ustar_notifications', ['userid' => $hrd->id, 'eventtype' => 'registration_requested'], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records('local_ustar_notify_delivery', ['notificationid' => $notice->id, 'channel' => 'ustar']));
        $records = $DB->get_records('local_ustar_conn_signups'); $receipt = reset($records);
        foreach ([$this->form()['password'], $entry['session'], $entry['browser'], $entry['command'], 'example.invalid'] as $raw) {
            $this->assertStringNotContainsString($raw, json_encode($receipt));
        }
    }
    public function test_same_command_changed_payload_or_new_command_cannot_create_another_account(): void {
        global $DB;
        $entry = $this->entry(); $this->submit($entry);
        $this->denied(fn() => $this->submit($entry, array_replace($this->form(), ['password' => 'Changed-Only-Qx9!2026'])), 'idempotency_conflict');
        $this->denied(fn() => $this->submit(array_replace($entry, ['command' => opaque_token::issue('ur1')])), 'already_registered');
        $this->denied(fn() => $this->submit(array_replace($entry, ['browser' => opaque_token::issue('ub1')])), 'invalid_entry');
        $this->assertSame(1, $DB->count_records('local_ustar_conn_signups'));
    }
    public function test_email_collision_never_auto_links_an_existing_user_and_validation_can_be_corrected(): void {
        global $DB;
        $existing = $this->getDataGenerator()->create_user(['email' => $this->form()['email']]);
        $before = $DB->count_records('user'); $entry = $this->entry();
        try { $this->submit($entry); $this->fail('Existing email must be rejected'); }
        catch (registration_validation_exception $error) { $this->assertSame('email', $error->field); }
        $this->assertSame($before, $DB->count_records('user'));
        $this->assertSame(0, $DB->count_records('local_ustar_conn_links'));
        $this->assertSame(0, $DB->count_records('local_ustar_conn_signups'));
        $this->submit($entry, $this->form('another_signup'));
        $link = $DB->get_record('local_ustar_conn_links', ['subject' => '77777'], '*', MUST_EXIST);
        $this->assertNotSame((int)$existing->id, (int)$link->userid);
    }
    public function test_explicit_signup_promotes_only_its_entry_and_revoke_blocks_old_receipt(): void {
        global $DB;
        $first = $this->entry(); $other = $this->entry();
        $this->submit($first);
        $this->denied(fn() => $this->signup_status($other), 'restart_required');
        $user = $DB->get_record('user', ['username' => 'connect_signup'], '*', MUST_EXIST);
        $this->setUser($user);
        $link = $DB->get_record('local_ustar_conn_links', ['userid' => $user->id], '*', MUST_EXIST);
        identity_service::revoke((int)$link->id);
        $this->denied(fn() => $this->submit($first), 'revoked_entry');
        $this->assertSame(1, $DB->count_records('local_ustar_conn_signups'));
    }
    public function test_current_suspension_and_session_expiry_block_retry_or_status(): void {
        global $DB;
        $entry = $this->entry(); $this->submit($entry);
        $user = $DB->get_record('user', ['username' => 'connect_signup'], '*', MUST_EXIST);
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->denied(fn() => $this->submit($entry), 'revoked_entry');
        $DB->set_field('user', 'suspended', 0, ['id' => $user->id]);
        $DB->set_field('local_ustar_conn_sessions', 'expiresat', time()-1);
        $this->denied(fn() => $this->signup_status($entry), 'invalid_entry');
    }
    public function test_hrd_approval_is_canonical_and_hr_alone_cannot_approve(): void {
        global $DB;
        $hr = $this->reviewer(['local/ustar:hrmanage']);
        $hrd = $this->reviewer(['local/ustar:hrmanage', 'local/ustar:approveregistration']);
        $entry = $this->entry(); $this->submit($entry);
        $request = $DB->get_record('local_ustar_staff_requests', ['requesttype' => staffing_requests::TYPE_REGISTRATION], '*', MUST_EXIST);
        $this->setUser($hr);
        try { staffing_requests::review((int)$request->id, staffing_requests::STATUS_APPROVED, (int)$hr->id,
                ['positionid' => 'retail_seller']); $this->fail('HR must not approve registration'); }
        catch (\required_capability_exception $error) { $this->assertSame('nopermissions', $error->errorcode); }
        $this->setUser($hrd);
        staffing_requests::review((int)$request->id, staffing_requests::STATUS_APPROVED, (int)$hrd->id, ['positionid' => 'retail_seller']);
        staffing_requests::review((int)$request->id, staffing_requests::STATUS_APPROVED, (int)$hrd->id, ['positionid' => 'retail_seller']);
        $this->assertSame('approved', $this->signup_status($entry)['registration']['status']);
        $this->assertSame('linked_active', $this->signup_status($entry)['account_state']);
        $this->assertSame('onboarding', $this->signup_status($entry)['scope']);
        $this->assertSame((int)$request->employeeid, identity_service::resolve_active_user(new identity('telegram', '123456', '77777')));
        $this->assertSame(1, $DB->count_records('local_ustar_notifications', [
            'userid' => $request->employeeid, 'eventtype' => 'registration_approved']));
        $this->assertSame(0, $DB->count_records('local_ustar_notifications', ['userid' => $hr->id, 'eventtype' => 'registration_requested']));
    }
    public function test_hrd_rejection_is_own_read_only_status_and_does_not_activate_user(): void {
        global $DB;
        $hrd = $this->reviewer(['local/ustar:hrmanage', 'local/ustar:approveregistration']);
        $entry = $this->entry(); $this->submit($entry);
        $request = $DB->get_record('local_ustar_staff_requests', ['requesttype' => staffing_requests::TYPE_REGISTRATION], '*', MUST_EXIST);
        $this->setUser($hrd);
        staffing_requests::review((int)$request->id, staffing_requests::STATUS_REJECTED, (int)$hrd->id,
            ['reviewcomment' => 'Synthetic rejection']);
        $status = $this->signup_status($entry);
        $this->assertSame('rejected', $status['registration']['status']);
        $this->assertSame('pending_approval', $status['account_state']);
        $this->assertArrayNotHasKey('userid', $status['registration']);
        $this->assertArrayNotHasKey('reviewcomment', $status['registration']);
        $this->assertFalse(employment::learning_allowed((int)$request->employeeid));
    }
    public function test_late_unique_collision_rolls_back_account_link_request_and_notifications(): void {
        global $DB;
        $this->reviewer(['local/ustar:hrmanage', 'local/ustar:approveregistration']);
        $entry = $this->entry(); $other = $this->entry('99999');
        $this->submit($other, $this->form('other_signup'));
        $DB->set_field('local_ustar_conn_signups', 'commandhash',
            signup_request::command_hash(new identity('telegram', '123456', '77777'), $entry['command']));
        $counts = [];
        foreach (['user', 'local_ustar_conn_links', 'local_ustar_staff_requests', 'local_ustar_notifications'] as $table) {
            $counts[$table] = $DB->count_records($table);
        }
        try { $this->submit($entry); $this->fail('Late receipt collision must roll back'); }
        catch (\dml_write_exception $error) { $this->assertNotEmpty($error->errorcode); }
        foreach ($counts as $table => $count) { $this->assertSame($count, $DB->count_records($table), $table); }
        $this->assertSame('unlinked', $this->signup_status($entry)['account_state']);
    }
    public function test_receipt_privacy_and_retention_do_not_reopen_signup(): void {
        global $DB;
        $entry = $this->entry(); $this->submit($entry);
        $user = $DB->get_record('user', ['username' => 'connect_signup'], '*', MUST_EXIST);
        $DB->set_field('local_ustar_conn_signups', 'expiresat', time()-1);
        $this->assertSame(1, identity_service::prune_expired(1)['signups']);
        $this->denied(fn() => $this->submit($entry), 'already_registered');
        privacy\provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $user, 'local_ustar', [\context_system::instance()->id]));
        $this->assertSame(0, $DB->count_records('local_ustar_conn_signups'));
        $this->assertSame(0, $DB->count_records('local_ustar_conn_sessions'));
    }
    public function test_privacy_erases_live_receipt_without_deleting_canonical_personnel_state(): void {
        global $DB;
        $entry = $this->entry(); $this->submit($entry);
        $user = $DB->get_record('user', ['username' => 'connect_signup'], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records('local_ustar_conn_signups'));
        privacy\provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $user, 'local_ustar', [\context_system::instance()->id]));
        $this->assertSame(0, $DB->count_records('local_ustar_conn_signups'));
        $this->assertTrue($DB->record_exists('user', ['id' => $user->id]));
        $this->assertTrue($DB->record_exists('local_ustar_staff_requests', ['employeeid' => $user->id]));
        $this->denied(fn() => $this->signup_status($entry), 'invalid_entry');
    }
}
