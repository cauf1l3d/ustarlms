<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Explicit HR changes to the existing structure document, preserving stable IDs. */
final class organization_structure_editor {
    public const BLOCKS = [
        'commercial' => 'Коммерческий',
        'operations' => 'Операционный',
        'finance' => 'Финансовый',
        'logistics' => 'Логистика',
        'administrative' => 'Аппарат директора',
    ];
    public const COMPANY_ROLES = [
        'member' => 'Сотрудник',
        'executive' => 'Генеральный директор',
        'commercial_director' => 'Коммерческий директор',
        'operations_director' => 'Операционный директор',
        'finance_director' => 'Финансовый директор',
        'assistant' => 'Ассистент руководства',
    ];

    public static function revision(): int {
        global $DB;
        return (int)$DB->get_field('local_ustar_structure', 'version',
            ['name' => structure::NAME_STRUCTURE]);
    }

    public static function change(int $actorid, string $action, array $input, int $expectedversion): void {
        global $DB;
        if (!capabilities::has($actorid, capabilities::HR_WRITE)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/ustar:hrmanage', 'nopermissions', '');
        }
        view_as::assert_writable();
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')
            ->get_lock('structure:document', 10);
        if (!$lock) {
            throw new \moodle_exception('Структура сейчас изменяется. Повторите действие.');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $current = self::revision();
            if ($current !== $expectedversion) {
                throw new \moodle_exception('Структура уже изменена. Обновите страницу перед сохранением.');
            }
            $data = structure::get(structure::NAME_STRUCTURE);
            $id = trim((string)($input['id'] ?? ''));
            $name = trim(clean_param((string)($input['name'] ?? ''), PARAM_TEXT));
            if ($name === '' || \core_text::strlen($name) > 120) {
                throw new \invalid_parameter_exception('Укажите название длиной до 120 символов.');
            }
            if ($action === 'createdepartment' || $action === 'updatedepartment') {
                $block = (string)($input['block'] ?? '');
                if (!isset(self::BLOCKS[$block])) {
                    throw new \invalid_parameter_exception('Выберите блок компании.');
                }
                if ($action === 'createdepartment') {
                    $id = 'dept_' . bin2hex(random_bytes(8));
                    $data['departments'][] = [
                        'id' => $id, 'name' => $name, 'block' => $block, 'cohort' => '',
                    ];
                } else {
                    $found = false;
                    foreach ($data['departments'] as &$department) {
                        if ((string)$department['id'] === $id) {
                            $department['name'] = $name;
                            $department['block'] = $block;
                            $found = true;
                            break;
                        }
                    }
                    unset($department);
                    if (!$found) {
                        throw new \invalid_parameter_exception('Подразделение больше не существует.');
                    }
                }
            } else if ($action === 'createposition') {
                $valid = false;
                foreach ($data['departments'] as $department) {
                    if ((string)$department['id'] === $id) {
                        $valid = true;
                        break;
                    }
                }
                if (!$valid) {
                    throw new \invalid_parameter_exception('Выберите существующее подразделение.');
                }
                $positionid = 'pos_' . bin2hex(random_bytes(8));
                $data['positions'][] = [
                    'id' => $positionid, 'department' => $id,
                    'name' => $name, 'level' => 1, 'next' => null,
                    'companyrole' => 'member',
                ];
                $data['matrix'][$positionid] = [];
            } else if ($action === 'updateposition') {
                $companyrole = (string)($input['companyrole'] ?? '');
                if (!isset(self::COMPANY_ROLES[$companyrole])) {
                    throw new \invalid_parameter_exception('Выберите роль в схеме компании.');
                }
                $found = false;
                foreach ($data['positions'] as &$position) {
                    if ((string)$position['id'] === $id) {
                        $position['name'] = $name;
                        $position['companyrole'] = $companyrole;
                        $found = true;
                        break;
                    }
                }
                unset($position);
                if (!$found) {
                    throw new \invalid_parameter_exception('Должность больше не существует.');
                }
            } else {
                throw new \invalid_parameter_exception('Неизвестное действие со структурой.');
            }

            structure::save(structure::NAME_STRUCTURE, $data);
            people::log_action($actorid, null, 'organization_structure_changed', [
                'action' => $action, 'entityid' => $action === 'createposition' ? $positionid : $id,
                'revision' => $current + 1,
            ]);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
