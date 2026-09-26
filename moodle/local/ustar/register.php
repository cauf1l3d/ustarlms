<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/authlib.php');

if ((int)get_config('local_ustar', 'version') < 2026082749) {
    throw new moodle_exception('Обновление USTAR ещё не завершено. Повторите попытку позже.');
}

if (isloggedin() && !isguestuser()) {
    redirect(new moodle_url('/local/ustar/profile.php'));
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/ustar/register.php'));
$PAGE->set_pagelayout('login');
$PAGE->set_title('Регистрация в USTAR Академии');
$PAGE->set_heading('Регистрация в USTAR Академии');

$error = '';
$values = [
    'username' => '', 'email' => '', 'firstname' => '', 'lastname' => '', 'departmentid' => '',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    foreach (array_keys($values) as $key) {
        $values[$key] = optional_param($key, '', PARAM_RAW_TRIMMED);
    }
    if (optional_param('website', '', PARAM_RAW) !== '') {
        $error = 'Не удалось отправить заявку. Повторите попытку позже.';
    } else if (optional_param('password', '', PARAM_RAW)
            !== optional_param('passwordconfirm', '', PARAM_RAW)) {
        $error = 'Пароли не совпадают.';
    } else {
        try {
            $userid = \local_ustar\registration_service::register($values + [
                'password' => required_param('password', PARAM_RAW),
            ]);
            complete_user_login($DB->get_record('user', ['id' => $userid], '*', MUST_EXIST));
            redirect(new moodle_url('/local/ustar/profile.php'),
                'Профиль создан. Заявка передана HRD для подтверждения.', null,
                \core\output\notification::NOTIFY_SUCCESS);
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('USTAR self-registration failed: ' . get_class($e)
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
            debugging('USTAR registration failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $error = 'Регистрация временно недоступна. Обратитесь к HRD.';
        }
    }
}

$departments = \local_ustar\registration_service::departments();
foreach ($departments as &$department) {
    $department['selected'] = $department['id'] === $values['departmentid'];
}
unset($department);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ustar/register', [
    'error' => $error, 'values' => $values, 'departments' => $departments,
    'sesskey' => sesskey(), 'loginurl' => (new moodle_url('/login/index.php'))->out(false),
]);
echo $OUTPUT->footer();
