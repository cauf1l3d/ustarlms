<?php
namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

/** A public registration validation error tied to one form field. */
final class registration_validation_exception extends \InvalidArgumentException {
    public function __construct(public readonly string $field, string $message) {
        parent::__construct($message);
    }
}
