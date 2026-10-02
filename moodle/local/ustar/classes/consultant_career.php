<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Consultant role equivalence explicitly confirmed by USTAR; no staff records are changed. */
final class consultant_career {
    public static function is_consultant(string $name): bool {
        return preg_match('/^(?:продавец[\s\-–—]+кассир|продавец[\s\-–—]+консультант|консультант)$/iu', trim($name)) === 1;
    }

    /** Reuse the existing retail definition only for an equivalent role and the same published material. */
    public static function product_definitions(string $positionid): array {
        global $DB;
        $structure = structure::get(structure::NAME_STRUCTURE);
        $position = null;
        foreach ($structure['positions'] ?? [] as $item) {
            if ((string)$item['id'] === $positionid) { $position = $item; break; }
        }
        if (!$position || !self::is_consultant((string)$position['name'])) { return []; }
        $scoped = route_scope::available();
        $parent = $scoped ? route_scope::parent_for_position($positionid) : null;
        $route = $parent ?: route_model::get_route($positionid);
        if (!$route) { return []; }
        $cmids = [];
        foreach ($DB->get_records('local_ustar_route_points', ['routeid' => $route->id, 'active' => 1]) as $point) {
            if ($parent && !route_scope::point_applies((int)$point->id, $positionid)) { continue; }
            $version = route_model::current_published_version((int)$point->id);
            if (!$version) { continue; }
            $requirements = route_model::requirements_for_version($version);
            $product = false;
            foreach ($requirements as $r) {
                if (($r['type'] ?? '') === 'native' && ($r['sourcekey'] ?? '') === 'product_scorm_ack' && !empty($r['required'])) {
                    $product = true;
                }
            }
            if (!$product) { continue; }
            foreach ($requirements as $r) {
                if (($r['type'] ?? '') === 'cm' && !empty($r['required'])) { $cmids[(int)$r['sourceid']] = true; }
            }
        }
        $result = [];
        foreach ($DB->get_records('local_ustar_skill_evidence', [
                'skillid' => 'product_know', 'positionid' => 'retail_seller', 'active' => 1,
                'evidencetype' => 'learning', 'pathkey' => 'retail_product'], 'sortorder ASC,id ASC') as $definition) {
            if (isset($cmids[(int)$definition->cmid])) { $result[] = $definition; }
            else { return []; } // Never weaken a multi-material evidence path.
        }
        return $result;
    }
}
