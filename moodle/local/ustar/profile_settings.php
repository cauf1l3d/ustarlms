<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_login();
require_capability('local/ustar:use', context_system::instance());
$context = context_user::instance($USER->id);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/profile_settings.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Настройки профиля | USTAR');
$PAGE->set_heading('Настройки профиля');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/ux_tools.css'));
$auth = get_auth_plugin($USER->auth);
$canphoto = empty($CFG->disableuserimages)
    && has_capability('moodle/user:editownprofile', context_system::instance())
    && !is_mnet_remote_user($USER) && $auth->can_edit_profile() && !$auth->edit_profile_url();
$options = ['maxbytes'=>min((int)$CFG->maxbytes ?: 5242880, 5242880), 'subdirs'=>0,
    'maxfiles'=>1, 'accepted_types'=>['.jpg','.jpeg','.png']];
$form = new \local_ustar\form\profile_settings(null, ['canphoto'=>$canphoto, 'fileoptions'=>$options]);
$back = new moodle_url('/local/ustar/profile.php');
if ($form->is_cancelled()) {redirect($back);}
if ($data = $form->get_data()) {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    // Never accept an id, role, position, email or auth field from the form.
    $update = (object)['id'=>(int)$USER->id, 'lang'=>$data->lang, 'timezone'=>$data->timezone];
    user_update_user($update, false, false);
    set_user_preference('local_ustar_preset', $data->preset, $USER->id);
    if ($canphoto) {
        core_user::update_picture((object)['id'=>(int)$USER->id,
            'imagefile'=>(int)$data->imagefile, 'deletepicture'=>!empty($data->deletepicture)], $options);
    }
    $USER->lang = $data->lang;
    $USER->timezone = $data->timezone;
    $USER->picture = $DB->get_field('user', 'picture', ['id'=>$USER->id]);
    \core\event\user_updated::create_from_userid($USER->id)->trigger();
    redirect($back, 'Настройки сохранены', null, \core\output\notification::NOTIFY_SUCCESS);
}
$initial = (object)['lang'=>$USER->lang, 'timezone'=>core_date::get_user_timezone(),
    'preset'=>get_user_preferences('local_ustar_preset', 'yellow')];
if ($canphoto) {
    $draft = file_get_submitted_draft_itemid('imagefile');
    file_prepare_draft_area($draft, $context->id, 'user', 'newicon', 0, $options);
    $initial->imagefile = $draft;
}
$form->set_data($initial);
echo $OUTPUT->header();
echo '<div class="u-product-page u-ux-tools"><header class="u-product-head"><div><h1>Настройки профиля</h1><p>'
    .s(fullname($USER)).' · '.s($USER->email).'</p></div></header><section class="u-panel u-ux-form">';
echo '<p>ФИО, рабочую почту и должность изменяет HR. Здесь можно настроить оформление, язык, часовой пояс и фотографию.</p>';
if (!$canphoto) {echo '<p>Изменение фото ограничено настройками вашей учётной записи.</p>';}
$form->display();
echo '</section></div>';
echo $OUTPUT->footer();
