<?php
define('CLI_SCRIPT', true);
$config = dirname(__DIR__, 3) . '/config.php';
if (!is_file($config)) {
    $config = dirname(__DIR__, 4) . '/config.php';
}
require($config);

$context = context_system::instance();
$structure = \local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE);
$posmap = [];
foreach ($structure['positions'] ?? [] as $position) {
    $posmap[(string)$position['id']] = $position;
}

echo 'GLOBAL_THEME=' . (string)($CFG->theme ?? '') . PHP_EOL;
echo 'WWWROOT=' . (string)$CFG->wwwroot . PHP_EOL;
echo 'LOGIN_URL=' . $CFG->wwwroot . '/login/index.php' . PHP_EOL;
echo 'RETAIL_HEAD_NAME=' . (string)($posmap['retail_head']['name'] ?? 'MISSING') . PHP_EOL;

echo "ROLE_UI_USERS_BEGIN\n";
$fieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'ustar_position']);
if ($fieldid) {
    $rows = $DB->get_records_sql(
        "SELECT u.id, u.username, u.firstname, u.lastname, d.data AS positionid\n"
        . "  FROM {user} u\n"
        . "  JOIN {user_info_data} d ON d.userid = u.id AND d.fieldid = :fieldid\n"
        . " WHERE u.deleted = 0 AND d.data <> ''\n"
        . " ORDER BY u.id",
        ['fieldid' => $fieldid]
    );
    foreach ($rows as $row) {
        $position = $posmap[(string)$row->positionid] ?? [];
        $department = (string)($position['department'] ?? '');
        $interesting = $row->positionid === 'retail_head' || $department === 'hr' || $row->username === 'konstorztest';
        if (!$interesting) {
            continue;
        }
        echo 'USER=' . (int)$row->id
            . '|username=' . $row->username
            . '|position=' . $row->positionid
            . '|title=' . (string)($position['name'] ?? '')
            . '|manager=' . (has_capability('local/ustar:viewteam', $context, (int)$row->id) ? 'YES' : 'NO')
            . '|hr=' . (has_capability('local/ustar:hr', $context, (int)$row->id) ? 'YES' : 'NO')
            . '|exec=' . (has_capability('local/ustar:executive', $context, (int)$row->id) ? 'YES' : 'NO')
            . '|landing=' . \local_ustar\position_access::landing_path((int)$row->id)
            . PHP_EOL;
    }
}
$rabadov = $DB->get_record('user', ['username' => 'rabadov', 'deleted' => 0], 'id,username');
if ($rabadov) {
    echo 'USER=' . (int)$rabadov->id
        . '|username=rabadov'
        . '|manager=' . (has_capability('local/ustar:viewteam', $context, (int)$rabadov->id) ? 'YES' : 'NO')
        . '|hr=' . (has_capability('local/ustar:hr', $context, (int)$rabadov->id) ? 'YES' : 'NO')
        . '|exec=' . (has_capability('local/ustar:executive', $context, (int)$rabadov->id) ? 'YES' : 'NO')
        . '|landing=' . \local_ustar\position_access::landing_path((int)$rabadov->id)
        . PHP_EOL;
} else {
    echo "USER=rabadov|status=NOT_FOUND\n";
}
echo "ROLE_UI_USERS_END\n";
echo "ROLE_UI_CHECK=OK\n";
