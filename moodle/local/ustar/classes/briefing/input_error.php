<?php
namespace local_ustar\briefing;

defined('MOODLE_INTERNAL') || die();

/** A validation message safe to display in the Tasks workspace. */
class input_error extends \invalid_parameter_exception {
    public function user_message(): string {
        return (string)$this->debuginfo;
    }
}
