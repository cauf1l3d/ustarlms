<?php
namespace local_ustar\task;
defined('MOODLE_INTERNAL') || die();
final class reconcile_competitions extends \core\task\scheduled_task {
    public function get_name(): string { return 'USTAR: сверка баллов обучающих сезонов'; }
    public function execute(): void { \local_ustar\competition::reconcile_learning(200); }
}
