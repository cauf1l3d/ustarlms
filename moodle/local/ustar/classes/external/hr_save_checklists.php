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
        $ids = [];
        foreach ($data['items'] as &$checklist) {
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($checklist['id'] ?? ''));
            if ($id === '' || isset($ids[$id])) throw new \invalid_parameter_exception('Checklist ids must be unique');
            $ids[$id] = true; $checklist['id'] = $id;
            $checklist['title'] = trim((string)($checklist['title'] ?? ''));
            if ($checklist['title'] === '') throw new \invalid_parameter_exception('Checklist title is required');
            $checklist['active'] = !empty($checklist['active']);
            $checklist['recurrence'] = in_array(($checklist['recurrence'] ?? 'daily'), ['daily','weekly','manual'], true) ? $checklist['recurrence'] : 'daily';
            $checklist['positionIds'] = array_values(array_unique(array_filter(array_map('strval', $checklist['positionIds'] ?? []))));
            $itemcount = 0;
            foreach (($checklist['sections'] ?? []) as &$section) {
                $section['title'] = trim((string)($section['title'] ?? 'Раздел'));
                foreach (($section['items'] ?? []) as &$item) {
                    $itemcount++;
                    if ($itemcount > 150) throw new \invalid_parameter_exception('Maximum 150 items per checklist');
                    $item['id'] = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($item['id'] ?? ('item_' . $itemcount)));
                    $item['title'] = trim((string)($item['title'] ?? ''));
                }
                unset($item);
            }
            unset($section);
        }
        unset($checklist);
        $data['version'] = (int)($data['version'] ?? 0) + 1;
        checklists::save($data);
        people::log_action((int)$USER->id, null, 'checklists_published', ['count' => count($data['items']), 'version' => $data['version']]);
        return ['json' => json_encode(['ok' => true, 'version' => $data['version'], 'count' => count($data['items'])], JSON_UNESCAPED_UNICODE)];
    }
    public static function execute_returns() { return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Checklist publish result')]); }
}
