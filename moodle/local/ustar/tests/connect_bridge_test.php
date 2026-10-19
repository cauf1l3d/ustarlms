<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

use local_ustar\connect\{bridge_auth, bridge_http, bridge_service};

#[\PHPUnit\Framework\Attributes\CoversClass(bridge_service::class)]
final class connect_bridge_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp(); $this->resetAfterTest(true); $this->preventResetByRollback();
    }
    private function keys(): array {
        return ['tg1' => ['secret' => str_repeat('12', 32), 'provider' => 'telegram',
            'tenant' => '123456', 'operations' => bridge_auth::OPERATIONS]];
    }
    private function signed(string $op, array $payload): array {
        ksort($payload);
        $body = json_encode(['operation' => $op, 'payload' => $payload], JSON_UNESCAPED_SLASHES);
        $headers = ['X-Ustar-Key' => 'tg1', 'X-Ustar-Time' => (string)time(),
            'X-Ustar-Nonce' => 'rn1_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')];
        $headers['X-Ustar-Signature'] = hash_hmac('sha256', implode("\n", [
            'USTAR-CONNECT-BRIDGE-v1', 'POST', bridge_auth::PATH, 'tg1', $headers['X-Ustar-Time'],
            $headers['X-Ustar-Nonce'], hash('sha256', $body)]), hex2bin(str_repeat('12', 32)));
        return [$body, $headers];
    }
    private function denied(callable $call, string $reason): void {
        try { $call(); $this->fail('Expected ' . $reason); }
        catch (\InvalidArgumentException $error) { $this->assertSame($reason, $error->getMessage()); }
    }
    public function test_transport_nonce_is_durable_and_one_request_is_not_replayed(): void {
        global $DB;
        [$body, $headers] = $this->signed('launch.create',
            ['provider' => 'telegram', 'tenant' => '123456', 'subject' => '77777']);
        $this->assertArrayHasKey('launch', bridge_service::dispatch($body, $headers, $this->keys()));
        $this->denied(fn() => bridge_service::dispatch($body, $headers, $this->keys()), 'replayed_transport');
        $this->assertSame(1, $DB->count_records('local_ustar_conn_launches'));
        $this->assertSame(1, $DB->count_records('local_ustar_conn_proofs', ['challengeid' => 0]));
    }
    public function test_key_scope_unknown_actor_fields_and_unknown_operations_fail_closed(): void {
        $payload = ['provider' => 'telegram', 'tenant' => '123456', 'subject' => '77777'];
        [$body, $headers] = $this->signed('launch.create', $payload + ['userid' => '2']);
        $this->denied(fn() => bridge_service::dispatch($body, $headers, $this->keys()), 'invalid_payload');
        $payload['tenant'] = '654321';
        [$body, $headers] = $this->signed('launch.create', $payload);
        $this->denied(fn() => bridge_service::dispatch($body, $headers, $this->keys()), 'provider_mismatch');
        [$body, $headers] = $this->signed('hr.approve', $payload);
        $this->denied(fn() => bridge_service::dispatch($body, $headers, $this->keys()), 'forbidden_operation');
        [$body, $headers] = $this->signed('launch.create', $payload);
        $keys = $this->keys(); $keys['tg1']['operations'] = ['entry.status'];
        $this->denied(fn() => bridge_service::dispatch($body, $headers, $keys), 'forbidden_operation');
    }
    public function test_http_is_disabled_by_default_and_forwarded_headers_cannot_bypass_peer_or_tls(): void {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on', 'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => bridge_auth::PATH, 'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '100'];
        $this->denied(fn() => bridge_http::guard($server, false, ['127.0.0.1']), 'bridge_disabled');
        bridge_http::guard($server, true, ['127.0.0.1']);
        foreach ([['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1'],
                ['HTTPS' => '', 'HTTP_X_FORWARDED_PROTO' => 'https']] as $changes) {
            $this->denied(fn() => bridge_http::guard(array_replace($server, $changes), true, ['127.0.0.1']), 'invalid_transport');
        }
        foreach ([['REQUEST_METHOD' => 'GET'], ['QUERY_STRING' => 'x=1'], ['REQUEST_URI' => '/other'],
                ['CONTENT_LENGTH' => '16385'], ['CONTENT_TYPE' => 'text/plain']] as $changes) {
            $this->denied(fn() => bridge_http::guard(array_replace($server, $changes), true, ['127.0.0.1']), 'invalid_request');
        }
    }
    public function test_signed_registration_flow_uses_bound_session_and_rejects_actor_fields(): void {
        global $DB;
        \local_ustar\accounts::ensure_profile_field();
        $sender = ['provider' => 'telegram', 'tenant' => '123456', 'subject' => '77777'];
        [$body, $headers] = $this->signed('launch.create', $sender);
        $launch = bridge_service::dispatch($body, $headers, $this->keys());
        $browser = \local_ustar\connect\opaque_token::issue('ub1');
        [$body, $headers] = $this->signed('launch.exchange', $sender + [
            'launch' => $launch['launch'], 'browser' => $browser, 'proof' => hash('sha256', 'bridge-signup'), 'auth_date' => time()]);
        $entry = bridge_service::dispatch($body, $headers, $this->keys());
        $binding = ['browser' => $browser, 'session' => $entry['session']];
        [$body, $headers] = $this->signed('registration.options', $binding);
        $this->assertNotEmpty(bridge_service::dispatch($body, $headers, $this->keys())['departments']);
        $form = ['departmentid' => 'retail', 'email' => 'bridge-signup@example.invalid', 'firstname' => 'Synthetic',
            'lastname' => 'Bridge', 'password' => 'Fixture-Only-Qx9!2026', 'username' => 'bridge_signup'];
        $payload = $binding + $form + ['command' => \local_ustar\connect\opaque_token::issue('ur1')];
        [$body, $headers] = $this->signed('registration.submit', $payload + ['userid' => '2']);
        $this->denied(fn() => bridge_service::dispatch($body, $headers, $this->keys()), 'invalid_payload');
        [$body, $headers] = $this->signed('registration.submit', $payload);
        $this->assertSame('registered', bridge_service::dispatch($body, $headers, $this->keys())['outcome']);
        [$body, $headers] = $this->signed('registration.submit', $payload);
        $this->assertTrue(bridge_service::dispatch($body, $headers, $this->keys())['replayed']);
        [$body, $headers] = $this->signed('registration.status', $binding);
        $result = bridge_service::dispatch($body, $headers, $this->keys());
        $this->assertSame('pending', $result['registration']['status']);
        $this->assertStringNotContainsString($form['password'], json_encode($result));
        $this->assertSame(1, $DB->count_records('local_ustar_conn_signups'));
    }
}
