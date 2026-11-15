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
        if (isset($p['widget'])) {
            return bot_checklist_widget::update($sender, $p);
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
                if (!hash_equals($old->authority,$d->authority)) { return self::menu($d,$link,$sender); }
                if (!hash_equals($old->payloadhash,$hash)) { throw new \InvalidArgumentException('idempotency_conflict'); }
                $saved = json_decode($old->responsejson, true, 32, JSON_THROW_ON_ERROR);
                return isset($saved['_invitation_pending']) ? self::menu($d, $link, $sender) : $saved;
            }
            if ((int)$d->ratewindow<time()-60) { $d->ratewindow=time(); $d->ratecount=0; }
            if (++$d->ratecount>40) { throw new \InvalidArgumentException('rate_limited'); }
            bot_store::save($d); // Count rejected inputs too, outside any business rollback.
            $pending = $userid ? bot_agreement::pending($userid, bot_actor::invited($link)) : null;
            $agreementrequired = $pending !== null && bot_agreement::is_required($pending);
            try {
                $action = $p['callback'] !== '' ? bot_store::resolve($d, $p['callback']) : null;
            } catch (\InvalidArgumentException $error) {
                if ($agreementrequired && $error->getMessage() === 'expired_bot_action') {
                    return self::menu($d, $link, $sender);
                }
                if (bot_actor::invited($link) && $error->getMessage() === 'expired_bot_action') {
                    return self::menu($d, $link, $sender);
                }
                throw $error;
            }
            $intent=$action?$action->intent:self::command($p['text']);
            if ($intent === bot_checklist_widget::INTENT) {
                throw new \InvalidArgumentException('invalid_bot_action');
            }
            $data=$action?json_decode($action->payload,true,32,JSON_THROW_ON_ERROR):[];
            $operate=function() use(&$d,&$link,$p,$sender,$action,$intent,$data):array {
                $invitation = self::invitation_token($p['text']);
                if ($invitation !== null && !$action) {
                    if ((int)$d->userid || $link) {
                        return self::menu($d, $link, $sender);
                    }
                    try {
                        $redeemed = bot_invitation::redeem($sender, $invitation, $p['event']);
                    } catch (\InvalidArgumentException $error) {
                        return bot_store::card('Подключение Telegram',
                            'Приглашение недействительно или связь уже занята. Обратитесь к администратору.',
                            [self::home($d)]) + ['plain' => true];
                    }
                    if ($redeemed) {
                        $link = $redeemed;
                        $d = bot_store::dialog($sender, (int)$link->userid, $link);
                    }
                    return self::menu($d, $link, $sender);
                }
                if ((int)$d->userid && bot_agreement::is_required(
                        $pending = bot_agreement::pending((int)$d->userid, bot_actor::invited($link)))) {
                    if ($action && in_array($intent, ['agreement.page', 'agreement.accept', 'agreement.decline'], true)) {
                        $view = bot_actor::run((int)$d->userid, static fn() => bot_agreement::route(
                            $d, $pending, $intent, $data),
                            $link, true);
                        if (!$view) {
                            // Consent changed the authorization stamp. Issue menu tokens for the new state.
                            $d = bot_store::dialog($sender, (int)$d->userid, $link);
                            return self::menu($d, $link);
                        }
                        return $view;
                    }
                    return self::menu($d, $link);
                }
                if ($action && str_starts_with($intent, 'agreement.')) {
                    return self::menu($d, $link);
                }
                if ($action && ($action->status ?? '') === 'done'
                        && isset(json_decode($action->resultjson, true, 32, JSON_THROW_ON_ERROR)['_invitation_pending'])) {
                    return self::menu($d, $link, $sender);
                }

                if ($action && $action->status==='done' && !self::read_intent($intent)) {
                    // Repeated writes return their receipt. An old answered form never advances twice.
                    if(str_starts_with($intent,'form.')) {
                        return $d->phase==='form.input'?bot_forms::ask($d):self::menu($d,$link);
                    }
                    return json_decode($action->resultjson,true,32,JSON_THROW_ON_ERROR);
                }
                if ($action && !in_array($action->status,['issued','done'],true)) { throw new \InvalidArgumentException('expired_bot_action'); }
                if (in_array($intent,['menu','cancel','help'],true)) { return self::menu($d,$link,$sender); }
                if (!(int)$d->userid) {
                    if ($intent === 'invitation.start' && bot_invitation::enabled()) {
                        return bot_store::card('Подключение Telegram', 'Вставьте одноразовый код из приглашения.',
                            [self::home($d)]) + ['plain' => true];
                    }
                    return bot_registration::route($d,$link,$intent,$data,$p['text']);
                }
                if ($intent==='input') { $intent=$d->phase; $data=bot_store::data($d); }
                if (bot_actor::invited($link) && !self::invited_intent($intent, $d)) {
                    return self::menu($d, $link);
                }
                return bot_actor::run((int)$d->userid,function() use($d,$intent,$data,$p,$link):array {
                    return match(true) {
                        str_starts_with($intent,'form.')=>bot_forms::route($d,$intent,$data,$p),
                        str_starts_with($intent,'work.')=>bot_work::route($d,$intent,$data,$p,bot_actor::invited($link)),
                        str_starts_with($intent,'people.')=>bot_people::route($d,$intent,$data,$p['text']),
                        str_starts_with($intent,'learn.')=>bot_learning::route($d,$intent,$data,$p['text'],bot_actor::invited($link)),
                        str_starts_with($intent,'broadcast.')=>bot_broadcast::route($d,$intent,$data),
                        str_starts_with($intent,'invitation.')=>self::invitation_operator($d,$intent,$data),
                        str_starts_with($intent,'settings')=>self::settings($d,$intent,$data),
                        $intent === 'team.menu' => self::team_menu($d),
                        default=>self::menu($d,bot_actor::link(new identity($p['provider'],$p['tenant'],$p['subject']))),
                    };
                }, $link);
            };
            // Registration owns its email lock/real commit. Its callback stores the event receipt.
            if ($intent==='registration.confirm' && !(int)$d->userid) {
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
            return self::input_problem($sender,'Проверьте ответы и обязательные поля. Запись не принята. Измените ответы и отправьте заново.');
        } catch(\required_capability_exception $error) {
            throw new \InvalidArgumentException('forbidden_scope');
        } catch(\dml_missing_record_exception $error) {
            throw new \InvalidArgumentException('expired_bot_action');
        }
        unset($result['_invitation_pending']);
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
                $view['buttons']=array_values(array_filter($view['buttons'],
                    static fn($b) => !in_array($b['label'], ['✓ Подтвердить', 'Опубликовать в Ленте'], true)));
                if(isset($view['_guard'])){bot_guards::verify($view['_guard'],(int)$d->userid);unset($view['_guard']);}
                return $view;
            };
            if ($id && bot_agreement::is_required(bot_agreement::pending($id, bot_actor::invited($link)))) {
                return self::menu($d, $link, $sender);
            }
            return $id ? bot_actor::run($id, $make, $link) : $make();
        });
    }
    public static function remember(\stdClass $d,string $event,string $hash,array $view): void {
        global $DB;
        if (isset($view['_invitation_pending'])) {
            $view = ['_invitation_pending' => true];
        }
        $DB->insert_record('local_ustar_conn_updates',(object)['eventhash'=>$event,'identityhash'=>$d->identityhash,
            'userid'=>$d->userid,'linkrevision'=>$d->linkrevision,'authority'=>$d->authority,'payloadhash'=>$hash,
            'responsejson'=>bot_store::json($view),'timecreated'=>time()]);
    }
    private static function invitation_token(string $text): ?string {
        return preg_match('/\A(?:\/start )?(ui1_[A-Za-z0-9_-]{43})\z/', trim($text), $matches)
            ? $matches[1] : null;
    }

    private static function invited_intent(string $intent, \stdClass $dialog): bool {
        if (str_starts_with($intent, 'form.')) {
            $commit = bot_store::data($dialog)['commit'] ?? '';
            return in_array($commit, ['work.checklist.commit', 'work.report.commit', 'settings.commit'], true);
        }
        return in_array($intent, ['work.mine', 'work.task', 'work.start', 'work.report', 'work.report.commit',
            'work.photos', 'work.checklists', 'work.checklist', 'work.checklist.commit', 'learn.mine',
            'learn.results', 'learn.inbox', 'learn.read', 'settings', 'settings.toggle', 'settings.edit',
            'settings.commit', 'settings.unlink', 'settings.unlink.confirm'], true);
    }

    private static function invitation_operator(\stdClass $dialog, string $intent, array $data): array {
        if (!bot_invitation::enabled() || !bot_invitation::can_manage()) {
            throw new \InvalidArgumentException('forbidden_scope');
        }
        if ($intent === 'invitation.operator') {
            return bot_forms::start($dialog, 'Приглашение сотрудника', [
                ['key' => 'username', 'label' => 'Логин сотрудника в Академии', 'required' => true, 'maxlen' => 100],
                ['key' => 'subject', 'label' => 'Проверенный числовой Telegram ID',
                    'hint' => 'Если ID неизвестен, нажмите «Пропустить».', 'maxlen' => 16],
            ], 'invitation.prepare');
        }
        if ($intent !== 'invitation.prepare' || $dialog->phase !== 'form.confirm') {
            throw new \InvalidArgumentException('expired_bot_action');
        }
        $target = bot_invitation::preview_issue($data['values']['username'], $data['values']['subject']);
        $verification = $target['subject'] === '' ? 'Сотрудник сообщит код для проверки по рабочему контакту.'
            : 'Привязка к Telegram ID ' . $target['subject'] . '.';
        return bot_confirmation::prepare($dialog, 'execute.invitation.issue',
            ['userid' => $target['userid'], 'subject' => $target['subject']],
            "Приглашение для сотрудника {$target['label']}.\n{$verification}\nСсылка появится в Академии после подтверждения.");
    }

    private static function command(string $text): string {
        return match(trim($text)) {
            '/start','/menu','Меню','Главная','Назад'=>'menu', '/cancel','Отмена'=>'cancel','/help','Помощь'=>'help',
            '/tasks','Мои задачи'=>'work.mine','/checklists','Чек-листы'=>'work.checklists',
            '/team','Моя команда'=>'team.menu','/review','Проверить отчёты'=>'work.review',
            '/assign','Назначить задачу'=>'work.assign','/learn','Обучение'=>'learn.mine',
            '/results','Мои результаты'=>'learn.results','/settings','Настройки'=>'settings',
            '/register','Регистрация'=>'registration.start',default=>'input',
        };
    }
    private static function read_intent(string $intent): bool {
        return in_array($intent,['menu','cancel','help','form.preview','team.menu','work.mine','work.team','work.review','work.company','work.task','work.photos',
            'work.checklists','work.checklist','work.report','work.return','work.assign','learn.mine','learn.results','learn.inbox',
            'learn.team','learn.person','settings','settings.edit','settings.unlink','people.requests','people.request',
            'people.request.approve','people.request.reject','people.request.adapt','people.hire','people.terminate','people.grades','people.grade',
            'people.retrain','people.adaptations','people.adaptation','people.adaptation.daily','people.adaptation.final',
            'people.adaptation.decision','people.adaptation.case','people.adaptation.control','people.adaptation.reset','people.confirmation.status'],true);
    }
    public static function menu(\stdClass $d, ?\stdClass $link=null, ?identity $sender=null): array {
        bot_store::state($d,'menu');
        if (!(int)$d->userid) {
            if ($sender && ($verification = bot_invitation::pending_code($sender))) {
                return bot_store::card('Подтверждение подключения',
                    'Сообщите код ' . $verification . ' администратору через ваш рабочий контакт. '
                    . 'После подтверждения нажмите кнопку ниже.',
                    [bot_store::button($d, 'Проверить подключение', 'menu')]) + ['plain' => true, '_invitation_pending' => true];
            }
            if ($link) {
                $state=\local_ustar\registration_service::state((int)$link->userid);
                $status=(string)($state['request']->status??'pending');
                return bot_store::card('👤 Ваша учётная запись','Статус заявки: '.self::status_label($status).
                    "\nПосле одобрения настройте первый пароль в Академии из офисной сети. Пароль в чат не отправляйте.",
                    [bot_store::button($d,'Обновить статус','menu')]);
            }
            $buttons = [];
            if (bot_invitation::enabled()) {
                $buttons[] = bot_store::button($d, 'Подключить рабочую учётку', 'invitation.start');
            }
            $buttons[] = bot_store::button($d,'📝 Зарегистрироваться','registration.start');
            $buttons[] = bot_store::button($d,'🔗 Как привязать учётку','help');
            return bot_store::card('USTAR · Добро пожаловать',
                'Подключите существующую учётку или отправьте заявку на регистрацию.', $buttons);
        }
        $id = (int)$d->userid;
        $invited = bot_actor::invited($link);
        if (!bot_actor::eligible($id, $invited) || ($invited && bot_actor::requires_native_role($id))) {
            return bot_store::card('🔐 Завершите вход в Академии',
                'Рабочие действия требуют завершённой настройки учётной записи. Откройте Академию из офисной сети и выполните требование входа или смены первого пароля. Пароль в чат не отправляйте. Если для входа включён дополнительный фактор или внешний провайдер, используйте защищённую Академию.',
                [bot_store::button($d,'Проверить доступ','menu')]);
        }
        $pending = bot_agreement::pending($id, $invited);
        if (bot_agreement::is_required($pending)) {
            return bot_actor::run($id, static fn() => bot_agreement::view($d, $pending), $link, true);
        }
        $buttons = [
            bot_store::button($d, '📋 Мои задачи', 'work.mine'),
            bot_store::button($d, '☑️ Чек-листы', 'work.checklists'),
            bot_store::button($d, '🎓 Обучение', 'learn.mine'),
        ];
        $team = $invited ? [] : self::team_actions($id);
        if ($team) {
            $buttons[] = bot_store::button($d, '👥 Управление командой', 'team.menu');
        }
        $buttons[] = bot_store::button($d, '🔔 Уведомления', 'learn.inbox');
        $buttons[] = bot_store::button($d, '⚙️ Настройки', 'settings');
        if (!$invited && announcements::enabled() && announcements::operator_capability($id)) {
            $buttons[] = bot_store::button($d, '📣 Рассылки Telegram', 'broadcast.posts');
        }
        if (!$invited && bot_invitation::enabled() && bot_invitation::operator_allowed($id)) {
            $buttons[] = bot_store::button($d, '🎟 Приглашения Telegram', 'invitation.operator');
        }
        return bot_store::card('USTAR · Главное меню', '', $buttons);
    }

    private static function team_actions(int $id): array {
        $actions = [];
        if (\local_ustar\task_workspace\service::can_manage($id)) {
            $actions = [
                'work.team' => '👥 Задачи команды', 'work.review' => '✅ Проверить отчёты',
                'work.assign' => '➕ Назначить задачу', 'learn.team' => '🎓 Обучение команды',
            ];
        }
        if (\local_ustar\hr_access::can_grade($id)) { $actions['people.grades'] = '📝 Аттестации'; }
        if (\local_ustar\forced_retraining::can_manage($id)) { $actions['people.retrain'] = '🔁 Повторный курс'; }
        if (bot_actor::capability($id,'hrmanage') || !empty(\local_ustar\staffing_requests::manager_scope($id)['allowed'])) {
            $actions['people.requests'] = '👤 Заявки сотрудников';
        }
        if (\local_ustar\adaptation_service::is_hrd_actor($id)) {
            $actions['people.adaptations'] = '🧭 Адаптация и эскалации';
        }
        if (bot_actor::capability($id,'executive')) { $actions['work.company'] = '🏢 Сводка компании'; }
        return $actions;
    }

    private static function team_menu(\stdClass $d): array {
        bot_store::state($d, 'menu');
        $buttons = [];
        foreach (self::team_actions((int)$d->userid) as $intent => $label) {
            $buttons[] = bot_store::button($d, $label, $intent);
        }
        $buttons[] = self::home($d);
        return bot_store::card('👥 Управление командой', count($buttons) === 1 ? 'Раздел доступен руководителям.' : '', $buttons);
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
