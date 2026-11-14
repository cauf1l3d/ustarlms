<?php
define('NO_DEBUG_DISPLAY', true);
require_once(__DIR__ . '/../../config.php');

use local_ustar\connect\bot_invitation;

require_login();
bot_invitation::require_manager();
$context = context_system::instance();
$url = new moodle_url('/local/ustar/connect_invitations.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Приглашения Telegram | USTAR');
$PAGE->set_heading('Приглашения Telegram');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/ux_tools.css'));
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$enabled = bot_invitation::enabled();
$issued = null;
$error = '';
$query = trim(optional_param('q', '', PARAM_TEXT));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        $action = required_param('action', PARAM_ALPHA);
        if ($action === 'issue') {
            $issued = bot_invitation::issue(required_param('userid', PARAM_INT),
                trim(optional_param('telegramid', '', PARAM_RAW)));
        } else if ($action === 'confirm') {
            bot_invitation::confirm(required_param('inviteid', PARAM_INT),
                strtoupper(trim(required_param('verification', PARAM_RAW))));
            redirect($url, 'Подключение подтверждено. Сотрудник может открыть меню бота.');
        } else if ($action === 'cancel') {
            bot_invitation::cancel(required_param('inviteid', PARAM_INT));
            redirect($url, 'Приглашение отменено.');
        } else if ($action === 'revoke') {
            bot_invitation::revoke(required_param('linkid', PARAM_INT));
            redirect($url, 'Доступ в Telegram отозван.');
        } else {
            throw new InvalidArgumentException('invalid_invitation');
        }
    } catch (invalid_parameter_exception $exception) {
        $error = $exception->debuginfo;
    } catch (InvalidArgumentException $exception) {
        $error = match ($exception->getMessage()) {
            'link_conflict' => 'У сотрудника уже есть привязка Telegram. Сначала отзовите её.',
            'inactive_actor' => 'Выберите действующую учётную запись сотрудника.',
            'native_auth_required' => 'Для этой учётной записи требуется стандартный вход в Академию.',
            'agreement_unavailable' => 'Включите обработчик «Политики» и опубликуйте соглашение для сотрудников.',
            default => 'Проверьте код, Telegram ID и срок действия приглашения.',
        };
    }
}

$people = [];
if ($enabled && core_text::strlen($query) >= 2) {
    $names = $DB->sql_fullname('firstname', 'lastname');
    $reversed = $DB->sql_fullname('lastname', 'firstname');
    $match = implode(' OR ', [$DB->sql_like('username', ':login', false),
        $DB->sql_like($names, ':name', false), $DB->sql_like($reversed, ':reverse', false)]);
    $pattern = '%' . $DB->sql_like_escape(core_text::substr($query, 0, 80)) . '%';
    $users = $DB->get_records_select('user', "deleted = 0 AND suspended = 0 AND confirmed = 1 AND auth = :auth AND ($match)",
        ['auth' => 'manual', 'login' => $pattern, 'name' => $pattern, 'reverse' => $pattern],
        'lastname, firstname, id', 'id, firstname, lastname, middlename, firstnamephonetic, lastnamephonetic, alternatename, username', 0, 25);
    foreach ($users as $person) {
        $people[] = ['id' => $person->id, 'name' => fullname($person) . ' · ' . $person->username];
    }
}

$invites = [];
$now = time();
foreach ($DB->get_records_sql('SELECT i.id,i.subject,i.expectedsubject,i.expiresat,u.firstname,u.lastname,
            u.middlename,u.firstnamephonetic,u.lastnamephonetic,u.alternatename
          FROM {local_ustar_conn_challenges} i JOIN {user} u ON u.id = i.userid
         WHERE i.issuedby > 0 AND i.confirmedlinkid = 0 AND i.expiresat > :now ORDER BY i.id DESC',
        ['now' => $now], 0, 50) as $row) {
    $invites[] = ['id' => $row->id, 'name' => fullname($row), 'expires' => userdate($row->expiresat),
        'claimed' => $enabled && $row->subject !== '' && empty($row->expectedsubject)];
}
$links = [];
foreach ($DB->get_records_sql('SELECT l.id,l.timemodified,u.firstname,u.lastname,u.middlename,
            u.firstnamephonetic,u.lastnamephonetic,u.alternatename
          FROM {local_ustar_conn_links} l JOIN {user} u ON u.id = l.userid
         WHERE l.botinvited = 1 AND l.provider = :provider AND l.state = :state ORDER BY l.id DESC',
        ['provider' => 'telegram', 'state' => 'linked'], 0, 100) as $row) {
    $links[] = ['id' => $row->id, 'name' => fullname($row), 'date' => userdate($row->timemodified)];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ustar/connect_invitations', [
    'url' => $url->out(false), 'messengers' => (new moodle_url('/local/ustar/connect.php'))->out(false),
    'enabled' => $enabled, 'issued' => $issued, 'error' => $error, 'people' => $people, 'haspeople' => (bool)$people,
    'searched' => core_text::strlen($query) >= 2, 'q' => $query, 'invites' => $invites, 'links' => $links,
    'sesskey' => sesskey(),
]);
echo $OUTPUT->footer();
