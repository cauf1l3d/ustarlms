<?php
require_once(__DIR__ . '/../../config.php');
require_login();
global $USER, $DB;
$context=context_system::instance();
require_capability('local/ustar:managecompetition',$context);
\local_ustar\view_as::assert_writable();
$url=new moodle_url('/local/ustar/competition_studio.php');
$seasonid=max(0,optional_param('season',0,PARAM_INT));
$page=max(0,min(10000,optional_param('page',0,PARAM_INT)));
$errors=[];$input=null;
$timezone = core_date::get_user_timezone();
if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) { $timezone = 'Europe/Moscow'; }
$parse_date=static function(string $date,bool $end) use ($timezone): int {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$parts)
            || !checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])) {
        throw new invalid_parameter_exception('Укажите корректные даты начала и окончания.');
    }
    return \local_ustar\task_workspace\calendar::timestamp($date, $end ? '23:59' : '00:00', $timezone) + ($end ? 59 : 0);
};
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_sesskey();
    $action=required_param('action',PARAM_ALPHA);
    try {
        $id=optional_param('competitionid',0,PARAM_INT);
        if (in_array($action,['create','revise'],true)) {
            $input=['title'=>required_param('title',PARAM_TEXT),
                'audiencevalue'=>required_param('departmentid',PARAM_ALPHANUMEXT),
                'startdate'=>required_param('startdate',PARAM_RAW_TRIMMED),'enddate'=>required_param('enddate',PARAM_RAW_TRIMMED),
                'pointsperxp'=>required_param('pointsperxp',PARAM_INT),'is_draft'=>true,'id'=>$id,
                'timemodified'=>optional_param('expected',0,PARAM_INT)];
            $start=$parse_date($input['startdate'],false);$end=$parse_date($input['enddate'],true);
            if ($action==='create') {
                $id=\local_ustar\competition::create_draft('season_'.bin2hex(random_bytes(8)),
                    $input['title'],$input['audiencevalue'],$start,$end,$input['pointsperxp'],(int)$USER->id);
            } else {
                $seasonid=$id;
                \local_ustar\competition::revise_draft($id,(int)$USER->id,$input['timemodified'],
                    $input['title'],$input['audiencevalue'],$start,$end,$input['pointsperxp']);
            }
        } else if (in_array($action,['publish','close'],true)) {
            $seasonid=$id;
            if (!optional_param('confirm',0,PARAM_BOOL)) throw new invalid_parameter_exception('Подтвердите действие с сезоном.');
            if ($action==='publish') \local_ustar\competition::publish($id,(int)$USER->id);
            else \local_ustar\competition::close($id,(int)$USER->id);
        } else {
            throw new invalid_parameter_exception('Неизвестное действие.');
        }
        redirect(new moodle_url($url,['season'=>$id,'saved'=>1]));
    } catch (Throwable $e) {
        $errors[]=$e instanceof moodle_exception ? $e->getMessage() : 'Не удалось сохранить сезон. Повторите попытку.';
    }
}
$selected=$seasonid ? \local_ustar\competition::operator_season($seasonid,(int)$USER->id) : null;
$form=$input ?? ($selected['definition'] ?? ['is_draft'=>true,'pointsperxp'=>1]);
if ($input===null && $seasonid) {
    $form['startdate']=userdate($form['startat'],'%Y-%m-%d');
    $form['enddate']=userdate($form['endat'],'%Y-%m-%d');
}
$departments=\local_ustar\competition::department_options();
foreach ($departments as &$d) $d['selected']=$d['id']===($form['audiencevalue'] ?? '');
unset($d);
$form['departments']=$departments;$form['action']=$seasonid?'revise':'create';
$form['submitlabel']=$seasonid?'Сохранить изменения':'Создать черновик';
$total=$DB->count_records('local_ustar_competitions');
$rows=\local_ustar\competition::operator_rows($page);
$PAGE->set_context($context);$PAGE->set_url($url,['season'=>$seasonid,'page'=>$page]);
$PAGE->set_pagelayout('ustar');$PAGE->set_title('Соревнования | USTAR');$PAGE->set_heading('Соревнования');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/task_workspace.css',['v'=>'20260930-ux']));
$PAGE->requires->css(new moodle_url('/local/ustar/styles/competition_studio.css',['v'=>'20260930']));
$data=['url'=>$url->out(false),'sesskey'=>sesskey(),'rows'=>$rows,'hasrows'=>!empty($rows),'form'=>$form,
    'timezone' => $timezone, 'selected'=>$selected['definition'] ?? null,'scores'=>$selected['scores'] ?? [],
    'hasscores'=>!empty($selected['scores']),'scorelimit'=>count($selected['scores'] ?? [])===100,
    'errors'=>$errors,'haserrors'=>!empty($errors),'saved'=>optional_param('saved',0,PARAM_BOOL),
    'pagination'=>$OUTPUT->paging_bar($total,$page,25,$url)];
$output=$PAGE->get_renderer('local_ustar');
echo $output->header();echo $output->render_from_template('local_ustar/competition_studio',$data);echo $output->footer();
