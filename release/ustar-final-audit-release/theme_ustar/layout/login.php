<?php
defined('MOODLE_INTERNAL') || die();

global $SITE;

$bodyattributes = $OUTPUT->body_attributes(['u-login-body']);
$runtimecss = '';
try {
    if (class_exists('\\local_ustar\\branding')) {
        $runtimecss = \local_ustar\branding::inline_css();
    }
} catch (\Throwable $e) {
    // The login page must remain available even during an interrupted plugin upgrade.
    $runtimecss = '';
}

$templatecontext = [
    'sitename' => format_string(
        $SITE->shortname,
        true,
        ['context' => context_course::instance(SITEID), 'escape' => false]
    ),
    'output' => $OUTPUT,
    'bodyattributes' => $bodyattributes,
    'runtimebrandcss' => $runtimecss,
    'brandmarkurl' => $OUTPUT->image_url('brand/logo-onlight', 'theme_ustar')->out(false),
    'mascoturl' => $OUTPUT->image_url('brand/mascot-admin', 'theme_ustar')->out(false),
    'academybannerurl' => $OUTPUT->image_url('brand/ustar-academy-banner', 'theme_ustar')->out(false),
];

echo $OUTPUT->render_from_template('theme_ustar/login', $templatecontext);
