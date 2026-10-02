<?php
define('CLI_SCRIPT', true);
define('IGNORE_COMPONENT_CACHE', true);
require('/stage/moodle/config.php');
if ($CFG->dbhost !== 'db' || $CFG->dbname !== 'ustar_stage1' || $CFG->prefix !== 'stage_') {
    throw new RuntimeException('REFUSING_NON_ISOLATED_BASELINE');
}
if (get_config('local_ustar', 'version')) {
    throw new RuntimeException('Baseline capability preparation must precede plugin installation');
}
update_capabilities('local_ustar');
echo "BASELINE_FIXTURE_PREPARATION=capability registration before historical install callback\n";
