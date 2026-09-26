<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/ustar:use', $context);
$view = optional_param('view', 'mine', PARAM_ALPHA) === 'team' ? 'team' : 'mine';
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
    } catch (\Throwable $e) {
        $notice = $e->getMessage();
    }
}

try {
    \local_ustar\grade_promotion::reconcile((int)$USER->id);
} catch (\Throwable $e) {
    debugging('USTAR grade reconciliation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
}
$current = \local_ustar\grade_promotion::current((int)$USER->id);
$eligibility = \local_ustar\grade_promotion::eligibility((int)$USER->id);
$ownrequests = \local_ustar\grade_promotion::own_requests((int)$USER->id);
$teamrequests = \local_ustar\grade_promotion::pending_for_manager((int)$USER->id);

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
echo html_writer::tag('h1', $view === 'team' ? 'Согласование грейдов' : 'Грейды');
echo html_writer::tag('p', $view === 'team'
    ? 'Заявки сотрудников вашей команды. Перед решением проверьте условия перехода и результат обучения.'
    : 'Ступень, условия перехода и история ваших заявок.', ['class' => 'u-grades__intro']);
echo html_writer::end_tag('header');
if ($notice !== '') { echo $OUTPUT->notification(s($notice), 'notifyproblem'); }
if (optional_param('requested', 0, PARAM_BOOL)) { echo $OUTPUT->notification('Заявка отправлена действующему руководителю.', 'notifysuccess'); }
if (optional_param('decided', 0, PARAM_BOOL)) { echo $OUTPUT->notification('Решение по заявке сохранено.', 'notifysuccess'); }

echo html_writer::start_div('u-stage6-tabs');
echo html_writer::tag('a', 'Мой грейд', ['href' => (new moodle_url('/local/ustar/grades.php'))->out(false), 'class' => $view === 'mine' ? 'is-active' : '']);
if ($teamrequests || \local_ustar\organization_model::is_manager((int)$USER->id)) {
    echo html_writer::tag('a', 'Заявки команды', ['href' => (new moodle_url('/local/ustar/grades.php', ['view' => 'team']))->out(false), 'class' => $view === 'team' ? 'is-active' : '']);
}
if (\local_ustar\grade_rules::can_manage((int)$USER->id)) {
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
