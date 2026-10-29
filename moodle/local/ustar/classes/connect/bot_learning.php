<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Read-only personal learning snapshot and canonical inbox. Never marks a course complete. */
final class bot_learning {
    public static function route(\stdClass $d,string $intent,array $data,string $text): array {
        $id=(int)$d->userid;
        if(in_array($intent,['learn.team','learn.person'],true)) {
            return self::team($d,$intent,$data);
        }
        if ($intent==='learn.read') {
            \local_ustar\communication::mark_notification($id,(int)$data['id'],(string)$data['source']);
            $intent='learn.inbox';
        }
        if ($intent==='learn.inbox') {
            $all=\local_ustar\communication::notifications($id,100); $offset=max(0,(int)($data['offset']??0));
            $items=array_slice($all,$offset,6); bot_store::state($d,'menu'); $rows=[];$buttons=[];
            foreach ($items as $item) {
                $rows[]=[$item['subject'],$item['message']];
                if (!empty($item['unread'])) {
                    $buttons[]=bot_store::button($d,'Прочитано: '.\core_text::substr($item['subject'],0,32),'learn.read',
                        ['id'=>$item['id'],'source'=>$item['source']]);
                }
            }
            if ($offset>0) { $buttons[]=bot_store::button($d,'← Назад','learn.inbox',['offset'=>max(0,$offset-6)]); }
            if ($offset+6<count($all)) { $buttons[]=bot_store::button($d,'Далее →','learn.inbox',['offset'=>$offset+6]); }
            $buttons[]=bot_service::home($d);
            return bot_store::card('🔔 Мои уведомления',$items?'Последние события Академии.':'Новых событий нет.',$buttons,[],$rows);
        }
        if (!in_array($intent,['learn.mine','learn.results'],true)) { throw new \InvalidArgumentException('invalid_bot_action'); }
        $identity=\local_ustar\organization_identity::resolve($id);
        $snapshot=\local_ustar\route_model::read_only_snapshot((string)$identity['positionid'],$id);
        bot_store::state($d,'menu'); $rows=[];$buttons=[];$offset=max(0,(int)($data['offset']??0));$all=$snapshot['points']??[];
        foreach (array_slice($all,$offset,8) as $point) {
            $rows[]=[$point['title'],$point['done']?'✓ Завершён':($point['current']?'▶ Следующий':'Позже')];
        }
        if($intent==='learn.results'){
            $rows=[];
            $all=\local_ustar\assessment_summary::for_employee($id,$id,$snapshot['points']??[]);
            foreach(array_slice($all,$offset,8) as $result){
                $rows[]=[$result['quizname'],$result['statuslabel'].' · '.($result['lastscore']??'—')];
            }
            if(!$rows){$rows[]=['Аттестации','Завершённых попыток пока нет'];}
        }
        if($offset){$buttons[]=bot_store::button($d,'← Назад',$intent,['offset'=>max(0,$offset-8)]);}
        if($offset+8<count($all)){$buttons[]=bot_store::button($d,'Далее →',$intent,['offset'=>$offset+8]);}
        $buttons[]=bot_service::home($d);
        $current=$snapshot['currentpoint']['title']??'';
        $text=empty($snapshot['ok'])?'Для вашей должности маршрут пока не опубликован.':
            ($current!==''?'Следующий шаг: '.$current:'Все текущие шаги завершены.');
        $text.="\n\nПолный курс, Quiz и SCORM открываются в Академии из офисной сети. Просмотр сообщения не засчитывает прохождение.";
        return bot_store::card($intent==='learn.results'?'📊 Мои результаты':'🎓 Мой маршрут',$text,$buttons,
            [['label'=>'Маршрут','value'=>(int)($snapshot['donepoints']??0),'total'=>(int)($snapshot['totalpoints']??0)]],$rows);
    }
    private static function team(\stdClass $d,string $intent,array $data): array {
        $id=(int)$d->userid;bot_store::state($d,'menu');$rows=[];$buttons=[];
        if($intent==='learn.person') {
            $target=(int)$data['person'];$model=\local_ustar\department_learning::detail($id,$target);
            $snapshot=$model['snapshot'];$person=$model['person'];
            foreach(array_slice($snapshot['points']??[],max(0,(int)($data['offset']??0)),8) as $point) {
                $rows[]=[$point['title'],!empty($point['done'])?'✓ Завершён':(!empty($point['current'])?'▶ Следующий':'Позже')];
            }
            foreach($model['assessments']??[] as $a) {$rows[]=[$a['quizname'],$a['statuslabel'].' · '.($a['lastscore']??'—')];}
            $offset=max(0,(int)($data['offset']??0));
            if($offset){$buttons[]=bot_store::button($d,'← Предыдущие шаги','learn.person',['person'=>$target,'offset'=>max(0,$offset-8)]);}
            if($offset+8<count($snapshot['points']??[])){$buttons[]=bot_store::button($d,'Следующие шаги →','learn.person',['person'=>$target,'offset'=>$offset+8]);}
            $buttons[]=bot_store::button($d,'← Команда','learn.team');$buttons[]=bot_service::home($d);
            return bot_store::guarded(bot_store::card('🎓 '.$person['fullname'],
                $person['position']."\nСледующий шаг: ".($snapshot['currentpoint']['title']??'нет текущего шага'),$buttons,
                [['label'=>'Маршрут','value'=>(int)($snapshot['donepoints']??0),'total'=>(int)($snapshot['totalpoints']??0)]],$rows),['employees'=>[$target]]);
        }
        $model=\local_ustar\department_learning::for_manager($id);
        if(empty($model['allowed'])){throw new \InvalidArgumentException('forbidden_scope');}
        $people=array_values($model['people']);$offset=max(0,(int)($data['offset']??0));$items=array_slice($people,$offset,8);
        foreach($items as $person){
            $rows[]=[$person['fullname'],$person['statelabel'].' · '.$person['donepoints'].'/'.$person['totalpoints']];
            $buttons[]=bot_store::button($d,$person['fullname'],'learn.person',['person'=>$person['id']]);
        }
        if($offset){$buttons[]=bot_store::button($d,'← Назад','learn.team',['offset'=>max(0,$offset-8)]);}
        if($offset+8<count($people)){$buttons[]=bot_store::button($d,'Далее →','learn.team',['offset'=>$offset+8]);}
        $buttons[]=bot_service::home($d);$s=$model['summary'];
        return bot_store::guarded(bot_store::card('🎓 Обучение команды','Текущие маршруты сотрудников в вашей области доступа.',$buttons,
            [['label'=>'Всё актуально','value'=>$s['complete'],'total'=>$s['total']],
             ['label'=>'В процессе','value'=>$s['inprogress'],'total'=>$s['total']],
             ['label'=>'Не начат','value'=>$s['notstarted'],'total'=>$s['total']]],$rows),['employees'=>array_column($items,'id')]);
    }
}
