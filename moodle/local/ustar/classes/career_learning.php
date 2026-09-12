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
            // USTAR confirmed product_know -> the published product SCORM point.
            foreach ($requirements as $r) {
                if (($r['type'] ?? '') === 'native' && ($r['sourcekey'] ?? '') === 'product_scorm_ack') {
                    $byskill['product_know'][] = ['label' => $row['title'], 'url' => $row['url'], 'satisfied' => $row['done']];
                    break;
                }
            }
            // Only explicit links in the published version establish skill -> material relationships.
            foreach ($requirements as $requirement) {
                if (($requirement['type'] ?? '') !== 'skill' || empty($requirement['sourcekey'])) { continue; }
                foreach ($materials as $material) {
                    $byskill[$requirement['sourcekey']][] = ['label' => $material['label'],
                        'url' => $row['url'], 'satisfied' => $row['done']];
                }
            }
        }
        return ['points' => $rows, 'skills' => $byskill];
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
