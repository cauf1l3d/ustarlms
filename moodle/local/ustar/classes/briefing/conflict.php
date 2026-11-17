<?php
namespace local_ustar\briefing;

defined('MOODLE_INTERNAL') || die();

/** Render a fresh record after an optimistic revision conflict. */
final class conflict extends \invalid_parameter_exception {
    public function __construct() {
        parent::__construct('Данные изменились. Проверьте свежие значения и повторите действие.');
    }
}
