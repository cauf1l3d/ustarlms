<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

use local_ustar\connect\identity;
use local_ustar\connect\identity_service;
use local_ustar\connect\link_management;

#[\PHPUnit\Framework\Attributes\CoversClass(link_management::class)]
final class connect_link_management_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        $this->setUser($this->getDataGenerator()->create_user());
        global $CFG;
        $CFG->ustar_connect_channels = ['telegram' => ['tenant' => '123456', 'username' => 'ustarcorp_bot']];
    }

    private function request(string $action, array $extra = [], string $method = 'POST', bool $https = true): array {
        return link_management::handle($method, $https, ['action' => $action, 'sesskey' => sesskey()] + $extra);
    }

    private function denied(callable $call, string $reason): void {
        try { $call(); $this->fail('Expected '.$reason); }
        catch (\InvalidArgumentException $error) { $this->assertSame($reason, $error->getMessage()); }
    }

    private function claimed(): array {
        $view = $this->request('issue');
        identity_service::claim_verified($view['challenge']['code'], new identity('telegram', '123456', '8727627355'),
            hash('sha256', $view['challenge']['code']));
        return $view;
    }

    public function test_get_is_read_only_and_configuration_is_server_owned(): void {
        global $DB, $CFG;
        $view = link_management::handle('GET', true, []);
        $this->assertTrue($view['enabled']);
        $this->assertSame(0, $DB->count_records('local_ustar_conn_challenges'));
        $this->denied(fn() => $this->request('issue', [], 'GET'), 'invalid_request');
        $issued = $this->request('issue', ['tenant' => '999', 'username' => 'attacker_bot']);
        $record = $DB->get_record('local_ustar_conn_challenges', [], '*', MUST_EXIST);
        $this->assertSame('123456', $record->tenant);
        $this->assertSame('https://t.me/ustarcorp_bot?startapp='.$issued['challenge']['code'], $issued['challenge']['launch_url']);
        $this->assertStringNotContainsString($issued['challenge']['code'], json_encode($record));
        unset($CFG->ustar_connect_channels);
        $this->assertFalse(link_management::handle('GET', true, [])['enabled']);
        $this->denied(fn() => $this->request('issue'), 'channel_unavailable');
    }

    public function test_https_csrf_methods_and_action_allowlist_fail_before_writes(): void {
        global $DB;
        $this->denied(fn() => $this->request('issue', [], 'POST', false), 'https_required');
        $this->denied(fn() => link_management::handle('POST', true, ['action' => 'issue', 'sesskey' => 'wrong']), 'invalid_sesskey');
        $this->denied(fn() => link_management::handle('DELETE', true, []), 'invalid_request');
        $this->denied(fn() => $this->request('arbitrary'), 'invalid_request');
        $this->assertSame(0, $DB->count_records('local_ustar_conn_challenges'));
    }

    public function test_preview_and_second_confirmation_use_same_owner_session(): void {
        global $USER;
        $view = $this->claimed(); $code = $view['challenge']['code'];
        $owner = clone $USER; $originalsesskey = sesskey();
        $preview = $this->request('preview', ['code' => $code]);
        $this->assertTrue($preview['challenge']['claimed']);
        $this->assertSame('8727627355', $preview['challenge']['subject']);
        $this->assertSame([], $preview['links']);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->denied(fn() => $this->request('preview', ['code' => $code]), 'invalid_challenge');
        $this->denied(fn() => $this->request('confirm', ['code' => $code]), 'invalid_challenge');
        $this->setUser($owner); $USER->sesskey = 'new-browser-session';
        $this->denied(fn() => $this->request('confirm', ['code' => $code]), 'invalid_challenge');
        $this->setUser($owner); $USER->sesskey = $originalsesskey;
        $result = $this->request('confirm', ['code' => $code]);
        $this->assertTrue($result['confirmed']);
        $this->assertSame('linked', $result['links'][0]['state']);
        $this->assertSame($result['links'], $this->request('confirm', ['code' => $code])['links']);
        $this->assertArrayNotHasKey('identityhash', $result['links'][0]);
        $this->assertArrayNotHasKey('userid', $result['links'][0]);
    }

    public function test_revoke_is_owner_only_and_works_when_new_issue_disabled(): void {
        global $CFG, $USER;
        $view = $this->claimed(); $code = $view['challenge']['code'];
        $result = $this->request('confirm', ['code' => $code]);
        $id = $result['links'][0]['id']; $owner = clone $USER;
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertSame([], link_management::handle('GET', true, [])['links']);
        $this->denied(fn() => $this->request('revoke', ['linkid' => (string)$id]), 'unknown_link');
        $this->setUser($owner); unset($CFG->ustar_connect_channels);
        $result = $this->request('revoke', ['linkid' => (string)$id]);
        $this->assertSame('revoked', $result['links'][0]['state']);
        $this->denied(fn() => $this->request('confirm', ['code' => $code]), 'revoked_link');
    }

    public function test_disabled_bad_bot_configuration_is_not_used_as_a_launch_url(): void {
        global $CFG;
        foreach ([['tenant' => '00123', 'username' => 'ustarcorp_bot'],
                  ['tenant' => '123456', 'username' => 'evil.com/path'],
                  ['tenant' => '123456', 'username' => 'valid_bot', 'token' => 'not-a-configuration-field']] as $config) {
            $CFG->ustar_connect_channels = ['telegram' => $config];
            $this->assertFalse(link_management::handle('GET', true, [])['enabled']);
            $this->denied(fn() => $this->request('issue'), 'channel_unavailable');
        }
    }

    public function test_pending_can_link_without_employee_grant_and_blocked_actor_cannot_list(): void {
        global $DB, $USER;
        employment::register_pending((int)$USER->id);
        $view = $this->claimed();
        $this->request('confirm', ['code' => $view['challenge']['code']]);
        $this->assertSame(0, identity_service::resolve_active_user(new identity('telegram', '123456', '8727627355')));
        $DB->set_field('user', 'suspended', 1, ['id' => $USER->id]);
        $this->denied(fn() => link_management::handle('GET', true, []), 'inactive_actor');
        $this->setGuestUser();
        $this->denied(fn() => link_management::handle('GET', true, []), 'unauthenticated');
    }
}
