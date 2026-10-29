<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** Server-owned finite forms. Callback values are opaque actions, never executable client data. */
final class bot_forms {
    public static function start(\stdClass $d,string $title,array $fields,string $commit,array $context=[]): array {
        if (!$fields || count($fields)>250) { throw new \InvalidArgumentException('invalid_bot_form'); }
        bot_store::state($d,'form.input',['title'=>$title,'fields'=>$fields,'index'=>0,'values'=>[],
            'commit'=>$commit,'context'=>$context]);
        return self::ask($d);
    }
    public static function route(\stdClass $d,string $intent,array $action,array $p): array {
        if($intent==='form.preview' && $d->phase==='form.confirm'){return self::preview($d,max(0,(int)($action['offset']??0)));}
        if($intent==='form.restart' && $d->phase==='form.confirm') {
            $f=bot_store::data($d);$f['index']=0;$f['values']=[];bot_store::state($d,'form.input',$f);return self::ask($d);
        }
        if ($d->phase!=='form.input') { throw new \InvalidArgumentException('expired_bot_action'); }
        $f=bot_store::data($d); $field=self::field($f);
        bot_guards::form($d,$f);
        if($intent==='form.back') {
            $f['index']=max(0,$f['index']-1);
            foreach(array_slice($f['fields'],$f['index']) as $next){unset($f['values'][$next['key']]);}
            bot_store::state($d,'form.input',$f);return self::ask($d);
        }
        if ($intent==='form.page') { return self::ask($d,max(0,(int)($action['offset']??0))); }
        if ($intent==='form.reference') { return self::ask($d,0,'',max(0,(int)($action['part']??0))); }
        $value=$intent==='form.answer'?($action['value']??''):trim($p['text']);
        if (($field['type']??'text')==='photo') { $value=$p['media']; }
        $choices=self::choices($d,$field,$f['values']);
        if ($choices && !array_key_exists((string)$value,$choices)) { return self::ask($d,0,'Выберите вариант кнопкой.'); }
        if (!is_scalar($value) || (strlen((string)$value)>4000)
                || (!empty($field['required']) && trim((string)$value)==='')) { return self::ask($d,0,'Заполните обязательное поле.'); }
        $value=(string)$value;
        if(($field['type']??'')==='email' && !validate_email($value)){return self::ask($d,0,'Проверьте адрес email.');}
        if (in_array($field['type']??'',['number','integer'],true) && ($value==='' || !is_numeric($value) || !is_finite((float)$value))) {
            return self::ask($d,0,'Укажите число.');
        }
        if(($field['type']??'')==='integer' && !preg_match('/\A[0-9]+\z/',$value)){return self::ask($d,0,'Укажите целое число.');}
        if(isset($field['maxlen']) && \core_text::strlen($value)>(int)$field['maxlen']){return self::ask($d,0,'Сократите ответ: максимум '.$field['maxlen'].' символов.');}
        if(in_array($field['type']??'',['number','integer'],true) && ((isset($field['min']) && (float)$value<$field['min']) || (isset($field['max']) && (float)$value>$field['max']))){return self::ask($d,0,'Число вне допустимого диапазона.');}
        if (($field['type']??'')==='date') {
            if (preg_match('/\A(\d{2})\.(\d{2})\.(\d{4})\z/',$value,$m)) { $value=$m[3].'-'.$m[2].'-'.$m[1]; }
            if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/',$value,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) {
                return self::ask($d,0,'Дата в формате ДД.ММ.ГГГГ.');
            }
        }
        if (($field['type']??'')==='clock' && !preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/',$value)) {
            return self::ask($d,0,'Время в формате ЧЧ:ММ.');
        }
        if(($field['type']??'')==='timezone' && !in_array($value,\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true)){
            return self::ask($d,0,'Укажите существующий часовой пояс, например Europe/Moscow.');
        }
        $f['values'][$field['key']]=$value; $f['index']++;
        if ($f['index']<count($f['fields'])) { bot_store::state($d,'form.input',$f); return self::ask($d); }
        bot_store::state($d,'form.confirm',$f);return self::preview($d);
    }
    public static function preview(\stdClass $d,int $offset=0): array {
        $f=bot_store::data($d);bot_guards::form($d,$f);$rows=[];$buttons=[];
        foreach (array_slice($f['fields'],$offset,8) as $item) {
            $choices=self::choices($d,$item,$f['values']); $v=$f['values'][$item['key']];
            if(($item['type']??'')==='photo'){$v=$v!==''?'Фото приложено':'Без фото';}
            $rows[]=[$item['label'],$choices[$v]??($v===''?'—':$v)];
        }
        if($offset){$buttons[]=bot_store::button($d,'← Предыдущие ответы','form.preview',['offset'=>max(0,$offset-8)]);}
        if($offset+8<count($f['fields'])){$buttons[]=bot_store::button($d,'Следующие ответы →','form.preview',['offset'=>$offset+8]);}
        $buttons[]=bot_store::button($d,'✓ Подтвердить',$f['commit'],['values'=>$f['values'],'context'=>$f['context']]);
        $buttons[]=bot_store::button($d,'Изменить ответы','form.restart');$buttons[]=bot_service::cancel($d);
        return bot_store::guarded(bot_store::card('Проверьте перед отправкой',$f['title']."\nПоля ".($offset+1).'–'.min(count($f['fields']),$offset+8).' из '.count($f['fields']),$buttons,[],$rows),bot_guards::form_guard($f));
    }
    private static function field(array $form): array {
        $field=$form['fields'][$form['index']];
        foreach($field['required_if']??[] as $key=>$matches){
            if(in_array($form['values'][$key]??'',(array)$matches,true)){$field['required']=true;}
        }
        return $field;
    }
    public static function ask(\stdClass $d,int $offset=0,string $error='',int $referencepart=0): array {
        $f=bot_store::data($d); $field=self::field($f);
        bot_guards::form($d,$f);
        if(!empty($field['reference'])) {
            $parts=(int)ceil(\core_text::strlen($field['reference'])/1050);
            if($referencepart<$parts) {
                $buttons=[];
                if($referencepart){$buttons[]=bot_store::button($d,'← Предыдущая часть','form.reference',['part'=>$referencepart-1]);}
                $buttons[]=bot_store::button($d,$referencepart+1<$parts?'Следующая часть →':'К оценке ответа →','form.reference',['part'=>$referencepart+1]);
                $buttons[]=bot_service::cancel($d);
                return bot_store::guarded(bot_store::card($f['title'],
                    'Ответ · часть '.($referencepart+1).' из '.$parts."\n\n".\core_text::substr($field['reference'],$referencepart*1050,1050),$buttons),bot_guards::form_guard($f));
            }
        }
        $choices=self::choices($d,$field,$f['values']); $buttons=[];
        if(isset($field['provider']) && !$choices){return bot_store::card($f['title'],'В этой области нет доступных вариантов. Проверьте назначение и права в Академии.',[bot_service::cancel($d)]);}
        foreach (array_slice($choices,$offset,8,true) as $key=>$label) {
            $buttons[]=bot_store::button($d,\core_text::substr((string)$label,0,60),'form.answer',['value'=>(string)$key]);
        }
        if ($offset>0) { $buttons[]=bot_store::button($d,'← Назад','form.page',['offset'=>max(0,$offset-8)]); }
        if ($offset+8<count($choices)) { $buttons[]=bot_store::button($d,'Далее →','form.page',['offset'=>$offset+8]); }
        if (empty($field['required']) && !$choices) { $buttons[]=bot_store::button($d,'Пропустить','form.answer',['value'=>'']); }
        if($f['index']>0){$buttons[]=bot_store::button($d,'← Предыдущий вопрос','form.back');}
        $buttons[]=bot_service::cancel($d);
        $hint=$choices?'Выберите вариант.':($field['hint']??'Ответьте следующим сообщением.');
        if (($field['type']??'')==='photo') { $hint='Пришлите фото результата как фотографию. Документ не заменяет фото.'; }
        return bot_store::guarded(bot_store::card($f['title'],($error!==''?'⚠️ '.$error."\n\n":'').$field['label']."\n".$hint,$buttons,
            [['label'=>'Заполнено','value'=>(int)$f['index'],'total'=>count($f['fields'])]]),bot_guards::form_guard($f));
    }
    private static function choices(\stdClass $d,array $field,array $values): array {
        if (isset($field['choices'])) { return $field['choices']; }
        $provider=$field['provider']??'';
        if (str_starts_with($provider,'recipients.')) {
            $options=\local_ustar\task_workspace\recipients::options((int)$d->userid,$values['department']??'',$values['position']??'');
            return $options[substr($provider,11)]??[];
        }
        if ($provider==='registration.departments') { return array_column(\local_ustar\registration_service::departments(),'name','id'); }
        if(str_starts_with($provider,'staffing.')) {
            $scope=\local_ustar\staffing_requests::manager_scope((int)$d->userid);
            if(empty($scope['allowed'])){return [];}
            return $provider==='staffing.positions'?array_column($scope['positions'],'name','id'):
                array_column($scope['employees'],'fullname','id');
        }
        if ($provider==='retrain.topics') {
            return array_column(\local_ustar\forced_retraining::topics_for_user((int)$d->userid,(int)($values['person']??0)),'title','policyid');
        }
        return [];
    }
}
