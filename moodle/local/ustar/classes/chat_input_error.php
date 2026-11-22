<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** Only application-authored notices may be returned to the composer. */
final class chat_input_error extends \invalid_parameter_exception {
    public function __construct(public readonly string $notice) {
        parent::__construct($notice);
    }
}
