<?php
namespace local_ustar\task;

defined('MOODLE_INTERNAL') || die();

final class remind_briefing extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('taskremindbriefing', 'local_ustar');
    }

    public function execute(): void {
        \local_ustar\briefing\reminders::run();
    }
}
