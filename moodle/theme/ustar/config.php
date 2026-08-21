<?php
defined('MOODLE_INTERNAL') || die();

/*
 * USTAR Academy
 *
 * Clean Boost child theme.
 *
 * Production may continue using Boost Union independently.
 * USTAR intentionally does not inherit Boost Union custom SCSS,
 * because the existing Boost Union configuration contains the
 * legacy USTAR v5 visual layer with global !important overrides.
 */

$THEME->name = 'ustar';

$THEME->sheets = [];
$THEME->editor_sheets = [];

$THEME->parents = ['boost'];

$THEME->scss = function($theme) {
    return theme_ustar_get_main_scss_content($theme);
};

$THEME->prescsscallback = 'theme_ustar_get_pre_scss';
$THEME->extrascsscallback = 'theme_ustar_get_extra_scss';

$THEME->rendererfactory = 'theme_overridden_renderer_factory';

$THEME->usefallback = false;
$THEME->precompiledcsscallback = null;

$THEME->enable_dock = false;
$THEME->requiredblocks = '';
$THEME->haseditswitch = true;
$THEME->usescourseindex = true;

$THEME->iconsystem = \core\output\icon_system::FONTAWESOME;

/*
 * USTAR product application layout.
 *
 * Used only by USTAR product pages.
 */
$THEME->layouts = [
    'login' => [
        'file' => 'login.php',
        'regions' => [],
        'options' => [
            'langmenu' => true,
            'nonavbar' => true,
            'nofooter' => true,
        ],
    ],

    'ustar' => [
        'file' => 'ustar.php',
        'regions' => [],
        'options' => [
            'nonavbar' => true,
            'nofooter' => true,
            'nocourseheader' => true,
        ],
    ],

    /*
     * Moodle activity runtime.
     *
     * Forum / Quiz / Page / Lesson / SCORM landing pages and
     * other normal course-module pages stay inside USTAR.
     */
    /*
     * Main Moodle course page.
     *
     * /course/view.php must remain inside the USTAR product shell
     * just like normal course activities.
     */
    'course' => [
        'file' => 'ustar.php',
        'regions' => [],
        'options' => [
            'nonavbar' => true,
            'nofooter' => true,
            'nocourseheader' => true,
        ],
    ],

    'incourse' => [
        'file' => 'ustar.php',
        'regions' => [],
        'options' => [
            'nonavbar' => true,
            'nofooter' => true,
            'nocourseheader' => true,
        ],
    ],
];
