<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Edits the existing structure.positions[].next relation only. */
final class career_path {
    public static function validate(array $structure, string $from, string $to): void {
        $map = array_column($structure['positions'] ?? [], null, 'id');
        if (!isset($map[$from]) || ($to !== '' && !isset($map[$to]))) {
            throw new \invalid_parameter_exception('Должность больше не существует. Обновите страницу.');
        }
        $seen = [$from => true];
        $cursor = $to;
        while ($cursor !== '') {
            if (isset($seen[$cursor])) {
                throw new \invalid_parameter_exception('Переход создаёт цикл карьерной лестницы.');
            }
            if (!isset($map[$cursor])) {
                throw new \invalid_parameter_exception('В выбранной лестнице есть ссылка на отсутствующую должность.');
            }
            $seen[$cursor] = true;
            $cursor = trim((string)($map[$cursor]['next'] ?? ''));
        }
    }

    public static function fingerprint(array $structure): string {
        return hash('sha256', json_encode($structure['positions'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    public static function save(string $from, string $to, string $expected): void {
        global $DB, $USER;
        require_capability('local/ustar:hrmanage', \context_system::instance());
        view_as::assert_writable();
        $tx = $DB->start_delegated_transaction();
        try {
        $DB->get_record_sql('SELECT * FROM {local_ustar_structure} WHERE name = :name FOR UPDATE',
            ['name'=>structure::NAME_STRUCTURE], MUST_EXIST);
        $structure = structure::get(structure::NAME_STRUCTURE);
        if (!hash_equals(self::fingerprint($structure), $expected)) {
            throw new \invalid_parameter_exception('Структура уже изменена другим пользователем. Обновите страницу.');
        }
        self::validate($structure, $from, $to);
        $old = '';
        foreach ($structure['positions'] as &$position) {
            if ((string)$position['id'] === $from) {
                $old = (string)($position['next'] ?? '');
                $position['next'] = $to !== '' ? $to : null;
            }
        }
        unset($position);
        structure::save(structure::NAME_STRUCTURE, $structure);
        people::log_action((int)$USER->id, null, 'career_path_updated',
            ['positionid'=>$from, 'previousnext'=>$old, 'next'=>$to]);
        $tx->allow_commit();
        } catch (\Throwable $e) {
            $tx->rollback($e);
        }
    }
}
