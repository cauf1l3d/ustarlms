<?php
/**
 * Library quick-check pipeline — manual run and dry-run.
 *
 *   php local/ustar/cli/library_quick_sync.php --dryrun   # shows what WOULD change
 *   php local/ustar/cli/library_quick_sync.php            # applies
 *
 * Use --dryrun first after any catalog import. Exit codes: 0 ok, 1 error, 2 locked.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

$dryrun = in_array('--dryrun', $argv, true);

if (!\local_ustar\library_assessment::available()) {
    fwrite(STDERR, "local_ustar_library_assessment is absent. Run the upgrade first.\n");
    exit(1);
}

$result = \local_ustar\library_quick_sync::run($dryrun);

if (!empty($result['locked'])) {
    fwrite(STDERR, "Another sync is running. Try again in a minute.\n");
    exit(2);
}

printf("%s  created=%d refreshed=%d noop=%d archived=%d skipped=%d\n",
    $dryrun ? 'DRYRUN' : 'APPLIED',
    $result['created'], $result['refreshed'], $result['noop'],
    $result['archived'], $result['skipped']);

exit(0);
