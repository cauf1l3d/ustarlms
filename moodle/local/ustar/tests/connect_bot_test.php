<?php
namespace local_ustar;
defined('MOODLE_INTERNAL') || die();

use local_ustar\connect\{bot_service, identity, identity_service};

#[\PHPUnit\Framework\Attributes\CoversClass(bot_service::class)]
final class connect_bot_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp(); $this->resetAfterTest(true); $this->preventResetByRollback();
    }
    private function request(int $event, string $text = 'Меню', string $callback = ''): array {
        return ['auth_date'=>time(), 'event'=>(string)$event, 'message'=>'7', 'text'=>$text,
            'callback'=>$callback, 'media'=>'', 'provider'=>'telegram', 'tenant'=>'123456', 'subject'=>'555'];
    }
    private function linked(): identity {
        global $CFG;
        $CFG->ustar_connect_bot_enabled = true;
        $user=$this->getDataGenerator()->create_user(['auth'=>'manual']); $this->setUser($user);
        $sender=new identity('telegram','123456','555');
        $challenge=identity_service::issue('telegram','123456');
        identity_service::claim_verified($challenge['code'],$sender,hash('sha256','bot-test-proof'));
        identity_service::confirm($challenge['code']);
        return $sender;
    }
    public function test_disabled_feature_does_not_create_dialogs(): void {
        global $DB;
        $this->expectException(\InvalidArgumentException::class);
        bot_service::update(new identity('telegram','123456','555'),$this->request(1));
    }
    public function test_menu_is_replayed_without_second_dialog_and_user_is_restored(): void {
        global $DB,$USER;
        $sender=$this->linked(); $before=(int)$USER->id;
        $a=bot_service::update($sender,$this->request(1));
        $this->assertSame($a,bot_service::update($sender,$this->request(1)));
        $retry=$this->request(1);$retry['auth_date']++;
        $this->assertSame($a,bot_service::update($sender,$retry));
        $this->assertSame(1,$DB->count_records('local_ustar_conn_dialogs'));
        $this->assertSame(1,$DB->count_records('local_ustar_conn_updates'));
        $this->assertSame($before,(int)$USER->id);
        $this->assertNotEmpty($a['buttons']);
    }
    public function test_changed_same_event_is_not_reinterpreted(): void {
        $sender=$this->linked(); bot_service::update($sender,$this->request(2));
        $this->expectException(\InvalidArgumentException::class);
        bot_service::update($sender,$this->request(2,'Чужая команда'));
    }
    public function test_revoked_link_cannot_read_previously_successful_receipt(): void {
        global $DB;
        $sender=$this->linked(); bot_service::update($sender,$this->request(3));
        $link=$DB->get_record('local_ustar_conn_links',['identityhash'=>$sender->key()]);
        identity_service::revoke((int)$link->id);
        $result=bot_service::update($sender,$this->request(3));
        $this->assertStringContainsString('Привяз',json_encode($result,JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('Мои задачи',json_encode($result,JSON_UNESCAPED_UNICODE));
    }
    private function click(identity $sender,array $view,string $label,int $event): array {
        foreach($view['buttons'] as $button){
            if(str_contains($button['label'],$label)){
                return bot_service::update($sender,$this->request($event,'',$button['callback']));
            }
        }
        $this->fail('Missing button: '.$label);
    }
    private function work(identity $sender,bool $photo=false): int {
        global $USER;
        $employee=clone $USER;$this->setAdminUser();
        $id=\local_ustar\task_workspace\service::create((int)$USER->id,(int)$employee->id,[
            'title'=>'Synthetic bot task','date'=>gmdate('Y-m-d'),'clock'=>'17:00','requirephoto'=>$photo,
            'policy'=>\local_ustar\task_workspace\policy::validate(['timezone'=>'UTC','mode'=>'calendar'])]);
        $this->setGuestUser();return $id;
    }
    public function test_task_report_button_twice_keeps_one_canonical_report_and_guest_context(): void {
        global $DB,$USER;
        $sender=$this->linked();$id=$this->work($sender);
        $list=bot_service::update($sender,$this->request(10,'Мои задачи'));
        $card=$this->click($sender,$list,'Synthetic',11);
        $photo=$this->click($sender,$card,'Отправить отчёт',12);
        $this->click($sender,$photo,'Пропустить',121);
        $preview=bot_service::update($sender,$this->request(13,'Готово, результат проверен'));
        $a=$this->click($sender,$preview,'Подтвердить',14);
        $b=$this->click($sender,$preview,'Подтвердить',15);
        $this->assertSame($a,$b);
        $this->assertSame('in_review',$DB->get_field('local_ustar_learning_tasks','status',['id'=>$id]));
        $this->assertSame(1,$DB->count_records('local_ustar_task_reports',['taskid'=>$id]));
        $this->assertTrue(isguestuser($USER));
    }
    public function test_other_client_changed_task_is_not_overwritten(): void {
        global $DB;
        $sender=$this->linked();$user=clone $GLOBALS['USER'];$id=$this->work($sender);
        $list=bot_service::update($sender,$this->request(20,'Мои задачи'));
        $card=$this->click($sender,$list,'Synthetic',21);$photo=$this->click($sender,$card,'Отправить отчёт',22);
        $this->click($sender,$photo,'Пропустить',221);
        $preview=bot_service::update($sender,$this->request(23,'Старый ответ'));
        $this->setUser($user);\local_ustar\task_workspace\service::report($id,$user->id,1,[],'Новый черновик',false);$this->setGuestUser();
        try{$this->click($sender,$preview,'Подтвердить',24);$this->fail('Stale report accepted');}
        catch(\moodle_exception $e){$this->assertSame(2,(int)$DB->get_field('local_ustar_learning_tasks','version',['id'=>$id]));}
        $this->assertSame(1,$DB->count_records('local_ustar_task_reports',['taskid'=>$id]));
    }
    public function test_personal_photo_is_canonical_file_and_report_not_public_upload(): void {
        global $DB;
        $sender=$this->linked();$id=$this->work($sender,true);
        $list=bot_service::update($sender,$this->request(30,'Мои задачи'));
        $card=$this->click($sender,$list,'Synthetic',31);$this->click($sender,$card,'Отправить отчёт',32);
        $p=['auth_date'=>time(),'chunk'=>'','event'=>'33','file'=>'synthetic_picture','part'=>'start',
            'provider'=>'telegram','subject'=>'555','tenant'=>'123456','upload'=>''];
        $start=\local_ustar\connect\bot_upload::chunk($sender,$p);$p['upload']=$start['upload'];$p['file']='';$p['part']='1';
        $p['chunk']='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=';
        \local_ustar\connect\bot_upload::chunk($sender,$p);$p['chunk']='';$p['part']='finish';
        \local_ustar\connect\bot_upload::chunk($sender,$p);
        $input=$this->request(33,'');$input['media']=$start['upload'];bot_service::update($sender,$input);
        $preview=bot_service::update($sender,$this->request(34,'Фото результата'));
        $this->click($sender,$preview,'Подтвердить',35);
        $this->assertSame('in_review',$DB->get_field('local_ustar_learning_tasks','status',['id'=>$id]));
        $files=get_file_storage()->get_area_files(\context_system::instance()->id,'local_ustar',task_files::RESULT,$id,'',false);
        $this->assertCount(1,$files);$file=reset($files);$this->assertSame('image/png',$file->get_mimetype());
        $this->assertStringContainsString('synthetic_picture',$file->get_source());
    }
    public function test_preferences_changed_in_native_page_are_not_silently_flipped(): void {
        $sender=$this->linked();$user=clone $GLOBALS['USER'];
        $settings=bot_service::update($sender,$this->request(40,'Настройки'));
        $p=\local_ustar\connect\notification_preferences::defaults();$p['interval_minutes']=30;
        set_user_preference(\local_ustar\connect\notification_preferences::NAME,json_encode($p),$user->id);
        $this->expectException(\InvalidArgumentException::class);$this->click($sender,$settings,'Включить',41);
    }
    public function test_native_confirmation_of_foreign_actor_is_denied(): void {
        $sender=$this->linked();
        $this->expectException(\InvalidArgumentException::class);
        \local_ustar\connect\bot_confirmation::confirm('cb4_'.str_repeat('A',43),(int)$GLOBALS['USER']->id+1);
    }
    public function test_old_successful_task_receipt_does_not_leak_after_reassignment(): void {
        global $DB;
        $sender=$this->linked();$id=$this->work($sender);
        $list=bot_service::update($sender,$this->request(60,'Мои задачи'));
        $this->assertNotEmpty($list['rows']);
        $other=$this->getDataGenerator()->create_user();
        $DB->set_field('local_ustar_learning_tasks','assigneeid',$other->id,['id'=>$id]);
        $this->expectException(\InvalidArgumentException::class);
        bot_service::update($sender,$this->request(60,'Мои задачи'));
    }
    public function test_native_hr_confirmation_rejects_once_and_keeps_canonical_actor(): void {
        global $DB,$USER;
        $sender=$this->linked();$reviewer=clone $USER;$context=\context_system::instance();
        $role=$this->getDataGenerator()->create_role();
        foreach(['hrmanage','approveregistration'] as $cap){assign_capability('local/ustar:'.$cap,CAP_ALLOW,$role,$context->id);}
        role_assign($role,$reviewer->id,$context->id);accesslib_clear_all_caches(true);
        accounts::ensure_profile_field();
        $uid=registration_service::register(['username'=>'bot_confirm_fixture','password'=>'Aa9!'.random_string(32),
            'firstname'=>'Pending','lastname'=>'Synthetic','email'=>'bot.confirm@example.invalid','departmentid'=>'retail']);
        $request=$DB->get_record('local_ustar_staff_requests',['employeeid'=>$uid]);
        $this->setGuestUser();$menu=bot_service::update($sender,$this->request(70));
        $list=$this->click($sender,$menu,'Заявки сотрудников',71);
        $card=$this->click($sender,$list,'Pending',72);$this->click($sender,$card,'Отклонить',73);
        $preview=bot_service::update($sender,$this->request(74,'Ошибочные данные, подайте заново'));
        $pending=$this->click($sender,$preview,'Подтвердить',75);
        $this->assertSame('pending',$DB->get_field('local_ustar_staff_requests','status',['id'=>$request->id]));
        $url=array_values(array_filter($pending['buttons'],static fn($b)=>isset($b['url'])))[0]['url'];
        parse_str(parse_url($url,PHP_URL_QUERY),$query);
        $this->setUser($reviewer);
        $first=\local_ustar\connect\bot_confirmation::confirm($query['action'],(int)$reviewer->id);
        $this->assertSame($first,\local_ustar\connect\bot_confirmation::confirm($query['action'],(int)$reviewer->id));
        $fresh=$DB->get_record('local_ustar_staff_requests',['id'=>$request->id]);
        $this->assertSame('rejected',$fresh->status);$this->assertSame((int)$reviewer->id,(int)$fresh->reviewedby);
        $this->assertSame('Ошибочные данные, подайте заново',$fresh->reviewcomment);
    }
    public function test_anon_signup_has_no_chat_password_and_refresh_button_works_after_link_created(): void {
        global $DB,$CFG;
        $CFG->ustar_connect_bot_enabled=true;$this->setGuestUser();accounts::ensure_profile_field();
        $sender=new identity('telegram','123456','555');
        bot_service::update($sender,$this->request(50,'Регистрация'));
        $event=51;
        foreach(['Synthetic','Bot','bot.role@example.invalid','bot_role_fixture'] as $answer){$view=bot_service::update($sender,$this->request($event++,$answer));}
        $departments=array_column(registration_service::departments(),'name','id');
        $view=$this->click($sender,$view,$departments['retail'], $event++);
        $result=$this->click($sender,$view,'Подтвердить',$event++);
        $this->assertSame(1,$DB->count_records('local_ustar_conn_signups'));
        $link=$DB->get_record('local_ustar_conn_links',['identityhash'=>$sender->key()]);
        $this->assertSame(employment::PENDING,employment::resolve((int)$link->userid)['status']);
        $this->assertEquals(1,get_user_preferences('auth_forcepasswordchange',0,(int)$link->userid));
        $refresh=$this->click($sender,$result,'Обновить статус',$event++);
        $this->assertStringContainsString('заявки',$refresh['text']);
    }
}
