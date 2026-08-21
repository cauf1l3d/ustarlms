<?php
require_once(__DIR__ . '/../../config.php');

require_login();

global $USER, $DB;

$context = context_system::instance();
require_capability('local/ustar:use', $context);

$selected = optional_param('id', '', PARAM_ALPHANUMEXT);
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    \local_ustar\view_as::assert_writable();
    $selected = required_param('checklistid', PARAM_ALPHANUMEXT);
    $definition = \local_ustar\checklists::find($selected);
    if (!$definition) {
        throw new moodle_exception('Unknown checklist');
    }

    $answers = [];
    foreach (\local_ustar\checklists::flat_items($definition) as $itemid => $item) {
        $fieldid = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$itemid);
        $answers[$itemid] = [
            'done' => optional_param('done_' . $fieldid, 0, PARAM_BOOL),
            'comment' => optional_param('comment_' . $fieldid, '', PARAM_TEXT),
        ];
    }
    $comment = optional_param('comment', '', PARAM_TEXT);
    $result = \local_ustar\native_data::submit_checklist($selected, $answers, $comment);
}

$payload = \local_ustar\native_data::checklists();
$rows = [];
$current = null;
foreach (($payload['checklists'] ?? []) as $checklist) {
    $checklist['url'] = (new moodle_url('/local/ustar/checklists.php', ['id' => $checklist['id']]))->out(false);
    $today = $checklist['today'] ?? [];
    $checklist['complete'] = ($today['status'] ?? '') === 'completed';
    $checklist['progress'] = !empty($today['total'])
        ? min(100, (int)round(((int)$today['done'] / max(1, (int)$today['total'])) * 100))
        : 0;

    if ($selected === '' && $current === null) {
        $selected = (string)$checklist['id'];
    }
    if ((string)$checklist['id'] === $selected) {
        $existinganswers = [];
        $datekey = (string)($payload['date'] ?? userdate(time(), '%Y-%m-%d'));
        $run = $DB->get_record('local_ustar_check_runs', [
            'checklistkey' => (string)$checklist['id'],
            'userid' => (int)$USER->id,
            'datekey' => $datekey,
        ]);
        if ($run) {
            foreach ($DB->get_records('local_ustar_check_answers', ['runid' => (int)$run->id]) as $answer) {
                $existinganswers[(string)$answer->itemkey] = $answer;
            }
            $checklist['todaycomment'] = (string)$run->comment;
        } else {
            $checklist['todaycomment'] = (string)($today['comment'] ?? '');
        }

        foreach (($checklist['sections'] ?? []) as &$section) {
            foreach (($section['items'] ?? []) as &$item) {
                $item['fieldid'] = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$item['id']);
                $answer = $existinganswers[(string)$item['id']] ?? null;
                $item['checked'] = $answer ? !empty($answer->checked) : false;
                $item['comment'] = $answer ? (string)$answer->comment : '';
            }
            unset($item);
        }
        unset($section);
        $current = $checklist;
    }
    $rows[] = $checklist;
}

$data = [
    'date' => (string)($payload['date'] ?? ''),
    'checklists' => $rows,
    'haschecklists' => !empty($rows),
    'current' => $current,
    'hascurrent' => $current !== null,
    'result' => $result,
    'hasresult' => $result !== null,
    'sesskey' => sesskey(),
    'checkicon' => \local_ustar\ui::icon('check', 'u-feature-icon'),
];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/checklists.php', $selected ? ['id' => $selected] : []));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Чек-листы | USTAR Academy');
$PAGE->set_heading('USTAR Academy');

$output = $PAGE->get_renderer('local_ustar');
echo $output->header();
echo $output->render_from_template('local_ustar/checklists', $data);
echo $output->footer();
