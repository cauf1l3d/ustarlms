<?php
namespace local_ustar\task;

defined('MOODLE_INTERNAL') || die();

final class import_feed_sources extends \core\task\scheduled_task {
    public function get_name(): string {
        return 'USTAR RSS feed import';
    }

    public function execute(): void {
        \local_ustar\feed_rss::import_enabled();
    }
}
