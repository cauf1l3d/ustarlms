<?php
namespace local_ustar\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_value;
use local_ustar\people;
use local_ustar\structure;

class hr_save_learning extends base {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'skillsjson' => new external_value(PARAM_RAW, 'Full skill array'),
            'matrixjson' => new external_value(PARAM_RAW, 'Position skill matrix'),
            'expectedversion' => new external_value(PARAM_INT, 'Revision read with the learning model'),
        ]);
    }
    public static function execute(string $skillsjson, string $matrixjson, int $expectedversion): array {
        global $DB, $USER;
        self::guard();
        \local_ustar\view_as::assert_writable();
        $ctx = \context_system::instance();
        if (!has_capability('local/ustar:hrmanage', $ctx) && !has_capability('local/ustar:admin', $ctx)) {
            throw new \required_capability_exception($ctx, 'local/ustar:hrmanage', 'nopermissions', '');
        }
        $params = self::validate_parameters(self::execute_parameters(),
            compact('skillsjson', 'matrixjson', 'expectedversion'));
        $skills = json_decode($params['skillsjson'], true);
        $matrix = json_decode($params['matrixjson'], true);
        if (!is_array($skills) || !is_array($matrix) || count($skills) > 500 || count($matrix) > 2000) {
            throw new \invalid_parameter_exception('Некорректная модель обучения');
        }
        $seen = [];
        foreach ($skills as &$skill) {
            $id = trim((string)($skill['id'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $id) || isset($seen[$id])) {
                throw new \invalid_parameter_exception('ID навыка должен быть уникальным и содержать до 64 латинских символов, цифр, точек, дефисов или подчёркиваний');
            }
            $seen[$id] = true; $skill['id'] = $id;
            $skill['name'] = trim((string)($skill['name'] ?? ''));
            if ($skill['name'] === '' || \core_text::strlen($skill['name']) > 120) {
                throw new \invalid_parameter_exception('Укажите название навыка длиной до 120 символов');
            }
            $skill['category'] = trim((string)($skill['category'] ?? 'Общее'));
            if (!is_array($skill['courses'] ?? null)) {
                throw new \invalid_parameter_exception('Связанные курсы должны быть списком');
            }
            foreach ($skill['courses'] as $courseref) {
                if (!is_string($courseref) && !is_int($courseref)) {
                    throw new \invalid_parameter_exception('Некорректный идентификатор курса');
                }
            }
            $skill['courses'] = array_values(array_unique(array_filter(array_map('trim', $skill['courses']))));
        }
        unset($skill);
        $courserefs = [];
        foreach ($skills as $skill) {
            foreach ($skill['courses'] as $ref) { $courserefs[$ref] = $ref; }
        }
        if ($courserefs) {
            [$insql, $courseparams] = $DB->get_in_or_equal(array_values($courserefs), SQL_PARAMS_NAMED, 'course');
            $found = [];
            foreach ($DB->get_records_select('course', "idnumber {$insql}", $courseparams,
                '', 'id,idnumber') as $course) {
                $found[(string)$course->idnumber] = true;
            }
            if (count($found) !== count($courserefs)) {
                throw new \invalid_parameter_exception('Один или несколько связанных Moodle-курсов не найдены');
            }
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('structure:document', 10);
        if (!$lock) {
            throw new \moodle_exception('Структура сейчас изменяется. Повторите действие.');
        }
        try {
        $tx = $DB->start_delegated_transaction();
        $record = $DB->get_record_sql('SELECT id, version FROM {local_ustar_structure}
            WHERE name = :name FOR UPDATE', ['name' => structure::NAME_STRUCTURE], IGNORE_MISSING);
        $currentversion = $record ? (int)$record->version : 0;
        if ((int)$params['expectedversion'] !== $currentversion) {
            throw new \moodle_exception('Модель изменилась в другой сессии. Обновите страницу перед сохранением.');
        }
        $st = structure::get(structure::NAME_STRUCTURE);
        foreach ($st['skills'] ?? [] as $existing) {
            if (!isset($seen[(string)$existing['id']])) {
                throw new \invalid_parameter_exception('Удаление или смена ID существующего навыка требует переноса исторических стандартов и подтверждений');
            }
        }
        $posids = array_fill_keys(array_map(static fn($p) => $p['id'], $st['positions'] ?? []), true);
        $cleanmatrix = [];
        foreach ($matrix as $positionid => $row) {
            if (!isset($posids[$positionid]) || !is_array($row)) {
                throw new \invalid_parameter_exception('Матрица содержит неизвестную должность');
            }
            foreach ($row as $skillid => $level) {
                if (!isset($seen[$skillid]) || (int)$level < 1 || (int)$level > 5) {
                    throw new \invalid_parameter_exception('Матрица содержит неизвестный навык или уровень');
                }
                $cleanmatrix[$positionid][$skillid] = (int)$level;
            }
        }
        foreach ($posids as $positionid => $_) {
            if (!array_key_exists($positionid, $matrix)) {
                $cleanmatrix[$positionid] = array_filter($st['matrix'][$positionid] ?? [],
                    static fn($skillid): bool => isset($seen[$skillid]), ARRAY_FILTER_USE_KEY);
            } else {
                $cleanmatrix[$positionid] = $cleanmatrix[$positionid] ?? [];
            }
        }
        $st['skills'] = array_values($skills);
        $st['matrix'] = $cleanmatrix;
        structure::save(structure::NAME_STRUCTURE, $st);
        people::log_action((int)$USER->id, null, 'learning_model_saved', ['skills' => count($skills), 'matrixPositions' => count($cleanmatrix)]);
        $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
        return ['json' => json_encode(['ok' => true, 'skills' => count($skills),
            'matrixPositions' => count($cleanmatrix), 'revision' => $currentversion + 1],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
    }
    public static function execute_returns() { return new \core_external\external_single_structure(['json' => new external_value(PARAM_RAW, 'Learning model publish result')]); }
}
