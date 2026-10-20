<?php
// Actual HTTP facade under Moodle5.1.8/PG16. Fixed synthetic fixture key only.
if (__DIR__ !== '/source/tests/stage' || getcwd() !== '/stage/moodle') {
    throw new RuntimeException('isolated_fixture_required');
}
define('CLI_SCRIPT', true);
require('/stage/moodle/config.php');
$counts = static function() use ($DB): array {
    $result = [];
    foreach ($DB->get_tables() as $table) {
        if (str_starts_with($table, 'local_ustar_conn_')) {
            $result[$table] = $DB->count_records($table);
        }
    }
    ksort($result);
    if (count($result) !== 6) { throw new RuntimeException('connect_schema_missing'); }
    return $result;
};
$before = $counts();
foreach ([true, false] as $valid) {
    $body = '{"operation":"registration.options","payload":{"probe":"link-scope-v1"}}';
    $at = (string)time();
    $nonce = 'rn1_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $message = implode("\n", ['USTAR-CONNECT-BRIDGE-v1', 'POST', '/local/ustar/connect_bridge.php',
        'tg_link_v1', $at, $nonce, hash('sha256', $body)]);
    $signature = hash_hmac('sha256', $message, hex2bin(str_repeat($valid ? '12' : '34', 32)));
    $context = stream_context_create(['http' => ['method' => 'POST', 'content' => $body,
        'timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0,
        'header' => "Host: ustar.local\r\nContent-Type: application/json\r\nX-Ustar-Key: tg_link_v1\r\nX-Ustar-Time: $at\r\nX-Ustar-Nonce: $nonce\r\nX-Ustar-Signature: $signature\r\n"]]);
    $response = file_get_contents('http://127.0.0.1:8087/local/ustar/connect_bridge.php', false, $context);
    $expected = '{"ok":false,"reason":"' . ($valid ? 'forbidden_operation' : 'invalid_transport') . '"}';
    if ($response !== $expected || !str_contains($http_response_header[0], ' 403 ')) {
        $value = json_decode((string)$response, true);
        $reason = is_array($value) && is_string($value['reason'] ?? null)
            && preg_match('/\A[a-z_]{1,64}\z/', $value['reason']) ? $value['reason'] : 'not_fixed_JSON';
        $diagnostic = ['status' => $http_response_header[0] ?? 'none', 'valid_signature' => $valid,
            'fixed_reason' => $reason, 'body_bytes' => strlen((string)$response),
            'body_prefix_hex' => bin2hex(substr((string)$response, 0, 24))];
        file_put_contents('/artifacts/connect-scope-probe-failure.json', json_encode($diagnostic));
        throw new RuntimeException('signed_scope_probe_unverified:' . json_encode($diagnostic));
    }
}
if ($counts() !== $before) { throw new RuntimeException('scope_probe_changed_connect_state'); }
echo "CONNECT_SCOPE_NATIVE=PASS; actual Moodle/PG, signed scope denial, wrong HMAC denied, six tables unchanged\n";
