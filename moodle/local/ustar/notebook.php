<?php
require_once(__DIR__ . '/../../config.php');
require_login();
use local_ustar\{notebook_board, learning_tasks, task_files};
$actor = (int)$USER->id;
$context = context_system::instance();
notebook_board::actor($actor);
require_capability('local/ustar:use', $context);
$notice = '';
$input = [];
$page = max(0, min(100000, optional_param('pageno', 0, PARAM_INT)));
$query = optional_param('q', '', PARAM_TEXT);
$filter = optional_param('filter', 'all', PARAM_ALPHA);
if (!in_array($filter, ['all', 'active', 'done'], true)) { $filter = 'all'; }
$url = new moodle_url('/local/ustar/notebook.php', ['pageno' => $page, 'q' => $query, 'filter' => $filter]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = required_param('action', PARAM_ALPHA);
    if (in_array($action, ['move', 'board'], true)) {
        // JSON stays separate from Moodle's page/redirect output.
        try {
            require_sesskey();
            if ($action === 'board') {
                $json = required_param('patch', PARAM_RAW);
                if (strlen($json) > 250000) { throw new invalid_parameter_exception('Слишком большой размер доски.'); }
                $patch = json_decode($json, true);
                if (!is_array($patch)) { throw new invalid_parameter_exception('Некорректные данные доски.'); }
                $layout = notebook_board::update($actor, required_param('revision', PARAM_INT), $patch);
            } else {
                $layout = notebook_board::move($actor, required_param('id', PARAM_INT), required_param('revision', PARAM_INT),
                    required_param('x', PARAM_INT), required_param('y', PARAM_INT), required_param('color', PARAM_ALPHA));
            }
            $result = ['ok' => true, 'revision' => $layout['revision']];
        } catch (Throwable $e) { http_response_code(400); $result = ['ok' => false, 'error' => $e->getMessage()]; }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    require_sesskey();
    try {
        $id = optional_param('id', 0, PARAM_INT);
        $version = optional_param('version', 0, PARAM_INT);
        if ($action === 'create') {
            learning_tasks::create_note($actor, required_param('title', PARAM_TEXT), optional_param('description', '', PARAM_TEXT), task_files::uploaded());
        } else {
            $note = learning_tasks::view($id, $actor);
            if (!$note['private']) { throw new invalid_parameter_exception('Это не личная заметка.'); }
            if ($action === 'edit') {
                learning_tasks::update_note($id, $actor, $version, required_param('title', PARAM_TEXT),
                    optional_param('description', '', PARAM_TEXT), task_files::uploaded());
            } else if ($action === 'complete') {
                learning_tasks::transition($id, $actor, 'complete', $version);
            } else if ($action === 'delete' && required_param('confirmdelete', PARAM_BOOL)) {
                learning_tasks::delete_note($id, $actor, $version);
            } else { throw new invalid_parameter_exception('Неизвестное действие блокнота.'); }
        }
        redirect($url, 'Изменения сохранены.');
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $input = ['action' => $action, 'id' => $id, 'title' => optional_param('title', '', PARAM_TEXT),
            'description' => optional_param('description', '', PARAM_TEXT)];
    }
}
$layout = notebook_board::layout($actor);
$notes = learning_tasks::notes_for_owner($actor, $page, $filter, $query);
foreach ($notes as $i => &$note) {
    $position = $layout['items'][$note['id']] ?? ['x' => ($i % 3) * 340 + 24, 'y' => intdiv($i, 3) * 340 + 24, 'color' => 'neutral'];
    $note['x'] = max(0, min(5000, (int)($position['x'] ?? 0)));
    $note['y'] = max(0, min(5000, (int)($position['y'] ?? 0)));
    $note['color'] = in_array($position['color'] ?? '', ['neutral','yellow','blue','green','pink','purple'], true) ? $position['color'] : 'neutral';
    $note['width'] = max(240, min(1200, (int)($position['width'] ?? 310)));
    $note['height'] = max(180, min(1200, (int)($position['height'] ?? 320)));
    $note['frame'] = $position['frame'] ?? '';
    $note['open'] = $note['status'] === 'open';
    $note['files'] = task_files::list_for($note['id'], $actor);
    $note['editopen'] = ($input['id'] ?? 0) === $note['id'];
    if ($note['editopen']) { $note['titleplain'] = $input['title']; $note['descriptionplain'] = $input['description']; }
}
unset($note);
$total = learning_tasks::count_for($actor, 'notebook', $filter, $query);
$data = ['notes' => $notes, 'hasnotes' => !empty($notes), 'total' => $total, 'revision' => $layout['revision'],
    'boardjson' => json_encode(['frames' => $layout['frames'], 'links' => $layout['links'], 'background' => $layout['background']]),
    'sesskey' => sesskey(), 'url' => $url->out(false), 'q' => $query, 'filter' => $filter,
    'all' => $filter === 'all', 'active' => $filter === 'active', 'done' => $filter === 'done',
    'previous' => $page ? (new moodle_url($url, ['pageno' => $page - 1]))->out(false) : '',
    'next' => ($page + 1) * 25 < $total ? (new moodle_url($url, ['pageno' => $page + 1]))->out(false) : '',
    'page' => $page + 1, 'tasksurl' => (new moodle_url('/local/ustar/tasks.php'))->out(false),
    'notice' => $notice, 'createtitle' => ($input['action'] ?? '') === 'create' ? $input['title'] : '',
    'createbody' => ($input['action'] ?? '') === 'create' ? $input['description'] : '',
    'createopen' => ($input['action'] ?? '') === 'create'];
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Личный блокнот | USTAR');
$PAGE->set_heading('Личный блокнот');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/task_workspace.css', ['v' => '20260930-ux5']));
$PAGE->requires->css(new moodle_url('/local/ustar/styles/notebook.css', ['v' => '20260930-ux5']));
$PAGE->requires->js(new moodle_url('/local/ustar/notebook.js', ['v' => '20260930-ux5']));
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ustar/notebook', $data);
echo $OUTPUT->footer();
