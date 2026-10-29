<?php
namespace local_ustar\connect;
defined('MOODLE_INTERNAL') || die();

/** One fixed bot operation: canonical actor, durable replay guard, bounded dialog router. */
final class bot_service {
    public static function update(identity $sender, array $input): array {
        global $CFG;
        if (($CFG->ustar_connect_bot_enabled??false)!==true) { throw new \InvalidArgumentException('bot_disabled'); }
        $p=bot_request::validate($input,time());
        if ($sender->provider!==$p['provider'] || $sender->tenant!==$p['tenant'] || $sender->subject!==$p['subject']) {
            throw new \InvalidArgumentException('invalid_bot_request');
        }
        try {
        $result=identity_service::locked(function() use($sender,$p):array {
            global $DB;
            $userid=bot_actor::active($sender); $link=bot_actor::link($sender);
            $d=bot_store::dialog($sender,$userid,$link);
            $event=bot_store::event_key($sender,$p['event']);
            // Receive time authenticates freshness, not business intent; a re-polled callback
            // has the same event/action even when its verified receive timestamp changes.
            $hash=hash('sha256',bot_store::json(array_diff_key($p,['auth_date'=>true])));
            $old=$DB->get_record('local_ustar_conn_updates',['eventhash'=>$event]);
            if ($old) {
                if (!hash_equals($old->authority,$d->authority)) { return self::menu($d,$link); }
                if (!hash_equals($old->payloadhash,$hash)) { throw new \InvalidArgumentException('idempotency_conflict'); }
                return json_decode($old->responsejson,true,32,JSON_THROW_ON_ERROR);
            }
            if ((int)$d->ratewindow<time()-60) { $d->ratewindow=time(); $d->ratecount=0; }
            if (++$d->ratecount>40) { throw new \InvalidArgumentException('rate_limited'); }
            bot_store::save($d); // Count rejected inputs too, outside any business rollback.
            $action=$p['callback']!==''?bot_store::resolve($d,$p['callback']):null;
            $intent=$action?$action->intent:self::command($p['text']);
            $data=$action?json_decode($action->payload,true,32,JSON_THROW_ON_ERROR):[];
            $operate=function() use($d,$link,$p,$action,$intent,$data):array {
                if ($action && $action->status==='done' && !self::read_intent($intent)) {
                    // Repeated writes return their receipt. An old answered form never advances twice.
                    if(str_starts_with($intent,'form.')) {
                        return $d->phase==='form.input'?bot_forms::ask($d):self::menu($d,$link);
                    }
                    return json_decode($action->resultjson,true,32,JSON_THROW_ON_ERROR);
                }
                if ($action && !in_array($action->status,['issued','done'],true)) { throw new \InvalidArgumentException('expired_bot_action'); }
                if (in_array($intent,['menu','cancel','help'],true)) { return self::menu($d,$link); }
                if (!(int)$d->userid) { return bot_registration::route($d,$link,$intent,$data,$p['text']); }
                if ($intent==='input') { $intent=$d->phase; $data=bot_store::data($d); }
                return bot_actor::run((int)$d->userid,function() use($d,$intent,$data,$p):array {
                    return match(true) {
                        str_starts_with($intent,'form.')=>bot_forms::route($d,$intent,$data,$p),
                        str_starts_with($intent,'work.')=>bot_work::route($d,$intent,$data,$p),
                        str_starts_with($intent,'people.')=>bot_people::route($d,$intent,$data,$p['text']),
                        str_starts_with($intent,'learn.')=>bot_learning::route($d,$intent,$data,$p['text']),
                        str_starts_with($intent,'settings')=>self::settings($d,$intent,$data),
                        default=>self::menu($d,bot_actor::link(new identity($p['provider'],$p['tenant'],$p['subject']))),
                    };
                });
            };
            // Registration owns its email lock/real commit. Its callback stores the event receipt.
            if ($intent==='registration.confirm') {
                return bot_registration::commit($d,$link,$sender,$data,$event,$hash,$action);
            }
            $tx=$DB->start_delegated_transaction();
            try {
                $view=$operate();
                if ($action) { bot_store::done($action,$view); }
                bot_store::save($d);
                self::remember($d,$event,$hash,$view);
                $tx->allow_commit(); return $view;
            } catch(\Throwable $e) { $tx->rollback($e); }
        });
        } catch(\local_ustar\registration_validation_exception $error) {
            $fields=['email'=>'email','username'=>'логин','firstname'=>'имя','lastname'=>'фамилию','departmentid'=>'подразделение'];
            return self::input_problem($sender,'Проверьте '.($fields[$error->field]??'поля анкеты').'. Формат или занятые данные не позволяют отправить заявку.');
        } catch(\invalid_parameter_exception $error) {
            return self::input_problem($sender,'Проверьте ответы, обязательные поля и дату. Запись не принята; измените ответы и отправьте заново.');
        } catch(\required_capability_exception $error) {
            throw new \InvalidArgumentException('forbidden_scope');
        } catch(\dml_missing_record_exception $error) {
            throw new \InvalidArgumentException('expired_bot_action');
        }
        if(isset($result['_guard'])) {
            bot_guards::verify($result['_guard'],bot_actor::active($sender));unset($result['_guard']);
        }
        return $result;
    }
    private static function input_problem(identity $sender,string $text): array {
        return identity_service::locked(function()use($sender,$text):array {
            $id=bot_actor::active($sender);$link=bot_actor::link($sender);$d=bot_store::dialog($sender,$id,$link);
            $make=static function()use($d,$link,$text):array {
                $view=$d->phase==='form.confirm'?bot_forms::preview($d):self::menu($d,$link);
                $view['title']='⚠️ Проверьте данные';$view['text']=$text;
                $view['buttons']=array_values(array_filter($view['buttons'],static fn($b)=>$b['label']!=='✓ Подтвердить'));
                if(isset($view['_guard'])){bot_guards::verify($view['_guard'],(int)$d->userid);unset($view['_guard']);}
                return $view;
            };
            return $id?bot_actor::run($id,$make):$make();
        });
    }
    public static function remember(\stdClass $d,string $event,string $hash,array $view): void {
        global $DB;
        $DB->insert_record('local_ustar_conn_updates',(object)['eventhash'=>$event,'identityhash'=>$d->identityhash,
            'userid'=>$d->userid,'linkrevision'=>$d->linkrevision,'authority'=>$d->authority,'payloadhash'=>$hash,
            'responsejson'=>bot_store::json($view),'timecreated'=>time()]);
    }
    private static function command(string $text): string {
        return match(trim($text)) {
            '/start','/menu','Меню','Главная','Назад'=>'menu', '/cancel','Отмена'=>'cancel','/help','Помощь'=>'help',
            '/tasks','Мои задачи'=>'work.mine','/checklists','Чек-листы'=>'work.checklists',
            '/team','Моя команда'=>'work.team','/review','Проверить отчёты'=>'work.review',
            '/assign','Назначить задачу'=>'work.assign','/learn','Обучение'=>'learn.mine',
            '/results','Мои результаты'=>'learn.results','/settings','Настройки'=>'settings',
            '/register','Регистрация'=>'registration.start',default=>'input',
        };
    }
    private static function read_intent(string $intent): bool {
        return in_array($intent,['menu','cancel','help','form.preview','work.mine','work.team','work.review','work.company','work.task','work.photos',
            'work.checklists','work.checklist','work.report','work.return','work.assign','learn.mine','learn.results','learn.inbox',
            'learn.team','learn.person','settings','settings.edit','settings.unlink','people.requests','people.request',
            'people.request.approve','people.request.reject','people.request.adapt','people.hire','people.terminate','people.grades','people.grade',
            'people.retrain','people.adaptations','people.adaptation','people.adaptation.daily','people.adaptation.final',
            'people.adaptation.decision','people.adaptation.case','people.adaptation.control','people.adaptation.reset','people.confirmation.status'],true);
    }
    public static function menu(\stdClass $d, ?\stdClass $link=null): array {
        bot_store::state($d,'menu');
        if (!(int)$d->userid) {
            if ($link) {
                $state=\local_ustar\registration_service::state((int)$link->userid);
                $status=(string)($state['request']->status??'pending');
                return bot_store::card('👤 Ваша учётная запись','Статус заявки: '.self::status_label($status).
                    "\nПосле одобрения настройте первый пароль в Академии из офисной сети. Пароль в чат не отправляйте.",
                    [bot_store::button($d,'Обновить статус','menu')]);
            }
            return bot_store::card('USTAR · Добро пожаловать','Свяжите существующую учётку через «Личный кабинет → Мессенджеры» в Академии. Новому сотруднику доступна заявка без пароля.',
                [bot_store::button($d,'📝 Зарегистрироваться','registration.start'),bot_store::button($d,'🔗 Как привязать учётку','help')]);
        }
        if(!bot_actor::eligible((int)$d->userid)) {
            return bot_store::card('🔐 Завершите вход в Академии',
                'Рабочие действия требуют завершённой настройки учётной записи. Откройте Академию из офисной сети и выполните требование входа или смены первого пароля. Пароль в чат не отправляйте. Если для входа включён дополнительный фактор или внешний провайдер, используйте защищённую Академию.',
                [bot_store::button($d,'Проверить доступ','menu')]);
        }
        $buttons=[bot_store::button($d,'📋 Мои задачи','work.mine'),bot_store::button($d,'☑️ Чек-листы','work.checklists'),
            bot_store::button($d,'🎓 Обучение','learn.mine'),bot_store::button($d,'📊 Мои результаты','learn.results'),
            bot_store::button($d,'🔔 Уведомления','learn.inbox'),bot_store::button($d,'⚙️ Настройки','settings')];
        $id=(int)$d->userid;
        $buttons[]=bot_store::button($d,\local_ustar\adaptation_service::is_hrd_actor($id)?'🧭 Адаптация и эскалации':'🧭 Адаптация','people.adaptations');
        if (\local_ustar\task_workspace\service::can_manage($id)) {
            $buttons[]=bot_store::button($d,'👥 Моя команда','work.team');
            $buttons[]=bot_store::button($d,'🎓 Обучение команды','learn.team');
            $buttons[]=bot_store::button($d,'✅ Проверить отчёты','work.review');
            $buttons[]=bot_store::button($d,'➕ Назначить задачу','work.assign');
        }
        if (\local_ustar\hr_access::can_grade($id)) { $buttons[]=bot_store::button($d,'📝 Аттестации','people.grades'); }
        if (\local_ustar\forced_retraining::can_manage($id)) { $buttons[]=bot_store::button($d,'🔁 Повторный курс','people.retrain'); }
        if (bot_actor::capability($id,'hrmanage') || !empty(\local_ustar\staffing_requests::manager_scope($id)['allowed'])) {
            $buttons[]=bot_store::button($d,'👤 Заявки сотрудников','people.requests');
        }
        if (bot_actor::capability($id,'executive')) { $buttons[]=bot_store::button($d,'🏢 Сводка компании','work.company'); }
        return bot_store::card('USTAR · Ваш рабочий день','Выберите действие. Доступ определяется текущими правами в Академии.',$buttons);
    }
    public static function home(\stdClass $d): array { return bot_store::button($d,'⌂ Главное меню','menu'); }
    public static function cancel(\stdClass $d): array { return bot_store::button($d,'Отмена','cancel'); }
    public static function status_label(string $status): string {
        return ['pending'=>'ожидает решения','approved'=>'одобрена','rejected'=>'отклонена','assigned'=>'назначена',
            'in_progress'=>'в работе','in_review'=>'на проверке','completed'=>'завершена','cancelled'=>'отменена'][$status]??'проверяется';
    }
    private static function settings(\stdClass $d,string $intent,array $data): array {
        $p=notification_preferences::for_user((int)$d->userid);
        $hash=hash('sha256',bot_store::json($p));
        if ($intent==='settings.toggle') {
            if(!hash_equals($hash,$data['expected']??'')){throw new \InvalidArgumentException('stale_revision');}
            $p['enabled']=!$p['enabled'];notification_preferences::save_own($p);
        }
        if($intent==='settings.edit') {
            $fields=[['key'=>'quiet_start','label'=>'Начало тихих часов · ЧЧ:ММ','type'=>'clock','required'=>true],
                ['key'=>'quiet_end','label'=>'Конец тихих часов · ЧЧ:ММ','type'=>'clock','required'=>true],
                ['key'=>'timezone','label'=>'Часовой пояс (например Europe/Moscow)','type'=>'timezone','required'=>true],
                ['key'=>'interval','label'=>'Интервал между уведомлениями в минутах','type'=>'integer','min'=>1,'max'=>1440,'required'=>true],
                ['key'=>'severity','label'=>'Важность уведомлений','choices'=>['normal'=>'Все события','action'=>'Действия и критичные','critical'=>'Только критичные'],'required'=>true]];
            foreach(['registration'=>'Регистрация','tasks'=>'Задачи','feed'=>'Лента','grades'=>'Грейды'] as $key=>$label){
                $fields[]=['key'=>$key,'label'=>'Получать: '.$label,'choices'=>['1'=>'Да','0'=>'Нет'],'required'=>true];
            }
            return bot_forms::start($d,'⚙️ Личные уведомления',$fields,'settings.commit',['expected'=>$hash]);
        }
        if($intent==='settings.commit') {
            if(!hash_equals($hash,$data['context']['expected']??'')){throw new \InvalidArgumentException('stale_revision');}
            $v=$data['values'];$p['quiet_start']=$v['quiet_start'];$p['quiet_end']=$v['quiet_end'];$p['timezone']=$v['timezone'];
            $p['interval_minutes']=(int)$v['interval'];$p['min_severity']=$v['severity'];$p['categories']=[];
            foreach(notification_preferences::CATEGORIES as $key){if(($v[$key]??'0')==='1'){$p['categories'][]=$key;}}
            notification_preferences::save_own($p);
        }
        if($intent==='settings.unlink') {
            bot_store::state($d,'menu');
            return bot_store::card('Отключить Telegram?','Уведомления и рабочие действия в этом чате станут недоступны. Для новой привязки нужно подтверждение в Академии.',
                [bot_store::button($d,'Отключить эту связь','settings.unlink.confirm'),bot_service::home($d)]);
        }
        if($intent==='settings.unlink.confirm') {
            identity_service::revoke_current_in_transaction((int)$d->linkid);
            bot_store::state($d,'menu');return bot_store::card('✓ Telegram отключён','Новая привязка доступна в «Личном кабинете → Мессенджеры» Академии.');
        }
        bot_store::state($d,'menu');
        return bot_store::card('⚙️ Уведомления',$p['enabled']?'Включены':'Выключены',
            [bot_store::button($d,$p['enabled']?'Выключить':'Включить','settings.toggle',['expected'=>hash('sha256',bot_store::json($p))]),
             bot_store::button($d,'Изменить часы и категории','settings.edit'),
             bot_store::button($d,'Отключить привязку','settings.unlink'),self::home($d)],[],
            [['Тихие часы',$p['quiet_start'].'–'.$p['quiet_end']],['Часовой пояс',$p['timezone']],
             ['Интервал',$p['interval_minutes'].' мин'],['Привязка','Отзыв доступен в «Мессенджерах» Академии']]);
    }
}
