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
    }
    $data = \local_ustar\connect\link_management::handle($method,
        is_https() && parse_url($CFG->wwwroot, PHP_URL_SCHEME) === 'https', $input);
} catch (InvalidArgumentException $failure) {
    http_response_code($failure->getMessage() === 'rate_limited' ? 429 : 403);
    $error = match ($failure->getMessage()) {
        'https_required' => 'Подключение мессенджера доступно только через доверенный HTTPS-адрес Академии. Обратитесь к администратору.',
        'channel_unavailable' => 'Бот Академии пока не подключён. Новые связи временно недоступны.',
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
$data += ['url' => $url->out(false), 'profileurl' => (new moodle_url('/local/ustar/profile.php'))->out(false),
    'sesskey' => sesskey(), 'fullname' => fullname($USER), 'error' => $error, 'haslinks' => (bool)$data['links']];
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ustar/connect', $data);
echo $OUTPUT->footer();
