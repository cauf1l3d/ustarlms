<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Answer presentation for the existing team quiz; never changes organization IDs. */
final class team_quiz {
    /** Spelling variants such as Зав.склад / Завсклад and е / ё are one choice. */
    public static function position_key(string $label): string {
        $label = str_replace('ё', 'е', \core_text::strtolower(trim($label)));
        return preg_replace('/[\s.\x{00A0}]+/u', '', $label);
    }

    /** Prefer subjects' actual labels, then add distinct catalogue distractors. */
    public static function options(array $selected, array $positionmap): array {
        $options = [];
        $seen = [];
        $add = static function(string $id, string $label) use (&$options, &$seen): void {
            $key = self::position_key($label);
            if ($key !== '' && !isset($seen[$key])) {
                $options[$id] = $label;
                $seen[$key] = true;
            }
        };
        foreach ($selected as $person) {
            $id = (string)$person['positionid'];
            if (isset($positionmap[$id])) {
                $add($id, (string)$positionmap[$id]['name']);
            }
        }
        foreach ($positionmap as $id => $position) {
            if (count($options) >= 8) {
                break;
            }
            $add((string)$id, (string)($position['name'] ?? ''));
        }
        return $options;
    }

    /** A shared answer remains correct for every corresponding canonical position. */
    public static function answer_matches(string $answer, string $positionid, array $positionmap): bool {
        if ($answer === '' || !isset($positionmap[$answer], $positionmap[$positionid])) {
            return false;
        }
        $key = self::position_key((string)($positionmap[$positionid]['name'] ?? ''));
        return $key !== '' && $key === self::position_key((string)($positionmap[$answer]['name'] ?? ''));
    }
}
