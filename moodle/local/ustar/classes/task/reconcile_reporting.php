<?php
namespace local_ustar\task;

defined('MOODLE_INTERNAL') || die();

/** Repair derived reporting after an assignment or acting period changes. */
final class reconcile_reporting extends \core\task\scheduled_task {
    public function get_name(): string {
        return 'USTAR: сверка подчинённости';
    }

    public function execute(): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $result = \local_ustar\organization_model::rebuild_reporting();
            $transaction->allow_commit();
            mtrace('USTAR reporting reconciliation: ' . json_encode($result));
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }
}
