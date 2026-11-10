<?php
namespace {
    define('MOODLE_INTERNAL',true);
    define('IGNORE_MISSING',0);
    final class route_history_fixture_db {
        public bool $configured = true;
        public function get_records_sql($sql,$params): array {
            // This learner has credit, but automatic advancement creates no review window.
            return str_contains($sql,'local_ustar_route_progress')
                ? [9=>(object)['id'=>9,'requirementsjson'=>'[{"type":"cm","sourceid":41}]',
                    'accesspolicyjson'=>$this->configured ? '{"advance":"auto","repeat":"close"}' : null]] : [];
        }
    }
    $DB = new route_history_fixture_db();
    function get_coursemodule_from_id(...$args) { return (object)['id'=>41,'course'=>21]; }
}
namespace local_ustar {
    final class structure {
        public static function resolve_user($id) { return ['position'=>['id'=>'fixture']]; }
    }
    final class route_model {
        public static function read_only_snapshot(...$args) { return ['points'=>[]]; }
        public static function requirements_for_version($v) { return json_decode($v->requirementsjson,true); }
    }
    final class employment { public static function learning_allowed($id) { return true; } }
    final class forced_retraining {
        public static bool $assigned = false;
        public static function material_access(...$args) { return self::$assigned; }
    }
}
namespace {
    require_once(__DIR__.'/../../moodle/local/ustar/classes/route_point_access.php');
    function verify_history($expected,$label): void {
        if (\local_ustar\route_point_access::allowed_cmid(5,41) !== $expected) { throw new \RuntimeException($label); }
    }
    verify_history(false,'Removing an auto-advanced point must keep its saved material URL closed');
    \local_ustar\forced_retraining::$assigned = true;
    verify_history(true,'An independent assignment still grants its material');
    \local_ustar\forced_retraining::$assigned = false;
    verify_history(false,'Cancellation revokes that independent grant');
    $DB->configured = false;
    verify_history(null,'Legacy unconfigured completion does not acquire a new access restriction');
    echo "PASS route material access history\n";
}
