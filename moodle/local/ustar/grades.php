<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/ustar:use', $context);
$view = optional_param('view', 'mine', PARAM_ALPHA);
if (!in_array($view, ['mine', 'team', 'assignments'], true)) { $view = 'mine'; }
$canassign = has_capability('local/ustar:hrmanage', $context);
if ($view === 'assignments' && !$canassign) { require_capability('local/ustar:hrmanage', $context); }
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    try {
        $action = required_param('action', PARAM_ALPHANUMEXT);
        if ($action === 'request') {
            \local_ustar\grade_promotion::request((int)$USER->id);
            redirect(new moodle_url('/local/ustar/grades.php', ['requested' => 1]));
        }
        if ($action === 'decide') {
            $requestid = required_param('id', PARAM_INT);
            $decision = required_param('decision', PARAM_ALPHA);
            if (!in_array($decision, ['approve', 'return'], true)) {
                throw new invalid_parameter_exception('Выберите допустимое решение по заявке.');
            }
            \local_ustar\grade_promotion::decide(
                $requestid, (int)$USER->id, $decision === 'approve', optional_param('reason', '', PARAM_TEXT)
            );
            redirect(new moodle_url('/local/ustar/grades.php', ['view' => 'team', 'decided' => 1]));
        }
        if ($action === 'assigninitial') {
            require_capability('local/ustar:hrmanage', $context);
            \local_ustar\grade_promotion::assign_initial(
                required_param('userid', PARAM_INT), (int)$USER->id, required_param('reason', PARAM_TEXT)
            );
            redirect(new moodle_url('/local/ustar/grades.php', [
                'view' => 'assignments', 'q' => optional_param('q', '', PARAM_RAW_TRIMMED), 'assigned' => 1,
            ]));
        }
        if ($action === 'correct') {
            require_capability('local/ustar:hrmanage', $context);
            \local_ustar\grade_promotion::correct(required_param('userid', PARAM_INT),
                required_param('gradekey', PARAM_ALPHANUMEXT), required_param('revision', PARAM_INT),
                (int)$USER->id, required_param('reason', PARAM_TEXT));
            redirect(new moodle_url('/local/ustar/grades.php', [
                'view' => 'assignments', 'q' => optional_param('q', '', PARAM_RAW_TRIMMED), 'corrected' => 1,
            ]));
        }
    } catch (\Throwable $e) {
        $notice = $e->getMessage();
    }
}

$current = $view === 'mine' ? \local_ustar\grade_promotion::current((int)$USER->id) : [];
$eligibility = $view === 'mine' ? \local_ustar\grade_promotion::eligibility((int)$USER->id) : [];
$ownrequests = $view === 'mine' ? \local_ustar\grade_promotion::own_requests((int)$USER->id) : [];
$teamrequests = $view === 'team' ? \local_ustar\grade_promotion::pending_for_manager((int)$USER->id) : [];
$query = $view === 'assignments' ? trim(optional_param('q', '', PARAM_RAW_TRIMMED)) : '';
$candidates = [];
if ($view === 'assignments' && core_text::strlen($query) >= 2) {
    $escaped = $DB->sql_like_escape($query);
    $term = '%' . $escaped . '%';
    $typefieldid = (int)$DB->get_field('user_info_field', 'id',
        ['shortname' => \local_ustar\accounts::FIELD]);
    $candidates = $DB->get_records_sql(
        'SELECT u.id, u.firstname, u.lastname, u.email FROM {user} u
          LEFT JOIN {local_ustar_employment} e ON e.userid = u.id
          LEFT JOIN {user_info_data} d ON d.userid = u.id AND d.fieldid = :typefieldid
          WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 1
            AND (e.status IS NULL OR e.status = :active)
            AND (d.data IS NULL OR d.data NOT IN (:service, :test)) AND ('
            . $DB->sql_like('u.firstname', ':firstname', false) . ' OR '
            . $DB->sql_like('u.lastname', ':lastname', false) . ' OR '
            . $DB->sql_like('u.email', ':email', false) . ')
          ORDER BY u.lastname, u.firstname, u.id',
        ['typefieldid' => $typefieldid, 'active' => \local_ustar\employment::ACTIVE,
            'service' => \local_ustar\accounts::TYPE_SERVICE,
            'test' => \local_ustar\accounts::TYPE_TEST,
            'firstname' => $term, 'lastname' => $term, 'email' => $term], 0, 25
    );
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/grades.php', ['view' => $view]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Грейды | USTAR Academy');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/stage6.css'));
echo $OUTPUT->header();
echo html_writer::start_div('u-grades');
echo html_writer::start_tag('header', ['class' => 'u-grades__header']);
echo html_writer::tag('p', 'Развитие · USTAR Академия', ['class' => 'u-grades__eyebrow']);
echo html_writer::tag('h1', $view === 'team' ? 'Согласование грейдов' : ($view === 'assignments' ? 'Назначения грейдов' : 'Грейды'));
echo html_writer::tag('p', $view === 'team'
    ? 'Заявки сотрудников вашей команды. Перед решением проверьте условия перехода и результат обучения.'
    : ($view === 'assignments' ? 'Найдите сотрудника и назначьте начальную ступень с указанием основания.'
        : 'Ступень, условия перехода и история ваших заявок.'), ['class' => 'u-grades__intro']);
echo html_writer::end_tag('header');
if ($notice !== '') { echo $OUTPUT->notification(s($notice), 'notifyproblem'); }
if (optional_param('requested', 0, PARAM_BOOL)) { echo $OUTPUT->notification('Заявка отправлена действующему руководителю.', 'notifysuccess'); }
if (optional_param('decided', 0, PARAM_BOOL)) { echo $OUTPUT->notification('Решение по заявке сохранено.', 'notifysuccess'); }
if (optional_param('assigned', 0, PARAM_BOOL)) { echo $OUTPUT->notification('Начальная ступень назначена.', 'notifysuccess'); }
if (optional_param('corrected', 0, PARAM_BOOL)) { echo $OUTPUT->notification('Коррекция грейда сохранена в истории.', 'notifysuccess'); }

echo html_writer::start_div('u-stage6-tabs');
echo html_writer::tag('a', 'Мой грейд', ['href' => (new moodle_url('/local/ustar/grades.php'))->out(false), 'class' => $view === 'mine' ? 'is-active' : '']);
if ($teamrequests || \local_ustar\organization_model::is_manager((int)$USER->id)) {
    echo html_writer::tag('a', 'Заявки команды', ['href' => (new moodle_url('/local/ustar/grades.php', ['view' => 'team']))->out(false), 'class' => $view === 'team' ? 'is-active' : '']);
}
if ($canassign) {
    echo html_writer::tag('a', 'Назначения', ['href' => (new moodle_url('/local/ustar/grades.php',
        ['view' => 'assignments']))->out(false), 'class' => $view === 'assignments' ? 'is-active' : '']);
}
if (\local_ustar\grade_rules::can_manage((int)$USER->id)) {
    echo html_writer::tag('a', 'Настройка лестниц', [
        'href' => (new moodle_url('/local/ustar/grade_ladders.php'))->out(false),
    ]);
    echo html_writer::tag('a', 'Правила переходов', [
        'href' => (new moodle_url('/local/ustar/grade_rules.php'))->out(false),
    ]);
}
echo html_writer::end_div();

if ($view === 'mine') {
    if (empty($current['enabled'])) {
        echo $OUTPUT->notification('Для вашей должности грейдовая лестница не настроена.', 'notifyinfo');
    } else {
        echo html_writer::tag('h2', 'Текущая ступень: ' . s((string)$current['label']));
        echo html_writer::tag('p', s((string)$eligibility['reason']));
        echo html_writer::start_tag('ul');
        foreach ((array)$eligibility['requirements'] as $requirement) {
            echo html_writer::tag('li',
                (!empty($requirement['complete']) ? '✓ ' : '○ ') . s((string)$requirement['title']));
        }
        echo html_writer::end_tag('ul');
        if (!empty($eligibility['eligible'])) {
            echo html_writer::start_tag('form', ['method' => 'post']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'request']);
            echo html_writer::empty_tag('input', ['type' => 'submit',
                'value' => 'Отправить заявку на «' . s((string)$eligibility['nextlabel']) . '»', 'class' => 'u-btn u-btn--primary']);
            echo html_writer::end_tag('form');
        }
    }
    if ($ownrequests) {
        echo $OUTPUT->heading('Мои заявки', 3);
        echo html_writer::start_tag('ul');
        foreach ($ownrequests as $request) {
            $text = s((string)$request->fromgrade . ' → ' . (string)$request->tograde . ' · ' . (string)$request->status);
            if (!empty($request->decisionreason)) { $text .= ' · ' . s((string)$request->decisionreason); }
            echo html_writer::tag('li', $text);
        }
        echo html_writer::end_tag('ul');
    }
}
if ($view === 'assignments') {
    echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'u-grades__search']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'view', 'value' => 'assignments']);
    echo html_writer::tag('label', 'Имя или почта сотрудника', ['for' => 'grade-search']);
    echo html_writer::empty_tag('input', ['type' => 'search', 'name' => 'q', 'id' => 'grade-search',
        'value' => $query, 'minlength' => 2, 'class' => 'form-control']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Найти', 'class' => 'u-btn']);
    echo html_writer::end_tag('form');
    if ($query !== '' && core_text::strlen($query) < 2) {
        echo $OUTPUT->notification('Для поиска укажите не менее двух символов.', 'notifyinfo');
    } else if ($query !== '' && !$candidates) {
        echo $OUTPUT->notification('Сотрудники не найдены.', 'notifyinfo');
    }
    $shown = 0;
    foreach ($candidates as $candidate) {
        if (!\local_ustar\accounts::is_business_account((int)$candidate->id)
                || !\local_ustar\employment::is_active((int)$candidate->id)) { continue; }
        $shown++;
        $grade = \local_ustar\grade_promotion::current((int)$candidate->id);
        echo html_writer::start_div('u-stage6-card u-grades__request');
        echo html_writer::tag('h2', s(fullname($candidate)));
        echo html_writer::tag('p', s((string)$candidate->email));
        echo html_writer::tag('p', empty($grade['enabled']) ? 'Лестница для должности не настроена'
            : 'Текущая ступень: ' . s((string)$grade['label']));
        $previous = $DB->get_record('local_ustar_employee_grades', ['userid' => (int)$candidate->id],
            'id,positionid,revision,gradekey,ladderversionid', IGNORE_MISSING);
        if ($previous && empty($grade['recorded'])) {
            echo html_writer::tag('p',
                'Есть грейд прежней должности. Перед новым назначением требуется решение о переносе.',
                ['class' => 'u-grades__hint']);
        }
        if (!empty($grade['enabled']) && empty($grade['recorded']) && !$previous) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-grades__assignment']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'assigninitial']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => (int)$candidate->id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'q', 'value' => $query]);
            echo html_writer::tag('label', 'Основание назначения', ['for' => 'grade-assignment-' . (int)$candidate->id]);
            echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'reason',
                'id' => 'grade-assignment-' . (int)$candidate->id, 'required' => 'required', 'class' => 'form-control']);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Назначить начальную ступень',
                'class' => 'u-btn u-btn--primary']);
            echo html_writer::end_tag('form');
        }
        if (!empty($grade['enabled']) && $previous) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-grades__assignment']);
            foreach (['sesskey' => sesskey(), 'action' => 'correct', 'userid' => (int)$candidate->id,
                    'revision' => (int)$previous->revision, 'q' => $query] as $field => $value) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $field, 'value' => $value]);
            }
            $options = [];
            foreach (\local_ustar\career_grades::catalogue_for_position((string)$grade['positionid']) as $step) {
                $options[(string)$step['id']] = (string)$step['name'];
            }
            echo html_writer::tag('label', 'Подтверждённая ступень', ['for' => 'grade-correct-' . (int)$candidate->id]);
            echo html_writer::select($options, 'gradekey', $previous->gradekey, false,
                ['id' => 'grade-correct-' . (int)$candidate->id, 'class' => 'form-select']);
            echo html_writer::tag('label', 'Основание переноса или коррекции',
                ['for' => 'grade-correct-reason-' . (int)$candidate->id]);
            echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'reason',
                'id' => 'grade-correct-reason-' . (int)$candidate->id,
                'required' => 'required', 'class' => 'form-control']);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Сохранить решение HR',
                'class' => 'u-btn']);
            echo html_writer::end_tag('form');
        }
        if ($DB->get_manager()->table_exists(new xmldb_table('local_ustar_hr_actions'))) {
            $events = $DB->get_records_select('local_ustar_hr_actions',
                'targetuserid = :userid AND action IN (:initial, :corrected)',
                ['userid' => (int)$candidate->id, 'initial' => 'grade_initial_assigned',
                    'corrected' => 'grade_corrected'], 'timecreated DESC, id DESC', '*', 0, 5);
            if ($events) {
                echo html_writer::tag('h3', 'История решений');
                echo html_writer::start_tag('ul');
                foreach ($events as $event) {
                    $details = json_decode((string)$event->detailsjson, true) ?: [];
                    echo html_writer::tag('li', s(userdate((int)$event->timecreated)) . ' · '
                        . ($event->action === 'grade_corrected' ? 'Коррекция' : 'Начальное назначение')
                        . ' · ' . s((string)($details['reason'] ?? '')));
                }
                echo html_writer::end_tag('ul');
            }
        }
        echo html_writer::end_div();
    }
    if ($candidates && !$shown) {
        echo $OUTPUT->notification('Действующие сотрудники по запросу не найдены.', 'notifyinfo');
    }
}
if ($view === 'team') {
    if (!$teamrequests) {
        echo $OUTPUT->notification('Нет заявок, где вы являетесь действующим руководителем.', 'notifyinfo');
    }
    foreach ($teamrequests as $request) {
        $employee = $DB->get_record('user', ['id' => (int)$request->userid], 'id,firstname,lastname', IGNORE_MISSING);
        echo html_writer::start_div('u-stage6-card u-grades__request');
        echo html_writer::tag('p', 'Заявка на переход', ['class' => 'u-grades__eyebrow']);
        echo html_writer::tag('h2', $employee ? fullname($employee) : 'Сотрудник #' . (int)$request->userid);
        echo html_writer::tag('p', s((string)$request->fromgrade) . ' → ' . s((string)$request->tograde),
            ['class' => 'u-grades__transition']);
        echo html_writer::start_div('u-grades__actions');
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'decide']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$request->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'decision', 'value' => 'approve']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Согласовать', 'class' => 'u-btn u-btn--primary']);
        echo html_writer::end_tag('form');
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'decide']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (int)$request->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'decision', 'value' => 'return']);
        echo html_writer::tag('label', 'Причина возврата', ['for' => 'grade-reason-' . (int)$request->id]);
        echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'reason',
            'id' => 'grade-reason-' . (int)$request->id, 'required' => 'required', 'class' => 'form-control']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Вернуть', 'class' => 'u-btn']);
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}
echo html_writer::end_div();
echo $OUTPUT->footer();
