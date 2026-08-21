<?php
/** Administrator-only session fallback to the pre-cutover Moodle theme. */
require_once(__DIR__ . '/../../config.php');
require_login();

global $CFG, $PAGE, $OUTPUT, $SESSION, $USER;
$context = context_system::instance();
require_capability('local/ustar:legacyui', $context);
if (!is_siteadmin((int)$USER->id) && !has_capability('local/ustar:admin', $context)) {
    throw new required_capability_exception($context, 'local/ustar:legacyui', 'nopermissions', '');
}

$legacy = trim((string)get_config('local_ustar', 'legacytheme'));
if ($legacy === '' || $legacy === 'ustar') {
    // Safe default for the current installation family; CUTOVER stores the exact previous theme.
    $legacy = 'boost_union';
}
$action = optional_param('action', '', PARAM_ALPHA);

if ($action !== '') {
    require_sesskey();
    if ($action === 'enable') {
        // Session theme is not a public URL override. It is set only after capability + sesskey checks.
        $SESSION->theme = $legacy;
        \local_ustar\event\legacy_ui_toggled::create([
            'context' => $context,
            'other' => ['state'=>'enabled','theme'=>$legacy],
        ])->trigger();
        redirect(new moodle_url('/'));
    }
    if ($action === 'disable') {
        unset($SESSION->theme);
        \local_ustar\event\legacy_ui_toggled::create([
            'context' => $context,
            'other' => ['state'=>'disabled','theme'=>$legacy],
        ])->trigger();
        redirect(new moodle_url('/local/ustar/home.php'));
    }
    throw new invalid_parameter_exception('Неизвестное действие');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/legacy.php'));
$PAGE->set_title('Legacy UI | USTAR');
$PAGE->set_heading('USTAR — резервный интерфейс администратора');
$active = isset($SESSION->theme) && (string)$SESSION->theme === $legacy;

echo $OUTPUT->header();
echo html_writer::start_div('container py-4');
echo html_writer::tag('h2', 'Резервный интерфейс администратора');
echo html_writer::tag('p', 'Эта функция не является публичным обходом темы. Она доступна только администратору, действует в текущей сессии и журналируется.');
echo html_writer::tag('p', 'Сохранённая предыдущая тема: ' . s($legacy));
if ($active) {
    echo html_writer::tag('div', 'Сейчас включён Legacy UI.', ['class'=>'alert alert-warning']);
    echo html_writer::link(new moodle_url('/local/ustar/legacy.php', ['action'=>'disable','sesskey'=>sesskey()]), 'Вернуться в USTAR', ['class'=>'btn btn-primary']);
} else {
    echo html_writer::link(new moodle_url('/local/ustar/legacy.php', ['action'=>'enable','sesskey'=>sesskey()]), 'Открыть Legacy UI', ['class'=>'btn btn-secondary']);
}
echo html_writer::end_div();
echo $OUTPUT->footer();
