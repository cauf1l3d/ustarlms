<?php
require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/ustar:admin', $context);

$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    $action = required_param('action', PARAM_ALPHA);

    if ($action === 'preset') {
        $preset = required_param('preset', PARAM_ALPHANUMEXT);
        \local_ustar\branding::save_preset($preset);
    } else if ($action === 'custom') {
        $values = [];
        foreach ([
            'accent', 'accentStrong', 'canvas', 'surface', 'surfaceMuted',
            'text', 'textSecondary', 'darkCanvas', 'darkSurface', 'darkText', 'darkTextSecondary'
        ] as $field) {
            $values[$field] = optional_param($field, '', PARAM_TEXT);
        }
        try {
            \local_ustar\branding::save_custom($values);
        } catch (\invalid_parameter_exception $e) {
            redirect(new moodle_url('/local/ustar/brand.php', ['error' => 'contrast']));
        }
    }
    redirect(new moodle_url('/local/ustar/brand.php', ['saved' => 1]));
}

$saved = optional_param('saved', 0, PARAM_BOOL);
$error = optional_param('error', '', PARAM_ALPHANUMEXT);
$current = \local_ustar\branding::current();
$presets = [];
foreach (\local_ustar\branding::presets() as $preset) {
    $preset['selected'] = empty($current['themeCustom']) && $preset['id'] === $current['themePreset'];
    $presets[] = $preset;
}

$data = [
    'saved' => $saved,
    'contrasterror' => $error === 'contrast',
    'presets' => $presets,
    'current' => $current,
    'iscustom' => !empty($current['themeCustom']),
    'sesskey' => sesskey(),
    'paletteicon' => \local_ustar\ui::icon('palette', 'u-feature-icon'),
    'bannerurl' => $OUTPUT->image_url('brand/ustar-academy-banner', 'theme_ustar')->out(false),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/brand.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Оформление | Центр управления USTAR');
$PAGE->set_heading('Центр управления USTAR');
$PAGE->requires->js_call_amd('local_ustar/brand_studio', 'init');

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/brand', $data);
echo $output->footer();
