<?php
namespace local_ustar\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates grade-promotion requests only for transitions explicitly configured
 * for automatic submission. Approval and promotion remain unchanged.
 */
final class submit_grade_requests extends \core\task\scheduled_task {
    public function get_name(): string {
        return 'USTAR automatic grade requests';
    }

    public function execute(): void {
        $stats = \local_ustar\grade_promotion::process_automatic_requests(50);
        mtrace(
            'USTAR grade auto requests: checked=' . (int)$stats['checked']
            . ' submitted=' . (int)$stats['submitted']
            . ' waiting=' . (int)$stats['waiting']
            . ' returned=' . (int)$stats['returned']
            . ' errors=' . (int)$stats['errors']
        );
    }
}
