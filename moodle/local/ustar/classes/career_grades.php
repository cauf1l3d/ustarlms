<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** One catalogue shared by the position editor, HR profile and route step 04. */
final class career_grades {
    public static function catalogue(): array {
        static $data;
        if ($data === null) {
            $data = json_decode(file_get_contents(__DIR__.'/../data/consultant_grades.json'), true, 512, JSON_THROW_ON_ERROR);
        }
        return $data['grades'];
    }
    public static function key(array $position): string {
        $saved = get_config('local_ustar', 'careergrade_'.($position['id'] ?? ''));
        if ($saved !== false) { return in_array($saved, array_column(self::catalogue(), 'id'), true) ? $saved : ''; }
        // Previously approved role equivalence, not an inference from level or department.
        if (consultant_career::is_consultant((string)($position['name'] ?? ''))) { return 'consultant'; }
        return '';
    }
    public static function view(array $position): array {
        $key = self::key($position); $grades = self::catalogue(); $current = '';
        foreach ($grades as &$row) {
            $row['current'] = $row['id'] === $key;
            if ($row['current']) { $current = $row['name']; }
        }
        unset($row);
        return ['hasgrade'=>$key !== '', 'gradeid'=>$key, 'gradename'=>$current,
            'grades'=>$grades, 'gradepositionname'=>(string)($position['name'] ?? ''),
            'gradesurl'=>(new \moodle_url('/local/ustar/grades.php'))->out(false)];
    }
    public static function fingerprint(array $position): string {
        return hash('sha256', json_encode([$position, get_config('local_ustar', 'careergrade_'.$position['id'])]));
    }
    public static function save(string $positionid, string $grade, string $expected): void {
        global $DB, $USER;
        require_capability('local/ustar:hrmanage', \context_system::instance());
        view_as::assert_writable();
        if ($grade !== 'none' && !in_array($grade, array_column(self::catalogue(), 'id'), true)) {
            throw new \invalid_parameter_exception('Неизвестный грейд.');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('careergrade_'.$positionid, 10);
        if (!$lock) { throw new \moodle_exception('locktimeout'); }
        try {
            $tx = $DB->start_delegated_transaction();
            try {
                $DB->get_record_sql('SELECT * FROM {local_ustar_structure} WHERE name = :name FOR UPDATE',
                    ['name'=>structure::NAME_STRUCTURE], MUST_EXIST);
                $map = array_column(structure::get(structure::NAME_STRUCTURE)['positions'] ?? [], null, 'id');
                if (!isset($map[$positionid]) || !hash_equals(self::fingerprint($map[$positionid]), $expected)) {
                    throw new \invalid_parameter_exception('Модель должности изменилась. Обновите страницу.');
                }
                $old = self::key($map[$positionid]);
                set_config('careergrade_'.$positionid, $grade, 'local_ustar');
                people::log_action((int)$USER->id, null, 'career_grade_updated',
                    ['positionid'=>$positionid, 'previousgrade'=>$old, 'grade'=>$grade]);
                $tx->allow_commit();
            } catch (\Throwable $e) { $tx->rollback($e); }
        } finally { $lock->release(); }
    }
}
