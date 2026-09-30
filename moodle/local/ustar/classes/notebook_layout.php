<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
/** Coordinates of existing private notes, not a second content store. */
final class notebook_layout {
    public static function save(int $noteid, int $actor, int $x, int $y): void {
        global $USER;
        view_as::assert_writable();
        $note = learning_tasks::view($noteid, $actor);
        if ((int)$USER->id !== $actor || !$note['private'] || (int)$note['assigneeid'] !== $actor
                || !employment::is_active($actor)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
        }
        if ($x < 0 || $y < 0 || $x > 10000 || $y > 10000) {
            throw new \invalid_parameter_exception('Карточка должна оставаться в пределах доски.');
        }
        set_user_preference('ustar_notepos_' . $noteid, json_encode(['x'=>$x,'y'=>$y]), $actor);
    }
    public static function positions(int $actor, array $notes): array {
        global $USER;
        if ((int)$USER->id !== $actor || view_as::active()) {
            throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
        }
        $preferences = get_user_preferences(null, null, $actor);
        $out = [];
        foreach (array_values($notes) as $index => $note) {
            $saved = json_decode((string)($preferences['ustar_notepos_'.$note['id']] ?? ''), true);
            $out[$note['id']] = ['x'=>max(0,min(10000,(int)($saved['x'] ?? ($index%3)*330))),
                'y'=>max(0,min(10000,(int)($saved['y'] ?? intdiv($index,3)*300)))];
        }
        return $out;
    }
}
