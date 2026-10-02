<?php
define('CLI_SCRIPT', true);
require('/var/www/html/public/config.php');

global $CFG, $DB, $USER;

if (!in_array($CFG->wwwroot, [
    'http://127.0.0.1:18080',
    'http://127.0.0.1:18081',
    'http://127.0.0.1:18082',
], true) || empty($CFG->noemailever)) {
    fwrite(STDERR, "Refusing non-isolated Moodle instance\n");
    exit(1);
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php test_page_denial.php <username> <path>\n");
    exit(2);
}

$username = (string)$argv[1];
$path = (string)$argv[2];
$allowedpaths = [
    '/local/ustar/hr.php',
    '/local/ustar/operations.php',
    '/local/ustar/positions.php',
    '/local/ustar/materials.php',
    '/local/ustar/material_ack_export.php',
    '/local/ustar/route_studio.php',
    '/local/ustar/executive.php',
    '/local/ustar/brand.php',
    '/local/ustar/game_studio.php',
    '/local/ustar/checklist_studio.php',
    '/local/ustar/material_bulk.php',
];

if (!in_array($path, $allowedpaths, true)) {
    fwrite(STDERR, "Refusing path outside denial-test allowlist\n");
    exit(2);
}

$user = $DB->get_record('user', [
    'username' => $username,
    'mnethostid' => $CFG->mnet_localhost_id,
    'deleted' => 0,
], '*', MUST_EXIST);

$USER = $user;
\core\session\manager::set_user($user);
$libdirbeforeinclude = (string)$CFG->libdir;

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = $path;
$_SERVER['PHP_SELF'] = $path;
$_SERVER['REQUEST_URI'] = $path;

ob_start();
try {
    require($CFG->dirroot . $path);
    ob_end_clean();
    echo json_encode([
        'status' => 'FAIL',
        'username' => $username,
        'path' => $path,
        'reason' => 'page returned without a capability denial',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
} catch (required_capability_exception $exception) {
    ob_end_clean();
    echo json_encode([
        'status' => 'PASS',
        'username' => $username,
        'path' => $path,
        'exception' => get_class($exception),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    ob_end_clean();
    echo json_encode([
        'status' => 'FAIL',
        'username' => $username,
        'path' => $path,
        'exception' => get_class($exception),
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString(),
        'libdir_before_include' => $libdirbeforeinclude,
        'libdir_after_failure' => (string)($CFG->libdir ?? ''),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
