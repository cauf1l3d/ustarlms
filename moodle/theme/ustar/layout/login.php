<?php
defined('MOODLE_INTERNAL') || die();

global $SITE;

$bodyattributes = $OUTPUT->body_attributes(['ustar-auth-body']);
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
    'loginreferenceurl' => $OUTPUT->image_url('brand/login-original-recovery-20260914', 'theme_ustar')->out(false),
    'loginposterurl' => $OUTPUT->image_url('brand/login-poster', 'theme_ustar')->out(false),
    'loginwordmarkurl' => $OUTPUT->image_url('brand/login-wordmark', 'theme_ustar')->out(false),
    'loginstarturl' => $OUTPUT->image_url('brand/login-start', 'theme_ustar')->out(false),
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

