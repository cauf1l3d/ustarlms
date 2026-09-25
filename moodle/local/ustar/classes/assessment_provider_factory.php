<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

final class assessment_provider_factory {
    public static function for_policy(\stdClass $policy): assessment_provider {
        return match ((string)$policy->providerkind) {
            'moodle_quiz' => new moodle_quiz_assessment_provider(),
            default => throw new \moodle_exception(
                'Неподдерживаемый провайдер аттестации: ' . (string)$policy->providerkind
            ),
        };
    }
}
