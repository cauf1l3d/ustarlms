<?php
require_once(__DIR__ . '/../../config.php');
require_login();
use local_ustar\reward_control as rewards;
$actor=(int)$USER->id;
rewards::assert_manager($actor);
$context=context_system::instance();
$url=new moodle_url('/local/ustar/reward_control.php');
$notice=''; $preview=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_sesskey();
    try {
        $action=required_param('action',PARAM_ALPHA);
        if ($action==='rules') {
            $rules=[];
            foreach (rewards::defaults() as $kind=>$rule) {
                $rules[$kind]=['xp'=>required_param($kind.'_xp',PARAM_INT),
                    'coins'=>optional_param($kind.'_coins',0,PARAM_INT)];
            }
            rewards::save($actor,$rules,required_param('reason',PARAM_TEXT),required_param('revision',PARAM_ALPHANUM));
            redirect($url,'Новая версия правил сохранена. Применяется к будущим событиям.');
        } else if (in_array($action,['condition','disable'],true)) {
            $condition=null;$disable='';
            if ($action==='condition') {
                $timezone=core_date::get_user_timezone();
                if (!in_array($timezone,DateTimeZone::listIdentifiers(),true)) { $timezone='Europe/Moscow'; }
                $from=required_param('fromdate',PARAM_RAW_TRIMMED);$until=optional_param('untildate','',PARAM_RAW_TRIMMED);
                $scope=required_param('conditionscope',PARAM_ALPHA);
                $scopeid=$scope==='company'?'':required_param('scope_'.$scope,PARAM_ALPHANUMEXT);
                $condition=['title'=>required_param('conditiontitle',PARAM_TEXT),'kind'=>required_param('conditionkind',PARAM_ALPHA),
                    'scope'=>$scope,'scopeid'=>$scopeid,'resource'=>optional_param('conditionresource','',PARAM_ALPHANUMEXT),
                    'xp'=>required_param('conditionxp',PARAM_INT),'coins'=>optional_param('conditioncoins',0,PARAM_INT),
                    'from'=>\local_ustar\task_workspace\calendar::timestamp($from,'00:00',$timezone),
                    'until'=>$until!==''?\local_ustar\task_workspace\calendar::timestamp($until,'23:59',$timezone)+59:0];
            } else { $disable=required_param('conditionid',PARAM_ALPHANUM); }
            rewards::save_condition($actor,$condition,$disable,required_param('reason',PARAM_TEXT),required_param('revision',PARAM_ALPHANUM));
            redirect($url,'Условия сохранены для будущих событий.');
        } else if ($action==='preview') {
            $target=required_param('userid',PARAM_INT);
            $preview=rewards::preview($actor,$target);
            $units=optional_param_array('units',[],PARAM_ALPHA);
            if (!$units || array_diff($units,['xp','coins','kpi'])) { throw new invalid_parameter_exception('Выберите показатели.'); }
            $reason=required_param('reason',PARAM_TEXT);
            if (trim($reason)==='') { throw new invalid_parameter_exception('Укажите причину.'); }
            $token=bin2hex(random_bytes(16));
            $SESSION->ustar_reward_reset=['actor'=>$actor,'preview'=>$preview,'units'=>$units,'reason'=>$reason,'token'=>$token,'expires'=>time()+600];
        } else if ($action==='reset') {
            $pending=$SESSION->ustar_reward_reset ?? null;
            if (!$pending || $pending['actor']!==$actor || $pending['expires']<time()
                    || !hash_equals($pending['token'],required_param('token',PARAM_ALPHANUM))) {
                throw new invalid_parameter_exception('Предварительный просмотр истёк. Выберите сотрудника повторно.');
            }
            rewards::reset($actor,$pending['preview']['userid'],$pending['units'],$pending['reason'],$pending['preview'],$pending['token']);
            unset($SESSION->ustar_reward_reset);
            redirect($url,'Выбранные показатели сброшены. История сохранена.');
        } else { throw new invalid_parameter_exception('Неизвестное действие.'); }
    } catch (Throwable $e) { $notice=$e->getMessage(); }
}
$PAGE->set_context($context); $PAGE->set_url($url); $PAGE->set_pagelayout('ustar');
$PAGE->set_title('Управление геймификацией | USTAR'); $PAGE->set_heading('USTAR Academy');
$PAGE->requires->css(new moodle_url('/local/ustar/styles/task_workspace.css', ['v' => '20260930-ux5']));
$PAGE->requires->css(new moodle_url('/local/ustar/styles/reward_control.css',['v'=>'20260930-ux5']));
$PAGE->requires->js(new moodle_url('/local/ustar/reward_control.js',['v'=>'20260930-ux5']));
echo $OUTPUT->header();
echo '<div class="u-workspace u-reward-control"><header class="uw-heading"><div><p class="uw-eyebrow">Администратор</p><h1>Управление геймификацией</h1><p>Правила наград и адресная корректировка показателей сотрудников.</p></div></header>';
if ($notice) { echo $OUTPUT->notification(s($notice),'notifyproblem'); }
function ustar_reward_hidden(string $name,$value): string { return html_writer::empty_tag('input',['type'=>'hidden','name'=>$name,'value'=>$value]); }
if ($preview && isset($SESSION->ustar_reward_reset)) {
    $pending=$SESSION->ustar_reward_reset;
    echo '<section class="uw-panel uw-editor"><h2>Проверка перед сбросом: '.s($preview['fullname']).'</h2><p>№ '.$preview['userid'].'</p><ul>';
    foreach ($pending['units'] as $unit) { echo '<li>'.s(strtoupper($unit)).': '.$preview[$unit].' → 0</li>'; }
    echo '</ul><p>Причина: '.s($pending['reason']).'</p><p>Прохождения, аттестации, задачи и журнал начислений сохраняются.</p><form method="post" action="'.$url->out().'">'
        .ustar_reward_hidden('sesskey',sesskey()).ustar_reward_hidden('action','reset').ustar_reward_hidden('token',$pending['token'])
        .'<button class="uw-btn uw-primary">Подтвердить сброс выбранных показателей</button> <a class="uw-btn" href="'.$url->out().'">Отмена</a></form></section>';
}
$labels=['course'=>'Завершение курса','activity'=>'Завершение учебной активности','route'=>'Подтверждённая точка маршрута',
    'game'=>'Первый верный ответ на вопрос игры','task'=>'Принятое поручение','checklist'=>'Принятый чек-лист'];
$versions=rewards::versions(); $latest=end($versions);$rules=$versions ? $latest['rules'] : rewards::defaults();
$options=\local_ustar\reward_conditions::options();$conditions=$latest['conditions']??[];
$rows=[];
foreach ($conditions as $c) {
    $scope=['company'=>'Вся компания','department'=>'Отдел','position'=>'Должность','person'=>'Сотрудник'][$c['scope']];
    $map=['department'=>'departments','position'=>'positions','person'=>'people'];
    $who=$c['scope']==='company'?$scope:$scope.': '.($options[$map[$c['scope']]][$c['scopeid']]??$c['scopeid']);
    $rows[]=$c+['kindlabel'=>$labels[$c['kind']],'wholabel'=>$who,
        'resourcelabel'=>$c['resource']!==''?($options['resources'][$c['kind']][$c['resource']]??'Источник №'.$c['resource']):'Любой источник этого события',
        'period'=>userdate($c['from'],'%d.%m.%Y').' — '.($c['until']?userdate($c['until'],'%d.%m.%Y'):'без окончания')];
}
$selectoptions=static function(array $values): array { $out=[];foreach ($values as $id=>$name) { $out[]=['id'=>$id,'name'=>$name]; }return $out; };
echo $OUTPUT->render_from_template('local_ustar/reward_conditions',[
    'url'=>$url->out(false),'sesskey'=>sesskey(),'revision'=>hash('sha256',json_encode($versions)),
    'rows'=>$rows,'hasconditions'=>!empty($rows),'resourcejson'=>json_encode($options['resources']),
    'departments'=>$selectoptions($options['departments']),'positions'=>$selectoptions($options['positions']),
    'people'=>$selectoptions($options['people']),'tomorrow'=>(new DateTimeImmutable('now',core_date::get_user_timezone_object()))->modify('+1 day')->format('Y-m-d')]);
echo '<details class="uw-panel uw-editor"><summary>Базовые награды для всей компании</summary><h2>За что и сколько начислять</h2><p>Новая версия действует на будущие события. Начисленные награды не пересчитываются. Ноль отключает награду за событие. Игровые XP: −1 — значение из редактора вопроса.</p><form class="uw-form" method="post" action="'.$url->out().'">'
    .ustar_reward_hidden('sesskey',sesskey()).ustar_reward_hidden('action','rules')
    .ustar_reward_hidden('revision',hash('sha256',json_encode($versions)));
foreach ($labels as $kind=>$label) {
    echo '<fieldset><legend>'.s($label).'</legend><div class="uw-form-grid"><label class="uw-field"><span>XP</span><input type="number" min="'.($kind==='game'?-1:0).'" max="10000" name="'.$kind.'_xp" value="'.$rules[$kind]['xp'].'" required></label>';
    if (in_array($kind,['route','task','checklist'],true)) {
        echo '<label class="uw-field"><span>USCOIN</span><input type="number" min="0" max="10000" name="'.$kind.'_coins" value="'.$rules[$kind]['coins'].'" required></label>';
    } else { echo '<p class="uw-footnote">USCOIN за обучение начисляется при подтверждении точки маршрута.</p>'; }
    echo '</div></fieldset>';
}
echo '<label class="uw-field"><span>Причина изменения правил</span><textarea name="reason" required maxlength="2000"></textarea></label><button class="uw-btn uw-primary">Сохранить новую версию правил</button></form></details>';
$department=optional_param('departmentid','',PARAM_ALPHANUMEXT); $position=optional_param('positionid','',PARAM_ALPHANUMEXT);
$options=\local_ustar\task_workspace\recipients::options($actor,$department,$position);
echo '<section class="uw-panel uw-editor"><h2>Сброс показателей сотрудника</h2><form method="get" class="uw-form"><div class="uw-form-grid"><label class="uw-field">Отдел'
    .html_writer::select([''=>'Выберите отдел']+$options['departments'],'departmentid',$department,false).'</label><label class="uw-field">Должность'
    .html_writer::select([''=>'Выберите должность']+$options['positions'],'positionid',$position,false).'</label></div><button class="uw-btn">Показать сотрудников</button></form>';
if ($options['people']) {
    echo '<form class="uw-form" method="post" action="'.$url->out().'">'.ustar_reward_hidden('sesskey',sesskey()).ustar_reward_hidden('action','preview')
        .'<label class="uw-field">Сотрудник'.html_writer::select($options['people'],'userid',0,[''=>'Выберите сотрудника']).'</label>';
    foreach (['xp'=>'XP и уровень','coins'=>'Баланс USCOIN','kpi'=>'Личные баллы KPI'] as $key=>$label) {
        echo '<label class="uw-check"><input type="checkbox" name="units[]" value="'.$key.'"> '.s($label).'</label>';
    }
    echo '<label class="uw-field"><span>Причина сброса</span><textarea name="reason" required maxlength="2000"></textarea></label><button class="uw-btn">Предварительный просмотр</button></form>';
}
echo '</section><section class="uw-panel uw-editor"><h2>Последние сбросы</h2>';
foreach ($DB->get_records('local_ustar_reward_resets',[],'id DESC','*',0,20) as $row) {
    $details=json_decode($row->detailsjson,true) ?: [];
    echo '<p>'.s(userdate($row->timecreated,'%d.%m.%Y %H:%M')).' · сотрудник №'.$row->userid.' · '.s(implode(', ',$details['units']??[])).' · '.s($details['reason']??'').'</p>';
}
echo '<h2>Последние версии правил</h2>';
foreach (array_reverse(array_slice($versions,-10)) as $v) { echo '<p>'.s(userdate($v['at'],'%d.%m.%Y %H:%M')).' · '.s($v['reason']).'</p>'; }
echo '</section></div>'.$OUTPUT->footer();
