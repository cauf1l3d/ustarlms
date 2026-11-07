<?php
namespace local_ustar\task;
defined('MOODLE_INTERNAL') || die();

/** Expired review windows advance without a browser; completion is never inferred from time. */
final class reconcile_route_access extends \core\task\scheduled_task {
    public function get_name(): string { return get_string('task_route_access','local_ustar'); }
    public function execute(): void {
        global $DB;
        $users = $DB->get_fieldset_sql('SELECT DISTINCT userid FROM {local_ustar_route_access}
            WHERE closedat=0 AND deadline>0 AND deadline<=:now',['now'=>time()]);
        foreach ($users as $userid) {
            try {
                \local_ustar\route_point_access::expire((int)$userid);
                if (!\local_ustar\employment::learning_allowed((int)$userid)) { continue; }
                $resolved = \local_ustar\structure::resolve_user((int)$userid);
                $position = (string)($resolved['position']['id'] ?? '');
                if ($position !== '' && empty(\local_ustar\adaptation_service::route_card((int)$userid,false)['blocked'])) {
                    \local_ustar\route_model::for_user($position,(int)$userid);
                }
            } catch (\Throwable $e) {
                mtrace('Route review deadline pending for user '.(int)$userid);
                debugging($e->getMessage(),DEBUG_DEVELOPER);
            }
        }
    }
}
