<?php
/**
 * USTAR Library — idempotent offer seed.
 *
 * Creates one draft offer per active catalog item and published content item.
 * Safe to run repeatedly. Never publishes or opens access automatically.
 *
 * Usage:
 *   php local/ustar/cli/library_offer_seed.php --dryrun
 *   php local/ustar/cli/library_offer_seed.php --actor=2
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

use local_ustar\library_offer;

global $DB;

$dryrun = in_array('--dryrun', $argv, true);
$actor = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--actor=') === 0) {
        $actor = (int)substr($arg, 8);
    }
}

if ($actor <= 0) {
    // Fall back to the first site admin so usermodified is never zero.
    $admins = get_admins();
    if (!$admins) {
        fwrite(STDERR, "No site admin found; pass --actor=<userid>.\n");
        exit(1);
    }
    $actor = (int)reset($admins)->id;
}

if (!library_offer::available()) {
    fwrite(STDERR, "Table local_ustar_library_offer is absent. Run the upgrade first.\n");
    exit(1);
}

if (!is_siteadmin($actor)) { fwrite(STDERR, 'CLI actor must be a site administrator.\n');exit(1); }
\core\session\manager::set_user($DB->get_record('user',['id'=>$actor],'*',MUST_EXIST));
$result = library_offer::seed($actor, $dryrun);

printf("%s: created %d, skipped %d (actor %d)\n",
    $dryrun ? 'DRYRUN' : 'SEEDED', $result['created'], $result['skipped'], $actor);

if ($dryrun) {
    echo "Nothing written. Re-run without --dryrun to apply.\n";
} else {
    echo "Offers are drafts with accesspolicy=assigned and price 0.\n";
    echo "Prices and paid policies stay a human decision in the Library editor.\n";
}
