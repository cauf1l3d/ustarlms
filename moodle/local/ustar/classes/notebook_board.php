<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

/** Spatial presentation of the existing private notes; no second note store. */
final class notebook_board {
    public static function actor(int $userid): void {
        global $USER;
        view_as::assert_writable();
        if ($userid !== (int)$USER->id || !team_access::active_actor($userid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
        }
    }
    public static function layout(int $userid): array {
        self::actor($userid);
        global $DB;
        // Read under the command lock without using a stale session preference cache.
        $value = $DB->get_field('user_preferences', 'value', ['userid' => $userid, 'name' => 'ustar_notebook_layout']);
        $data = json_decode((string)$value, true);
        return ['revision' => (int)($data['revision'] ?? 0), 'items' => (array)($data['items'] ?? [])];
    }
    public static function move(int $userid, int $noteid, int $revision, int $x, int $y, string $color): array {
        self::actor($userid);
        if ($x < 0 || $y < 0 || $x > 5000 || $y > 5000
                || !in_array($color, ['neutral', 'yellow', 'blue', 'green'], true)) {
            throw new \invalid_parameter_exception('Некорректное положение или цвет карточки.');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('notebook-layout:' . $userid, 10);
        if (!$lock) { throw new \moodle_exception('Доска сейчас сохраняется. Повторите действие.'); }
        try {
            $note = learning_tasks::view($noteid, $userid);
            if (!$note['private']) {
                throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
            }
            $data = self::layout($userid);
            if ($revision !== $data['revision']) { throw new \moodle_exception('Доска изменилась в другой вкладке. Обновите страницу.'); }
            // Keep only still-existing notes of this owner; bound the preference size.
            global $DB;
            $ids = $DB->get_fieldset_select('local_ustar_learning_tasks', 'id', 'ownerid=:u AND privacy=:p',
                ['u' => $userid, 'p' => learning_tasks::PRIVACY_OWNER]);
            $data['items'] = array_intersect_key($data['items'], array_flip($ids));
            if (count($data['items']) >= 500 && !isset($data['items'][$noteid])) {
                throw new \moodle_exception('На доске сохранено 500 позиций. Удалите ненужные заметки.');
            }
            $data['items'][$noteid] = ['x' => $x, 'y' => $y, 'color' => $color];
            $data['revision']++;
            set_user_preference('ustar_notebook_layout', json_encode($data), $userid);
            return $data;
        } finally { $lock->release(); }
    }
}
