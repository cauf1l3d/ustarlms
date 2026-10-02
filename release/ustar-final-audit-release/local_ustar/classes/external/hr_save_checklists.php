<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\checklists;
use local_ustar\people;

class hr_save_checklists extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(['json' => new external_value(PARAM_RAW, 'Checklist definition JSON')]);
    }
    public static function execute(string $json): array {
        global $USER;
        self::guard();
        \local_ustar\view_as::assert_writable();
        $ctx = \context_system::instance();
        if (!has_capability('local/ustar:hrmanage', $ctx) && !has_capability('local/ustar:admin', $ctx)) {
            throw new \required_capability_exception($ctx, 'local/ustar:hrmanage', 'nopermissions', '');
        }
        $params = self::validate_parameters(self::execute_parameters(), compact('json'));
        $data = json_decode($params['json'], true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items']) || count($data['items']) > 100) {
            throw new \invalid_parameter_exception('Invalid checklist structure');
        }
        $current = checklists::get();
        $currentversion = (int)($current['version'] ?? 0);
        if (!array_key_exists('version', $data) || (int)$data['version'] !== $currentversion) {
            throw new \invalid_parameter_exception('Checklist catalog changed; reload it before publishing');
        }
        $ids = [];
        foreach ($data['items'] as &$checklist) {
            if (!is_array($checklist)) throw new \invalid_parameter_exception('Each checklist must be an object');
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($checklist['id'] ?? ''));
            if ($id === '' || isset($ids[$id])) throw new \invalid_parameter_exception('Checklist ids must be unique');
            $ids[$id] = true; $checklist['id'] = $id;
            $checklist['title'] = trim((string)($checklist['title'] ?? ''));
            if ($checklist['title'] === '') throw new \invalid_parameter_exception('Checklist title is required');
            $checklist['active'] = !empty($checklist['active']);
            $checklist['recurrence'] = in_array(($checklist['recurrence'] ?? 'daily'), ['daily','weekly','manual'], true) ? $checklist['recurrence'] : 'daily';
            if (isset($checklist['positionIds']) && !is_array($checklist['positionIds'])) {
                throw new \invalid_parameter_exception('Checklist positionIds must be an array');
            }
            $checklist['positionIds'] = array_values(array_unique(array_filter(array_map(
                static fn($positionid): string => clean_param((string)$positionid, PARAM_ALPHANUMEXT),
                $checklist['positionIds'] ?? []
            ))));
            if (isset($checklist['sections']) && !is_array($checklist['sections'])) {
                throw new \invalid_parameter_exception('Checklist sections must be an array');
            }
            if (count($checklist['sections'] ?? []) > 50) {
                throw new \invalid_parameter_exception('Maximum 50 sections per checklist');
            }
            $itemcount = 0;
            $itemids = [];
            foreach (($checklist['sections'] ?? []) as &$section) {
                if (!is_array($section)) throw new \invalid_parameter_exception('Each checklist section must be an object');
                $section['title'] = trim((string)($section['title'] ?? 'Раздел'));
                if (isset($section['items']) && !is_array($section['items'])) {
                    throw new \invalid_parameter_exception('Checklist section items must be an array');
                }
                foreach (($section['items'] ?? []) as &$item) {
                    if (!is_array($item)) throw new \invalid_parameter_exception('Each checklist item must be an object');
                    $itemcount++;
                    if ($itemcount > 150) throw new \invalid_parameter_exception('Maximum 150 items per checklist');
                    $item['id'] = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($item['id'] ?? ('item_' . $itemcount)));
                    $item['title'] = trim((string)($item['title'] ?? ''));
                    if ($item['id'] === '' || isset($itemids[$item['id']])) {
                        throw new \invalid_parameter_exception('Checklist item ids must be non-empty and unique');
                    }
                    if ($item['title'] === '') {
                        throw new \invalid_parameter_exception('Checklist item title is required');
                    }
                    $itemids[$item['id']] = true;
                }
                unset($item);
            }
            unset($section);
        }
        unset($checklist);
        $data['version'] = $currentversion + 1;
        checklists::save($data);
        people::log_action((int)$USER->id, null, 'checklists_published', ['count' => count($data['items']), 'version' => $data['version']]);
        return ['json' => json_encode(['ok' => true, 'version' => $data['version'], 'count' => count($data['items'])], JSON_UNESCAPED_UNICODE)];
    }
    public static function execute_returns() { return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Checklist publish result')]); }
}
