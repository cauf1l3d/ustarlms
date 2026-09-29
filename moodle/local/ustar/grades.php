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
                'view' => 'assignments',
                'department' => optional_param('department', '', PARAM_ALPHANUMEXT),
                'position' => optional_param('position', '', PARAM_ALPHANUMEXT),
                'employeeid' => optional_param('employeeid', 0, PARAM_INT),
                'assigned' => 1,
            ]));
        }
        if ($action === 'correct') {
            require_capability('local/ustar:hrmanage', $context);
            \local_ustar\grade_promotion::correct(required_param('userid', PARAM_INT),
                required_param('gradekey', PARAM_ALPHANUMEXT), required_param('revision', PARAM_INT),
                (int)$USER->id, required_param('reason', PARAM_TEXT));
            redirect(new moodle_url('/local/ustar/grades.php', [
                'view' => 'assignments',
                'department' => optional_param('department', '', PARAM_ALPHANUMEXT),
                'position' => optional_param('position', '', PARAM_ALPHANUMEXT),
                'employeeid' => optional_param('employeeid', 0, PARAM_INT),
                'corrected' => 1,
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
$departmentfilter = $view === 'assignments'
    ? optional_param('department', '', PARAM_ALPHANUMEXT) : '';
$positionfilter = $view === 'assignments'
    ? optional_param('position', '', PARAM_ALPHANUMEXT) : '';
$employeeid = $view === 'assignments'
    ? optional_param('employeeid', 0, PARAM_INT) : 0;

$assignmentdepartments = [];
$assignmentpositions = [];
$assignmentemployees = [];
$candidates = [];

if ($view === 'assignments') {
    echo html_writer::start_div('u-stage6-card u-grades__assignment-picker');
    echo html_writer::tag('h2', 'Выбор сотрудника');
    echo html_writer::tag(
        'p',
        'Фильтры связаны между собой: подразделение → должность → сотрудник. '
            . 'Список сотрудников загружается только для выбранной должности.',
        ['class' => 'u-grades__hint']
    );

    echo html_writer::start_tag('form', [
        'method' => 'get',
        'class' => 'u-grades__assignment-filters',
        'id' => 'grade-assignment-filters',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'view', 'value' => 'assignments']);

    echo html_writer::start_div('u-grades__filter-field');
    echo html_writer::tag('label', '1. Подразделение', ['for' => 'grade-department']);
    echo html_writer::select(
        $assignmentdepartments,
        'department',
        $departmentfilter,
        false,
        [
            'id' => 'grade-department',
            'class' => 'form-select',
            'onchange' => 'this.form.elements.position.value="";this.form.elements.employeeid.value="0";this.form.submit();',
        ]
    );
    echo html_writer::end_div();

    echo html_writer::start_div('u-grades__filter-field');
    echo html_writer::tag('label', '2. Должность', ['for' => 'grade-position']);
    echo html_writer::select(
        $assignmentpositions,
        'position',
        $positionfilter,
        false,
        [
            'id' => 'grade-position',
            'class' => 'form-select',
            'disabled' => $departmentfilter === '' ? 'disabled' : null,
            'onchange' => 'this.form.elements.employeeid.value="0";this.form.submit();',
        ]
    );
    echo html_writer::end_div();

    echo html_writer::start_div('u-grades__filter-field');
    echo html_writer::tag('label', '3. Сотрудник', ['for' => 'grade-employee']);
    echo html_writer::select(
        $assignmentemployees,
        'employeeid',
        $employeeid,
        false,
        [
            'id' => 'grade-employee',
            'class' => 'form-select',
            'disabled' => $positionfilter === '' ? 'disabled' : null,
            'onchange' => 'this.form.submit();',
        ]
    );
    echo html_writer::end_div();

    echo html_writer::noscript(
        html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => 'Показать',
            'class' => 'u-btn',
        ])
    );
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

    if ($positionfilter !== '' && count($assignmentemployees) <= 1) {
        echo $OUTPUT->notification(
            'Для выбранной должности нет действующих сотрудников в канонической оргструктуре.',
            'notifyinfo'
        );
    }

    foreach ($candidates as $candidate) {
        $grade = \local_ustar\grade_promotion::current((int)$candidate->id);
        $previous = $DB->get_record(
            'local_ustar_employee_grades',
            ['userid' => (int)$candidate->id],
            'id,positionid,revision,gradekey,ladderversionid,source,timemodified',
            IGNORE_MISSING
        );

        echo html_writer::start_div('u-stage6-card u-grades__request u-grades__assignment-card');
        echo html_writer::tag('p', 'Карточка назначения', ['class' => 'u-grades__eyebrow']);
        echo html_writer::tag('h2', s(fullname($candidate)));
        echo html_writer::tag('p', s((string)$candidate->email), ['class' => 'u-grades__hint']);

        if (empty($grade['enabled'])) {
            echo $OUTPUT->notification(
                'Для должности сотрудника не привязана опубликованная лестница грейдов.',
                'notifyinfo'
            );
        } else {
            $binding = \local_ustar\grade_ladders::binding((string)$grade['positionid']);
            $version = $binding && !empty($binding->ladderversionid)
                ? $DB->get_record(
                    'local_ustar_grade_ladder_ver',
                    ['id' => (int)$binding->ladderversionid],
                    'id,versionno,gradehash',
                    IGNORE_MISSING
                )
                : null;

            echo html_writer::start_div('u-grades__assignment-summary');
            echo html_writer::tag(
                'div',
                '<span>Текущая ступень</span><strong>' . s((string)$grade['label']) . '</strong>'
            );
            echo html_writer::tag(
                'div',
                '<span>Лестница должности</span><strong>'
                    . ($version
                        ? 'Версия ' . (int)$version->versionno
                        : 'Не привязана')
                    . '</strong>'
            );
            echo html_writer::tag(
                'div',
                '<span>Состояние назначения</span><strong>'
                    . ($previous ? 'Назначено' : 'Требует назначения')
                    . '</strong>'
            );
            echo html_writer::end_div();
        }

        if ($previous && empty($grade['recorded'])) {
            echo html_writer::tag(
                'p',
                'Есть грейд прежней должности. Нужна явная HR-коррекция; автоматически такой грейд не переносится.',
                ['class' => 'u-grades__hint']
            );
        }

        if (!empty($grade['enabled']) && empty($grade['recorded']) && !$previous) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-grades__assignment']);
            foreach ([
                'sesskey' => sesskey(),
                'action' => 'assigninitial',
                'userid' => (int)$candidate->id,
                'department' => $departmentfilter,
                'position' => $positionfilter,
                'employeeid' => (int)$candidate->id,
            ] as $field => $value) {
                echo html_writer::empty_tag('input', [
                    'type' => 'hidden',
                    'name' => $field,
                    'value' => $value,
                ]);
            }
            echo html_writer::tag(
                'p',
                'Автоматическое назначение отсутствует для этой ранее созданной учётной записи. '
                    . 'Можно назначить стартовую ступень вручную с фиксацией основания.',
                ['class' => 'u-grades__hint']
            );
            echo html_writer::tag(
                'label',
                'Основание назначения',
                ['for' => 'grade-assignment-' . (int)$candidate->id]
            );
            echo html_writer::empty_tag('input', [
                'type' => 'text',
                'name' => 'reason',
                'id' => 'grade-assignment-' . (int)$candidate->id,
                'required' => 'required',
                'class' => 'form-control',
            ]);
            echo html_writer::empty_tag('input', [
                'type' => 'submit',
                'value' => 'Назначить стартовый грейд',
                'class' => 'u-btn u-btn--primary',
            ]);
            echo html_writer::end_tag('form');
        }

        if (!empty($grade['enabled']) && $previous) {
            echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'u-grades__assignment']);
            foreach ([
                'sesskey' => sesskey(),
                'action' => 'correct',
                'userid' => (int)$candidate->id,
                'revision' => (int)$previous->revision,
                'department' => $departmentfilter,
                'position' => $positionfilter,
                'employeeid' => (int)$candidate->id,
            ] as $field => $value) {
                echo html_writer::empty_tag('input', [
                    'type' => 'hidden',
                    'name' => $field,
                    'value' => $value,
                ]);
            }

            $options = [];
            foreach (\local_ustar\career_grades::catalogue_for_position((string)$grade['positionid']) as $step) {
                $options[(string)$step['id']] = (string)$step['name'];
            }

            echo html_writer::tag(
                'h3',
                'Редактировать назначение'
            );
            echo html_writer::tag(
                'label',
                'Подтверждённая ступень',
                ['for' => 'grade-correct-' . (int)$candidate->id]
            );
            echo html_writer::select(
                $options,
                'gradekey',
                $previous->gradekey,
                false,
                [
                    'id' => 'grade-correct-' . (int)$candidate->id,
                    'class' => 'form-select',
                ]
            );
            echo html_writer::tag(
                'label',
                'Основание изменения',
                ['for' => 'grade-correct-reason-' . (int)$candidate->id]
            );
            echo html_writer::empty_tag('input', [
                'type' => 'text',
                'name' => 'reason',
                'id' => 'grade-correct-reason-' . (int)$candidate->id,
                'required' => 'required',
                'class' => 'form-control',
                'placeholder' => 'Например: перенос после проверки HR, исправление ошибочного назначения',
            ]);
            echo html_writer::empty_tag('input', [
                'type' => 'submit',
                'value' => 'Сохранить назначение',
                'class' => 'u-btn u-btn--primary',
            ]);
            echo html_writer::end_tag('form');
        }

        if ($DB->get_manager()->table_exists(new xmldb_table('local_ustar_hr_actions'))) {
            $events = $DB->get_records_select(
                'local_ustar_hr_actions',
                'targetuserid = :userid AND action IN (:initial, :corrected)',
                [
                    'userid' => (int)$candidate->id,
                    'initial' => 'grade_initial_assigned',
                    'corrected' => 'grade_corrected',
                ],
                'timecreated DESC, id DESC',
                '*',
                0,
                5
            );
            if ($events) {
                echo html_writer::tag('h3', 'История решений');
                echo html_writer::start_tag('ul', ['class' => 'u-grades__history']);
                foreach ($events as $event) {
                    $details = json_decode((string)$event->detailsjson, true) ?: [];
                    echo html_writer::tag(
                        'li',
                        s(userdate((int)$event->timecreated))
                            . ' · '
                            . ($event->action === 'grade_corrected' ? 'Коррекция' : 'Начальное назначение')
                            . ' · '
                            . s((string)($details['reason'] ?? ''))
                    );
                }
                echo html_writer::end_tag('ul');
            }
        }

        echo html_writer::end_div();
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
        $display = \local_ustar\grade_promotion::request_display($request);
        echo html_writer::tag('p', s($display['fromlabel']) . ' → ' . s($display['tolabel']),
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
