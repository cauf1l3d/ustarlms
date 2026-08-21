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
    'positionid' => 'retail_seller',
], ['h' => 'help']);

if ($unrecognized) {
    cli_error('Неизвестные параметры: ' . implode(', ', $unrecognized));
}
if (!empty($options['help'])) {
    echo "USTAR Learning Route 2.0: проверка маршрута пользователя\n\n";
    echo "php check_route_user.php --userid=123 [--positionid=retail_seller]\n";
    echo "php check_route_user.php --email=user@example.com [--positionid=retail_seller]\n";
    exit(0);
}

$userid = (int)$options['userid'];
if ($userid <= 0 && trim((string)$options['email']) !== '') {
    $userid = (int)$DB->get_field('user', 'id', ['email' => trim((string)$options['email']), 'deleted' => 0]);
}
if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
    cli_error('USER_NOT_FOUND');
}
$positionid = clean_param((string)$options['positionid'], PARAM_ALPHANUMEXT);
$route = \local_ustar\route_model::for_user($positionid, $userid);
if (empty($route['ok'])) {
    cli_error('ROUTE_NOT_AVAILABLE reason=' . (string)($route['reason'] ?? 'unknown'));
}

echo 'USER_ID=' . $userid . PHP_EOL;
echo 'POSITION=' . $positionid . PHP_EOL;
echo 'ROUTE=' . (string)$route['name'] . PHP_EOL;
echo 'ADAPTATION=' . (int)$route['adaptationdone'] . '/' . (int)$route['adaptationtotal'] . ' (' . (int)$route['adaptationprogress'] . '%)' . PHP_EOL;
echo 'ADMITTED=' . (!empty($route['admitted']) ? 'YES' : 'NO') . PHP_EOL;
echo 'CONTINUOUS_PENDING=' . (int)$route['continuouspending'] . PHP_EOL;
echo 'FRESHNESS=' . (int)$route['freshness'] . '%' . PHP_EOL;
foreach ($route['points'] as $point) {
    echo sprintf(
        "POINT=%02d phase=%s version=v%d status=%s title=%s\n",
        (int)$point['number'],
        (string)$point['phase'],
        (int)$point['versionno'],
        (string)$point['status'],
        (string)$point['title']
    );
}
echo "ROUTE_USER_CHECK=OK\n";
