<?php
namespace local_ustar\task;
defined('MOODLE_INTERNAL') || die();
final class sync_library_product extends \core\task\adhoc_task {
    public function execute(): void {
        $id=(int)($this->get_custom_data()->productid??0);
        if ($id>0 && get_config('local_ustar','library_rail')) { \local_ustar\library_quick_sync::sync_product($id); }
    }
}
