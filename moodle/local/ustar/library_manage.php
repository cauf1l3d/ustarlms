<?php
require_once(__DIR__.'/../../config.php');require_login();
use local_ustar\{library_access,library_offer,library_grant,library_store,library_assessment,view_as};
$uid=(int)$USER->id;$author=library_access::is_authoring($uid);$grantor=library_access::can_grant($uid);$assessor=library_access::can_assess($uid);$admin=library_access::is_admin($uid);
if (!$author && !$grantor && !$assessor) { throw new required_capability_exception(context_system::instance(),'local/ustar:librarygrant','nopermissions',''); }
$PAGE->set_context(context_system::instance());$PAGE->set_url(new moodle_url('/local/ustar/library_manage.php'));$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Управление Библиотекой | USTAR');$PAGE->set_heading('Библиотека');$PAGE->requires->css(new moodle_url('/local/ustar/styles/library.css'));
$notice='';$edit=optional_param('edit',0,PARAM_INT);$checkid=optional_param('check',0,PARAM_INT);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_sesskey();library_access::require_actor($uid);
    try {
        $action=required_param('action',PARAM_ALPHA);
        if ($action==='save') {
            $parts=explode(':',required_param('subject',PARAM_TEXT),2);$input=$_POST;
            $input['itemtype']=$parts[0];$input['itemid']=(int)($parts[1]??0);
            $row=library_offer::save(optional_param('id',0,PARAM_INT),$input,$uid,required_param('expectedmodified',PARAM_INT));
            redirect(new moodle_url('/local/ustar/library_manage.php',['edit'=>$row->id]),'Предложение сохранено.');
        } else if ($action==='grant') {
            library_grant::assign(required_param('offerid',PARAM_INT),required_param('targetuserid',PARAM_INT),$uid,'',
                optional_param('comment','',PARAM_TEXT),required_param('nonce',PARAM_ALPHANUM));$notice='Доступ выдан.';
        } else if ($action==='revoke' || $action==='refund') {
            $id=required_param('grantid',PARAM_INT);$reason=optional_param('comment','',PARAM_TEXT);
            if ($action==='refund') { library_grant::refund($id,$uid,$reason);$notice='Покупка возвращена. USCOIN восстановлены.'; }
            else { library_grant::revoke($id,$uid,$reason);$notice='Доступ отозван. История сохранена.'; }
        } else if ($action==='settings') {
            if (!$admin) { library_access::fail('Настройка продаж доступна администратору.'); }
            $min=required_param('pricemin',PARAM_INT);$max=required_param('pricemax',PARAM_INT);
            if ($min<1 || $max<$min || $max>1000000) { library_access::fail('Проверьте диапазон цен.'); }
            $lock=\core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('library-settings',10);
            if (!$lock) { library_access::fail('Настройки сейчас изменяются. Повторите действие.'); }
            try {
                if (required_param('settingsrevision',PARAM_INT)!==(int)get_config('local_ustar','library_settings_revision')) { library_access::fail('Настройки изменились. Обновите страницу.'); }
                $tx=$DB->start_delegated_transaction();
                try {
                    set_config('library_pricemin',$min,'local_ustar');set_config('library_pricemax',$max,'local_ustar');
                    set_config('library_store_enabled',optional_param('storeenabled',0,PARAM_BOOL),'local_ustar');
                    set_config('library_settings_revision',max(time(),(int)get_config('local_ustar','library_settings_revision')+1),'local_ustar');
                    library_offer::audit(0,'store_settings_changed',$uid,['min'=>$min,'max'=>$max,'enabled'=>(bool)get_config('local_ustar','library_store_enabled')]);
                    $tx->allow_commit();
                } catch (Throwable $e) { $tx->rollback($e);throw $e; }
            } finally { $lock->release(); }
            $notice='Настройки сохранены.';
        } else if ($action==='savecheck') {
            $input=$_POST;$ref=explode(':',required_param('targetref',PARAM_TEXT),2);
            $input['targettype']=$ref[0];$input['targetkey']=$ref[0]==='topic' ? required_param('topic',PARAM_TEXT) : ($ref[1]??'');
            $input['questionsjson']=library_assessment::editor_questions((array)($_POST['questions']??[]));
            $id=library_assessment::save(optional_param('id',0,PARAM_INT),$input,$uid,required_param('expectedrevision',PARAM_INT));
            redirect(new moodle_url('/local/ustar/library_manage.php',['check'=>$id]),'Проверка сохранена.');
        } else { throw new invalid_parameter_exception('Неизвестное действие.'); }
    } catch (moodle_exception $e) { $notice=$e->getMessage(); }
}
$record=$edit && $author ? library_offer::get($edit) : null;
$subjects=[];
if ($author) {
    foreach (['content'=>'local_ustar_content','catalog'=>'local_ustar_catalog','assessment'=>'local_ustar_library_assessment'] as $type=>$table) {
        foreach ($DB->get_records($table,[],'title,id','*',0,500) as $row) {
            if (($type==='content' && $row->type==='folder') || ($type==='catalog' && in_array($row->itemtype,['group','subgroup'],true))) { continue; }
            $subjects[]=['value'=>$type.':'.$row->id,'label'=>match($type){'content'=>'Материал','catalog'=>'Товар',default=>'Проверка'}.' · '.$row->title,
                'selected'=>$record && $record->itemtype===$type && (int)$record->itemid===(int)$row->id];
        }
    }
}
$choices=static function(array $values,string $current): array { $out=[];foreach($values as $value=>$label){$out[]=['value'=>$value,'label'=>$label,'selected'=>(string)$value===$current];}return $out; };
$policies=$choices(['free'=>'Открыто для аудитории материала','assigned'=>'По назначению','paid'=>'За USCOIN','gated'=>'После точки маршрута'],$record->accesspolicy??'free');
$visibilities=$choices(['draft'=>'Черновик','published'=>'Опубликовано','archived'=>'Архив'],$record->visibility??'draft');
$gates=[['value'=>0,'label'=>'Не требуется','selected'=>empty($record->gatepointid)]];
if ($author) { foreach ($DB->get_records('local_ustar_route_points',['active'=>1],'id','id',0,500) as $point) {
    $version=\local_ustar\route_model::current_published_version((int)$point->id);
    if ($version) { $gates[]=['value'=>$point->id,'label'=>$version->title,'selected'=>$record && (int)$record->gatepointid===(int)$point->id]; }
} }
$offers=[];$grantoffers=[];$page=max(0,optional_param('page',0,PARAM_INT));
$all=$DB->get_records('local_ustar_library_offer',[],'timemodified DESC,id DESC');
$states=library_access::resolve_many(array_values($all),$uid);
foreach ($all as $offer) {
    if (!$author && empty($states[(int)$offer->id]['canview'])) { continue; }
    $offers[]=['title'=>$offer->title,'policy'=>match($offer->accesspolicy){'paid'=>$offer->pricecoin.' USCOIN','gated'=>'После точки маршрута','assigned'=>'По назначению',default=>'Открыто для аудитории'},
        'status'=>match($offer->visibility){'published'=>'Опубликовано','archived'=>'Архив',default=>'Черновик'},
        'editurl'=>(new moodle_url('/local/ustar/library_manage.php',['edit'=>$offer->id]))->out(false)];
    if ($offer->visibility==='published') { $grantoffers[]=['value'=>$offer->id,'label'=>$offer->title]; }
}
$targets=[];$ids=[];
if ($grantor) {
    $scope=\local_ustar\access_context::scope($uid);$candidates=$admin ? $DB->get_fieldset_select('user','id','id>1 AND deleted=0 AND suspended=0') : ($scope['userids']??[]);
    foreach ($candidates as $target) {
        $target=(int)$target;if (!library_access::can_target($uid,$target)) { continue; }
        $u=$DB->get_record('user',['id'=>$target],'*',MUST_EXIST);$targets[]=['value'=>$target,'label'=>fullname($u)];$ids[]=$target;
    }
}
$grants=[];
if ($ids) {
    [$ins,$params]=$DB->get_in_or_equal($ids,SQL_PARAMS_NAMED,'target');
    $rows=$DB->get_records_select('local_ustar_library_grant','userid '.$ins,$params,'id DESC','*',0,100);
    foreach ($rows as $row) {
        $offer=library_offer::get((int)$row->offerid);$target=$DB->get_record('user',['id'=>$row->userid]);
        $canrevoke=$admin || ($row->source!=='purchase' && ((int)$row->grantedby===$uid || \local_ustar\capabilities::has($uid,\local_ustar\capabilities::HR_WRITE)));
        $grants[]=['id'=>$row->id,'title'=>$offer->title??'Архивный материал','person'=>fullname($target),'date'=>userdate($row->timecreated,'%d.%m.%Y'),
            'source'=>$row->source==='purchase'?'Покупка':'Назначение','status'=>$row->revokedat?'Закрыт':'Действует',
            'canrevoke'=>$canrevoke && !$row->revokedat,'canrefund'=>$admin && $row->source==='purchase' && !$row->refundedat];
    }
}
$bank=$checkid && $assessor ? library_assessment::get($checkid) : null;
if ($bank && $bank->targettype==='quick') { library_access::fail('Проверки товаров обновляются по карточке товара.'); }
$bankquestions=$bank ? library_assessment::questions($bank->questionsjson) : [];$questionfields=[];
for($i=0;$i<max(5,count($bankquestions));$i++) {
    $q=$bankquestions[$i]??['text'=>'','options'=>[],'correct'=>0];$opts=[];
    for($j=0;$j<6;$j++) { $opts[]=['index'=>$j,'value'=>$q['options'][$j]??'','checked'=>$q['correct']===$j,'question'=>$i,'number'=>$j+1]; }
    $questionfields[]=['index'=>$i,'number'=>$i+1,'text'=>$q['text'],'options'=>$opts];
}
$checks=[];if ($assessor) { foreach($DB->get_records_select('local_ustar_library_assessment','targettype<>:quick',['quick'=>'quick'],'id DESC','*',0,100) as $row) {
    $checks[]=['title'=>$row->title,'editurl'=>(new moodle_url('/local/ustar/library_manage.php',['check'=>$row->id]))->out(false)];
} }
$targetrefs=[['value'=>'topic','label'=>'Тематическая проверка','selected'=>!$bank || $bank->targettype==='topic']];
foreach ($DB->get_records_select('local_ustar_catalog','active=1 AND (itemtype=:g OR itemtype=:s)',['g'=>'group','s'=>'subgroup'],'title') as $group) {
    $targetrefs[]=['value'=>'group:'.$group->id,'label'=>'Товары · '.$group->title,'selected'=>$bank && $bank->targettype==='group' && $bank->targetkey===(string)$group->id];
}
$skilldata=\local_ustar\structure::get('skills');
foreach (($skilldata['skills']??$skilldata) as $skill) {
    if (!is_array($skill) || empty($skill['id'])) { continue; }
    $targetrefs[]=['value'=>'skill:'.$skill['id'],'label'=>'Навык · '.($skill['name']??$skill['id']),
        'selected'=>$bank && $bank->targettype==='skill' && $bank->targetkey===(string)$skill['id']];
}
$bounds=library_store::price_bounds();
$data=['notice'=>$notice,'writable'=>!view_as::active(),'author'=>$author,'grantor'=>$grantor,'assessor'=>$assessor,'admin'=>$admin,
    'libraryurl'=>(new moodle_url('/local/ustar/library.php'))->out(false),'actionurl'=>(new moodle_url('/local/ustar/library_manage.php'))->out(false),
    'materialsurl'=>(new moodle_url('/local/ustar/materials.php'))->out(false),'catalogediturl'=>(new moodle_url('/local/ustar/catalog.php',['editor'=>1]))->out(false),
    'sesskey'=>sesskey(),'id'=>$record->id??0,'expectedmodified'=>$record->timemodified??0,'title'=>$record->title??'','summary'=>$record->summary??'',
    'pricecoin'=>$record->pricecoin??0,'subjects'=>$subjects,'policies'=>$policies,'visibilities'=>$visibilities,'gates'=>$gates,
    'offers'=>array_slice($offers,$page*50,50),'grantoffers'=>$grantoffers,'targets'=>$targets,'hastargets'=>(bool)$targets,'grants'=>$grants,'nonce'=>bin2hex(random_bytes(16)),
    'hasmoreoffers'=>count($offers)>($page+1)*50,'nexturl'=>(new moodle_url('/local/ustar/library_manage.php',['page'=>$page+1]))->out(false),
    'pricemin'=>$bounds['min'],'pricemax'=>$bounds['max'],'storeenabled'=>library_store::enabled(),'settingsrevision'=>(int)get_config('local_ustar','library_settings_revision'),
    'checkid'=>$bank->id??0,'checkrevision'=>$bank->revision??0,'checktitle'=>$bank->title??'','targetkey'=>$bank->targetkey??'',
    'targetrefs'=>$targetrefs,'topic'=>$bank && $bank->targettype==='topic' ? $bank->targetkey : '',
    'targettypes'=>$choices(['topic'=>'Тема','skill'=>'Навык','group'=>'Группа товаров'],$bank->targettype??'topic'),
    'checkstatuses'=>$choices(['draft'=>'Черновик','published'=>'Опубликовано','archived'=>'Архив'],$bank->status??'draft'),
    'questioncount'=>$bank->questioncount??2,'passpercent'=>$bank->passpercent??100,'maxattempts'=>$bank->maxattempts??3,
    'questionfields'=>$questionfields,'checks'=>$checks];
echo $OUTPUT->header();echo $OUTPUT->render_from_template('local_ustar/library_manage',$data);echo $OUTPUT->footer();
