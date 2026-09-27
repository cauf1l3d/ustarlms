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
    public static function catalogue_for_position(string $positionid): array {
        return grade_ladders::grades_for_position($positionid) ?? self::catalogue();
    }
    public static function key(array $position): string {
        $bound = grade_ladders::grades_for_position((string)($position['id'] ?? ''));
        if ($bound) { return (string)$bound[0]['id']; }
        $saved = get_config('local_ustar', 'careergrade_'.($position['id'] ?? ''));
        if ($saved !== false) { return in_array($saved, array_column(self::catalogue(), 'id'), true) ? $saved : ''; }
        // Previously approved role equivalence, not an inference from level or department.
        if (consultant_career::is_consultant((string)($position['name'] ?? ''))) { return 'consultant'; }
        return '';
    }
    public static function view(array $position): array {
        $positionid = (string)($position['id'] ?? '');
        $bound = grade_ladders::binding($positionid) !== null;
        $key = self::key($position); $grades = self::catalogue_for_position($positionid); $current = '';
        foreach ($grades as &$row) {
            $row['current'] = !$bound && $row['id'] === $key;
            if ($row['current']) { $current = $row['name']; }
        }
        unset($row);
        return ['hasgrade'=>$key !== '', 'boundladder'=>$bound, 'gradeid'=>$key,
            'gradename'=>$bound ? 'Опубликованная лестница' : $current,
            'grades'=>$grades, 'gradepositionname'=>(string)($position['name'] ?? ''),
            'gradesurl'=>(new \moodle_url('/local/ustar/grades.php'))->out(false),
            'settingsurl'=>(new \moodle_url('/local/ustar/grade_ladders.php',
                ['positionid' => $positionid]))->out(false)];
    }
    public static function employee_view(array $position, int $userid): array {
        $view = self::view($position);
        $current = grade_promotion::current($userid);
        if (empty($current['enabled'])) { return $view; }
        $view['hasgrade'] = true;
        $view['gradeid'] = (string)$current['grade'];
        $view['gradename'] = (string)$current['label'];
        foreach ($view['grades'] as &$grade) {
            $grade['current'] = !empty($current['recorded'])
                && (string)$grade['id'] === (string)$current['grade'];
        }
        unset($grade);
        return $view;
    }
    public static function fingerprint(array $position): string {
        return hash('sha256', json_encode([$position, get_config('local_ustar', 'careergrade_'.$position['id'])]));
    }
    public static function save(string $positionid, string $grade, string $expected): void {
        global $DB, $USER;
        require_capability('local/ustar:hrmanage', \context_system::instance());
        view_as::assert_writable();
        if (grade_ladders::available()) {
            throw new \invalid_parameter_exception('Настраивайте привязку должности через опубликованные лестницы грейдов.');
        }
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
