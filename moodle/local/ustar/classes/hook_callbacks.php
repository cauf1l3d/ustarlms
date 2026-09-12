<?php

namespace local_ustar;

defined('MOODLE_INTERNAL') || die();

use core\hook\output\before_footer_html_generation;

/**
 * Moodle 5 output hook callbacks.
 *
 * @package local_ustar
 */
final class hook_callbacks {
    public static function before_footer_html_generation(
        before_footer_html_generation $hook
    ): void {
        $hook->add_html(route_continue::footer_button());
    }
}

