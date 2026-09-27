<?php
// Aggregated review only. Never deletes or changes feed content.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unknown] = cli_get_params(['help' => false], ['h' => 'help']);
if ($unknown) {
    cli_error('Unknown arguments: ' . implode(', ', $unknown));
}
if ($options['help']) {
    echo "Read-only feed retention review. No identifiers, content or deletion.\n";
    echo "  php local/ustar/cli/feed_retention_report.php\n";
    exit(0);
}

$now = time();
echo json_encode([
    'generated_at_utc' => gmdate('c', $now),
    'mode' => 'read_only_review',
    'automatic_deletion' => false,
    'counts' => \local_ustar\feed_retention::review_counts($now),
    'warning' => 'Candidates require manual exception, relationship, attachment and backup review.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
