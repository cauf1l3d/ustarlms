<?php
// Explicit feed roles, managed only by a Moodle site administrator.
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/accesslib.php');

require_login();
if (!is_siteadmin((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(),
        'moodle/site:config', 'nopermissions', '');
}
$context = context_system::instance();
$url = new moodle_url('/local/ustar/admin_feed_roles.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title('Издатели и модераторы Ленты · USTAR');
$PAGE->set_heading('Издатели и модераторы Ленты');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/feed.css', ['v' => '20260927']));

$names = [
    'ustar_feed_person' => 'Личный издатель',
    'ustar_feed_department' => 'Издатель подразделения (только действующий руководитель)',
    'ustar_feed_academy' => 'Издатель от Академии',
    'ustar_feed_moderator' => 'Модератор',
];
$roles = [];
foreach ($names as $shortname => $label) {
    $roles[$shortname] = $DB->get_record('role', ['shortname' => $shortname], 'id,shortname', MUST_EXIST);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $shortname = required_param('role', PARAM_ALPHANUMEXT);
    $userid = required_param('userid', PARAM_INT);
    $action = required_param('action', PARAM_ALPHA);
    if (!isset($roles[$shortname]) || !in_array($action, ['grant', 'revoke'], true)) {
        throw new invalid_parameter_exception('Недопустимая роль или действие.');
    }
    if ($action === 'grant') {
        $target = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
            'id,firstname,lastname,suspended', MUST_EXIST);
        if ($target->suspended || !\local_ustar\accounts::participates($userid)) {
            throw new invalid_parameter_exception('Роль можно назначить только действующему сотруднику.');
        }
        if ($shortname === 'ustar_feed_department'
                && !\local_ustar\organization_model::manager_department_ids($userid)) {
            throw new invalid_parameter_exception('У сотрудника нет действующей области руководителя.');
        }
        if (!user_has_role_assignment($userid, (int)$roles[$shortname]->id, $context->id)) {
            role_assign((int)$roles[$shortname]->id, $userid, $context->id);
        }
    } else {
        // Revocation must remain possible even when the account was deleted.
        role_unassign((int)$roles[$shortname]->id, $userid, $context->id);
    }
    redirect($url, 'Права Ленты обновлены.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$search = trim(optional_param('q', '', PARAM_TEXT));
if (core_text::strlen($search) > 100) {
    throw new invalid_parameter_exception('Слишком длинный запрос.');
}
$candidates = [];
if (core_text::strlen($search) >= 2) {
    $like = '%' . $DB->sql_like_escape($search) . '%';
    $candidates = $DB->get_records_sql('SELECT id, firstname, lastname, email, suspended
        FROM {user} WHERE deleted = 0 AND id > 1 AND
        (' . $DB->sql_like('firstname', ':first', false) . ' OR ' .
            $DB->sql_like('lastname', ':last', false) . ' OR ' .
            $DB->sql_like('email', ':email', false) . ')
        ORDER BY lastname, firstname, id',
        ['first' => $like, 'last' => $like, 'email' => $like], 0, 30);
}

$assignments = $DB->get_records_sql('SELECT ra.id, ra.userid, ra.roleid,
        u.firstname, u.lastname, u.email, u.deleted, u.suspended
        FROM {role_assignments} ra JOIN {user} u ON u.id = ra.userid
        WHERE ra.contextid = :contextid AND ra.roleid IN (' .
            implode(',', array_map(static fn($role) => (int)$role->id, $roles)) . ')
        ORDER BY u.lastname, u.firstname, ra.id', ['contextid' => $context->id]);

echo $OUTPUT->header();
echo html_writer::start_div('u-feed');
echo html_writer::tag('p', 'Права выдаются конкретным действующим сотрудникам. '
    . 'Роль модератора не даёт права публикации. Права подразделения действуют только '
    . 'при актуальном назначении руководителя.', ['class' => 'u-feed__intro']);

echo html_writer::start_div('u-feed__composer');
echo html_writer::tag('h2', 'Найти сотрудника');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false)]);
echo html_writer::tag('label', 'Имя, фамилия или email', ['for' => 'feed-user-search']);
echo html_writer::empty_tag('input', ['id' => 'feed-user-search', 'name' => 'q',
    'value' => $search, 'class' => 'form-control', 'required' => 'required', 'minlength' => '2']);
echo html_writer::tag('button', 'Найти', ['type' => 'submit', 'class' => 'btn btn-primary mt-2']);
echo html_writer::end_tag('form');
if ($search !== '' && !$candidates) {
    echo html_writer::tag('p', 'Подходящие сотрудники не найдены.');
}
foreach ($candidates as $candidate) {
    if ($candidate->suspended || !\local_ustar\accounts::participates((int)$candidate->id)) {
        continue;
    }
    echo html_writer::start_div('u-feed__post');
    echo html_writer::tag('strong', s(fullname($candidate)) . ' · ID ' . (int)$candidate->id);
    echo html_writer::tag('p', s($candidate->email));
    foreach ($roles as $shortname => $role) {
        if ($shortname === 'ustar_feed_department'
                && !\local_ustar\organization_model::manager_department_ids((int)$candidate->id)) {
            continue;
        }
        $assigned = user_has_role_assignment((int)$candidate->id, (int)$role->id, $context->id);
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => (int)$candidate->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'role', 'value' => $shortname]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',
            'value' => $assigned ? 'revoke' : 'grant']);
        echo html_writer::tag('button', ($assigned ? 'Отозвать: ' : 'Выдать: ') . $names[$shortname],
            ['type' => 'submit', 'class' => 'btn btn-outline-secondary my-1']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::start_div('u-feed__composer');
echo html_writer::tag('h2', 'Действующие назначения');
foreach ($assignments as $assignment) {
    $shortname = array_search((int)$assignment->roleid,
        array_map(static fn($role) => (int)$role->id, $roles), true);
    $label = $names[$shortname];
    echo html_writer::start_div('u-feed__post');
    echo html_writer::tag('strong', s(fullname($assignment)) . ' · ID ' . (int)$assignment->userid);
    echo html_writer::tag('p', s($assignment->email) . ' · ' . s($label)
        . ($assignment->deleted || $assignment->suspended ? ' · Учётная запись неактивна' : ''));
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    foreach (['sesskey' => sesskey(), 'userid' => (int)$assignment->userid,
            'role' => $shortname, 'action' => 'revoke'] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    echo html_writer::tag('button', 'Отозвать право', ['type' => 'submit', 'class' => 'btn btn-outline-danger']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
