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
        // Deleting a note must not send dangling links back to the next board command.
        $ids = array_flip($DB->get_fieldset_select('local_ustar_learning_tasks', 'id', 'ownerid=:u AND privacy=:p',
            ['u' => $userid, 'p' => learning_tasks::PRIVACY_OWNER]));
        $items = array_intersect_key((array)($data['items'] ?? []), $ids);
        $links = array_values(array_filter((array)($data['links'] ?? []), static function($link) use ($ids): bool {
            return is_array($link) && isset($ids[$link['from'] ?? 0], $ids[$link['to'] ?? 0]);
        }));
        return ['revision' => (int)($data['revision'] ?? 0), 'items' => $items,
            'frames' => (array)($data['frames'] ?? []), 'links' => $links,
            'background' => $data['background'] ?? 'auto'];
    }
    public static function move(int $userid, int $noteid, int $revision, int $x, int $y, string $color): array {
        return self::update($userid, $revision, ['items' => [$noteid => ['x' => $x, 'y' => $y, 'color' => $color]]]);
    }

    /** Atomic presentation command. Notes and their content stay in learning_tasks. */
    public static function update(int $userid, int $revision, array $patch): array {
        self::actor($userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('notebook-layout:' . $userid, 10);
        if (!$lock) { throw new \moodle_exception('Доска сейчас сохраняется. Повторите действие.'); }
        try {
            $data = self::layout($userid);
            if ($revision !== $data['revision']) { throw new \moodle_exception('Доска изменилась в другой вкладке. Обновите страницу.'); }
            global $DB;
            $ids = array_flip($DB->get_fieldset_select('local_ustar_learning_tasks', 'id', 'ownerid=:u AND privacy=:p',
                ['u' => $userid, 'p' => learning_tasks::PRIVACY_OWNER]));
            $data['items'] = array_intersect_key($data['items'], $ids);
            if (isset($patch['background'])) {
                if (!in_array($patch['background'], ['auto','paper','cream','mint','sky','rose','slate'], true)) {
                    throw new \invalid_parameter_exception('Выберите доступный фон доски.');
                }
                $data['background'] = $patch['background'];
            }
            if (isset($patch['frames'])) {
                if (!is_array($patch['frames']) || count($patch['frames']) > 100) {
                    throw new \invalid_parameter_exception('На доске допускается до 100 фреймов.');
                }
                $frames = [];
                foreach ($patch['frames'] as $id => $frame) {
                    if (!preg_match('/^f[a-z0-9]{1,40}$/D', (string)$id) || !is_array($frame)) {
                        throw new \invalid_parameter_exception('Некорректный фрейм.');
                    }
                    $frames[$id] = self::geometry($frame, true);
                    $title = trim((string)($frame['title'] ?? 'Фрейм'));
                    if (\core_text::strlen($title) > 100) { throw new \invalid_parameter_exception('Название фрейма слишком длинное.'); }
                    $frames[$id]['title'] = clean_param($title, PARAM_TEXT);
                }
                // Move every member, including notes outside the current page/search.
                foreach ($frames as $id=>$frame) {
                    $old=$data['frames'][$id]??null;
                    if (!$old) { continue; }
                    $dx=$frame['x']-$old['x'];$dy=$frame['y']-$old['y'];
                    foreach ($data['items'] as $noteid=>&$item) {
                        if (($item['frame']??'')!==$id || isset($patch['items'][$noteid])) { continue; }
                        $item['x']+=$dx;$item['y']+=$dy;self::geometry($item);
                    }
                    unset($item);
                }
                $data['frames'] = $frames;
            }
            if (isset($patch['items'])) {
                if (!is_array($patch['items']) || count($patch['items']) > 500) {
                    throw new \invalid_parameter_exception('Некорректные карточки.');
                }
                foreach ($patch['items'] as $id => $item) {
                    if (!isset($ids[$id])) {
                        throw new \required_capability_exception(\context_system::instance(), 'local/ustar:use', 'nopermissions', '');
                    }
                    if (!is_array($item)) { throw new \invalid_parameter_exception('Некорректная карточка.'); }
                    $item = array_merge($data['items'][$id] ?? [], $item);
                    $value = self::geometry($item);
                    if (!in_array($item['color'] ?? '', ['neutral','yellow','blue','green','pink','purple'], true)) {
                        throw new \invalid_parameter_exception('Некорректный цвет карточки.');
                    }
                    $value['color'] = $item['color'];
                    if (isset($item['frame'])) { $value['frame'] = (string)$item['frame']; }
                    $data['items'][$id] = $value;
                }
            }
            if (count($data['items']) > 500) { throw new \invalid_parameter_exception('На доске допускается до 500 карточек.'); }
            foreach ($data['items'] as &$item) {
                if (!empty($item['frame']) && !isset($data['frames'][$item['frame']])) { $item['frame'] = ''; }
            }
            unset($item);
            $links = $patch['links'] ?? $data['links'];
            if (!is_array($links) || count($links) > 1000) { throw new \invalid_parameter_exception('На доске допускается до 1000 связей.'); }
            $clean = [];
            foreach ($links as $link) {
                if (!is_array($link)) { throw new \invalid_parameter_exception('Некорректная связь.'); }
                $from = (int)($link['from'] ?? 0); $to = (int)($link['to'] ?? 0);
                if ($from === $to || !isset($ids[$from], $ids[$to])) {
                    if (isset($patch['links'])) { throw new \invalid_parameter_exception('Связывать можно только свои разные заметки.'); }
                    continue;
                }
                $clean[$from . ':' . $to] = ['from' => $from, 'to' => $to];
            }
            $data['links'] = array_values($clean);
            $data['revision']++;
            set_user_preference('ustar_notebook_layout', json_encode($data), $userid);
            return $data;
        } finally { $lock->release(); }
    }

    private static function geometry(array $item, bool $frame = false): array {
        $out = [];
        foreach (['x' => [0,5000], 'y' => [0,5000], 'width' => [$frame ? 280 : 240, $frame ? 5000 : 1200],
                'height' => [$frame ? 180 : 180, $frame ? 5000 : 1200]] as $key => [$min,$max]) {
            if (!isset($item[$key]) && in_array($key, ['width','height'], true) && !$frame) { continue; }
            $value = $item[$key] ?? null;
            if (!is_int($value) || $value < $min || $value > $max) {
                throw new \invalid_parameter_exception('Некорректное положение или размер.');
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
