<?php
// Legacy role/reporting mutation retired pending scoped migration.
define('CLI_SCRIPT', true);
fwrite(STDERR, "RETIRED: import_reporting.php requires a reviewed scoped migration plan.\n");
exit(78);
