<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Read model of the employee's real published route, never a second assignment source. */
final class career_learning {
    public static function build(string $positionid, int $userid): array {
        $snapshot = route_model::read_only_snapshot($positionid, $userid);
        $rows = []; $byskill = [];
        foreach ($snapshot['points'] ?? [] as $index => $point) {
            $version = route_model::current_published_version((int)$point['id']);
            if (!$version) { continue; }
            $requirements = route_model::requirements_for_version($version);
            $materials = [];
            foreach ($requirements as $requirement) {
                $type = (string)($requirement['type'] ?? '');
                if (!in_array($type, ['cm', 'content', 'course'], true)) { continue; }
                $label = self::label($requirement);
                if ($label === '') { continue; }
                $materials[] = ['label' => $label];
            }
            if (!$materials) { continue; }
            $row = ['title' => (string)$point['title'], 'materials' => $materials,
                'statuslabel' => ['done' => 'Завершено', 'current' => 'Доступно сейчас', 'locked' => 'Откроется по порядку маршрута'][$point['status']], 'done' => !empty($point['done']),
                'url' => !empty($point['current']) ? (new \moodle_url('/local/ustar/route_next.php', ['sesskey' => sesskey()]))->out(false) : '',
                'number' => $index + 1];
            $rows[] = $row;
            foreach (self::skill_ids($requirements) as $skillid) {
                foreach ($materials as $material) {
                    $byskill[$skillid][] = ['label'=>$material['label'], 'url'=>$row['url'], 'satisfied'=>$row['done']];
                }
            }
        }
        return ['points' => $rows, 'skills' => $byskill];
    }

    /** Explicit published skill links plus the already approved product acknowledgement mapping. */
    public static function skill_ids(array $requirements): array {
        $ids = [];
        foreach ($requirements as $r) {
            if (($r['type'] ?? '') === 'skill' && !empty($r['sourcekey'])) { $ids[] = (string)$r['sourcekey']; }
            if (($r['type'] ?? '') === 'native' && ($r['sourcekey'] ?? '') === 'product_scorm_ack') { $ids[] = 'product_know'; }
        }
        return array_values(array_unique($ids));
    }

    /** Same published parent/position scope as the employee route, without reading learner progress. */
    public static function position_materials(string $positionid): array {
        static $cache = [];
        if (isset($cache[$positionid])) { return $cache[$positionid]; }
        $scoped = route_scope::available();
        $parent = $scoped ? route_scope::parent_for_position($positionid) : null;
        $route = $parent ?: route_model::get_route($positionid);
        if (!$route) { return $cache[$positionid] = []; }
        $points = $parent ? route_scope::points_for_position((int)$route->id, $positionid) : route_model::points((int)$route->id);
        $rows = [];
        foreach ($points as $point) {
            $version = route_model::current_published_version((int)$point->id);
            if (!$version) { continue; }
            $requirements = route_model::requirements_for_version($version);
            $skills = self::skill_ids($requirements);
            foreach ($requirements as $r) {
                $type = (string)($r['type'] ?? '');
                if (!in_array($type, ['cm','content','course'], true)) { continue; }
                $label = self::label($r);
                if ($label === '') { continue; }
                $id = (int)$r['sourceid'];
                $key = $positionid.':'.$point->id.':'.$type.':'.$id;
                $url = (new \moodle_url('/local/ustar/route_studio.php', ['position'=>$positionid]))->out(false);
                $rows[$key] = ['key'=>$key, 'id'=>$id, 'positionid'=>$positionid, 'routeid'=>(int)$route->id,
                    'pointid'=>(int)$point->id, 'versionid'=>(int)$version->id, 'name'=>$label, 'type'=>$type,
                    'typelabel'=>['cm'=>'Активность Moodle', 'course'=>'Курс Moodle', 'content'=>'Материал'][$type],
                    'skillids'=>$skills, 'unlinked'=>!$skills, 'url'=>$url, 'routeurl'=>$url];
            }
        }
        return $cache[$positionid] = array_values($rows);
    }

    private static function label(array $requirement): string {
        global $DB;
        $id = (int)($requirement['sourceid'] ?? 0);
        $type = $requirement['type'] ?? '';
        if ($type === 'cm') {
            $cm = get_coursemodule_from_id(null, $id, 0, false, IGNORE_MISSING);
            if (!$cm || !empty($cm->deletioninprogress)) { return ''; }
            return format_string((string)$cm->name);
        }
        if ($type === 'content') {
            $content = $DB->get_record('local_ustar_content', ['id' => $id, 'status' => 'published']);
            if (!$content) { return ''; }
            return format_string((string)$content->title);
        }
        $course = $DB->get_record('course', ['id' => $id], 'id,fullname');
        return $course ? format_string((string)$course->fullname) : '';
    }

    public static function retail(string $department, string $position): bool {
        return preg_match('/торговый зал|рознич|консультант|продавец/iu', $department . ' ' . $position) === 1;
    }
}
