<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

use local_ustar\connect\{identity, entry_service, identity_service, opaque_token};

#[\PHPUnit\Framework\Attributes\CoversClass(entry_service::class)]
final class connect_entry_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp(); $this->resetAfterTest(true); $this->preventResetByRollback();
    }
    private function sender(): identity { return new identity('telegram', '123456', '8727627355'); }
    private function proof(string $name): string { return hash('sha256', 'entry-fixture:' . $name); }
    private function denied(callable $call, string $reason): void {
        try { $call(); $this->fail('Expected ' . $reason); }
        catch (\InvalidArgumentException $error) { $this->assertSame($reason, $error->getMessage()); }
    }
    private function exchange(array $launch, string $browser, string $name = 'one'): array {
        return entry_service::exchange($this->sender(), $launch['launch'], $this->proof($name), time(), $browser, 'tg1');
    }
    public function test_launch_requires_matching_subject_and_exchange_is_single_use(): void {
        global $DB;
        $launch = entry_service::create_launch($this->sender(), 'tg1');
        $browser = opaque_token::issue('ub1');
        $this->denied(fn() => entry_service::exchange(new identity('telegram', '123456', '999'),
            $launch['launch'], $this->proof('wrong'), time(), $browser, 'tg1'), 'launch_mismatch');
        $this->assertSame(0, $DB->count_records('local_ustar_conn_sessions'));
        $entry = $this->exchange($launch, $browser);
        $this->assertSame('onboarding', $entry['scope']);
        $this->assertSame('unlinked', entry_service::status($entry['session'], $browser, 'tg1', 'telegram', '123456')['account_state']);
        $this->denied(fn() => $this->exchange($launch, $browser, 'again'), 'launch_consumed');
        $this->assertSame(1, $DB->count_records('local_ustar_conn_sessions'));
        $records = $DB->get_records('local_ustar_conn_sessions');
        $record = reset($records);
        $this->assertStringNotContainsString($entry['session'], json_encode($record));
        $this->assertStringNotContainsString($browser, json_encode($record));
    }
    public function test_browser_key_scope_expiry_and_logout_are_checked(): void {
        global $DB;
        $browser = opaque_token::issue('ub1');
        $entry = $this->exchange(entry_service::create_launch($this->sender(), 'tg1'), $browser);
        $this->denied(fn() => entry_service::status($entry['session'], opaque_token::issue('ub1'), 'tg1', 'telegram', '123456'), 'invalid_entry');
        $this->denied(fn() => entry_service::status($entry['session'], $browser, 'other', 'telegram', '123456'), 'invalid_entry');
        entry_service::revoke($entry['session'], $browser, 'tg1', 'telegram', '123456');
        $this->denied(fn() => entry_service::status($entry['session'], $browser, 'tg1', 'telegram', '123456'), 'invalid_entry');
        $DB->set_field('local_ustar_conn_launches', 'expiresat', time()-1);
        $expired = entry_service::create_launch($this->sender(), 'tg1');
        $DB->set_field('local_ustar_conn_launches', 'expiresat', time()-1);
        $this->denied(fn() => $this->exchange($expired, $browser, 'expired'), 'expired_launch');
    }
    public function test_proof_cannot_be_reused_with_another_ticket(): void {
        $browser = opaque_token::issue('ub1');
        $this->exchange(entry_service::create_launch($this->sender(), 'tg1'), $browser);
        $other = entry_service::create_launch($this->sender(), 'tg1');
        $this->denied(fn() => $this->exchange($other, $browser), 'replayed_proof');
    }
    public function test_existing_link_revocation_invalidates_entry(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $challenge = identity_service::issue('telegram', '123456');
        identity_service::claim_verified($challenge['code'], $this->sender(), $this->proof('link'));
        $link = identity_service::confirm($challenge['code']);
        $browser = opaque_token::issue('ub1');
        $entry = $this->exchange(entry_service::create_launch($this->sender(), 'tg1'), $browser);
        $this->assertSame('linked_active', entry_service::status($entry['session'], $browser, 'tg1', 'telegram', '123456')['account_state']);
        identity_service::revoke($link['id']);
        $this->denied(fn() => entry_service::status($entry['session'], $browser, 'tg1', 'telegram', '123456'), 'revoked_entry');
    }
    public function test_unlinked_session_cannot_upgrade_when_account_becomes_linked(): void {
        $browser = opaque_token::issue('ub1');
        $entry = $this->exchange(entry_service::create_launch($this->sender(), 'tg1'), $browser);
        $this->setUser($this->getDataGenerator()->create_user());
        $challenge = identity_service::issue('telegram', '123456');
        identity_service::claim_verified($challenge['code'], $this->sender(), $this->proof('new-link'));
        identity_service::confirm($challenge['code']);
        $this->denied(fn() => entry_service::status($entry['session'], $browser, 'tg1', 'telegram', '123456'), 'restart_required');
    }
    public function test_pending_current_flags_expiry_privacy_and_cleanup(): void {
        global $DB, $USER;
        $this->setUser($this->getDataGenerator()->create_user());
        $owner = clone $USER;
        employment::register_pending((int)$USER->id);
        $challenge = identity_service::issue('telegram', '123456');
        identity_service::claim_verified($challenge['code'], $this->sender(), $this->proof('pending-link'));
        identity_service::confirm($challenge['code']);
        $browser = opaque_token::issue('ub1');
        $entry = $this->exchange(entry_service::create_launch($this->sender(), 'tg1'), $browser);
        $this->assertSame('pending_approval', entry_service::status($entry['session'], $browser, 'tg1', 'telegram', '123456')['account_state']);
        $DB->set_field('user', 'suspended', 1, ['id' => $owner->id]);
        $this->denied(fn() => entry_service::status($entry['session'], $browser, 'tg1', 'telegram', '123456'), 'revoked_entry');
        $DB->set_field('user', 'suspended', 0, ['id' => $owner->id]);
        $DB->set_field('local_ustar_conn_sessions', 'expiresat', time()-1);
        $this->denied(fn() => entry_service::status($entry['session'], $browser, 'tg1', 'telegram', '123456'), 'invalid_entry');
        $DB->set_field('local_ustar_conn_launches', 'expiresat', time()-1);
        $pruned = identity_service::prune_expired(1);
        $this->assertSame(1, $pruned['sessions']); $this->assertSame(1, $pruned['launches']);
        $this->exchange(entry_service::create_launch($this->sender(), 'tg1'), $browser, 'privacy');
        \local_ustar\privacy\provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $owner, 'local_ustar', [\context_system::instance()->id]));
        $this->assertSame(0, $DB->count_records('local_ustar_conn_sessions'));
        $this->assertSame(0, $DB->count_records('local_ustar_conn_launches'));
        $this->assertGreaterThan(0, $DB->count_records('local_ustar_conn_proofs'));
    }
}
