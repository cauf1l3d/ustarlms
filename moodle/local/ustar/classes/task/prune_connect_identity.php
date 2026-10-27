<?php
namespace local_ustar\task;

defined('MOODLE_INTERNAL') || die();

/** Uses the existing Moodle cron; does not start another worker or send messages. */
final class prune_connect_identity extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('taskpruneconnectidentity', 'local_ustar');
    }

    public function execute(): void {
        \local_ustar\connect\identity_service::prune_expired(500);
    }
}
