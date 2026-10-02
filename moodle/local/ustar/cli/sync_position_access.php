<?php
// Legacy role/reporting mutation retired pending scoped migration.
define('CLI_SCRIPT', true);
fwrite(STDERR, "RETIRED: sync_position_access.php requires a reviewed scoped migration plan.\n");
exit(78);
