<?php
// Trusted Academy origin only. No code in GET, no password or bearer-login handler.
define('NO_DEBUG_DISPLAY', true);
require_once(__DIR__.'/../../config.php');
require_login();

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
$url = new moodle_url('/local/ustar/connect.php');
$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Мессенджеры | USTAR');
$PAGE->set_heading('Мессенджеры');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/ux_tools.css'));

$data = ['enabled' => false, 'links' => [], 'challenge' => null, 'confirmed' => false];
$error = '';
try {
    if (($_SERVER['QUERY_STRING'] ?? '') !== '') { throw new InvalidArgumentException('invalid_request'); }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $input = [];
    if ($method === 'POST') {
        foreach (['action', 'sesskey', 'code', 'linkid'] as $name) {
            $input[$name] = optional_param($name, '', PARAM_RAW);
        }
        if ($input['action'] === 'preferences') {
            $enabled = optional_param('notify_enabled', '0', PARAM_RAW);
            $interval = optional_param('interval_minutes', '', PARAM_RAW);
            if (!in_array($enabled, ['0', '1'], true) || !preg_match('/\A[1-9][0-9]{0,3}\z/', $interval)) {
                throw new InvalidArgumentException('invalid_preferences');
            }
            $input['preferences'] = ['enabled' => $enabled === '1',
                'categories' => array_values(optional_param_array('categories', [], PARAM_RAW)),
                'min_severity' => optional_param('min_severity', '', PARAM_RAW),
                'quiet_start' => optional_param('quiet_start', '', PARAM_RAW),
                'quiet_end' => optional_param('quiet_end', '', PARAM_RAW),
                'timezone' => optional_param('timezone', '', PARAM_RAW), 'interval_minutes' => (int)$interval];
        }
    }
    $data = \local_ustar\connect\link_management::handle($method,
        is_https() && parse_url($CFG->wwwroot, PHP_URL_SCHEME) === 'https', $input);
} catch (InvalidArgumentException $failure) {
    http_response_code($failure->getMessage() === 'rate_limited' ? 429 : 403);
    $error = match ($failure->getMessage()) {
        'https_required' => 'Подключение мессенджера доступно только через доверенный HTTPS-адрес Академии. Обратитесь к администратору.',
        'channel_unavailable' => 'Бот Академии пока не подключён. Новые связи временно недоступны.',
        'invalid_preferences' => 'Проверьте настройки: категории, часовой пояс IANA, время ЧЧ:ММ и интервал от 1 до 1440 минут.',
        'expired_challenge' => 'Срок ссылки истёк. Вернитесь на эту страницу и создайте новую ссылку.',
        'unclaimed_challenge' => 'Сначала откройте персональную ссылку в своём Telegram и дождитесь подтверждения отправки.',
        'link_conflict' => 'Этот Telegram или канал уже принадлежит другой связи. Автоматический перенос запрещён; обратитесь к администратору.',
        'revoked_link' => 'Связь отозвана. Старое подтверждение больше не действует; создайте новую ссылку.',
        'rate_limited' => 'Создано слишком много ссылок. Дождитесь истечения предыдущих: срок каждой — 10 минут.',
        'invalid_challenge', 'invalid_sesskey' => 'Подтверждение недоступно в этой сессии. Вернитесь в тот браузер Академии, где создавали ссылку.',
        default => 'Операция недоступна. Используйте свою действующую учётную запись сотрудника и текущую сессию Академии.',
    };
} catch (Throwable $failure) {
    // No request/exception text or trace: a challenge may be present in arguments.
    http_response_code(503);
    $error = 'Подключение временно недоступно. В режиме «Просмотр как» и под чужой сессией связи не изменяются.';
}
foreach ($data['links'] as &$link) {
    $link['linked'] = $link['state'] === 'linked';
    $link['providerlabel'] = ['telegram' => 'Telegram', 'bitrix' => 'Битрикс24', 'whatsapp' => 'WhatsApp'][$link['provider']] ?? 'Канал';
}
unset($link);
if ($data['challenge']) {
    $data['challenge']['expires_label'] = userdate($data['challenge']['expires_at']);
}
$categories = ['registration' => 'Регистрация', 'tasks' => 'Задачи', 'feed' => 'Лента', 'grades' => 'Грейды'];
if (isset($data['preferences'])) {
    $prefs = $data['preferences'];
    $data['preferences']['categories'] = [];
    foreach ($categories as $key => $label) {
        $data['preferences']['categories'][] = ['value' => $key, 'label' => $label, 'selected' => in_array($key, $prefs['categories'], true)];
    }
    $data['preferences']['severities'] = [];
    foreach (['normal' => 'Все события', 'action' => 'Требуется действие и важные', 'critical' => 'Только важные'] as $key => $label) {
        $data['preferences']['severities'][] = ['value' => $key, 'label' => $label, 'selected' => $key === $prefs['min_severity']];
    }
}
foreach ($data['deliveries'] ?? [] as $i => $delivery) {
    $data['deliveries'][$i]['categorylabel'] = $categories[$delivery['category']] ?? 'Событие';
    $data['deliveries'][$i]['statuslabel'] = ['pending' => 'В очереди', 'leased' => 'Проверяется', 'sending' => 'Отправляется',
        'provider_accepted' => 'Принято Telegram', 'retry_wait' => 'Ожидает повтора', 'uncertain' => 'Результат неизвестен — автоматического повтора нет',
        'failed' => 'Не доставлено', 'cancelled' => 'Отменено: событие или доступ изменились'][$delivery['status']] ?? 'Недоступно';
    $data['deliveries'][$i]['time_label'] = userdate($delivery['timemodified']);
    $data['deliveries'][$i]['next_label'] = $delivery['nextattempt'] ? userdate($delivery['nextattempt']) : '';
}
$data += ['url' => $url->out(false), 'profileurl' => (new moodle_url('/local/ustar/profile.php'))->out(false),
    'sesskey' => sesskey(), 'fullname' => fullname($USER), 'error' => $error, 'haslinks' => (bool)$data['links']];
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ustar/connect', $data);
echo $OUTPUT->footer();
