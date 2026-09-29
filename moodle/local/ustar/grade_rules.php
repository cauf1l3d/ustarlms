<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
if (!\local_ustar\grade_rules::can_manage((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/ustar:hrmanage', 'nopermissions', '');
}
\local_ustar\view_as::assert_writable();

$positions = \local_ustar\grade_rules::position_options();
$positionmap = array_column($positions, null, 'id');
$requestedpositionid = optional_param('positionid', '', PARAM_ALPHANUMEXT);
$notice = '';

if ($requestedpositionid !== '' && !isset($positionmap[$requestedpositionid])) {
    $notice = 'Для выбранной должности не привязана опубликованная лестница грейдов. '
        . 'Сначала откройте «Настройка лестниц», опубликуйте лестницу и привяжите её к должности.';
    $positionid = '';
} else {
    $positionid = $requestedpositionid !== ''
        ? $requestedpositionid
        : (string)($positions[0]['id'] ?? '');
}

$transitions = $positionid !== ''
    ? \local_ustar\grade_rules::transitions($positionid)
    : [];

$requestedfromgrade = optional_param('fromgrade', '', PARAM_ALPHANUMEXT);
$transitionmap = array_column($transitions, null, 'fromgrade');
if ($requestedfromgrade !== '' && isset($transitionmap[$requestedfromgrade])) {
    $fromgrade = $requestedfromgrade;
} else {
    // A position change may arrive with a stale transition from the previous
    // ladder. Never throw from GET navigation: select the first valid
    // transition of the newly selected position instead.
    $fromgrade = (string)($transitions[0]['fromgrade'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        $rule = \local_ustar\grade_rules::publish_transition(
            required_param('positionid', PARAM_ALPHANUMEXT),
            required_param('fromgrade', PARAM_ALPHANUMEXT),
            optional_param_array('pointids', [], PARAM_INT),
            (int)$USER->id,
            required_param('submissionmode', PARAM_ALPHA),
            optional_param('triggerpointid', 0, PARAM_INT)
        );
        redirect(new moodle_url('/local/ustar/grade_rules.php', [
            'positionid' => (string)$rule->positionid,
            'fromgrade' => (string)$rule->fromgrade,
            'saved' => 1,
        ]));
    } catch (Throwable $e) {
        $notice = $e->getMessage();
    }
}

$editor = ($positionid !== '' && $fromgrade !== '')
    ? \local_ustar\grade_rules::editor($positionid, $fromgrade)
    : ['transition' => null, 'points' => [], 'current' => null];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/grade_rules.php', [
    'positionid' => $positionid,
    'fromgrade' => $fromgrade,
]));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Правила грейдов | USTAR Academy');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/stage6.css'));

echo $OUTPUT->header();
echo $OUTPUT->heading('Правила перехода между грейдами');
echo html_writer::tag(
    'p',
    'Переходы берутся только из опубликованной лестницы, которая привязана к выбранной должности. '
    . 'Каждый переход публикуется отдельной неизменяемой версией. '
    . 'Выберите этапы маршрута, подтверждающие именно этот переход, и режим отправки заявки.'
);
if ($notice !== '') {
    echo $OUTPUT->notification(s($notice), 'notifyproblem');
}
if (optional_param('saved', 0, PARAM_BOOL)) {
    echo $OUTPUT->notification('Новая версия правила опубликована.', 'notifysuccess');
}

if (!$positions) {
    echo $OUTPUT->notification(
        'Нет должностей с привязанной опубликованной лестницей. '
            . 'Сначала создайте и опубликуйте лестницу, затем привяжите её к должности.',
        'notifywarning'
    );
    echo html_writer::tag(
        'p',
        html_writer::link(
            new moodle_url('/local/ustar/grade_ladders.php'),
            'Перейти к настройке лестниц',
            ['class' => 'u-btn u-btn--primary']
        )
    );
} else {
    echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'u-grades__rule-picker']);
    $positionoptions = [];
    foreach ($positions as $position) {
        $positionoptions[(string)$position['id']] = (string)$position['name'];
    }
    $transitionoptions = [];
    foreach ($transitions as $transition) {
        $transitionoptions[(string)$transition['fromgrade']] =
            (string)$transition['fromlabel'] . ' → ' . (string)$transition['tolabel'];
    }
    echo html_writer::tag('label', 'Должность', ['for' => 'grade-rule-position']);
    echo html_writer::select(
        $positionoptions,
        'positionid',
        $positionid,
        false,
        [
            'id' => 'grade-rule-position',
            'class' => 'form-select',
            'onchange' => 'this.form.elements.fromgrade.value="";this.form.submit();',
        ]
    );
    echo html_writer::tag('label', 'Переход', ['for' => 'grade-rule-transition']);
    echo html_writer::select(
        $transitionoptions,
        'fromgrade',
        $fromgrade,
        false,
        [
            'id' => 'grade-rule-transition',
            'class' => 'form-select',
            'disabled' => !$transitionoptions ? 'disabled' : null,
        ]
    );
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => 'Показать',
        'class' => 'u-btn',
        'disabled' => !$transitionoptions ? 'disabled' : null,
    ]);
    echo html_writer::end_tag('form');

    if (!$transitions && $positionid !== '') {
        echo $OUTPUT->notification(
            'У привязанной лестницы меньше двух корректных ступеней — переходы создать нельзя.',
            'notifyproblem'
        );
    }
}

if (!empty($editor['transition'])) {
    $transition = $editor['transition'];
    echo $OUTPUT->heading(
        s((string)$transition['fromlabel']) . ' → ' . s((string)$transition['tolabel']),
        3
    );

    if (!empty($transition['criteria'])) {
        echo html_writer::tag(
            'p',
            'Критерии исходного документа приведены справочно; '
            . 'автоматически проверяются только выбранные Evidence:'
        );
        echo html_writer::start_tag('ul');
        foreach ($transition['criteria'] as $criterion) {
            echo html_writer::tag('li', s((string)($criterion['text'] ?? '')));
        }
        echo html_writer::end_tag('ul');
    }

    if (!empty($editor['current'])) {
        echo $OUTPUT->notification(
            'Текущая версия правила: v' . (int)$editor['current']->versionno
                . ' · хэш правила ' . s(substr((string)$editor['current']->rulehash, 0, 12)),
            'notifyinfo'
        );
    }

    $submission = $editor['submission'] ?? [
        'mode' => \local_ustar\grade_rules::MODE_MANUAL,
        'triggerpointid' => 0,
        'triggerversionid' => 0,
        'triggertitle' => '',
    ];
    echo html_writer::tag(
        'p',
        $submission['mode'] === \local_ustar\grade_rules::MODE_AUTO
            ? 'Текущий режим: автоматическая отправка после контрольного шага «'
                . s((string)$submission['triggertitle']) . '» и выполнения всех условий.'
            : 'Текущий режим: сотрудник отправляет заявку вручную после выполнения условий.',
        ['class' => 'alert alert-info']
    );

    if (empty($editor['points'])) {
        echo $OUTPUT->notification(
            'Для должности нет опубликованных точек маршрута. Правило публиковать нельзя.',
            'notifywarning'
        );
    } else {
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey(),
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'positionid', 'value' => $positionid,
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'fromgrade', 'value' => $fromgrade,
        ]);

        echo html_writer::tag('label', 'Режим отправки заявки');
        echo html_writer::select([
            \local_ustar\grade_rules::MODE_MANUAL => 'Ручной — сотрудник нажимает кнопку',
            \local_ustar\grade_rules::MODE_AUTO => 'Автоматический — после контрольного шага',
        ], 'submissionmode', (string)$submission['mode'], false, ['class' => 'form-select']);

        $triggeroptions = [0 => '— Контрольный шаг не выбран —'];
        foreach ($editor['points'] as $point) {
            $triggeroptions[(int)$point['pointid']] =
                (string)$point['title'] . ' · version #' . (int)$point['versionid'];
        }
        echo html_writer::tag('label', 'Контрольный шаг для автоматического режима');
        echo html_writer::select(
            $triggeroptions,
            'triggerpointid',
            (int)$submission['triggerpointid'],
            false,
            ['class' => 'form-select']
        );
        echo html_writer::tag(
            'p',
            'В автоматическом режиме контрольный шаг должен входить в выбранные условия ниже. '
                . 'Заявка не обходит остальные критерии: grade_promotion проверит их тем же способом, что и при ручной отправке.',
            ['class' => 'text-muted']
        );

        foreach ($editor['points'] as $point) {
            $id = 'grade_point_' . (int)$point['pointid'];
            echo html_writer::start_tag('label', [
                'for' => $id, 'style' => 'display:block;margin:.5rem 0',
            ]);
            $attrs = [
                'id' => $id,
                'type' => 'checkbox',
                'name' => 'pointids[]',
                'value' => (int)$point['pointid'],
            ];
            if (!empty($point['selected'])) {
                $attrs['checked'] = 'checked';
            }
            echo html_writer::empty_tag('input', $attrs);
            echo ' ' . s((string)$point['title']) . ' · version #' . (int)$point['versionid'];
            echo html_writer::end_tag('label');
        }

        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => 'Опубликовать новую версию правила',
            'class' => 'btn btn-primary',
        ]);
        echo html_writer::end_tag('form');
    }
}

echo html_writer::tag(
    'p',
    html_writer::link(new moodle_url('/local/ustar/grades.php'), 'Вернуться к грейдам')
);
echo $OUTPUT->footer();
