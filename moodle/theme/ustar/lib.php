<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Build the standard Moodle / Boost SCSS stack.
 */
function theme_ustar_get_main_scss_content($theme) {
    global $CFG;

    require_once($CFG->dirroot . '/theme/boost/lib.php');

    return theme_boost_get_main_scss_content($theme);
}

/**
 * Reserved for USTAR pre-SCSS variables.
 */
function theme_ustar_get_pre_scss($theme) {
    return '';
}

/**
 * Final USTAR visual layer.
 *
 * Moodle appends extra SCSS after the main Boost SCSS stack.
 */
function theme_ustar_get_extra_scss($theme) {
    $files = [
        __DIR__ . '/scss/_tokens.scss',
        __DIR__ . '/scss/_foundation.scss',
        __DIR__ . '/scss/_shell.scss',
        __DIR__ . '/scss/_fable_shell.scss',
        __DIR__ . '/scss/_learning.scss',
        __DIR__ . '/scss/_development.scss',
        __DIR__ . '/scss/_evidence.scss',
        __DIR__ . '/scss/_learning_path.scss',
        __DIR__ . '/scss/_activity_runtime.scss',
        __DIR__ . '/scss/_hr_people.scss',
        __DIR__ . '/scss/_positions.scss',
        __DIR__ . '/scss/_operations.scss',
        __DIR__ . '/scss/_materials.scss',
        __DIR__ . '/scss/_polish.scss',
        __DIR__ . '/scss/_product_parity.scss',
        __DIR__ . '/scss/_v15.scss',
        __DIR__ . '/scss/_login.scss',
    ];

    $scss = '';

    foreach ($files as $file) {
        if (!is_readable($file)) {
            throw new \coding_exception(
                'USTAR SCSS file is not readable: ' . $file
            );
        }

        $scss .= PHP_EOL;
        $scss .= '/* USTAR: ' . basename($file) . ' */';
        $scss .= PHP_EOL;
        $scss .= file_get_contents($file);
        $scss .= PHP_EOL;
    }

    return $scss;
}
