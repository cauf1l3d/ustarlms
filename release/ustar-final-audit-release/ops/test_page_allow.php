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
    fwrite(STDERR, "Usage: php test_page_allow.php <username> <path>\n");
    exit(2);
}

$username = (string)$argv[1];
$path = (string)$argv[2];
$allowedpaths = [
    '/local/ustar/home.php',
    '/local/ustar/team.php',
    '/local/ustar/hr.php',
    '/local/ustar/operations.php',
    '/local/ustar/positions.php',
    '/local/ustar/materials.php',
    '/local/ustar/route_studio.php',
    '/local/ustar/executive.php',
    '/local/ustar/brand.php',
    '/local/ustar/game_studio.php',
    '/local/ustar/checklist_studio.php',
    '/local/ustar/material_bulk.php',
];

if (!in_array($path, $allowedpaths, true)) {
    fwrite(STDERR, "Refusing path outside allow-test allowlist\n");
    exit(2);
}

$user = $DB->get_record('user', [
    'username' => $username,
    'mnethostid' => $CFG->mnet_localhost_id,
    'deleted' => 0,
], '*', MUST_EXIST);

$USER = $user;
\core\session\manager::set_user($user);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = $path;
$_SERVER['PHP_SELF'] = $path;
$_SERVER['REQUEST_URI'] = $path;

ob_start();
try {
    require($CFG->dirroot . $path);
    $bytes = strlen((string)ob_get_contents());
    ob_end_clean();
    echo json_encode([
        'status' => 'PASS',
        'username' => $username,
        'path' => $path,
        'rendered_bytes' => $bytes,
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
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
