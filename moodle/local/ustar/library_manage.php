<?php
require_once(__DIR__.'/../../config.php');require_login();
use local_ustar\{library_access,library_offer,library_grant,library_store,library_assessment,library_manage_form,library_manage_reader,view_as};
$uid=(int)$USER->id;$author=library_access::is_authoring($uid);$grantor=library_access::can_grant($uid);$assessor=library_access::can_assess($uid);$admin=library_access::is_admin($uid);
if (!$author && !$grantor && !$assessor) { throw new required_capability_exception(context_system::instance(),'local/ustar:librarygrant','nopermissions',''); }
$PAGE->set_context(context_system::instance());$PAGE->set_url(new moodle_url('/local/ustar/library_manage.php'));$PAGE->set_pagelayout('ustar');
$PAGE->set_title('Управление Библиотекой | USTAR');$PAGE->set_heading('Библиотека');$PAGE->requires->css(new moodle_url('/local/ustar/styles/library.css'));
$notice='';$failedaction='';$draft=null;$edit=optional_param('edit',0,PARAM_INT);$checkid=optional_param('check',0,PARAM_INT);
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
        } else if ($action==='marketplace') {
            library_access::save_marketplace_departments(
                optional_param_array('marketdept',[],PARAM_ALPHANUMEXT),$uid,required_param('settingsrevision',PARAM_INT));
            $notice='Доступ отделов к Маркетплейсу сохранён.';
        } else if ($action==='settings') {
            if (!$admin) { library_access::fail('Настройка продаж доступна администратору.'); }
            $min=required_param('pricemin',PARAM_INT);$max=required_param('pricemax',PARAM_INT);
            if ($min<1 || $max<$min || $max>1000000) { library_access::fail('Проверьте диапазон цен.'); }
            $lock=\core\lock\lock_config::get_lock_factory('local_ustar')->get_lock('library-settings',10);
            if (!$lock) { library_access::fail('Настройки сейчас изменяются. Повторите действие.'); }
            try {
                if (required_param('settingsrevision',PARAM_INT)!==(int)$DB->get_field('config_plugins','value',['plugin'=>'local_ustar','name'=>'library_settings_revision'])) { library_access::fail('Настройки изменились. Обновите страницу.'); }
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
    } catch (moodle_exception $e) {
        $notice=$e->getMessage();$failedaction=$action??'';
        if ($failedaction==='save' && $author) { $edit=optional_param('id',0,PARAM_INT);$draft=$_POST; }
        if ($failedaction==='savecheck' && $assessor) { $checkid=optional_param('id',0,PARAM_INT);$draft=$_POST; }
    }
}
$record=$edit && $author ? library_offer::get($edit) : null;
$offerform=library_manage_form::offer($record,$failedaction==='save'?$draft:null);
$filters=[];
foreach (['subjectq','personq','historyq','checkq'] as $name) { $filters[$name]=optional_param($name,'',PARAM_TEXT); }
foreach (['subjectpage','personpage','historypage','checkpage'] as $name) { $filters[$name]=max(0,optional_param($name,0,PARAM_INT)); }
$filters['subjecttype']=optional_param('subjecttype','content',PARAM_ALPHA);
if (!in_array($filters['subjecttype'],['content','catalog','assessment'],true)) { $filters['subjecttype']='content'; }
$sourcepage=library_manage_reader::subjects($uid,$filters['subjecttype'],$filters['subjectq'],$filters['subjectpage'],$offerform['subject']);
$subjects=$sourcepage['items'];
$choices=static function(array $values,string $current): array { $out=[];if(!array_key_exists($current,$values)){$out[]=['value'=>$current,'label'=>'Выберите допустимое значение','selected'=>true];}foreach($values as $value=>$label){$out[]=['value'=>$value,'label'=>$label,'selected'=>(string)$value===$current];}return $out; };
$policies=$choices(['free'=>'Открыто для аудитории материала','assigned'=>'По назначению','paid'=>'За USCOIN','gated'=>'После точки маршрута'],$offerform['accesspolicy']);
$visibilities=$choices(['draft'=>'Черновик','published'=>'Опубликовано','archived'=>'Архив'],$offerform['visibility']);
$gates=[['value'=>0,'label'=>'Не требуется','selected'=>empty($offerform['gatepointid'])]];
if ($author) { foreach ($DB->get_records('local_ustar_route_points',['active'=>1],'id','id',0,500) as $point) {
    $version=\local_ustar\route_model::current_published_version((int)$point->id);
    if ($version) { $gates[]=['value'=>$point->id,'label'=>$version->title,'selected'=>(int)$offerform['gatepointid']===(int)$point->id]; }
} }
if ($author && (int)$offerform['gatepointid']>0 && !array_filter($gates,static fn($g)=>(int)$g['value']===(int)$offerform['gatepointid'])) {
    $version=\local_ustar\route_model::current_published_version((int)$offerform['gatepointid']);
    $gates[]=['value'=>$offerform['gatepointid'],'label'=>$version?$version->title:'Выбранная точка недоступна','selected'=>true];
}
$offers=[];$grantoffers=[];$page=max(0,optional_param('page',0,PARAM_INT));
$all=$DB->get_records('local_ustar_library_offer',[],'timemodified DESC,id DESC');
$states=library_access::resolve_many(array_values($all),$uid);
foreach ($all as $offer) {
    if (!$author && empty($states[(int)$offer->id]['canview'])) { continue; }
    $offers[]=['title'=>$offer->title,'policy'=>match($offer->accesspolicy){'paid'=>$offer->pricecoin.' USCOIN','gated'=>'После точки маршрута','assigned'=>'По назначению',default=>'Открыто для аудитории'},
        'status'=>match($offer->visibility){'published'=>'Опубликовано','archived'=>'Архив',default=>'Черновик'},
        'editurl'=>(new moodle_url('/local/ustar/library_manage.php',$filters+['edit'=>$offer->id,'check'=>$checkid]))->out(false)];
    if ($offer->visibility==='published') { $grantoffers[]=['value'=>$offer->id,'label'=>$offer->title]; }
}
$peoplepage=library_manage_reader::targets($uid,$filters['personq'],$filters['personpage']);
$targets=$peoplepage['items'];
$historypage=library_manage_reader::history($uid,$filters['historyq'],$filters['historypage'],time());
$grants=$historypage['items'];
$bank=$checkid && $assessor ? library_assessment::get($checkid) : null;
if ($bank && $bank->targettype==='quick') { library_access::fail('Проверки товаров обновляются по карточке товара.'); }
$bankform=library_manage_form::assessment($bank,$failedaction==='savecheck'?$draft:null);
$checkpage=library_manage_reader::checks($uid,$filters['checkq'],$filters['checkpage']);
$checks=[];
foreach ($checkpage['items'] as $row) { $checks[]=['title'=>$row->title,'editurl'=>(new moodle_url('/local/ustar/library_manage.php',$filters+['edit'=>$edit,'check'=>$row->id]))->out(false)]; }
$targetrefs=[['value'=>'topic','label'=>'Тематическая проверка','selected'=>$bankform['targetref']==='topic']];
foreach ($DB->get_records_select('local_ustar_catalog','active=1 AND (itemtype=:g OR itemtype=:s)',['g'=>'group','s'=>'subgroup'],'title') as $group) {
    $targetrefs[]=['value'=>'group:'.$group->id,'label'=>'Товары · '.$group->title,'selected'=>$bankform['targetref']==='group:'.$group->id];
}
$skilldata=\local_ustar\structure::get('skills');
foreach (($skilldata['skills']??$skilldata) as $skill) {
    if (!is_array($skill) || empty($skill['id'])) { continue; }
    $targetrefs[]=['value'=>'skill:'.$skill['id'],'label'=>'Навык · '.($skill['name']??$skill['id']),
        'selected'=>$bankform['targetref']==='skill:'.$skill['id']];
}
if (!array_filter($targetrefs,static fn($r)=>$r['selected'])) { $targetrefs[]=['value'=>$bankform['targetref'],'label'=>'Выбранная связь недоступна','selected'=>true]; }
$marketdepartments=[];
if ($admin) {
    $allowed=library_access::marketplace_departments();
    foreach (\local_ustar\structure::get(\local_ustar\structure::NAME_STRUCTURE)['departments']??[] as $department) {
        $marketdepartments[]=['id'=>(string)$department['id'],'name'=>(string)$department['name'],
            'selected'=>in_array((string)$department['id'],$allowed,true)];
    }
}
$bounds=library_store::price_bounds();
$data=['marketdepartments'=>$marketdepartments,'hasmarketdepartments'=>(bool)$marketdepartments,'notice'=>$notice,'writable'=>!view_as::active(),'author'=>$author,'grantor'=>$grantor,'assessor'=>$assessor,'admin'=>$admin,
    'manageurl'=>(new moodle_url('/local/ustar/library_manage.php'))->out(false),'libraryurl'=>(new moodle_url('/local/ustar/library.php'))->out(false),'actionurl'=>(new moodle_url('/local/ustar/library_manage.php'))->out(false),
    'materialsurl'=>(new moodle_url('/local/ustar/materials.php'))->out(false),'catalogediturl'=>(new moodle_url('/local/ustar/catalog.php',['editor'=>1]))->out(false),
    'sesskey'=>sesskey(),'id'=>$record->id??0,'expectedmodified'=>$record->timemodified??0,'title'=>$record->title??'','summary'=>$record->summary??'',
    'pricecoin'=>$record->pricecoin??0,'subjects'=>$subjects,'policies'=>$policies,'visibilities'=>$visibilities,'gates'=>$gates,
    'offers'=>array_slice($offers,$page*50,50),'grantoffers'=>$grantoffers,'targets'=>$targets,'hastargets'=>(bool)$targets,'grants'=>$grants,'nonce'=>bin2hex(random_bytes(16)),
    'hasmoreoffers'=>count($offers)>($page+1)*50,
    'pricemin'=>$bounds['min'],'pricemax'=>$bounds['max'],'storeenabled'=>library_store::enabled(),'settingsrevision'=>(int)get_config('local_ustar','library_settings_revision'),
    'checkid'=>$bank->id??0,'checkrevision'=>$bank->revision??0,'checktitle'=>$bank->title??'','targetkey'=>$bank->targetkey??'',
    'targetrefs'=>$targetrefs,'topic'=>$bank && $bank->targettype==='topic' ? $bank->targetkey : '',
    'targettypes'=>$choices(['topic'=>'Тема','skill'=>'Навык','group'=>'Группа товаров'],$bank->targettype??'topic'),
    'checkstatuses'=>$choices(['draft'=>'Черновик','published'=>'Опубликовано','archived'=>'Архив'],$bankform['checkstatus']),
    'questioncount'=>$bank->questioncount??2,'passpercent'=>$bank->passpercent??100,'maxattempts'=>$bank->maxattempts??3,
    'questionfields'=>$bankform['questionfields'],'checks'=>$checks];
$context=$filters+['edit'=>$edit,'check'=>$checkid,'page'=>$page];
$searchfields=static function(array $omit)use($context): array {
    $rows=[];foreach ($context as $name=>$value) { if (!in_array($name,$omit,true)) { $rows[]=['name'=>$name,'value'=>$value]; } }return $rows;
};
$pagination=static function(string $key,bool $more,string $anchor)use($context): array {
    $current=(int)$context[$key];return ['label'=>match($key){'subjectpage'=>'Страницы материалов','personpage'=>'Страницы сотрудников','historypage'=>'Страницы истории','page'=>'Страницы предложений',default=>'Страницы проверок'},'number'=>$current+1,'hasprevious'=>$current>0,'hasnext'=>$more,
        'previousurl'=>(new moodle_url('/local/ustar/library_manage.php',array_replace($context,[$key=>max(0,$current-1)])))->out(false).'#'.$anchor,
        'nexturl'=>(new moodle_url('/local/ustar/library_manage.php',array_replace($context,[$key=>$current+1])))->out(false).'#'.$anchor];
};
$data=array_merge($data,$filters,['selectedsubjectlabel'=>$sourcepage['selectedlabel'],
    'subjecttypes'=>$choices(['content'=>'Материалы','catalog'=>'Товары','assessment'=>'Проверки'],$filters['subjecttype']),
    'subjectsearchfields'=>$searchfields(['subjecttype','subjectq','subjectpage']),
    'personsearchfields'=>$searchfields(['personq','personpage']),
    'historysearchfields'=>$searchfields(['historyq','historypage']),
    'checksearchfields'=>$searchfields(['checkq','checkpage']),
    'offerpagination'=>$pagination('page',count($offers)>($page+1)*50,'library-offers'),
    'subjectpagination'=>$pagination('subjectpage',$sourcepage['hasmore'],'library-subject'),
    'personpagination'=>$pagination('personpage',$peoplepage['hasmore'],'library-person'),
    'historypagination'=>$pagination('historypage',$historypage['hasmore'],'library-history'),
    'checkpagination'=>$pagination('checkpage',$checkpage['hasmore'],'library-checks'),
    'actionurl'=>(new moodle_url('/local/ustar/library_manage.php',$context))->out(false),
    'checksuitempty'=>!$checks,'historyempty'=>!$grants],$offerform,$bankform,['formerror'=>$failedaction!=='','offererror'=>$failedaction==='save','checkerror'=>$failedaction==='savecheck','offerreloadurl'=>(new moodle_url('/local/ustar/library_manage.php',['edit'=>$edit]))->out(false),'checkreloadurl'=>(new moodle_url('/local/ustar/library_manage.php',['check'=>$checkid]))->out(false)]);
$PAGE->requires->js_call_amd('local_ustar/library_manage','init');
echo $OUTPUT->header();echo $OUTPUT->render_from_template('local_ustar/library_manage',$data);echo $OUTPUT->footer();
