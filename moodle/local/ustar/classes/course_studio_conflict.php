<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();
/** An expected optimistic-concurrency refusal, exposed by the API as HTTP 409. */
final class course_studio_conflict extends \moodle_exception {
    public function __construct() { parent::__construct('coursestudioconflict', 'local_ustar'); }
}
