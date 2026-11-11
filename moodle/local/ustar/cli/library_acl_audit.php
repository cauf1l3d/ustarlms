<?php
/**
 * USTAR Library — read-only ACL audit.
 *
 * Reports published materials with no active content_access rule (invisible
 * to employees under the deny-by-default model) and offers whose subject is
 * unreachable. Never publishes, never grants, never writes.
 *
 * Usage:
 *   php local/ustar/cli/library_acl_audit.php
 *   php local/ustar/cli/library_acl_audit.php --csv > acl_audit.csv
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

global $DB;

$csv = in_array('--csv', $argv, true);

// ---------------------------------------------------------------- header
if (!$csv) {
    echo "=== USTAR Library — ACL audit (read-only) ===\n";
    echo 'time      : ' . date('c') . "\n";
    echo 'wwwroot   : ' . $CFG->wwwroot . "\n";
    echo 'prefix    : ' . $CFG->prefix . "\n\n";
}

// -------------------------------------------------- materials with no rule
$orphans = $DB->get_records_sql(
    "SELECT c.id, c.title, c.type, c.category, c.timecreated, c.timemodified
       FROM {local_ustar_content} c
      WHERE c.status = :status
        AND NOT EXISTS (
            SELECT 1 FROM {local_ustar_content_access} a
             WHERE a.contentid = c.id AND a.active = 1)
   ORDER BY c.type ASC, c.title ASC",
    ['status' => 'published']
);

$total = $DB->count_records('local_ustar_content', ['status' => 'published']);

if ($csv) {
    echo "id,type,category,title\n";
    foreach ($orphans as $row) {
        echo implode(',', [
            (int)$row->id,
            csv_safe((string)$row->type),
            csv_safe((string)$row->category),
            csv_safe((string)$row->title),
        ]) . "\n";
    }
    exit(0);
}

$count = count($orphans);
$visible = $total - $count;
printf("published materials       : %d\n", $total);
printf("visible (has active rule) : %d\n", $visible);
printf("invisible (no active rule): %d  (%.1f%%)\n\n",
    $count, $total > 0 ? $count / $total * 100 : 0);

if ($count === 0) {
    echo "No orphan materials.\n";
} else {
    // Group by type so the owner sees the shape, not 110 individual rows.
    $bytype = [];
    foreach ($orphans as $row) {
        $bytype[(string)$row->type] = ($bytype[(string)$row->type] ?? 0) + 1;
    }
    echo "invisible by type:\n";
    foreach ($bytype as $type => $n) {
        printf("  %-16s %d\n", $type, $n);
    }

    echo "\noldest 10 invisible items (id | type | title):\n";
    $slice = array_slice($orphans, 0, 10);
    foreach ($slice as $row) {
        printf("  %-5d | %-13s | %s\n", (int)$row->id, (string)$row->type,
            \core_text::substr((string)$row->title, 0, 60));
    }
    echo "\nThese are NOT published to employees by design (deny-by-default).\n";
    echo "Mass-publishing them would be a security event; decide per item in\n";
    echo "Control Center -> Materials, or in bulk via an explicit owner order.\n";
}

// ------------------------------------------------------- offer integrity
if ($DB->get_manager()->table_exists(new xmldb_table('local_ustar_library_offer'))) {
    echo "\n--- offers ---\n";

    $offers = $DB->count_records('local_ustar_library_offer');
    printf("offers total : %d\n", $offers);

    $paid = $DB->count_records('local_ustar_library_offer',
        ['accesspolicy' => 'paid', 'visibility' => 'published']);
    printf("paid+published: %d\n", $paid);

    // Offers whose subject disappeared: warn only.
    $dangling = $DB->get_records_sql(
        "SELECT o.id, o.itemtype, o.itemid, o.title
           FROM {local_ustar_library_offer} o
          WHERE (o.itemtype = 'content'
                 AND NOT EXISTS (SELECT 1 FROM {local_ustar_content} c
                                  WHERE c.id = o.itemid))
             OR (o.itemtype = 'catalog'
                 AND NOT EXISTS (SELECT 1 FROM {local_ustar_catalog} k
                                  WHERE k.id = o.itemid))
       ORDER BY o.id ASC", [], 0, 20);

    if ($dangling) {
        printf("dangling offers: %d\n", count($dangling));
        foreach ($dangling as $row) {
            printf("  offer %d -> %s:%d (%s)\n", (int)$row->id,
                (string)$row->itemtype, (int)$row->itemid, (string)$row->title);
        }
    } else {
        echo "dangling offers: 0\n";
    }
} else {
    echo "\n(library_offer table absent — run the F0 upgrade first)\n";
}

// ------------------------------------------------------- economy sanity
if ($DB->get_manager()->table_exists(new xmldb_table('local_ustar_coin_balance'))) {
    echo "\n--- economy vs. pricing ---\n";
    $row = $DB->get_record_sql(
        "SELECT COUNT('X') AS wallets,
                COALESCE(MAX(balance), 0) AS maxbal,
                COALESCE(SUM(balance), 0) AS sumbal
           FROM {local_ustar_coin_balance}");
    printf("wallets %d, max %d, sum %d\n", (int)$row->wallets,
        (int)$row->maxbal, (int)$row->sumbal);

    $min = (int)get_config('local_ustar', 'library_pricemin') ?: 1;
    $max = (int)get_config('local_ustar', 'library_pricemax') ?: 25;
    printf("configured price band: %d..%d USCOIN\n", $min, $max);

    $afford = $DB->count_records_select('local_ustar_coin_balance',
        'balance >= :p', ['p' => $min]);
    printf("wallets able to afford the minimum price: %d\n", $afford);
    if ($afford === 0) {
        echo "WARNING: nobody can afford the cheapest offer. The marketplace\n";
        echo "would ship unusable. Keep library_store_enabled = 0 until the\n";
        echo "emission (faucet) decision is made.\n";
    }
}

echo "\n=== end ===\n";

/** Minimal CSV escaping without pulling in a library. */
function csv_safe(string $value): string {
    $value = str_replace(["\r", "\n"], ' ', $value);
    if (strpos($value, ',') !== false || strpos($value, '"') !== false) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}
