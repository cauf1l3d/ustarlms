<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Synthetic canonical registration; never calls a bot or production endpoint. */
#[\PHPUnit\Framework\Attributes\CoversClass(registration_service::class)]
final class connect_signup_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp(); $this->resetAfterTest(true); $this->preventResetByRollback();
        $this->setGuestUser();
        accounts::ensure_profile_field();
    }
    private function form(string $name = 'connect_signup'): array {
        return ['username' => $name, 'password' => 'Fixture-Only-Qx9!2026',
            'email' => $name . '@example.invalid', 'firstname' => 'Synthetic',
            'lastname' => 'Connect', 'departmentid' => 'retail'];
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
}
