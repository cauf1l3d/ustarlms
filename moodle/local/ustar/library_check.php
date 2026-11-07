<?php
require_once(__DIR__.'/../../config.php');require_login();
use local_ustar\{library_assessment,library_access,view_as};
$id=required_param('id',PARAM_INT);$uid=(int)$USER->id;
$PAGE->set_context(context_system::instance());$PAGE->set_url(new moodle_url('/local/ustar/library_check.php',['id'=>$id]));
$PAGE->set_pagelayout('ustar');$PAGE->set_title('Проверка знаний | USTAR');$PAGE->set_heading('Проверка знаний');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/library.css'));
$definition=library_assessment::get($id);
if (!$definition || !library_assessment::can_open($definition,$uid)) { library_access::fail('Проверка недоступна.'); }
$notice='';$result=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_sesskey();library_access::require_actor($uid);
    try {
        $action=required_param('action',PARAM_ALPHA);
        if ($action==='start') {
            library_assessment::get_or_start($id,$uid);redirect(new moodle_url('/local/ustar/library_check.php',['id'=>$id]));
        } else if ($action==='submit') {
            $attemptid=required_param('attemptid',PARAM_INT);
            $attempt=$DB->get_record('local_ustar_library_attempt',['id'=>$attemptid,'assessmentid'=>$id,'userid'=>$uid],'*',MUST_EXIST);
            $result=library_assessment::submit($attemptid,$uid,$_POST);
            $definition->passpercent=$attempt->passpercent;
        } else { throw new invalid_parameter_exception('Неизвестное действие.'); }
    } catch (moodle_exception $e) { $notice=$e->getMessage(); }
}
$data=['title'=>$definition->title,'passpercent'=>$definition->passpercent,'maxattempts'=>$definition->maxattempts,
    'notice'=>$notice,'sesskey'=>sesskey(),'actionurl'=>(new moodle_url('/local/ustar/library_check.php',['id'=>$id]))->out(false),
    'backurl'=>(new moodle_url('/local/ustar/library.php',['tab'=>'checks']))->out(false),'writable'=>!view_as::active()];
$best=library_assessment::best($id,$uid);$data+=['hasbest'=>(bool)$best,'bestpercent'=>$best->score??0,'bestpassed'=>!empty($best->passed)];
if ($result) {
    $data+=$result;$data['canretry']=!$result['passed'] && $result['attemptsleft']>0;
    $template='local_ustar/library_check_result';
} else {
    $attempt=library_assessment::open_attempt($id,$uid);$questions=[];
    if ($attempt) {
        $data['passpercent']=$attempt->passpercent;$data['maxattempts']=$attempt->maxattempts;
        foreach (library_assessment::questions($attempt->questionsjson) as $i=>$q) {
            $opts=[];foreach ($q['options'] as $j=>$label) { $opts[]=['label'=>$label,'name'=>'q'.$i,'id'=>'q'.$i.'_'.$j,'value'=>$j]; }
            $questions[]=['number'=>$i+1,'text'=>$q['text'],'options'=>$opts];
        }
    }
    $data+=['hasquestions'=>(bool)$questions,'questions'=>$questions,'attemptid'=>$attempt->id??0,'canstart'=>!$attempt && !view_as::active()];
    $template='local_ustar/library_check';
}
echo $OUTPUT->header();echo $OUTPUT->render_from_template($template,$data);echo $OUTPUT->footer();
