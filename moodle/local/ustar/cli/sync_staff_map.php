<?php
#define CLI_SCRIPT true before config include.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');

use local_ustar\people;
use local_ustar\structure;

$opts = getopt('', ['file:', 'apply', 'include-protected']);
$file = $opts['file'] ?? '';
$apply = array_key_exists('apply', $opts);
$includeprotected = array_key_exists('include-protected', $opts);
if (!$file || !is_readable($file)) {
    fwrite(STDERR, "Usage: php sync_staff_map.php --file=/path/staff_position_map.csv [--apply] [--include-protected]\n");
    exit(2);
}

function ustar_norm_name(string $s): string {
    $s = core_text::strtolower(str_replace('ё', 'е', trim($s)));
    $s = preg_replace('/\([^)]*\)/u', ' ', $s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}
function ustar_tokens(string $s): array {
    $parts = preg_split('/\s+/u', ustar_norm_name($s), -1, PREG_SPLIT_NO_EMPTY);
    sort($parts, SORT_STRING);
    return $parts;
}
function ustar_signature(string $s): string { return implode('|', ustar_tokens($s)); }

$fh = fopen($file, 'rb');
$head = fgetcsv($fh);
if (!$head) { throw new RuntimeException('CSV is empty'); }
$head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
$rows = [];
while (($values = fgetcsv($fh)) !== false) {
    $row = [];
    foreach ($head as $i => $key) { $row[$key] = trim((string)($values[$i] ?? '')); }
    if (($row['status'] ?? '') === 'ready' && ($row['fullname'] ?? '') !== '' && ($row['positionid'] ?? '') !== '') $rows[] = $row;
}
fclose($fh);

$st = structure::get(structure::NAME_STRUCTURE);
$validpositions = array_fill_keys(array_map(static fn($p) => $p['id'], $st['positions'] ?? []), true);
$users = $DB->get_records_select('user', 'deleted = 0 AND id > 1', [], 'id ASC', 'id,username,firstname,lastname,email,suspended');
$byexact = [];
$bysig = [];
foreach ($users as $u) {
    $variants = [trim($u->firstname . ' ' . $u->lastname), trim($u->lastname . ' ' . $u->firstname)];
    foreach ($variants as $v) {
        $n = ustar_norm_name($v); $sig = ustar_signature($v);
        if ($n !== '') $byexact[$n][$u->id] = $u;
        if ($sig !== '') $bysig[$sig][$u->id] = $u;
    }
}

$ctx = context_system::instance();
$actorid = (int)get_admin()->id;
$matched = $ambiguous = $missing = $invalid = $protected = $updated = 0;
$report = [];
$transaction = $apply ? $DB->start_delegated_transaction() : null;
foreach ($rows as $row) {
    $fullname = $row['fullname']; $positionid = $row['positionid'];
    if (!isset($validpositions[$positionid])) {
        $invalid++; $report[] = ['INVALID_POSITION', $fullname, $positionid, '']; continue;
    }
    $n = ustar_norm_name($fullname); $sig = ustar_signature($fullname);
    $candidates = $byexact[$n] ?? [];
    if (!$candidates) $candidates = $bysig[$sig] ?? [];
    if (count($candidates) === 0) {
        // Staff map is normally "lastname firstname patronymic" while Moodle often stores only first+last.
        $parts = preg_split('/\s+/u', $n, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) >= 2) {
            $pair = ustar_signature($parts[0] . ' ' . $parts[1]);
            foreach ($users as $u) {
                if (ustar_signature($u->firstname . ' ' . $u->lastname) === $pair) $candidates[$u->id] = $u;
            }
        }
    }
    if (count($candidates) === 0) { $missing++; $report[] = ['NOT_FOUND', $fullname, $positionid, '']; continue; }
    if (count($candidates) > 1) { $ambiguous++; $report[] = ['AMBIGUOUS', $fullname, $positionid, implode(',', array_keys($candidates))]; continue; }
    $u = reset($candidates); $matched++;
    $isprotected = is_siteadmin($u) || has_capability('local/ustar:admin', $ctx, $u->id);
    if ($isprotected && !$includeprotected) { $protected++; $report[] = ['PROTECTED', $fullname, $positionid, $u->id . ':' . $u->username]; continue; }
    $old = people::position_id((int)$u->id);
    if ($apply && $old !== $positionid) {
        people::set_position_id((int)$u->id, $positionid);
        people::log_action($actorid, (int)$u->id, 'staff_map_sync', ['source' => basename($file), 'old' => $old, 'new' => $positionid]);
        $updated++;
    }
    $report[] = [$apply ? ($old === $positionid ? 'UNCHANGED' : 'UPDATED') : 'MATCH', $fullname, $positionid, $u->id . ':' . $u->username . ($old ? ':old=' . $old : '')];
}
if ($transaction) $transaction->allow_commit();

echo "USTAR STAFF MAP SYNC\n====================\n";
echo "Mode: " . ($apply ? "APPLY" : "DRY-RUN") . "\n";
echo "Rows ready: " . count($rows) . "\nMatched: $matched\nUpdated: $updated\nProtected: $protected\nAmbiguous: $ambiguous\nNot found: $missing\nInvalid position: $invalid\n\n";
foreach ($report as $r) printf("%-16s | %-38s | %-30s | %s\n", $r[0], $r[1], $r[2], $r[3]);
if (!$apply) echo "\nNo database changes were made. Re-run with --apply only after reviewing this report.\n";
