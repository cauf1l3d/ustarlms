<?php
require_once(__DIR__.'/../../config.php'); require_login(); global $USER;
$context=context_system::instance(); if(!\local_ustar\view_as::can_use()) throw new required_capability_exception($context,'local/ustar:viewas','nopermissions','');
if($_SERVER['REQUEST_METHOD']==='POST'){require_sesskey();$position=optional_param('position','',PARAM_ALPHANUMEXT); if($position==='')\local_ustar\view_as::clear(); else \local_ustar\view_as::set($position); redirect(new moodle_url('/local/ustar/home.php'));}
$positions=[]; $active=\local_ustar\view_as::position_id(); foreach(\local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE)['positions']??[] as $p)$positions[]=['id'=>(string)$p['id'],'name'=>(string)$p['name'],'selected'=>(string)$p['id']===$active];
$data=['positions'=>$positions,'hasactive'=>$active!=='','posturl'=>(new moodle_url('/local/ustar/view_as.php'))->out(false),'sesskey'=>sesskey()];
$PAGE->set_context($context);$PAGE->set_url(new moodle_url('/local/ustar/view_as.php'));$PAGE->set_pagelayout('ustar');$PAGE->set_title('Просмотр как | USTAR');$PAGE->set_heading('USTAR');$o=$PAGE->get_renderer('local_ustar');echo $o->header();echo $o->render_from_template('local_ustar/view_as',$data);echo $o->footer();
