<?php
namespace local_ustar\task_workspace;
defined('MOODLE_INTERNAL') || die();

/** Immutable validated policy pinned to each task/series. */
final class policy {
    public static function defaults(string $timezone = 'Europe/Moscow'): array {
        return ['timezone' => $timezone, 'mode' => 'work', 'weekdays' => [1, 2, 3, 4, 5],
            'excludedates' => [], 'startclock' => '09:00', 'endclock' => '18:00', 'reviewminutes' => 240, 'remindminutes' => 60,
            'levels' => [['recipient' => 'direct', 'minutes' => 120],
                ['recipient' => 'parent', 'minutes' => 540], ['recipient' => 'hrd', 'minutes' => 1080]]];
    }

    public static function validate(array $input): array {
        $p = array_merge(self::defaults(), $input);
        calendar::timezone((string)$p['timezone']);
        if (!in_array($p['mode'], ['work', 'calendar'], true)) {
            throw new \invalid_parameter_exception('Неизвестный режим календаря.');
        }
        $p['weekdays'] = array_values(array_unique(array_map('intval', (array)$p['weekdays'])));
        sort($p['weekdays']);
        if (!$p['weekdays'] || min($p['weekdays']) < 1 || max($p['weekdays']) > 7) {
            throw new \invalid_parameter_exception('Выберите рабочие дни недели.');
        }
        $p['excludedates'] = array_values(array_unique((array)$p['excludedates']));
        if (count($p['excludedates']) > 100) { throw new \invalid_parameter_exception('Слишком много исключений календаря.'); }
        foreach ($p['excludedates'] as $date) { calendar::timestamp((string)$date, '00:00', $p['timezone']); }
        $start = calendar::timestamp('2026-01-01', $p['startclock'], $p['timezone']);
        $end = calendar::timestamp('2026-01-01', $p['endclock'], $p['timezone']);
        if ($start >= $end) { throw new \invalid_parameter_exception('Окончание рабочего дня должно быть позже начала.'); }
        $p['remindminutes'] = (int)$p['remindminutes'];
        if ($p['remindminutes'] < 0 || $p['remindminutes'] > 43200) { throw new \invalid_parameter_exception('Напоминание: 0–43200 минут до срока.'); }
        $p['reviewminutes'] = (int)$p['reviewminutes'];
        if ($p['reviewminutes'] < 15 || $p['reviewminutes'] > 43200) {
            throw new \invalid_parameter_exception('Срок проверки: от 15 минут до 30 суток.');
        }
        if (!is_array($p['levels']) || count($p['levels']) > 5) {
            throw new \invalid_parameter_exception('В цепочке допускается до пяти уровней.');
        }
        $previous = 0;
        foreach ($p['levels'] as &$level) {
            if (!is_array($level) || !in_array($level['recipient'] ?? '', ['direct', 'parent', 'hrd'], true)) {
                throw new \invalid_parameter_exception('Недопустимый получатель эскалации.');
            }
            $level = ['recipient' => $level['recipient'], 'minutes' => (int)($level['minutes'] ?? 0)];
            if ($level['minutes'] <= $previous || $level['minutes'] > 43200) {
                throw new \invalid_parameter_exception('Задержки уровней должны возрастать, не более 30 суток.');
            }
            $previous = $level['minutes'];
        }
        unset($level);
        return array_intersect_key($p, self::defaults());
    }

    public static function definition(array $fields): array {
        if (!$fields || count($fields) > 40) { throw new \invalid_parameter_exception('Чек-лист должен содержать 1–40 полей.'); }
        $out = [];
        $keys = [];
        foreach ($fields as $i => $field) {
            $key = clean_param((string)($field['key'] ?? ('field_' . ($i + 1))), PARAM_ALPHANUMEXT);
            $label = trim(clean_param((string)($field['label'] ?? ''), PARAM_TEXT));
            $type = (string)($field['type'] ?? 'check');
            if (!$key || isset($keys[$key]) || !$label || \core_text::strlen($label) > 200
                    || !in_array($type, ['check', 'text', 'number', 'photo'], true)) {
                throw new \invalid_parameter_exception('Проверьте уникальные ключи, названия и типы полей.');
            }
            $keys[$key] = true;
            $out[] = ['key' => $key, 'label' => $label, 'type' => $type, 'required' => !empty($field['required'])];
        }
        return $out;
    }

    public static function answers(array $fields, array $answers, bool $hasphoto, bool $final): array {
        $out = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $value = $answers[$key] ?? '';
            if ($field['type'] === 'check') { $value = !empty($value) ? 1 : 0; }
            else if ($field['type'] === 'number') {
                if ($value !== '' && (!is_scalar($value) || !is_numeric($value) || !is_finite((float)$value))) {
                    throw new \invalid_parameter_exception('Укажите число: ' . $field['label']);
                }
                $value = $value === '' ? '' : (float)$value;
            } else if ($field['type'] === 'photo') { $value = $hasphoto ? 1 : 0; }
            else { $value = trim(clean_param(is_scalar($value) ? (string)$value : '', PARAM_TEXT)); }
            if ($final && $field['required'] && ($value === '' || (($field['type'] === 'check' || $field['type'] === 'photo') && !$value))) {
                throw new \invalid_parameter_exception('Заполните обязательное поле: ' . $field['label']);
            }
            $out[$key] = $value;
        }
        return $out;
    }
}
