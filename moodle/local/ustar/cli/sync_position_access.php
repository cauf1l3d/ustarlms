<?php
#define CLI entry point.
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require($config);

$all = in_array('--all', $argv, true);
$roles = \local_ustar\position_access::ensure_roles();

echo 'USTAR_MANAGER_ROLE_ID=' . (int)($roles['ustar_manager'] ?? 0) . PHP_EOL;
echo 'USTAR_HR_ROLE_ID=' . (int)($roles['ustar_hr'] ?? 0) . PHP_EOL;

if (!$all) {
    echo "NEXT=Re-run with --all to sync existing users\n";
    exit(0);
}

$users = $DB->get_records_select('user', 'deleted = 0 AND id > 1', null, 'id ASC', 'id,username');
$counts = ['manager' => 0, 'hr' => 0, 'employee' => 0, 'skipped' => 0];
foreach ($users as $user) {
    $result = \local_ustar\position_access::sync_user((int)$user->id);
    if (($result['status'] ?? '') === 'skipped') {
        $counts['skipped']++;
        continue;
    }
    $target = (string)($result['targetrole'] ?? '');
    if ($target === 'ustar_manager') {
        $counts['manager']++;
    } else if ($target === 'ustar_hr') {
        $counts['hr']++;
    } else {
        $counts['employee']++;
    }
}

echo 'SYNC_MANAGER=' . $counts['manager'] . PHP_EOL;
echo 'SYNC_HR=' . $counts['hr'] . PHP_EOL;
echo 'SYNC_EMPLOYEE=' . $counts['employee'] . PHP_EOL;
echo 'SYNC_SKIPPED=' . $counts['skipped'] . PHP_EOL;
echo "POSITION_ACCESS_SYNC=OK\n";
