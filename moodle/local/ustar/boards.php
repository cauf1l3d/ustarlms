<?php
require_once(__DIR__ . '/../../config.php'); require_login(); global $USER;
$context=context_system::instance(); require_capability('local/ustar:use',$context);
if (optional_param('create',0,PARAM_BOOL)) { require_sesskey(); $id=\local_ustar\boards::create((int)$USER->id); redirect(new moodle_url('/local/ustar/boards.php',['id'=>$id])); }
$id=optional_param('id',0,PARAM_INT); $board=$id?\local_ustar\boards::get_for_user($id,(int)$USER->id):null;
$data=['boards'=>\local_ustar\boards::list_for_user((int)$USER->id),'hasboards'=>!empty(\local_ustar\boards::list_for_user((int)$USER->id)),
 'createurl'=>(new moodle_url('/local/ustar/boards.php',['create'=>1,'sesskey'=>sesskey()]))->out(false),
 'board'=>$board?['id'=>(int)$board->id,'title'=>format_string($board->title),'json'=>(string)$board->documentjson,'version'=>(int)$board->version]:null,'hasboard'=>(bool)$board,
 'saveurl'=>(new moodle_url('/local/ustar/board_api.php'))->out(false),'sesskey'=>sesskey(),'boardicon'=>\local_ustar\ui::icon('spark','u-feature-icon')];
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/ustar/boards.php')); $PAGE->set_pagelayout('ustar'); $PAGE->set_title('Доска | USTAR Academy'); $PAGE->set_heading('USTAR Academy');

// Optional self-hosted DGM.js runtime. The Moodle plugin remains usable without it.
$dgmjsfile = __DIR__ . '/vendor/dgm/ustar-dgm.js';
$dgmcssfile = __DIR__ . '/vendor/dgm/ustar-dgm.css';
if (is_readable($dgmcssfile)) {
    $PAGE->requires->css(new moodle_url('/local/ustar/vendor/dgm/ustar-dgm.css', ['v' => filemtime($dgmcssfile)]));
}
if (is_readable($dgmjsfile)) {
    $PAGE->requires->js(new moodle_url('/local/ustar/vendor/dgm/ustar-dgm.js', ['v' => filemtime($dgmjsfile)]), true);
}
if($board)$PAGE->requires->js_call_amd('local_ustar/boards','init',[(int)$board->id,(int)$board->version,$data['saveurl'],sesskey()]);
$output=$PAGE->get_renderer('local_ustar'); echo $output->header(); echo $output->render_from_template('local_ustar/boards',$data); echo $output->footer();
