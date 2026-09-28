<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$actorid = (int)$USER->id;
\local_ustar\feed_access::require_manager($actorid);

require_once($CFG->libdir . '/accesslib.php');

$context = context_system::instance();
$url = new moodle_url('/local/ustar/feed_admin.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title('Настройки Ленты · USTAR Academy');
$PAGE->set_heading('Настройки Ленты');
$PAGE->set_pagelayout('ustar');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/feed.css', ['v' => '20260928-experience']));

$roles = [
    'editor' => [
        'shortname' => 'ustar_feed_editor',
        'label' => 'Редакторы',
        'description' => 'Могут редактировать доступные публикации, но не получают право публикации от имени Академии.',
    ],
    'department' => [
        'shortname' => 'ustar_feed_department',
        'label' => 'Издатели подразделений',
        'description' => 'Могут публиковать от имени подразделений в пределах своей текущей управленческой области.',
    ],
    'academy' => [
        'shortname' => 'ustar_feed_academy',
        'label' => 'Издатели Академии',
        'description' => 'Могут публиковать от имени Академии и выбирать аудиторию.',
    ],
    'moderator' => [
        'shortname' => 'ustar_feed_moderator',
        'label' => 'Модераторы',
        'description' => 'Могут скрывать и восстанавливать публикации и разбирать жалобы.',
    ],
];

$notice = '';
$noticeerror = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        $action = required_param('action', PARAM_ALPHA);
        $rolekey = required_param('rolekey', PARAM_ALPHANUMEXT);
        $targetid = required_param('userid', PARAM_INT);
        if (!isset($roles[$rolekey]) || !in_array($action, ['assign', 'unassign'], true)) {
            throw new invalid_parameter_exception('Неизвестное действие управления Лентой.');
        }

        $target = $DB->get_record('user', ['id' => $targetid, 'deleted' => 0], 'id,firstname,lastname,suspended',
            MUST_EXIST);
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => $roles[$rolekey]['shortname']]);
        if (!$roleid) {
            throw new moodle_exception('Роль Ленты ещё не установлена. Выполните обновление local_ustar.');
        }

        if ($action === 'assign') {
            if (!empty($target->suspended) || !\local_ustar\accounts::participates($targetid)) {
                throw new invalid_parameter_exception('Права Ленты можно назначить только активному сотруднику.');
            }
            role_assign($roleid, $targetid, $context->id);
            $notice = 'Права назначены: ' . fullname($target) . '.';
        } else {
            role_unassign($roleid, $targetid, $context->id);
            $notice = 'Права сняты: ' . fullname($target) . '.';
        }
        accesslib_clear_all_caches(true);
    } catch (moodle_exception $e) {
        $notice = $e->getMessage();
        $noticeerror = true;
    }
}

$roleids = [];
foreach ($roles as $key => $definition) {
    $roleids[$key] = (int)$DB->get_field('role', 'id', ['shortname' => $definition['shortname']]);
}

$employees = [];
$personsearch = \core_text::substr(trim(optional_param('person', '', PARAM_TEXT)), 0, 80);
$candidates = [];
if (\core_text::strlen($personsearch) >= 2) {
    $needle = '%' . $DB->sql_like_escape($personsearch) . '%';
    $like = $DB->sql_like('firstname', ':first', false) . ' OR '
        . $DB->sql_like('lastname', ':last', false) . ' OR '
        . $DB->sql_like('username', ':username', false) . ' OR '
        . $DB->sql_like('email', ':email', false);
    $candidates = $DB->get_records_sql("SELECT id, firstname, lastname, username, email, suspended
           FROM {user}
          WHERE deleted = 0 AND suspended = 0 AND id > 1 AND ({$like})
          ORDER BY lastname, firstname, id",
        ['first' => $needle, 'last' => $needle, 'username' => $needle, 'email' => $needle], 0, 30);
}
foreach ($candidates as $candidate) {
    if (\local_ustar\accounts::participates((int)$candidate->id)) {
        $employees[(int)$candidate->id] = fullname($candidate)
            . ' [' . (string)$candidate->username . ']';
    }
}

$holders = [];
foreach ($roleids as $key => $roleid) {
    $holders[$key] = [];
    if (!$roleid) {
        continue;
    }
    $holders[$key] = array_values($DB->get_records_sql(
        "SELECT ra.id, ra.userid, u.firstname, u.lastname, u.username, u.email
           FROM {role_assignments} ra
           JOIN {user} u ON u.id = ra.userid
          WHERE ra.contextid = :contextid
            AND ra.roleid = :roleid
            AND u.deleted = 0
          ORDER BY u.lastname, u.firstname, u.id",
        ['contextid' => $context->id, 'roleid' => $roleid]
    ));
}

$drafts = array_values($DB->get_records('local_ustar_feed_posts',
    ['status' => 'draft'], 'timemodified DESC, id DESC', '*', 0, 30));
$draftauthors = [];
if ($drafts) {
    $ids = array_values(array_unique(array_map(static fn($row) => (int)$row->actoruserid, $drafts)));
    [$sql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'draftuser');
    $draftauthors = $DB->get_records_select('user', "id {$sql}", $params, '', 'id,firstname,lastname');
}

$events = $DB->get_records_sql(
    "SELECT e.id, e.postid, e.actoruserid, e.action, e.reason, e.timecreated,
            u.firstname, u.lastname
       FROM {local_ustar_feed_events} e
  LEFT JOIN {user} u ON u.id = e.actoruserid
   ORDER BY e.id DESC",
    [],
    0,
    50
);

echo $OUTPUT->header();
echo html_writer::start_div('u-feed u-feed-admin');
echo html_writer::start_div('u-feed-admin__head');
echo html_writer::start_div();
echo html_writer::tag('h1', 'Управление лентой');
echo html_writer::tag('p',
    'Права авторов, модерация и история действий в одном месте.',
    ['class' => 'u-feed__intro']);
echo html_writer::end_div();
echo html_writer::link(new moodle_url('/local/ustar/feed.php'), '← Вернуться в Ленту',
    ['class' => 'u-btn u-btn--secondary']);
echo html_writer::end_div();

if ($notice !== '') {
    echo $OUTPUT->notification(s($notice), $noticeerror ? 'notifyproblem' : 'notifysuccess');
}

echo html_writer::start_div('u-feed-admin__owner');
echo html_writer::tag('h2', 'Контроль владельца');
echo html_writer::tag('p',
    'Право полного управления (feedmanage) не выдаётся из этой страницы. Оно остаётся у USTAR Superadmin / site administrator.');
echo html_writer::start_div('u-feed__actions');
echo html_writer::link(new moodle_url('/local/ustar/feed.php', ['moderation' => 1]),
    'Очередь модерации', ['class' => 'u-btn']);
echo html_writer::link(new moodle_url('/local/ustar/feed.php', ['moderation' => 1, 'hidden' => 1]),
    'Скрытые публикации', ['class' => 'u-btn u-btn--secondary']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_tag('section', ['class' => 'u-feed-admin__section']);
echo html_writer::tag('h2', 'Найти сотрудника для назначения');
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'u-feed-admin__search']);
echo html_writer::tag('label', 'Имя, фамилия, логин или почта', ['for' => 'feed-person']);
echo html_writer::empty_tag('input', ['type' => 'search', 'name' => 'person',
    'id' => 'feed-person', 'value' => $personsearch, 'class' => 'form-control',
    'minlength' => 2, 'placeholder' => 'Введите не менее двух символов']);
echo html_writer::tag('button', 'Найти', ['type' => 'submit', 'class' => 'u-btn']);
echo html_writer::end_tag('form');
if ($personsearch !== '') {
    echo html_writer::tag('p', $employees ? 'Выберите найденного сотрудника в разделе нужного права.' :
        'Нет действующих сотрудников по этому запросу.', ['class' => 'u-feed__source']);
}
echo html_writer::end_tag('section');

foreach ($roles as $key => $definition) {
    echo html_writer::start_tag('details', ['class' => 'u-feed-admin__section',
        'open' => $personsearch !== '' || $key === 'editor' ? 'open' : null]);
    echo html_writer::tag('summary', s($definition['label']) . ' · ' . count($holders[$key]));
    echo html_writer::tag('p', s($definition['description']), ['class' => 'u-feed__source']);

    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed-admin__assign']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'assign']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'rolekey', 'value' => $key]);
    echo html_writer::tag('label', 'Добавить сотрудника', ['for' => 'feed-role-' . $key]);
    echo html_writer::select($employees, 'userid', '', ['' => '— Выберите сотрудника —'],
        ['id' => 'feed-role-' . $key, 'class' => 'form-select',
            'required' => 'required', 'disabled' => !$employees ? 'disabled' : null]);
    echo html_writer::tag('button', 'Назначить', ['type' => 'submit', 'class' => 'u-btn',
        'disabled' => !$employees ? 'disabled' : null]);
    echo html_writer::end_tag('form');

    if (!$holders[$key]) {
        echo html_writer::tag('p', 'Пока никто не назначен.', ['class' => 'u-feed__empty']);
    } else {
        echo html_writer::start_tag('div', ['class' => 'u-feed-admin__holders']);
        foreach ($holders[$key] as $holder) {
            echo html_writer::start_div('u-feed-admin__holder');
            echo html_writer::tag('span', s(fullname($holder) . ' [' . (string)$holder->username . ']'));
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-feed__inline']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'unassign']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'rolekey', 'value' => $key]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid',
                'value' => (int)$holder->userid]);
            echo html_writer::tag('button', 'Снять право', ['type' => 'submit',
                'class' => 'u-btn u-btn--secondary']);
            echo html_writer::end_tag('form');
            echo html_writer::end_div();
        }
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('details');
}

echo html_writer::start_tag('section', ['class' => 'u-feed-admin__section']);
echo html_writer::tag('h2', 'Черновики на редактуру');
if (!$drafts) {
    echo html_writer::tag('p', 'Черновиков нет.', ['class' => 'u-feed__empty']);
} else {
    foreach ($drafts as $draft) {
        $author = $draftauthors[(int)$draft->actoruserid] ?? null;
        $label = ($author ? fullname($author) : 'Пользователь #' . (int)$draft->actoruserid)
            . ' · ' . userdate((int)$draft->timemodified)
            . ' · ' . \core_text::substr((string)$draft->body, 0, 120);
        echo html_writer::link(new moodle_url('/local/ustar/feed.php', ['postid' => (int)$draft->id]),
            s($label), ['class' => 'u-feed__draft']);
    }
}
echo html_writer::end_tag('section');

echo html_writer::start_tag('section', ['class' => 'u-feed-admin__section']);
echo html_writer::tag('h2', 'Последние действия');
if (!$events) {
    echo html_writer::tag('p', 'Журнал пока пуст.', ['class' => 'u-feed__empty']);
} else {
    echo html_writer::start_tag('div', ['class' => 'u-feed-admin__events']);
    foreach ($events as $event) {
        $actor = trim((string)$event->firstname . ' ' . (string)$event->lastname);
        if ($actor === '') {
            $actor = 'Пользователь #' . (int)$event->actoruserid;
        }
        $text = userdate((int)$event->timecreated) . ' · ' . $actor
            . ' · #' . (int)$event->postid . ' · ' . (string)$event->action;
        if ((string)$event->reason !== '') {
            $text .= ' · ' . (string)$event->reason;
        }
        echo html_writer::tag('div', s($text), ['class' => 'u-feed-admin__event']);
    }
    echo html_writer::end_tag('div');
}
echo html_writer::end_tag('section');

echo html_writer::end_div();
echo $OUTPUT->footer();
