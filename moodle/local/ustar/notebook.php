<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$actor = (int)$USER->id;
$context = context_system::instance();
if (!\local_ustar\employment::is_active($actor)) {
    throw new required_capability_exception($context, 'local/ustar:use', 'nopermissions', '');
}
\local_ustar\view_as::assert_writable();
if (!\local_ustar\capabilities::has($actor, \local_ustar\capabilities::COMPANY_READ)) {
    require_capability('local/ustar:use', $context);
}
$notice = '';
$input = ['title'=>'', 'body'=>''];
$noteid = max(0, optional_param('noteid', optional_param('taskid', optional_param('id',0,PARAM_INT),PARAM_INT),PARAM_INT));
$edit = optional_param('edit',0,PARAM_BOOL) || $noteid;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action',PARAM_ALPHA);
    try {
        if ($action === 'layout') {
            \local_ustar\notebook_layout::save(required_param('noteid',PARAM_INT),$actor,
                required_param('x',PARAM_INT),required_param('y',PARAM_INT));
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok'=>true]); exit;
        }
        $input = ['title'=>optional_param('title','',PARAM_TEXT),
            'body'=>optional_param('body',optional_param('description','',PARAM_TEXT),PARAM_TEXT)];
        if ($action === 'create' || $action === 'note') {
            \local_ustar\learning_tasks::create_note($actor,$input['title'],$input['body'],\local_ustar\task_files::uploaded());
        } else if ($action === 'edit' || $action === 'editnote') {
            \local_ustar\learning_tasks::update_note($noteid,$actor,required_param('version',PARAM_INT),
                $input['title'],$input['body'],\local_ustar\task_files::uploaded());
        } else if ($action === 'delete' || $action === 'deletenote') {
            if (!required_param('confirmdelete',PARAM_BOOL)) throw new invalid_parameter_exception('Подтвердите удаление заметки.');
            \local_ustar\learning_tasks::delete_note($noteid,$actor,required_param('version',PARAM_INT));
            unset_user_preference('ustar_notepos_'.$noteid,$actor);
        } else if ($action === 'transition') {
            $note = \local_ustar\learning_tasks::view($noteid,$actor);
            if (!$note['private']) throw new invalid_parameter_exception('Это не личная заметка.');
            \local_ustar\learning_tasks::transition($noteid,$actor,
                required_param('taskaction',PARAM_ALPHANUMEXT),required_param('version',PARAM_INT));
        } else {
            throw new invalid_parameter_exception('Неизвестное действие блокнота.');
        }
        redirect(new moodle_url('/local/ustar/notebook.php',['saved'=>1]));
    } catch (Throwable $e) {
        if ($action === 'layout') {
            http_response_code(400); header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store'); echo json_encode(['ok'=>false]); exit;
        }
        $notice = $e instanceof moodle_exception ? $e->getMessage() : 'Не удалось сохранить заметку. Повторите попытку.';
        $edit = true;
    }
}
$q = trim(optional_param('q','',PARAM_TEXT));
$page = max(0,min(10000,optional_param('pageno',0,PARAM_INT)));
$notes = \local_ustar\learning_tasks::notes_for_owner($actor,$page,'all',$q);
$positions = \local_ustar\notebook_layout::positions($actor,$notes);
$total = \local_ustar\learning_tasks::count_for($actor,'notebook','all',$q);
foreach ($notes as &$note) {
    $note['x']=$positions[$note['id']]['x']; $note['y']=$positions[$note['id']]['y'];
    $note['url']=(new moodle_url('/local/ustar/notebook.php',['noteid'=>$note['id']]))->out(false);
    $note['preview']=\core_text::substr($note['descriptionplain'],0,500);
    $note['date']=userdate($note['timecreated'],'%d.%m.%Y');
}
unset($note);
$selected = null;
if ($noteid) {
    $selected=\local_ustar\learning_tasks::view($noteid,$actor);
    if (!$selected['private']) throw new required_capability_exception($context,'local/ustar:use','nopermissions','');
}
if ($selected && $notice === '') $input=['title'=>$selected['titleplain'],'body'=>$selected['descriptionplain']];
$data=['notes'=>$notes,'hasnotes'=>(bool)$notes,'total'=>$total,'q'=>$q,'notice'=>$notice,
    'saved'=>optional_param('saved',0,PARAM_BOOL),'edit'=>(bool)$edit,'editing'=>(bool)$selected,'noteid'=>$noteid,
    'version'=>$selected['version'] ?? 0,'title'=>$input['title'],'body'=>$input['body'],'sesskey'=>sesskey(),
    'files'=>$selected ? \local_ustar\task_files::list_for($noteid,$actor) : [],
    'uploadlimit'=>display_size(\local_ustar\task_files::max_file_bytes()),
    'url'=>(new moodle_url('/local/ustar/notebook.php'))->out(false),
    'newurl'=>(new moodle_url('/local/ustar/notebook.php',['edit'=>1]))->out(false),
    'tasksurl'=>(new moodle_url('/local/ustar/tasks.php'))->out(false),
    'prevurl'=>$page ? (new moodle_url('/local/ustar/notebook.php',['pageno'=>$page-1,'q'=>$q]))->out(false) : '',
    'nexturl'=>($page+1)*25<$total ? (new moodle_url('/local/ustar/notebook.php',['pageno'=>$page+1,'q'=>$q]))->out(false) : '',
];
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ustar/notebook.php'));
$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Личный блокнот · USTAR');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/task_workspace.css',['v'=>'20260930-ux']));
$PAGE->requires->css(new moodle_url('/local/ustar/styles/notebook.css',['v'=>'20260930']));
$PAGE->requires->js(new moodle_url('/local/ustar/js/notebook.js',['v'=>'20260930']));
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_ustar/notebook',$data);
echo $OUTPUT->footer();
