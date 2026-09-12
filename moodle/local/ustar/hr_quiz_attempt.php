<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $USER;

$context = context_system::instance();
require_capability('local/ustar:hrmanage', $context);

$attemptid = required_param('attemptid', PARAM_INT);

/*
 * Configure the USTAR page before doing any grading work so even a runtime
 * exception stays inside the branded HR shell instead of dropping into the
 * legacy Moodle layout.
 */
$PAGE->set_context($context);
$PAGE->set_url(
    new moodle_url(
        '/local/ustar/hr_quiz_attempt.php',
        ['attemptid' => $attemptid]
    )
);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Проверка ответа | USTAR');
$PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(
    new moodle_url('/local/ustar/styles/route_native.css')
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $action = required_param('action', PARAM_ALPHA);

    if ($action === 'gradebatch') {
        $rawmarks = optional_param_array('mark', [], PARAM_RAW_TRIMMED);
        $comments = optional_param_array('comment', [], PARAM_TEXT);
        $grades = [];

        foreach ($rawmarks as $slotkey => $rawmark) {
            $slot = (int)$slotkey;
            $value = str_replace(',', '.', trim((string)$rawmark));

            if ($slot <= 0 || $value === '') {
                continue;
            }

            if (!is_numeric($value)) {
                throw new invalid_parameter_exception(
                    'Одна из оценок имеет неверный формат.'
                );
            }

            $grades[$slot] = [
                'mark' => (float)$value,
                'comment' => (string)($comments[$slotkey] ?? ''),
            ];
        }

        \local_ustar\route_quiz_grading::manual_grade_batch(
            $attemptid,
            $grades,
            (int)$USER->id,
            true
        );

        redirect(
            new moodle_url(
                '/local/ustar/hr_quiz_attempt.php',
                [
                    'attemptid' => $attemptid,
                    'graded' => 1,
                    'notificationpending' => (int)\local_ustar\route_quiz_grading::notification_failed(),
                ]
            )
        );
    }

    /* Backward compatibility for any stale single-question form. */
    if ($action === 'grade') {
        $slot = required_param('slot', PARAM_INT);
        $mark = required_param('mark', PARAM_FLOAT);
        $comment = optional_param('comment', '', PARAM_TEXT);

        \local_ustar\route_quiz_grading::manual_grade(
            $attemptid,
            $slot,
            $mark,
            $comment,
            (int)$USER->id
        );

        redirect(
            new moodle_url(
                '/local/ustar/hr_quiz_attempt.php',
                [
                    'attemptid' => $attemptid,
                    'graded' => 1,
                    'notificationpending' => (int)\local_ustar\route_quiz_grading::notification_failed(),
                ]
            )
        );
    }
}

$data = \local_ustar\route_quiz_grading::detail($attemptid);
$data['sesskey'] = sesskey();
$data['backurl'] = (
    new moodle_url('/local/ustar/hr_quiz_grading.php')
)->out(false);
$data['graded'] = optional_param('graded', 0, PARAM_BOOL);
$data['notificationpending'] = optional_param('notificationpending', 0, PARAM_BOOL);

$output = $PAGE->get_renderer('local_ustar');

echo $output->header();
echo $output->render_from_template(
    'local_ustar/hr_quiz_attempt',
    $data
);
echo $output->footer();
