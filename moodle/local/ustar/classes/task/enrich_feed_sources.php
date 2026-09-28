<?php
namespace local_ustar\task;

defined('MOODLE_INTERNAL') || die();

final class enrich_feed_sources extends \core\task\scheduled_task {
    public function get_name(): string {
        return 'USTAR external article enrichment';
    }

    public function execute(): void {
        $stats = \local_ustar\feed_article_resolver::run_pending();
        mtrace(
            'USTAR article resolver: processed=' . (int)$stats['processed']
            . ' done=' . (int)$stats['done']
            . ' limited=' . (int)$stats['limited']
            . ' failed=' . (int)$stats['failed']
            . ' busy=' . (int)$stats['busy']
        );
    }
}
