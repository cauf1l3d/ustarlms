<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

use local_ustar\connect\identity;
use local_ustar\connect\identity_service;

#[\PHPUnit\Framework\Attributes\CoversClass(identity_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(identity::class)]
final class connect_identity_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        // Production entrypoints own their transaction and named lock through commit.
        $this->preventResetByRollback();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    private function telegram(string $subject = '8727627355'): identity {
        return new identity('telegram', '123456', $subject);
    }

    private function proof(string $name): string {
        return hash('sha256', 'synthetic-connect-proof:' . $name);
    }

    private function complete(string $subject = '8727627355'): array {
        $challenge = identity_service::issue('telegram', '123456');
        identity_service::claim_verified($challenge['code'], $this->telegram($subject), $this->proof($challenge['code']));
        return identity_service::confirm($challenge['code']);
    }

    private function denied(callable $call, string $reason): void {
        try {
            $call();
            $this->fail('Expected denial: ' . $reason);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($reason, $e->getMessage());
        }
    }

    public function test_only_hash_is_stored_and_both_sides_are_required(): void {
        global $DB, $USER;
        $challenge = identity_service::issue('telegram', '123456');
        $this->assertSame(600, $challenge['expires_at'] - $challenge['created_at']);
        $record = $DB->get_record('local_ustar_conn_challenges', ['userid' => $USER->id], '*', MUST_EXIST);
        $this->assertSame(identity::code_hash($challenge['code']), $record->codehash);
        $this->assertStringNotContainsString($challenge['code'], json_encode($record));
        $this->denied(fn() => identity_service::confirm($challenge['code']), 'unclaimed_challenge');
        identity_service::claim_verified($challenge['code'], $this->telegram(), $this->proof('one'));
        $preview = identity_service::preview($challenge['code']);
        $this->assertTrue($preview['claimed']);
        $this->assertSame('8727627355', $preview['subject']);
        $this->assertSame(0, $DB->count_records('local_ustar_conn_links'));
        $link = identity_service::confirm($challenge['code']);
        $this->assertSame((int)$USER->id, $link['userid']);
        $this->assertSame($link, identity_service::confirm($challenge['code']));
        $this->assertSame((int)$USER->id, identity_service::resolve_active_user($this->telegram()));
    }

    public function test_other_user_and_new_browser_session_cannot_confirm(): void {
        global $USER;
        $owner = clone $USER;
        $challenge = identity_service::issue('telegram', '123456');
        identity_service::claim_verified($challenge['code'], $this->telegram(), $this->proof('session'));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->denied(fn() => identity_service::confirm($challenge['code']), 'invalid_challenge');
        $this->setUser($owner);
        $USER->sesskey = 'replacement-browser-session';
        $this->denied(fn() => identity_service::confirm($challenge['code']), 'invalid_challenge');
    }

    public function test_tenant_mismatch_and_proof_replay_are_rejected(): void {
        global $DB;
        $challenge = identity_service::issue('telegram', '123456');
        $wrong = new identity('telegram', '654321', '8727627355');
        $this->denied(fn() => identity_service::claim_verified($challenge['code'], $wrong, $this->proof('tenant')), 'provider_mismatch');
        $this->assertSame(0, $DB->count_records('local_ustar_conn_proofs'));
        identity_service::claim_verified($challenge['code'], $this->telegram(), $this->proof('replay'));
        $second = identity_service::issue('telegram', '123456');
        $this->denied(fn() => identity_service::claim_verified($second['code'], $this->telegram(), $this->proof('replay')), 'replayed_proof');
    }

    public function test_expiry_and_reclaim_do_not_override_sender(): void {
        global $DB;
        $challenge = identity_service::issue('telegram', '123456');
        identity_service::claim_verified($challenge['code'], $this->telegram(), $this->proof('claim'));
        $this->denied(fn() => identity_service::claim_verified($challenge['code'], $this->telegram('99'), $this->proof('reclaim')), 'claimed_challenge');
        $DB->set_field('local_ustar_conn_challenges', 'expiresat', time() - 1,
            ['codehash' => identity::code_hash($challenge['code'])]);
        $this->denied(fn() => identity_service::confirm($challenge['code']), 'expired_challenge');
    }

    public function test_revoke_prevents_old_confirmation_and_fresh_link_requires_both_proofs(): void {
        global $USER;
        $challenge = identity_service::issue('telegram', '123456');
        identity_service::claim_verified($challenge['code'], $this->telegram(), $this->proof('revoke'));
        $link = identity_service::confirm($challenge['code']);
        identity_service::revoke($link['id']);
        $this->assertSame(0, identity_service::resolve_active_user($this->telegram()));
        $this->denied(fn() => identity_service::confirm($challenge['code']), 'revoked_link');
        $restored = $this->complete();
        $this->assertSame($link['id'], $restored['id']);
        $this->assertGreaterThan($link['revision'], $restored['revision']);
        $this->assertSame((int)$USER->id, identity_service::resolve_active_user($this->telegram()));
    }

    public function test_identity_cannot_silently_transfer_and_one_channel_has_one_link(): void {
        global $DB, $USER;
        $owner = clone $USER;
        $link = $this->complete();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);
        $challenge = identity_service::issue('telegram', '123456');
        identity_service::claim_verified($challenge['code'], $this->telegram(), $this->proof('other-user'));
        $this->denied(fn() => identity_service::confirm($challenge['code']), 'link_conflict');
        $this->denied(fn() => identity_service::revoke($link['id']), 'unknown_link');
        $this->setUser($owner);
        $this->denied(fn() => $this->complete('1234'), 'link_conflict');
        $this->assertSame(1, $DB->count_records('local_ustar_conn_links'));
    }

    public function test_pending_can_link_but_never_resolves_as_active_employee(): void {
        global $USER;
        employment::register_pending((int)$USER->id);
        $this->complete();
        $this->assertSame(0, identity_service::resolve_active_user($this->telegram()));
    }

    public function test_current_moodle_and_employment_flags_are_not_cached(): void {
        global $DB, $USER;
        $this->complete();
        $userid = (int)$USER->id;
        $DB->set_field('user', 'suspended', 1, ['id' => $userid]);
        $this->assertSame(0, identity_service::resolve_active_user($this->telegram()));
        $DB->set_field('user', 'suspended', 0, ['id' => $userid]);
        employment::register_pending($userid);
        $DB->set_field('local_ustar_employment', 'status', employment::TERMINATED, ['userid' => $userid]);
        $this->assertSame(0, identity_service::resolve_active_user($this->telegram()));
        $this->denied(fn() => identity_service::issue('telegram', '123456'), 'inactive_actor');
    }

    public function test_guest_and_view_as_cannot_issue(): void {
        global $SESSION;
        $this->setGuestUser();
        $this->denied(fn() => identity_service::issue('telegram', '123456'), 'unauthenticated');
        $this->setAdminUser();
        $SESSION->ustar_view_position = 'synthetic-place';
        $this->expectException(\moodle_exception::class);
        identity_service::issue('telegram', '123456');
    }

    public function test_php_code_format_matches_worker_and_identity_namespaces_are_distinct(): void {
        // Python: sha256(b'ustar-connect-link-v1\0' + bytes(32)).hexdigest().
        $this->assertSame('89521a4d2c8a906a2d5e21114495b4c3cfce4562f9a6ae152911478c54a5f6e9',
            identity::code_hash('uc1_' . str_repeat('A', 43)));
        $this->assertNotSame($this->telegram()->key(), (new identity('bitrix', '123456', '8727627355'))->key());
        $this->denied(fn() => identity::code_hash('uc1_' . str_repeat('A', 42) . 'B'), 'invalid_link_code');
    }

    public function test_open_challenges_are_bounded_and_nested_transactions_are_rejected(): void {
        global $DB;
        for ($i = 0; $i < 5; $i++) { identity_service::issue('telegram', '123456'); }
        $this->denied(fn() => identity_service::issue('telegram', '123456'), 'rate_limited');
        $this->assertSame(5, $DB->count_records('local_ustar_conn_challenges'));
        $transaction = $DB->start_delegated_transaction();
        try {
            identity_service::issue('bitrix', 'company');
            $this->fail('Nested mutation must not release locks before outer commit');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('own the transaction', $e->getMessage());
        } finally { $transaction->allow_commit(); }
    }

    public function test_privacy_erases_only_selected_identity_metadata(): void {
        global $DB, $USER;
        $owner = clone $USER;
        $this->complete();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);
        $this->complete('22222');
        $contexts = new \core_privacy\local\request\approved_contextlist(
            $owner, 'local_ustar', [\context_system::instance()->id]);
        \local_ustar\privacy\provider::delete_data_for_user($contexts);
        $this->assertFalse($DB->record_exists('local_ustar_conn_links', ['userid' => $owner->id]));
        $this->assertFalse($DB->record_exists('local_ustar_conn_challenges', ['userid' => $owner->id]));
        $this->assertSame(1, $DB->count_records('local_ustar_conn_proofs'));
        $this->assertSame((int)$other->id, identity_service::resolve_active_user($this->telegram('22222')));
    }
}
