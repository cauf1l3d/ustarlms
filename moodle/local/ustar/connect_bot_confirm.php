<?php
// Native only: bot credentials and provider identity cannot authorize this POST.
require_once(__DIR__.'/../../config.php');
require_login();
$token=required_param('action',PARAM_RAW_TRIMMED);
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/ustar/connect_bot_confirm.php',['action'=>$token]);
$PAGE->set_title('Подтверждение действия USTAR Connect');
$PAGE->set_heading('USTAR Connect');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$error='';$done=false;$summary='';
try {
    [$action]=\local_ustar\connect\bot_confirmation::lookup($token,(int)$USER->id);
    $summary=(string)$action->summary;
    if (data_submitted()) {
        require_sesskey();
        \local_ustar\connect\bot_confirmation::confirm($token,(int)$USER->id);
        $done=true;
    } else { $done=$action->status==='done'; }
} catch(Throwable $e) {
    $error='Действие недоступно, изменилось или срок подтверждения истёк. Подготовьте его заново в боте. Запись не подтверждена этой страницей.';
}
echo $OUTPUT->header();
echo $OUTPUT->heading('Подтверждение действия');
if ($error) { echo $OUTPUT->notification($error,'notifyproblem'); }
else {
    echo html_writer::tag('p','Вы подтверждаете действие своей текущей учётной записью: '.fullname($USER));
    echo html_writer::tag('pre',s($summary),['style'=>'white-space:pre-wrap']);
    if ($done) { echo $OUTPUT->notification('Действие сохранено. Вернитесь в бот и нажмите «Проверить результат».','notifysuccess'); }
    else {
        echo html_writer::start_tag('form',['method'=>'post','action'=>$PAGE->url->out(false)]);
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
        echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'action','value'=>$token]);
        echo html_writer::tag('button','Подтверждаю указанное действие',['type'=>'submit','class'=>'btn btn-primary']);
        echo html_writer::end_tag('form');
    }
}
echo $OUTPUT->footer();
