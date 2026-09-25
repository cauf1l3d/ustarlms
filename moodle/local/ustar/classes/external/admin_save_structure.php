<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\structure;

class admin_save_structure extends base {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name' => new external_value(PARAM_ALPHA, 'structure | branding'),
            'json' => new external_value(PARAM_RAW, 'Payload JSON'),
            'expectedversion' => new external_value(PARAM_INT, 'Version returned by admin_get_structure', VALUE_DEFAULT, -1),
        ]);
    }

    public static function execute(string $name, string $json, int $expectedversion = -1): array {
        global $DB;
        self::guard();
        \local_ustar\view_as::assert_writable();
        require_capability('local/ustar:admin', \context_system::instance());
        $params = self::validate_parameters(self::execute_parameters(),
            ['name' => $name, 'json' => $json, 'expectedversion' => $expectedversion]);

        if (!in_array($params['name'], [structure::NAME_STRUCTURE, structure::NAME_BRANDING], true)) {
            throw new \invalid_parameter_exception('Unknown document name');
        }
        $data = json_decode($params['json'], true);
        if (!is_array($data)) {
            throw new \invalid_parameter_exception('Invalid JSON payload');
        }

        // Validate public-facing branding before publishing it to the pre-auth login page.
        if ($params['name'] === structure::NAME_BRANDING) {
            $hexkeys = ['primary', 'accent', 'accentSoft', 'bg', 'surface', 'text', 'muted', 'success', 'warning'];
            foreach ($hexkeys as $key) {
                if (isset($data[$key]) && !preg_match('/^#[0-9a-fA-F]{6}$/', (string)$data[$key])) {
                    throw new \invalid_parameter_exception("Invalid brand colour: {$key}");
                }
            }
            $urlkeys = ['logoUrl', 'sidebarHeroUrl', 'loginHeroUrl'];
            foreach ($urlkeys as $key) {
                if (!isset($data[$key]) || $data[$key] === '') {
                    continue;
                }
                $url = trim((string)$data[$key]);
                $isrelative = str_starts_with($url, '/') && !str_starts_with($url, '//');
                $ishttps = preg_match('#^https://#i', $url) === 1;
                if (!$isrelative && !$ishttps) {
                    throw new \invalid_parameter_exception("{$key} must be a relative path or HTTPS URL");
                }
                $data[$key] = $url;
            }
            foreach (['sidebarHeroFit', 'loginHeroFit'] as $key) {
                if (isset($data[$key]) && !in_array($data[$key], ['cover', 'contain'], true)) {
                    throw new \invalid_parameter_exception("Invalid image fit: {$key}");
                }
            }
            $positions = ['center', 'center top', 'center bottom', 'left center', 'right center', 'left top', 'right top', 'left bottom', 'right bottom'];
            foreach (['sidebarHeroPosition', 'loginHeroPosition'] as $key) {
                if (isset($data[$key]) && !in_array($data[$key], $positions, true)) {
                    throw new \invalid_parameter_exception("Invalid image position: {$key}");
                }
            }
            if (isset($data['sidebarHeroHeight'])) {
                $data['sidebarHeroHeight'] = max(72, min(220, (int)$data['sidebarHeroHeight']));
            }
            foreach (['sidebarHeroOverlay', 'loginHeroOverlay'] as $key) {
                if (isset($data[$key])) {
                    $data[$key] = max(0, min(90, (int)$data[$key]));
                }
            }
            if (isset($data['radius'])) {
                $data['radius'] = max(8, min(24, (int)$data['radius']));
            }
            foreach (['brandName' => 80, 'tagline' => 120, 'loginEyebrow' => 120, 'loginTitle' => 120, 'loginSubtitle' => 400] as $key => $maxlen) {
                if (isset($data[$key])) {
                    $data[$key] = trim(mb_substr(strip_tags((string)$data[$key]), 0, $maxlen));
                }
            }
        }

        // Strong structure validation: fail before publishing a broken career graph.
        if ($params['name'] === structure::NAME_STRUCTURE) {
            foreach (['departments', 'positions', 'skills', 'matrix'] as $key) {
                if (!isset($data[$key]) || !is_array($data[$key])) {
                    throw new \invalid_parameter_exception("Missing or invalid key: {$key}");
                }
            }

            $uniqueids = function(array $items, string $kind): array {
                $seen = [];
                foreach ($items as $item) {
                    $id = trim((string)($item['id'] ?? ''));
                    if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
                        throw new \invalid_parameter_exception("{$kind} has an invalid id");
                    }
                    if (isset($seen[$id])) {
                        throw new \invalid_parameter_exception("Duplicate {$kind} id: {$id}");
                    }
                    $seen[$id] = true;
                }
                return $seen;
            };

            $deptids = $uniqueids($data['departments'], 'department');
            $posids = $uniqueids($data['positions'], 'position');
            $skillids = $uniqueids($data['skills'], 'skill');
            $positionsbyid = [];
            foreach ($data['positions'] as $position) {
                $id = $position['id'];
                $department = (string)($position['department'] ?? '');
                if (!isset($deptids[$department])) {
                    throw new \invalid_parameter_exception("Position {$id} references unknown department: {$department}");
                }
                $level = (int)($position['level'] ?? 0);
                if ($level < 1 || $level > 20) {
                    throw new \invalid_parameter_exception("Position {$id} has invalid level");
                }
                $positionsbyid[$id] = $position;
            }
            foreach ($positionsbyid as $id => $position) {
                $next = trim((string)($position['next'] ?? ''));
                if ($next === '') {
                    continue;
                }
                if ($next === $id || !isset($positionsbyid[$next])) {
                    throw new \invalid_parameter_exception("Position {$id} has invalid next position: {$next}");
                }
                if ($positionsbyid[$next]['department'] !== $position['department']) {
                    throw new \invalid_parameter_exception("Career edge {$id} -> {$next} crosses departments");
                }
            }

            // Detect cycles in the one-way career graph.
            foreach (array_keys($positionsbyid) as $origin) {
                $seen = [];
                $cursor = $origin;
                while ($cursor !== '' && isset($positionsbyid[$cursor])) {
                    if (isset($seen[$cursor])) {
                        throw new \invalid_parameter_exception("Career ladder contains a cycle starting at {$origin}");
                    }
                    $seen[$cursor] = true;
                    $cursor = trim((string)($positionsbyid[$cursor]['next'] ?? ''));
                }
            }

            foreach ($data['skills'] as $skill) {
                if (!is_array($skill['courses'] ?? null)) {
                    throw new \invalid_parameter_exception("Skill {$skill['id']} must contain a courses array");
                }
            }
            foreach ($data['matrix'] as $posid => $row) {
                if (!isset($posids[$posid]) || !is_array($row)) {
                    throw new \invalid_parameter_exception("Matrix references unknown position: {$posid}");
                }
                foreach ($row as $sid => $level) {
                    if (!isset($skillids[$sid])) {
                        throw new \invalid_parameter_exception("Matrix references unknown skill: {$sid}");
                    }
                    if ((int)$level < 1 || (int)$level > 3) {
                        throw new \invalid_parameter_exception("Matrix level for {$posid}/{$sid} must be 1..3");
                    }
                }
            }
        }

        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock($params['name'] === structure::NAME_STRUCTURE
                ? 'structure:document' : 'structure:branding', 10);
        if (!$lock) {
            throw new \moodle_exception('Документ сейчас редактируется. Повторите сохранение.');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $current = (int)$DB->get_field('local_ustar_structure', 'version',
                ['name' => $params['name']]);
            if ($params['expectedversion'] < 0 || $current !== (int)$params['expectedversion']) {
                throw new \moodle_exception('Документ изменён в другой сессии. Обновите форму перед сохранением.');
            }
            if ($params['name'] === structure::NAME_STRUCTURE) {
                $previous = structure::get(structure::NAME_STRUCTURE);
                $newdepartments = array_column($data['departments'], null, 'id');
                $newpositions = array_column($data['positions'], null, 'id');
                foreach ($previous['departments'] ?? [] as $department) {
                    if (!isset($newdepartments[(string)$department['id']])) {
                        throw new \moodle_exception('Удаление или смена ID подразделения требует отдельного переноса кадровых связей.');
                    }
                }
                foreach ($previous['positions'] ?? [] as $position) {
                    $id = (string)$position['id'];
                    if (!isset($newpositions[$id])
                            || (string)$newpositions[$id]['department'] !== (string)$position['department']) {
                        throw new \moodle_exception('Удаление, смена ID или перенос существующей должности требует отдельной кадровой операции.');
                    }
                }
            }
            structure::save($params['name'], $data);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
        return ['status' => 'ok'];
    }

    public static function execute_returns() {
        return new \core_external\external_single_structure([
            'status' => new external_value(PARAM_TEXT),
        ]);
    }
}
