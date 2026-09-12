<?php
namespace theme_ustar\output;
defined('MOODLE_INTERNAL') || die();

/** Wording only. Core still owns response values, correct answer, attempts and grades. */
class qtype_truefalse_renderer extends \qtype_truefalse_renderer {
    private function permission_question(\question_attempt $qa): bool {
        $text = html_entity_decode(strip_tags($qa->get_question()->questiontext), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return preg_match('/можно\s+ли\s+сидеть\s+на\s+диване.*(?:видимости|виден|видно).*покупател/iu', $text) === 1;
    }

    public function formulation_and_controls(\question_attempt $qa, \question_display_options $options) {
        $html = parent::formulation_and_controls($qa, $options);
        if (!$this->permission_question($qa)) { return $html; }
        $field = preg_quote($qa->get_qt_field_name('answer'), '~');
        return preg_replace_callback('~(<label\\b[^>]*\\bfor="' . $field . '(true|false)"[^>]*>).*?(</label>)~su',
            static fn($m) => $m[1] . ($m[2] === 'true' ? 'Можно' : 'Нельзя') . $m[3], $html);
    }

    public function correct_response(\question_attempt $qa) {
        if (!$this->permission_question($qa)) { return parent::correct_response($qa); }
        return 'Правильный ответ: ' . ($qa->get_question()->rightanswer ? 'можно' : 'нельзя');
    }
}
