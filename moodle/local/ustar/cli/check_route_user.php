<?php
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require_once($config);
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'userid' => 0,
    'email' => '',
    'positionid' => '',
], ['h' => 'help']);

if ($unrecognized) {
    cli_error('Неизвестные параметры: ' . implode(', ', $unrecognized));
}
if (!empty($options['help'])) {
    echo "USTAR Learning Route: только сохранённое состояние, без синхронизации и записи\n\n";
    echo "php check_route_user.php --userid=123 [--positionid=position_code]\n";
    echo "php check_route_user.php --email=user@example.com [--positionid=position_code]\n";
    exit(0);
}

$userid = (int)$options['userid'];
if ($userid <= 0 && trim((string)$options['email']) !== '') {
    $userid = (int)$DB->get_field('user', 'id', ['email' => trim((string)$options['email']), 'deleted' => 0]);
}
if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
    cli_error('USER_NOT_FOUND');
}
$positionid = clean_param(trim((string)$options['positionid']), PARAM_ALPHANUMEXT);
if ($positionid === '') {
    $positionid = \local_ustar\people::position_id($userid);
}
if ($positionid === '') {
    cli_error('POSITION_NOT_AVAILABLE');
}
// for_user() reconciles completion and may enrol a real employee. Diagnostics
// must read persisted state without advancing a route or delivering rewards.
$route = \local_ustar\route_model::read_only_snapshot($positionid, $userid);
if (empty($route['ok'])) {
    cli_error('ROUTE_NOT_AVAILABLE reason=' . (string)($route['reason'] ?? 'unknown'));
}

echo 'USER_ID=' . $userid . PHP_EOL;
echo 'POSITION=' . $positionid . PHP_EOL;
echo 'ROUTE=' . (string)$route['name'] . PHP_EOL;
echo 'PERSISTED_PROGRESS=' . (int)$route['donepoints'] . '/' . (int)$route['totalpoints']
    . ' (' . (int)$route['progress'] . '%)' . PHP_EOL;
foreach ($route['points'] as $point) {
    echo sprintf(
        "POINT_ID=%d version_id=%d status=%s title=%s\n",
        (int)$point['id'],
        (int)$point['versionid'],
        (string)$point['status'],
        (string)$point['title']
    );
}
echo "PERSISTED_SNAPSHOT_ONLY=YES\nROUTE_USER_CHECK=OK\n";
