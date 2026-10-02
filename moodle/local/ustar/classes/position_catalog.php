<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Lightweight read model for the position entry screen. */
final class position_catalog {
    public static function present(array $positions, array $departments, array $structure): array {
        global $DB;

        $query = trim(optional_param('q', '', PARAM_TEXT));
        $query = \core_text::substr($query, 0, 100);
        $departmentid = optional_param('department', '', PARAM_ALPHANUMEXT);
        if ($departmentid !== '' && !isset($departments[$departmentid])) {
            $departmentid = '';
        }
        $page = max(0, optional_param('page', 0, PARAM_INT));

        // One lookup for all standards, never one query per position. Only
        // codes generated from the actual structure are used below.
        $standards = [];
        foreach ($DB->get_records_sql(
            'SELECT id, code, status, activeversionid FROM {local_ustar_standards} WHERE code LIKE :prefix',
            ['prefix' => 'position_%']
        ) as $record) {
            $standards[(string)$record->code] = $record;
        }

        $rows = [];
        foreach ($positions as $position) {
            $id = (string)($position['id'] ?? '');
            $name = (string)($position['name'] ?? '');
            $department = (string)($position['department'] ?? '');
            if ($id === '' || ($departmentid !== '' && $department !== $departmentid)) {
                continue;
            }
            $departmentname = (string)($departments[$department]['name'] ?? $department);
            if ($query !== '' && \core_text::strpos(\core_text::strtolower($name . ' ' . $departmentname),
                    \core_text::strtolower($query)) === false) {
                continue;
            }
            $standard = $standards[standard_model::position_code($id)] ?? null;
            $published = $standard && (string)$standard->status === standard_model::STATUS_PUBLISHED
                && (int)$standard->activeversionid > 0;
            $requirements = $structure['matrix'][$id] ?? [];
            $rows[] = [
                'name' => $name,
                'department' => $departmentname,
                'level' => (int)($position['level'] ?? 0),
                'status' => $published ? 'Опубликован' : (!empty($requirements) ? 'Не опубликован' : 'Не настроен'),
                'published' => $published,
                'url' => (new \moodle_url('/local/ustar/positions.php', ['positionid' => $id]))->out(false),
            ];
        }
        usort($rows, static function(array $a, array $b): int {
            return strcmp(\core_text::strtolower($a['department'] . ' ' . $a['name']),
                \core_text::strtolower($b['department'] . ' ' . $b['name']));
        });
        $count = count($rows);
        $pages = max(1, (int)ceil($count / 24));
        $page = min($page, $pages - 1);
        $base = ['q' => $query, 'department' => $departmentid];
        $options = [];
        foreach ($departments as $id => $department) {
            $options[] = ['id' => (string)$id, 'name' => (string)($department['name'] ?? $id),
                'selected' => (string)$id === $departmentid];
        }
        return [
            'query' => $query,
            'departmentid' => $departmentid,
            'departments' => $options,
            'positions' => array_slice($rows, $page * 24, 24),
            'count' => $count,
            'haspositions' => $count > 0,
            'page' => $page + 1,
            'pages' => $pages,
            'hasprevious' => $page > 0,
            'previousurl' => (new \moodle_url('/local/ustar/positions.php', $base + ['page' => $page - 1]))->out(false),
            'hasnext' => $page < $pages - 1,
            'nexturl' => (new \moodle_url('/local/ustar/positions.php', $base + ['page' => $page + 1]))->out(false),
        ];
    }
}
