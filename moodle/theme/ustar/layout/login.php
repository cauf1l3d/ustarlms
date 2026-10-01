<?php
defined('MOODLE_INTERNAL') || die();

global $SITE;

require_once($CFG->libdir . '/authlib.php');

$isregistration = (string)$PAGE->url->get_path() === '/local/ustar/register.php';
$bodyattributes = $OUTPUT->body_attributes($isregistration
    ? ['ustar-auth-body', 'ustar-register-body'] : ['ustar-auth-body']);
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
    'loginreferenceurl' => (new moodle_url('/theme/ustar/pix/brand/login-reference-20260914.png'))->out(false),
    'loginurl' => (new moodle_url('/login/index.php'))->out(false),
    'sitename' => format_string(
        $SITE->shortname,
        true,
        ['context' => context_course::instance(SITEID), 'escape' => false]
    ),
    'output' => $OUTPUT,
    'bodyattributes' => $bodyattributes,
    'runtimebrandcss' => $runtimecss,
    'signupenabled' => (int)get_config('local_ustar', 'version') >= 2026082749
        && !$isregistration,
    'starttitle' => $isregistration
        ? 'Создай профиль' : 'Начни с входа',
    'signupurl' => (new moodle_url('/local/ustar/register.php'))->out(false),
];

echo $OUTPUT->render_from_template('theme_ustar/login', $templatecontext);
