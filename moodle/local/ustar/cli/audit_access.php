<?php
// Read-only role/profile audit for USTAR HR, Executive and Superadmin accounts.
define('CLI_SCRIPT', true);

$dir = __DIR__;
$config = null;
for ($i = 0; $i < 7; $i++) {
    $candidate = $dir . '/config.php';
    if (is_file($candidate)) {
        $config = $candidate;
        break;
    }
    $dir = dirname($dir);
}
if (!$config) {
    fwrite(STDERR, "Could not locate Moodle config.php\n");
    exit(1);
}
require($config);

$ctx = context_system::instance();
$shortnames = ['ustar_superadmin', 'ustar_hr', 'ustar_executive'];
$roles = $DB->get_records_list('role', 'shortname', $shortnames, 'shortname ASC');

echo "USTAR ACCESS AUDIT\n";
echo "==================\n";
foreach ($roles as $role) {
    echo "\nROLE {$role->shortname} ({$role->name})\n";
    $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.suspended
              FROM {role_assignments} ra
              JOIN {user} u ON u.id = ra.userid
             WHERE ra.roleid = :roleid AND ra.contextid = :contextid AND u.deleted = 0
          ORDER BY u.id";
    $users = $DB->get_records_sql($sql, ['roleid' => $role->id, 'contextid' => $ctx->id]);
    if (!$users) {
        echo "  (no assignments)\n";
        continue;
    }
    foreach ($users as $u) {
        $position = \local_ustar\people::position_id((int)$u->id);
        $flags = [
            'siteadmin' => is_siteadmin($u),
            'admin' => has_capability('local/ustar:admin', $ctx, $u->id),
            'hr' => has_capability('local/ustar:hr', $ctx, $u->id),
            'hrmanage' => has_capability('local/ustar:hrmanage', $ctx, $u->id),
            'executive' => has_capability('local/ustar:executive', $ctx, $u->id),
            'token' => has_capability('moodle/webservice:createtoken', $ctx, $u->id),
            'rest' => has_capability('webservice/rest:use', $ctx, $u->id),
        ];
        printf(
            "  #%d %-24s %-32s position=%-18s suspended=%s %s\n",
            $u->id,
            $u->username,
            trim($u->firstname . ' ' . $u->lastname),
            $position ?: '-',
            $u->suspended ? 'yes' : 'no',
            implode(' ', array_map(fn($k, $v) => $k . '=' . ($v ? 'yes' : 'no'), array_keys($flags), array_values($flags)))
        );
    }
}

echo "\nExpected domain isolation:\n";
echo "  ustar_superadmin -> admin=yes, hr=no, executive=no\n";
echo "  ustar_hr         -> hr=yes, hrmanage=yes, admin=no, executive=no\n";
echo "  ustar_executive  -> executive=yes, admin=no, hr=no\n";
