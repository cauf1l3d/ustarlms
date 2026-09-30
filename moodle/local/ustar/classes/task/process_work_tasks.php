<?php
namespace local_ustar\task;
defined('MOODLE_INTERNAL') || die();
final class process_work_tasks extends \core\task\scheduled_task {
    public function get_name(): string { return 'USTAR recurring tasks and hierarchical escalation'; }
    public function execute(): void {
        $stats = \local_ustar\task_workspace\worker::run(100);
        mtrace('USTAR task workspace: ' . json_encode($stats));
    }
}
