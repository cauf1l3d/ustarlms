<?php
namespace local_ustar\connect;
use local_ustar\{staffing_requests,hr_access,route_quiz_grading,forced_retraining};
defined('MOODLE_INTERNAL') || die();

/** Role-specific forms, native domain validation and independently confirmed sensitive commands. */
final class bot_people {
    public static function route(\stdClass $d,string $intent,array $data,string $text): array {
        $id=(int)$d->userid;
        if ($intent==='people.confirmation.status') { return bot_confirmation::status($d,$data['token']); }
        if (str_starts_with($intent,'people.adapt')) { return bot_adaptation::route($d,$intent,$data); }
        if(in_array($intent,['people.hire','people.terminate'],true)) {
            if(empty(staffing_requests::manager_scope($id)['allowed'])){throw new \InvalidArgumentException('forbidden_scope');}
            $fields=$intent==='people.hire'?[
                ['key'=>'firstname','label'=>'Имя нового сотрудника','required'=>true,'maxlen'=>100],
                ['key'=>'lastname','label'=>'Фамилия нового сотрудника','required'=>true,'maxlen'=>100],
                ['key'=>'positionid','label'=>'Должность','provider'=>'staffing.positions','required'=>true]]:[
                ['key'=>'employeeid','label'=>'Сотрудник','provider'=>'staffing.employees','required'=>true],
                ['key'=>'reason','label'=>'Причина увольнения','required'=>true]];
            $fields[]=['key'=>'date','label'=>$intent==='people.hire'?'Дата выхода · ДД.ММ.ГГГГ':'Последний рабочий день · ДД.ММ.ГГГГ','type'=>'date','required'=>true];
            $fields[]=['key'=>'comment','label'=>'Комментарий к заявке'];
            return bot_forms::start($d,$intent==='people.hire'?'👤 Заявка на найм':'👤 Заявка на увольнение',$fields,
                'people.staffing.prepare',['type'=>$intent==='people.hire'?'hire':'terminate']);
        }
        if($intent==='people.staffing.prepare') {
            $v=$data['values'];$scope=staffing_requests::manager_scope($id);
            if(empty($scope['allowed'])){throw new \InvalidArgumentException('forbidden_scope');}
            $hire=$data['context']['type']==='hire';
            $name=$hire?$v['firstname'].' '.$v['lastname']:(array_column($scope['employees'],'fullname','id')[$v['employeeid']]??'');
            if($name===''){throw new \InvalidArgumentException('forbidden_scope');}
            return bot_confirmation::prepare($d,'execute.staffing.create',$data,
                ($hire?'Заявка на найм: ':'Заявка на увольнение: ').$name."\nДата: ".$v['date']."\n".($v['reason']??$v['comment']??'').
                "\n\nСоздание заявки не меняет статус сотрудника. Кадровое решение принимает уполномоченный сотрудник.");
        }
        if ($intent==='people.retrain') {
            if (!forced_retraining::can_manage($id)) { throw new \InvalidArgumentException('forbidden_scope'); }
            return bot_forms::start($d,'🔁 Повторный курс',array_merge(bot_work::recipient_fields(),[
                ['key'=>'topic','label'=>'Выберите ранее пройденный материал','provider'=>'retrain.topics','required'=>true],
                ['key'=>'reason','label'=>'Причина повторного курса','required'=>true]]),'people.retrain.prepare');
        }
        if ($intent==='people.retrain.prepare') {
            $v=$data['values']; \local_ustar\task_workspace\recipients::validate($id,(int)$v['person'],$v['department'],$v['position']);
            $topics=array_column(forced_retraining::topics_for_user($id,(int)$v['person']),'title','policyid');
            $person=\local_ustar\org::person((int)$v['person']);
            return bot_confirmation::prepare($d,'execute.retrain',$data,'Повторный курс: '.$person['fullname']."\n".($topics[$v['topic']]??'Материал недоступен')."\nПричина: ".$v['reason']);
        }
        if (str_starts_with($intent,'people.grade')) { return self::grades($d,$intent,$data); }
        if ($intent==='people.requests') {
            $all=staffing_requests::list_for($id); $offset=max(0,(int)($data['offset']??0));
            bot_store::state($d,'menu');$buttons=[];$rows=[];
            if(!empty(staffing_requests::manager_scope($id)['allowed'])){
                $buttons[]=bot_store::button($d,'➕ Заявка на найм','people.hire');
                $buttons[]=bot_store::button($d,'➖ Заявка на увольнение','people.terminate');
            }
            foreach (array_slice($all,$offset,8) as $item) {
                $buttons[]=bot_store::button($d,\core_text::substr($item['fullname'].' · '.$item['typelabel'],0,58),'people.request',['id'=>$item['id']]);
                $rows[]=[$item['fullname'],$item['statuslabel']];
            }
            if ($offset+8<count($all)) { $buttons[]=bot_store::button($d,'Далее →','people.requests',['offset'=>$offset+8]); }
            if ($offset) { $buttons[]=bot_store::button($d,'← Назад','people.requests',['offset'=>max(0,$offset-8)]); }
            $buttons[]=bot_service::home($d);
            return bot_store::card('👤 Кадровые заявки',$all?'Выберите заявку.':'Доступных заявок нет.',$buttons,[],$rows);
        }
        $request=null;
        foreach(staffing_requests::list_for($id) as $item) { if ($item['id']===(int)($data['id']??$data['context']['id']??0)) { $request=$item;break; } }
        if (!$request) { throw new \InvalidArgumentException('forbidden_scope'); }
        if ($intent==='people.request') {
            bot_store::state($d,'menu');$buttons=[];
            if ($request['canapprove']) { $buttons[]=bot_store::button($d,'Одобрить','people.request.approve',['id'=>$request['id']]); }
            if ($request['canreview']) { $buttons[]=bot_store::button($d,'Отклонить','people.request.reject',['id'=>$request['id']]); }
            $buttons[]=bot_service::home($d);
            return bot_store::guarded(bot_store::card('👤 '.$request['fullname'],$request['typelabel'],$buttons,[],[
                ['Подразделение',$request['department']],['Должность',$request['position']],['Статус',$request['statuslabel']]]),['staffing'=>$request['id']]);
        }
        if (!$request['canreview']) { throw new \InvalidArgumentException('forbidden_scope'); }
        if ($intent==='people.request.prepare') {
            return bot_store::guarded(bot_confirmation::prepare($d,'execute.staffing',$data,$request['typelabel'].': '.$request['fullname']."\nРешение: ".
                ($data['context']['decision']==='approved'?'Одобрить':'Отклонить')."\nКомментарий: ".($data['values']['reviewcomment']??'—')),['staffing'=>$request['id']]);
        }
        if (!in_array($intent,['people.request.approve','people.request.reject'],true)) { throw new \InvalidArgumentException('invalid_bot_action'); }
        $approve=$intent==='people.request.approve';$fields=[];
        if ($approve && $request['isregistration']) {
            $fields[]=['key'=>'positionid','label'=>'Подтвердите должность','choices'=>array_column($request['departmentpositions'],'name','id'),'required'=>true];
        }
        if ($approve && $request['ishire']) {
            $fields[]=['key'=>'username','label'=>'Логин нового сотрудника','required'=>true];
            $fields[]=['key'=>'email','label'=>'Email нового сотрудника','required'=>true];
        }
        $fields[]=['key'=>'reviewcomment','label'=>$approve?'Комментарий к решению':'Причина отказа','required'=>!$approve];
        return bot_forms::start($d,$approve?'Одобрение заявки':'Отклонение заявки',$fields,'people.request.prepare',
            ['id'=>$request['id'],'decision'=>$approve?'approved':'rejected','stamp'=>self::request_stamp($request['id'])]);
    }
    private static function grades(\stdClass $d,string $intent,array $data): array {
        $id=(int)$d->userid; hr_access::require_grader();
        if ($intent==='people.grades') {
            $all=array_values(array_filter(route_quiz_grading::attempts(),static fn($r)=>$r['ispending']));
            $offset=max(0,(int)($data['offset']??0));bot_store::state($d,'menu');$buttons=[];$rows=[];
            foreach(array_slice($all,$offset,8) as $a) {
                $buttons[]=bot_store::button($d,\core_text::substr($a['employee'].' · '.$a['quizname'],0,58),'people.grade',['attempt'=>$a['attemptid']]);
                $rows[]=[$a['employee'],$a['pending'].' ответов'];
            }
            if($offset+8<count($all)){$buttons[]=bot_store::button($d,'Далее →','people.grades',['offset'=>$offset+8]);}
            if($offset){$buttons[]=bot_store::button($d,'← Назад','people.grades',['offset'=>max(0,$offset-8)]);}
            $buttons[]=bot_service::home($d);
            return bot_store::card('📝 Проверки аттестаций','Только завершённые попытки, доступные по вашим правам.',$buttons,[],$rows);
        }
        $attempt=(int)($data['attempt']??$data['context']['attempt']??0);
        $a=route_quiz_grading::detail($attempt);
        if ($intent==='people.grade.prepare') {
            $summary='Ручная оценка: '.$a['employee'].' · '.$a['quizname'];
            foreach($a['questions'] as $q){if($q['needgrading']){$summary.="\nОтвет №".$q['number'].': '.$data['values']['mark_'.$q['slot']].'/'.$q['maxmark'];}}
            return bot_store::guarded(bot_confirmation::prepare($d,'execute.grade',$data,$summary),['attempt'=>$attempt]);
        }
        if ($intent!=='people.grade') { throw new \InvalidArgumentException('invalid_bot_action'); }
        $fields=[];
        foreach($a['questions'] as $q) {
            if(!$q['needgrading']){continue;}
            $body=self::plain($q['questionhtml'])."\nОтвет: ".self::plain($q['responsehtml']);
            $fields[]=['key'=>'mark_'.$q['slot'],'label'=>'Ответ №'.$q['number']." · Баллы (0–".$q['maxmark'].')',
                'reference'=>$body,'type'=>'number','min'=>0,'max'=>(float)$q['maxmark'],'required'=>true];
            $fields[]=['key'=>'comment_'.$q['slot'],'label'=>'Комментарий к ответу №'.$q['number']];
        }
        if (!$fields) { return bot_store::card('✓ Проверка завершена','Нет ответов, ожидающих ручной оценки.',[bot_service::home($d)]); }
        return bot_forms::start($d,'📝 '.$a['employee'],$fields,'people.grade.prepare',['attempt'=>$attempt,'stamp'=>self::grade_stamp($a)]);
    }
    public static function execute(string $intent,array $data,int $id): void {
        $v=$data['values']??[];$c=$data['context']??[];
        if($intent==='execute.staffing.create') {
            global $USER;
            $v['requesteddate']=(new \DateTimeImmutable($v['date'].' 12:00',\core_date::get_user_timezone_object($USER)))->getTimestamp();
            if($c['type']==='hire'){staffing_requests::create_hire($id,$v);}else{staffing_requests::create_termination($id,$v);}
            return;
        }
        if(str_starts_with($intent,'execute.adaptation.')) { bot_adaptation::execute($intent,$data,$id);return; }
        if($intent==='execute.retrain') {
            \local_ustar\task_workspace\recipients::validate($id,(int)$v['person'],$v['department'],$v['position']);
            forced_retraining::assign($id,(int)$v['person'],[(int)$v['topic']],$v['reason']);return;
        }
        if($intent==='execute.grade') {
            hr_access::require_grader();$a=route_quiz_grading::detail((int)$c['attempt']);
            if(!hash_equals($c['stamp'],self::grade_stamp($a))){throw new \InvalidArgumentException('stale_revision');}
            $grades=[];
            foreach($a['questions'] as $q){if($q['needgrading']){$grades[$q['slot']]=['mark'=>(float)$v['mark_'.$q['slot']],'comment'=>$v['comment_'.$q['slot']]??''];}}
            route_quiz_grading::manual_grade_batch((int)$c['attempt'],$grades,$id);return;
        }
        if($intent==='execute.staffing') {
            if(!hash_equals($c['stamp'],self::request_stamp((int)$c['id']))){throw new \InvalidArgumentException('stale_revision');}
            $v['password']='Aa9!'.bin2hex(random_bytes(32));
            $result=staffing_requests::review((int)$c['id'],$c['decision'],$id,$v);
            if(!empty($result['createduserid'])){set_user_preference('auth_forcepasswordchange',1,(int)$result['createduserid']);}
            return;
        }
        throw new \InvalidArgumentException('invalid_bot_action');
    }
    private static function request_stamp(int $id): string {
        global $DB;
        return hash('sha256',json_encode($DB->get_record('local_ustar_staff_requests',['id'=>$id],'*',MUST_EXIST)));
    }
    private static function grade_stamp(array $a): string {
        return hash('sha256',bot_store::json(['attempt'=>$a['attemptid'],'version'=>$a['versionid'],'questions'=>array_map(
            static fn($q)=>[$q['slot'],$q['mark'],$q['needgrading'],$q['maxmark']],$a['questions'])]));
    }
    private static function plain(string $text): string { return html_entity_decode(strip_tags($text),ENT_QUOTES,'UTF-8'); }
}
