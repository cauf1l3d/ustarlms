<?php
namespace local_ustar\task;

defined('MOODLE_INTERNAL') || die();

/** Bounded repair of committed completions whose reward needs reconciliation. */
final class reconcile_rewards extends \core\task\scheduled_task {
    public function get_name(): string {
        return 'USTAR: сверка наград за маршрут';
    }

    public function execute(): void {
        \local_ustar\route_rewards::reconcile(200);
        mtrace('USTAR reward reconciliation completed');
    }
}
