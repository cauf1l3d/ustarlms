<?php
require_once(__DIR__ . '/../../config.php');

require_login();
\local_ustar\feed_access::require_creator((int)$USER->id);
$query = \core_text::substr(trim(optional_param('q', '', PARAM_TEXT)), 0, 80);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (\core_text::strlen($query) < 2) {
    echo json_encode(['people' => []]);
    exit;
}
$needle = '%' . $DB->sql_like_escape($query) . '%';
$like = $DB->sql_like('firstname', ':first', false) . ' OR '
    . $DB->sql_like('lastname', ':last', false) . ' OR '
    . $DB->sql_like('username', ':username', false);
$rows = $DB->get_records_sql("SELECT id, firstname, lastname, username
       FROM {user}
      WHERE id > 1 AND deleted = 0 AND suspended = 0 AND ({$like})
   ORDER BY lastname, firstname, id",
    ['first' => $needle, 'last' => $needle, 'username' => $needle], 0, 40);
$people = [];
foreach ($rows as $user) {
    if (\local_ustar\accounts::participates((int)$user->id)) {
        $people[] = ['id' => (int)$user->id, 'name' => fullname($user) . ' [' . $user->username . ']'];
    }
}
echo json_encode(['people' => array_slice($people, 0, 20)]);
