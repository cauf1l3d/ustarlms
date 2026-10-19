<?php
// Disposable CLI fixture only. Two independent PHP processes share real PostgreSQL.
define('CLI_SCRIPT', true);
if (!is_dir('/stage/moodle') || getenv('USTAR_STAGE_PREFIX') === 'fresh_') {
    throw new RuntimeException('REFUSING_NON_ISOLATED_CONNECT_FIXTURE');
}
require '/stage/moodle/config.php';
require_once($CFG->dirroot . '/user/lib.php');

use local_ustar\connect\identity;
use local_ustar\connect\identity_service;
use local_ustar\connect\entry_service;
use local_ustar\connect\opaque_token;
use local_ustar\connect\signup_service;

function connect_user(int $userid): void {
    global $DB, $USER;
    \core\session\manager::set_user($DB->get_record('user', ['id' => $userid], '*', MUST_EXIST));
    $USER->sesskey = 'isolated-connect-concurrency-session';
}

if (($argv[1] ?? '') === 'child') {
    $input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    connect_user((int)$input['userid']);
    try {
        if ($input['op'] === 'confirm') {
            echo json_encode(identity_service::confirm($input['code']));
        } else if ($input['op'] === 'entry') {
            echo json_encode(entry_service::exchange(new identity('telegram', '123456', '77777'),
                $input['launch'], $input['proof'], $input['authdate'], $input['browser'], 'tg1'));
        } else if ($input['op'] === 'signup') {
            echo json_encode(signup_service::submit($input['session'], $input['browser'], $input['command'],
                $input['form'], 'tg1', 'telegram', '123456', str_repeat('12', 32)));
        } else if ($input['op'] === 'native-register') {
            \local_ustar\registration_service::register($input['form']);
            echo json_encode(['native_created' => true]);
        } else {
            identity_service::claim_verified($input['code'], new identity('telegram', '123456', '77777'), $input['proof']);
            echo json_encode(['claimed' => true]);
        }
    } catch (\local_ustar\registration_validation_exception $e) {
        echo json_encode(['denied' => 'validation_failed', 'field' => $e->field]);
    } catch (InvalidArgumentException $e) {
        echo json_encode(['denied' => $e->getMessage()]);
    }
    exit;
}

function connect_race(array $inputs): array {
    $workers = [];
    foreach ($inputs as $input) {
        $process = proc_open([PHP_BINARY, __FILE__, 'child'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start fixture child'); }
        fwrite($pipes[0], json_encode($input));
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') {
            throw new RuntimeException('Fixture child failed: ' . $error);
        }
        $results[] = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
    }
    return $results;
}

$userid = user_create_user((object)[
    'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
    'username' => 'connect_concurrency_fixture', 'firstname' => 'Synthetic', 'lastname' => 'Connect',
    'email' => 'connect-concurrency@example.invalid', 'suspended' => 0,
], false, false);
connect_user((int)$userid);
$challenge = identity_service::issue('telegram', '123456');
identity_service::claim_verified($challenge['code'], new identity('telegram', '123456', '77777'), hash('sha256', 'fixture-confirm'));
$input = ['userid' => $userid, 'op' => 'confirm', 'code' => $challenge['code']];
$results = connect_race([$input, $input]);
if ($results[0] !== $results[1] || empty($results[0]['id'])
        || $DB->count_records('local_ustar_conn_links', ['userid' => $userid]) !== 1) {
    throw new RuntimeException('Concurrent confirm did not produce one canonical link');
}
$one = identity_service::issue('telegram', '123456');
$two = identity_service::issue('telegram', '123456');
$proof = hash('sha256', 'same-independent-provider-proof');
$results = connect_race([
    ['userid' => $userid, 'op' => 'claim', 'code' => $one['code'], 'proof' => $proof],
    ['userid' => $userid, 'op' => 'claim', 'code' => $two['code'], 'proof' => $proof],
]);
if (count(array_filter($results, fn($item) => ($item['claimed'] ?? false) === true)) !== 1
        || count(array_filter($results, fn($item) => ($item['denied'] ?? '') === 'replayed_proof')) !== 1) {
    throw new RuntimeException('Concurrent replay was not rejected');
}
echo "CONNECT_CONCURRENT_CONFIRM_AND_REPLAY=PASS\n";
$launch = entry_service::create_launch(new identity('telegram', '123456', '77777'), 'tg1');
$input = ['userid' => $userid, 'op' => 'entry', 'launch' => $launch['launch'],
    'proof' => hash('sha256', 'concurrent-entry'), 'authdate' => time(), 'browser' => opaque_token::issue('ub1')];
$results = connect_race([$input, $input]);
if (count(array_filter($results, fn($item) => isset($item['session']))) !== 1
        || count(array_filter($results, fn($item) => ($item['denied'] ?? '') === 'launch_consumed')) !== 1
        || $DB->count_records('local_ustar_conn_sessions', ['userid' => $userid]) !== 1) {
    throw new RuntimeException('Concurrent launch exchange was not single-use');
}
echo "CONNECT_CONCURRENT_ENTRY_EXCHANGE=PASS\n";

function signup_entry(string $subject): array {
    $sender = new identity('telegram', '123456', $subject);
    $launch = entry_service::create_launch($sender, 'tg1');
    $browser = opaque_token::issue('ub1');
    $entry = entry_service::exchange($sender, $launch['launch'], hash('sha256', $launch['launch']), time(), $browser, 'tg1');
    return ['session' => $entry['session'], 'browser' => $browser, 'command' => opaque_token::issue('ur1')];
}
$form = ['username' => 'signup_concurrent', 'password' => 'Fixture-Only-Qx9!2026',
    'email' => 'signup-concurrent@example.invalid', 'firstname' => 'Synthetic', 'lastname' => 'Concurrent', 'departmentid' => 'retail'];
$input = signup_entry('88888') + ['userid' => $userid, 'op' => 'signup', 'form' => $form];
$results = connect_race([$input, $input]);
if (count(array_filter($results, fn($r) => ($r['outcome'] ?? '') === 'registered' && $r['replayed'] === false)) !== 1
        || count(array_filter($results, fn($r) => ($r['outcome'] ?? '') === 'registered' && $r['replayed'] === true)) !== 1
        || $DB->count_records('user', ['email' => $form['email']]) !== 1
        || $DB->count_records('local_ustar_conn_signups') !== 1) {
    throw new RuntimeException('Concurrent signup did not create one canonical account and one receipt');
}
echo "CONNECT_CONCURRENT_SIGNUP_AND_RETRY=PASS\n";
$form['username'] = 'signup_email_race'; $form['email'] = 'signup-email-race@example.invalid';
$nativeform = array_replace($form, ['username' => 'native_email_race']);
$results = connect_race([
    signup_entry('99991') + ['userid' => $userid, 'op' => 'signup', 'form' => $form],
    ['userid' => $userid, 'op' => 'native-register', 'form' => $nativeform],
]);
if (count(array_filter($results, fn($r) => ($r['outcome'] ?? '') === 'registered' || ($r['native_created'] ?? false))) !== 1
        || count(array_filter($results, fn($r) => ($r['denied'] ?? '') === 'validation_failed' && ($r['field'] ?? '') === 'email')) !== 1
        || $DB->count_records('user', ['email' => $form['email']]) !== 1) {
    throw new RuntimeException('LAN and Connect signup did not serialize the shared email through commit');
}
echo "CONNECT_CONCURRENT_LAN_EMAIL=PASS\n";
