<?php
namespace local_ustar\connect;
use local_ustar\{learning_tasks,checklist_service,checklists};
use local_ustar\task_workspace\{service,recipients};
defined('MOODLE_INTERNAL') || die();

/** Native task/checklist writers and ACL read models, shared with the Academy UI. */
final class bot_work {
    public static function route(\stdClass $d,string $intent,array $data,array $p): array {
        $id=(int)$d->userid;
        if($intent==='work.company'){return self::company($d,$data);}
        if (in_array($intent,['work.mine','work.team','work.review'],true)) { return self::listing($d,$intent,$data); }
        if ($intent==='work.assign') {
            if (!service::can_manage($id)) { throw new \InvalidArgumentException('forbidden_scope'); }
            return bot_forms::start($d,'➕ Новая задача',array_merge(self::recipient_fields(),[
                ['key'=>'title','label'=>'Название задачи','required'=>true],['key'=>'description','label'=>'Что нужно сделать','required'=>true],
                ['key'=>'date','label'=>'Дата выполнения','type'=>'date','required'=>true],
                ['key'=>'clock','label'=>'Время выполнения','type'=>'clock','required'=>true]]),'work.assign.commit');
        }
        if ($intent==='work.assign.commit') {
            $v=$data['values']; recipients::validate($id,(int)$v['person'],$v['department'],$v['position']);
            $task=service::create($id,(int)$v['person'],$v);
            bot_store::state($d,'menu'); return bot_store::card('✓ Задача назначена','№'.$task.' · История и уведомления сохранены в Академии.',[bot_service::home($d)]);
        }
        if ($intent==='work.checklists') {
            $all=checklist_service::list_for($id); bot_store::state($d,'menu'); $buttons=[]; $rows=[];
            $offset=max(0,(int)($data['offset']??0));
            foreach (array_slice(array_values($all['checklists']),$offset,8) as $c) {
                $buttons[]=bot_store::button($d,\core_text::substr($c['title'],0,50),'work.checklist',['id'=>$c['id'],'date'=>$all['date']]);
                $rows[]=[$c['title'],$c['today']['done'].'/'.$c['today']['total']];
            }
            if($offset){$buttons[]=bot_store::button($d,'← Назад','work.checklists',['offset'=>max(0,$offset-8)]);}
            if($offset+8<count($all['checklists'])){$buttons[]=bot_store::button($d,'Далее →','work.checklists',['offset'=>$offset+8]);}
            $buttons[]=bot_service::home($d);
            return bot_store::card('☑️ Чек-листы',$all['date'],$buttons,[],$rows);
        }
        if ($intent==='work.checklist') {
            $payload=checklist_service::present($id,(string)$data['id'],(string)$data['date']); $c=$payload['current'];
            if (!$payload['canedit'] || !empty($c['hasfinal'])) {
                return bot_store::card('☑️ '.$c['title'],'Сохранено '.$c['today']['done'].'/'.$c['today']['total'].'. Исправление отправленного чек-листа — с причиной в Академии.',[bot_service::home($d)]);
            }
            $fields=[];
            foreach (checklists::flat_items($c) as $key=>$item) {
                $fields[]=['key'=>(string)$item['id'],'label'=>(string)$item['title'],'choices'=>['1'=>'✓ Выполнено','0'=>'Не выполнено'],'required'=>true];
            }
            $fields[]=['key'=>'comment','label'=>'Комментарий к чек-листу'];
            return bot_forms::start($d,'☑️ '.$c['title'],$fields,'work.checklist.commit',
                ['id'=>$c['id'],'date'=>$data['date'],'revision'=>$c['today']['revision'],'definition'=>$c['definitionversion']]);
        }
        if ($intent==='work.checklist.commit') {
            $c=$data['context']; $v=$data['values'];
            if ($c['date']!==checklist_service::date_key()) { throw new \InvalidArgumentException('expired_bot_action'); }
            $comment=$v['comment']??''; unset($v['comment']); $answers=[];
            foreach ($v as $key=>$value) { $answers[$key]=['done'=>$value==='1','comment'=>'']; }
            $out=checklist_service::submit($id,(string)$c['id'],$answers,$comment,'final',(int)$c['revision'],'',(int)$c['definition']);
            bot_store::state($d,'menu'); return bot_store::card('✓ Чек-лист отправлен','Сохранено в Академии: '.$out['done'].'/'.$out['total'],[bot_service::home($d)]);
        }
        if ($intent==='work.report.commit') {
            $c=$data['context']; $v=$data['values']; $comment=$v['comment']??''; unset($v['comment']);
            $uploads=[];
            foreach($c['photokeys']??[] as $key){if(!empty($v[$key])){$uploads[]=bot_upload::received($d,$v[$key]);}$v[$key]=1;}
            unset($v['__photo']);
            service::report((int)$c['id'],$id,(int)$c['version'],$v,$comment,true,$uploads);
            bot_store::state($d,'menu'); return bot_store::card('✓ Отчёт отправлен','Результат сохранён в Академии и ожидает проверки.',[bot_service::home($d)]);
        }
        if ($intent==='work.return.commit') {
            learning_tasks::transition((int)$data['context']['id'],$id,'return',(int)$data['context']['version'],$data['values']['reason']);
            bot_store::state($d,'menu'); return bot_store::card('↩ На доработку','Комментарий и история сохранены. Сотрудник получит уведомление.',[bot_service::home($d)]);
        }
        $t=service::detail((int)($data['id']??0),$id);
        if (isset($data['version']) && (int)$data['version']!==$t['version']) { throw new \InvalidArgumentException('stale_revision'); }
        if (in_array($intent,['work.start','work.approve'],true)) {
            learning_tasks::transition($t['id'],$id,$intent==='work.start'?'start':'approve',$t['version']);
            return bot_store::card('✓ Сохранено','Состояние задачи обновлено в Академии.',[bot_store::button($d,'Открыть задачу','work.task',['id'=>$t['id']]),bot_service::home($d)]);
        }
        if ($intent==='work.return') {
            return bot_forms::start($d,'↩ Вернуть отчёт',[['key'=>'reason','label'=>'Что нужно исправить?','required'=>true]],'work.return.commit',['id'=>$t['id'],'version'=>$t['version']]);
        }
        if ($intent==='work.report') {
            if ($t['assigneeid']!==$id || !in_array($t['status'],['assigned','in_progress'],true)) { throw new \InvalidArgumentException('forbidden_scope'); }
            $fields=[];
            $photokeys=[];
            foreach ($t['fields'] as $field) {
                $item=['key'=>$field['key'],'label'=>$field['label'],'type'=>$field['type'],'required'=>$field['required']];
                if ($field['type']==='check') { $item['choices']=['1'=>'✓ Выполнено','0'=>'Не выполнено']; }
                $fields[]=$item;
                if($field['type']==='photo'){$photokeys[]=$field['key'];}
            }
            if(!$photokeys){$fields[]=['key'=>'__photo','label'=>'Фото результата · до 512 КБ','type'=>'photo','required'=>(bool)$t['photo']];$photokeys[]='__photo';}
            $fields[]=['key'=>'comment','label'=>'Опишите результат','required'=>!$t['fields']];
            return bot_forms::start($d,'📋 Отчёт: '.$t['title'],$fields,'work.report.commit',['id'=>$t['id'],'version'=>$t['version'],'photokeys'=>$photokeys]);
        }
        if($intent==='work.photos') {
            $view=bot_store::card('📷 Фото отчёта','Разрешённые фотографии выбранной задачи.',[bot_service::home($d)]);
            $photos=[];$native=0;
            foreach($t['files'] as $item){
                if(empty($item['image'])){continue;}
                $area=$item['version']?\local_ustar\task_files::RESULT:\local_ustar\task_files::ATTACHMENT;
                $path=$item['version']?'/v'.$item['version'].'/':'/';
                $file=get_file_storage()->get_file(\context_system::instance()->id,'local_ustar',$area,$t['id'],$path,$item['name']);
                $source=$file?json_decode((string)$file->get_source(),true):null;
                $tg=is_array($source)?($source['telegram_file_id']??''):'';
                if(is_string($tg) && preg_match('/\A[A-Za-z0-9_-]{1,256}\z/',$tg)){$photos[]=$tg;}else{$native++;}
                if(count($photos)>=5){break;}
            }
            $view['photos']=$photos;
            if($native){$view['text'].="\nФото, загруженные через браузер, пока просматриваются в Академии из офисной сети.";}
            return bot_store::guarded($view,['tasks'=>[$t['id']]]);
        }
        if ($intent!=='work.task') { throw new \InvalidArgumentException('invalid_bot_action'); }
        bot_store::state($d,'menu'); $buttons=[]; $target=['id'=>$t['id'],'version'=>$t['version']];
        if ($t['assigneeid']===$id && in_array($t['status'],['assigned','in_progress'],true)) {
            if ($t['status']==='assigned') { $buttons[]=bot_store::button($d,'▶ Взять в работу','work.start',$target); }
            if ($t['kind']!=='retraining') { $buttons[]=bot_store::button($d,'📤 Отправить отчёт','work.report',$target); }
        }
        if ($t['canreview'] && $t['status']==='in_review') {
            $buttons[]=bot_store::button($d,'✓ Принять результат','work.approve',$target);
            $buttons[]=bot_store::button($d,'↩ Вернуть с комментарием','work.return',$target);
        }
        if(array_filter($t['files'],static fn($file)=>!empty($file['image']))){$buttons[]=bot_store::button($d,'📷 Показать фото','work.photos',$target);}
        $buttons[]=bot_service::home($d); $last=$t['reports'][0]??null;
        $text=trim(html_entity_decode(strip_tags($t['description']),ENT_QUOTES,'UTF-8'));
        if ($last) { $text.="\n\nПоследний отчёт: ".\core_text::substr((string)$last->commenttext,0,1000); }
        return bot_store::guarded(bot_store::card('📋 '.$t['title'],\core_text::substr($text,0,1800),$buttons,[],
            [['Статус',bot_service::status_label($t['status'])],['Исполнитель',$t['assignee']],['Срок',self::date($t['dueat'])],['Версия',(string)$t['version']]]),['tasks'=>[$t['id']]]);
    }
    private static function company(\stdClass $d,array $data): array {
        if(!bot_actor::capability((int)$d->userid,'executive')){throw new \InvalidArgumentException('forbidden_scope');}
        $m=json_decode(\local_ustar\external\executive_get_dashboard::execute()['json'],true,32,JSON_THROW_ON_ERROR);
        bot_store::state($d,'menu');$offset=max(0,(int)($data['offset']??0));$buttons=[];
        $rows=[['Сотрудники',(string)$m['totalPeople']],['Без должности',(string)$m['unassignedPeople']],
            ['Учились за 30 дней',(string)$m['activeLearners30']],['Завершено курсов за 30 дней',(string)$m['completedCourses30']],
            ['Оценок работы за 30 дней',(string)$m['reviews30']],['Средняя оценка',$m['reviews30']?(string)$m['avgReviewScore']:'Нет оценок']];
        foreach(array_slice($m['departments'],$offset,6) as $dep){$rows[]=[$dep['name'],$dep['people'].' чел.'];}
        if($offset){$buttons[]=bot_store::button($d,'← Подразделения','work.company',['offset'=>max(0,$offset-6)]);}
        if($offset+6<count($m['departments'])){$buttons[]=bot_store::button($d,'Подразделения →','work.company',['offset'=>$offset+6]);}
        $buttons[]=bot_service::home($d);
        return bot_store::card('🏢 Компания · 30 дней','Сводка из Академии на момент запроса. Доступна по роли руководителя компании.',$buttons,
            [['label'=>'Назначена должность','value'=>$m['assignedPeople'],'total'=>$m['totalPeople']]],$rows);
    }
    public static function recipient_fields(): array {
        return [['key'=>'department','label'=>'Выберите подразделение','provider'=>'recipients.departments','required'=>true],
            ['key'=>'position','label'=>'Выберите должность','provider'=>'recipients.positions','required'=>true],
            ['key'=>'person','label'=>'Выберите сотрудника','provider'=>'recipients.people','required'=>true]];
    }
    private static function listing(\stdClass $d,string $intent,array $data): array {
        $id=(int)$d->userid; $scope=$intent==='work.mine'?'mine':'team';
        if ($intent==='work.company' && !bot_actor::capability($id,'executive')) { throw new \InvalidArgumentException('forbidden_scope'); }
        $summary=service::summary($id,$scope); $offset=max(0,(int)($data['offset']??0));
        $page=service::rows($id,$scope,$intent==='work.review'?'review':'active',(int)floor($offset/50));
        $items=array_slice($page['items'],$offset%50,8); bot_store::state($d,'menu'); $buttons=[]; $rows=[];
        foreach ($items as $t) {
            $buttons[]=bot_store::button($d,\core_text::substr($t['title'],0,52),'work.task',['id'=>$t['id']]);
            $rows[]=[$t['assignee'],bot_service::status_label($t['status'])];
        }
        if ($offset>0) { $buttons[]=bot_store::button($d,'← Назад',$intent,['offset'=>max(0,$offset-8)]); }
        if ($offset+count($items)<$page['total']) { $buttons[]=bot_store::button($d,'Далее →',$intent,['offset'=>$offset+count($items)]); }
        $buttons[]=bot_service::home($d);
        return bot_store::guarded(bot_store::card($intent==='work.mine'?'📋 Мои задачи':($intent==='work.review'?'✅ Очередь проверки':'📊 Сводка по задачам'),
            $items?'Выберите задачу.':'В этой выборке задач нет.',$buttons,[
                ['label'=>'Активные','value'=>$summary['active'],'total'=>$summary['total']],
                ['label'=>'На проверке','value'=>$summary['review'],'total'=>$summary['total']],
                ['label'=>'Просрочены','value'=>$summary['late'],'total'=>$summary['total']]],$rows),['tasks'=>array_column($items,'id')]);
    }
    private static function date(int $time): string {
        return $time?(new \DateTimeImmutable('@'.$time))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i'):'не указан';
    }
}
