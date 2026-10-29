<?php
namespace local_ustar\connect;
use local_ustar\adaptation_service as adaptation;
defined('MOODLE_INTERNAL') || die();

/** Existing independent adaptation reports and decisions, without exporting another side's reflections. */
final class bot_adaptation {
    public static function route(\stdClass $d,string $intent,array $data): array {
        global $DB;
        $id=(int)$d->userid;$hrd=adaptation::is_hrd_actor($id);
        if($intent==='people.adaptations') {
            if($hrd) {
                $context=adaptation::hr_control_context($id);$items=$context['cycles'];
            } else {
                $records=$DB->get_records_select('local_ustar_adaptations',
                    '(userid=:employee OR managerid=:manager) AND status IN (:active,:escalated)',
                    ['employee'=>$id,'manager'=>$id,'active'=>'active','escalated'=>'escalated'],'id DESC','*',0,50);
                $items=[];foreach($records as $record){$items[]=['id'=>(int)$record->id,'employee'=>\local_ustar\task_workspace\service::name((int)$record->userid),'statuslabel'=>$record->status];}
            }
            $offset=max(0,(int)($data['offset']??0));bot_store::state($d,'menu');$buttons=[];$rows=[];
            foreach(array_slice($items,$offset,8) as $item){$buttons[]=bot_store::button($d,\core_text::substr($item['employee'],0,50),'people.adaptation',['id'=>$item['id']]);$rows[]=[$item['employee'],$item['statuslabel']];}
            if($offset+8<count($items)){$buttons[]=bot_store::button($d,'Далее →','people.adaptations',['offset'=>$offset+8]);}
            if($offset){$buttons[]=bot_store::button($d,'← Назад','people.adaptations',['offset'=>max(0,$offset-8)]);}
            $buttons[]=bot_service::home($d);
            return bot_store::card('🧭 Адаптация',$items?'Выберите цикл.':'Активных циклов нет.',$buttons,[],$rows);
        }
        $aid=(int)($data['id']??$data['context']['id']??0);
        $record=$DB->get_record('local_ustar_adaptations',['id'=>$aid],'*',MUST_EXIST);
        $participant=(int)$record->userid===$id || (int)$record->managerid===$id;
        if(!$participant && !$hrd){throw new \InvalidArgumentException('forbidden_scope');}
        if($intent==='people.adaptation.prepare') {
            return bot_confirmation::prepare($d,'execute.adaptation.decision',$data,'Решение по адаптации №'.$aid."\n".bot_store::json($data['values']));
        }
        if($intent==='people.adaptation.decision') {
            $choices=$hrd&&$record->status==='escalated'?['complete'=>'Завершить','extend'=>'Продлить']:
                ['complete'=>'Завершить','extend'=>'Продлить','escalate'=>'Передать HRD'];
            if(!$hrd && (int)$record->managerid!==$id){throw new \InvalidArgumentException('forbidden_scope');}
            return bot_forms::start($d,'Решение по адаптации',[
                ['key'=>'decision','label'=>'Выберите решение','choices'=>$choices,'required'=>true],
                ['key'=>'reason','label'=>'Основание решения','required'=>true],
                ['key'=>'days','label'=>'Рабочих дней продления (0 — без продления)','type'=>'number','required'=>true]],
                'people.adaptation.prepare',['id'=>$aid,'status'=>$record->status,'modified'=>(int)$record->timemodified,'hrd'=>$hrd&&$record->status==='escalated']);
        }
        if(!$participant){
            $context=adaptation::hr_control_context($id);$rows=[];$buttons=[];
            foreach($context['cases'] as $case){
                if((int)$case['adaptationid']!==$aid){continue;}
                $rows[]=[$case['severity'],$case['summary']];
                $buttons[]=bot_store::button($d,$case['isopen']?'Взять случай в работу':'Закрыть случай','people.adaptation.case',
                    ['id'=>$aid,'fingerprint'=>$case['fingerprint'],'operation'=>$case['isopen']?'take':'resolve']);
            }
            if($record->status==='escalated'){$buttons[]=bot_store::button($d,'Подготовить решение','people.adaptation.decision',['id'=>$aid]);}
            $buttons[]=bot_service::home($d);
            if($intent==='people.adaptation.case') {
                return bot_forms::start($d,'Решение по случаю',[['key'=>'reason','label'=>'Комментарий','required'=>true]],'people.adaptation.case.prepare',['id'=>$aid]+$data);
            }
            if($intent==='people.adaptation.case.prepare') {
                return bot_confirmation::prepare($d,'execute.adaptation.case',$data,'Обработка случая адаптации №'.$aid."\n".$data['values']['reason']);
            }
            return bot_store::card('🧭 Контроль HRD · №'.$aid,'Состояние: '.$record->status,$buttons,[],$rows);
        }
        if($intent==='people.adaptation.daily.commit') {
            $v=$data['values'];$v['expectedworkdate']=$data['context']['workdate'];
            adaptation::submit_daily($aid,$id,$v);
            bot_store::state($d,'menu');return bot_store::card('✓ Ежедневный лист отправлен','Ответы сохранены в вашем цикле адаптации.',[bot_service::home($d)]);
        }
        if($intent==='people.adaptation.final.commit') {
            $v=$data['values'];$v['expectedround']=$data['context']['round'];adaptation::submit_final_report($aid,$id,$v);
            bot_store::state($d,'menu');return bot_store::card('✓ Итоговый отчёт отправлен','Другая сторона заполняет свой отчёт независимо.',[bot_service::home($d)]);
        }
        $context=adaptation::page_context($aid,$id);
        if($intent==='people.adaptation.daily') {
            if(!$context['canfill']){throw new \InvalidArgumentException('stale_revision');}
            $fields=[['key'=>'mastered','label'=>$context['q1'],'required'=>true],['key'=>'succeeded','label'=>$context['q2'],'required'=>true],
                ['key'=>'difficult','label'=>$context['q3'],'required'=>true],['key'=>'help','label'=>$context['q4'],'choices'=>['yes'=>'Да','no'=>'Нет'],'required'=>true],
                ['key'=>'ready','label'=>$context['q5'],'choices'=>['yes'=>'Да','partly'=>'Частично','no'=>'Нет'],'required'=>true],
                ['key'=>'action','label'=>'Конкретное следующее действие (обязательно, если нужна помощь или готовность неполная)']];
            if($context['isoverdue']){$fields[]=['key'=>'overduereason','label'=>'Причина просрочки','required'=>true];}
            return bot_forms::start($d,'Лист адаптации · '.$context['workdatelabel'],$fields,'people.adaptation.daily.commit',['id'=>$aid,'workdate'=>$context['workdate']]);
        }
        $final=adaptation::page_context($aid,$id,'final');
        if($intent==='people.adaptation.final') {
            if(!$final['canfinalfill']){throw new \InvalidArgumentException('stale_revision');}
            return bot_forms::start($d,'Итог адаптации',[
                ['key'=>'mastered','label'=>'Что освоено?','required'=>true],['key'=>'risks','label'=>'Какие риски остались?','required'=>true],
                ['key'=>'support','label'=>'Какая поддержка нужна?','required'=>true],
                ['key'=>'ready','label'=>'Готовность','choices'=>['yes'=>'Готов','partly'=>'Частично','no'=>'Не готов'],'required'=>true]],
                'people.adaptation.final.commit',['id'=>$aid,'round'=>$final['round']]);
        }
        bot_store::state($d,'menu');$buttons=[];
        if(!empty($context['canfill'])){$buttons[]=bot_store::button($d,'Заполнить ежедневный лист','people.adaptation.daily',['id'=>$aid]);}
        if($final['canfinalfill']){$buttons[]=bot_store::button($d,'Заполнить итоговый отчёт','people.adaptation.final',['id'=>$aid]);}
        if($final['canfinaldecide']){$buttons[]=bot_store::button($d,'Подготовить решение','people.adaptation.decision',['id'=>$aid]);}
        $buttons[]=bot_service::home($d);
        return bot_store::card('🧭 Адаптация · №'.$aid,$context['perspectivelabel']."\nСостояние: ".$record->status,$buttons,[],[
            ['Ежедневные листы',$context['dailycomplete']?'Завершены':'Продолжаются'],['Ваш итоговый отчёт',$final['hasownfinal']?'Отправлен':'Не отправлен']]);
    }
    public static function execute(string $intent,array $data,int $id): void {
        global $DB;
        $c=$data['context'];$v=$data['values'];$aid=(int)$c['id'];
        if($intent==='execute.adaptation.case') {
            if($c['operation']==='take'){adaptation::take_case($aid,$c['fingerprint'],$id);}
            else{adaptation::resolve_case($aid,$c['fingerprint'],$id,$v['reason']);}return;
        }
        if($intent!=='execute.adaptation.decision'){throw new \InvalidArgumentException('invalid_bot_action');}
        $a=$DB->get_record('local_ustar_adaptations',['id'=>$aid],'*',MUST_EXIST);
        if($a->status!==$c['status'] || (int)$a->timemodified!==(int)$c['modified']){throw new \InvalidArgumentException('stale_revision');}
        if(!empty($c['hrd'])){adaptation::hrd_final_decision($aid,$id,$v['decision'],$v['reason'],(int)$v['days']);}
        else{adaptation::manager_final_decision($aid,$id,$v['decision'],$v['reason'],(int)$v['days']);}
    }
}
