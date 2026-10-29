"""Role bot boundaries: real PHP values plus transport/rendering without provider sends."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'connect-worker'))


@unittest.skipUnless(os.environ.get('USTAR_TEST_PHP') or shutil.which('php'), 'PHP unavailable')
class BotRequestValues(unittest.TestCase):
    def php(self, expression):
        source = ('define("MOODLE_INTERNAL",true); require ' +
                  json.dumps(str(ROOT / 'moodle/local/ustar/classes/connect/bot_request.php')) +
                  '; ' + expression)
        r = subprocess.run([os.environ.get('USTAR_TEST_PHP', 'php'), '-n', '-r', source],
                           text=True, capture_output=True)
        self.assertEqual(r.returncode, 0, r.stderr)
        return json.loads(r.stdout)

    def test_current_personal_payload_is_fixed_and_never_accepts_actor_or_role(self):
        result = self.php(r'''$p=['auth_date'=>1000,'callback'=>'','event'=>'12','media'=>'',
            'message'=>'7','provider'=>'telegram','subject'=>'55','tenant'=>'123','text'=>'Меню'];
            $out=[\local_ustar\connect\bot_request::validate($p,1000)['text']];
            foreach(['userid','role','url'] as $field) { try {
                \local_ustar\connect\bot_request::validate($p+[$field=>'1'],1000);
                $out[]=false;
            } catch(\InvalidArgumentException $e){$out[]=$e->getMessage();} }
            echo json_encode($out);''')
        self.assertEqual(result, ['Меню'] + ['invalid_bot_request'] * 3)

    def test_stale_text_and_arbitrary_callback_are_rejected(self):
        result = self.php(r'''$p=['auth_date'=>1000,'callback'=>'','event'=>'12','media'=>'',
            'message'=>'7','provider'=>'telegram','subject'=>'55','tenant'=>'123','text'=>''];
            $out=[]; foreach([['auth_date'=>879],['callback'=>'approve:42'],['text'=>str_repeat('x',4001)],
                ['event'=>'01'],['subject'=>'0'],['media'=>'https://untrusted.invalid/file']] as $change) {
                try {\local_ustar\connect\bot_request::validate(array_replace($p,$change),1000);$out[]=false;}
                catch(\InvalidArgumentException $e){$out[]=$e->getMessage();}}
            echo json_encode($out);''')
        self.assertEqual(result, ['invalid_bot_request'] * 6)

    def test_callback_tokens_are_hashed_canonical_and_distinct(self):
        result = self.php(r'''$a=\local_ustar\connect\bot_request::token();
            $b=\local_ustar\connect\bot_request::token();
            echo json_encode([$a!==$b,strlen($a),strlen(\local_ustar\connect\bot_request::digest($a))]);''')
        self.assertEqual(result, [True, 47, 64])

    def test_role_delivery_requires_explicit_profile_and_preserves_ack_without_pilot_list(self):
        source=r'''namespace local_ustar\connect {
            class notification_delivery {public static function recipient_enabled(int $id):bool {
                global $CFG;return !property_exists($CFG,'ustar_connect_notify_userids') ||
                    (is_array($CFG->ustar_connect_notify_userids) && in_array($id,$CFG->ustar_connect_notify_userids,true));}}
        } namespace {define('MOODLE_INTERNAL',true);require %s;
            $method=new \ReflectionMethod(\local_ustar\connect\notification_action::class,'recipient_allowed');
            $out=[];foreach([
                [],['ustar_connect_bot_enabled'=>true],
                ['ustar_connect_bot_enabled'=>false,'ustar_connect_role_delivery_profile'=>'academy_linked_opt_in'],
                ['ustar_connect_bot_enabled'=>true,'ustar_connect_role_delivery_profile'=>'academy_linked_opt_in'],
                ['ustar_connect_bot_enabled'=>true,'ustar_connect_role_delivery_profile'=>'academy_linked_opt_in','ustar_connect_notify_userids'=>[]],
                ['ustar_connect_notify_userids'=>[55]],['ustar_connect_notify_userids'=>[56]],
            ] as $cfg){$CFG=(object)$cfg;$out[]=$method->invoke(null,55);}echo json_encode($out); }''' % json.dumps(str(ROOT/'moodle/local/ustar/classes/connect/notification_action.php'))
        result=subprocess.run([os.environ.get('USTAR_TEST_PHP','php'),'-n','-r',source],capture_output=True,text=True)
        self.assertEqual(0,result.returncode,result.stderr+result.stdout)
        self.assertEqual([False,False,False,True,False,True,False],json.loads(result.stdout))


class TelegramBotInterface(unittest.TestCase):
    def view(self):
        return {'title':'Команда <тест>', 'text':'Результат & состояние',
                'buttons':[{'label':'Мои задачи','callback':'cb4_'+'A'*43}],
                'metrics':[{'label':'Выполнено','value':3,'total':5}],
                'rows':[['Сотрудник','Готово']]}

    def test_real_card_escapes_input_and_keeps_chart_counts_and_keyboard(self):
        from ustar_connect.bot_cards import render_view
        value=render_view(self.view())
        self.assertIn('&lt;тест&gt;',value['text'])
        self.assertIn('&amp;',value['text'])
        self.assertIn('3/5',value['text'])
        self.assertIn('▰',value['text'])
        self.assertEqual(value['reply_markup']['inline_keyboard'][0][0]['callback_data'],'cb4_'+'A'*43)

    def test_card_never_accepts_foreign_url_or_arbitrary_html(self):
        from ustar_connect.bot_cards import render_view
        value=self.view();value['buttons']=[{'label':'Confirm','url':'https://attacker.invalid/?token=secret'}]
        with self.assertRaises(ValueError):render_view(value,academy_origin='https://ustar.local')
        value['buttons']=[{'label':'Confirm','url':'https://ustar.local/local/ustar/connect_bot_confirm.php?action=cb4_'+'A'*43}]
        self.assertIn('url',render_view(value,academy_origin='https://ustar.local')['reply_markup']['inline_keyboard'][0][0])

    def test_long_multilingual_card_fits_telegram_and_transport_limits(self):
        from ustar_connect.bot_cards import render_view
        value=self.view();value['text']='Длинный <ответ> 👨‍👩‍👧‍👦 '*1000
        value['rows']=[['Название'*100,'Значение'*100] for _ in range(40)]
        result=render_view(value)
        self.assertLessEqual(len(result['text'].encode('utf-16-le'))//2,4096)
        self.assertLess(len(json.dumps(result,ensure_ascii=False).encode()),15000)
        self.assertIn('сокращ',result['text'])

    def message(self,text='Мои задачи'):
        return {'update_id':10,'message':{'message_id':7,'date':1000,'text':text,
            'chat':{'id':55,'type':'private'},'from':{'id':55,'is_bot':False}}}

    def test_buttons_and_free_replies_work_but_forwarded_or_other_chat_do_not(self):
        from ustar_connect.bot_handler import parse_update
        self.assertEqual(parse_update(self.message(),bot_id='123456',username='ustarcorp_bot',now=1000).payload['text'],'Мои задачи')
        self.assertIsNotNone(parse_update(self.message('Отчёт выполнен'),bot_id='123456',username='ustarcorp_bot',now=1000))
        for field in ['forward_origin','via_bot','sender_chat','business_connection_id']:
            value=self.message();value['message'][field]={}
            self.assertIsNone(parse_update(value,bot_id='123456',username='ustarcorp_bot',now=1000))
        value=self.message();value['message']['chat']['id']=56
        self.assertIsNone(parse_update(value,bot_id='123456',username='ustarcorp_bot',now=1000))

    def test_start_link_stays_with_existing_two_sided_link_flow(self):
        from ustar_connect.bot_handler import parse_update
        self.assertIsNone(parse_update(self.message('/start uc1_'+'A'*43),bot_id='123456',username='ustarcorp_bot',now=1000))

    def test_photo_accepts_optional_size_but_rejects_invalid_advertised_size(self):
        from ustar_connect.bot_handler import parse_update
        value=self.message('');value['message']['photo']=[{'file_id':'synthetic_photo','width':100,'height':100}]
        event=parse_update(value,bot_id='123456',username='ustarcorp_bot',now=1000)
        self.assertIsNotNone(event)
        self.assertEqual(('synthetic_photo',None),event.photo)
        for invalid in [None,True,0,512*1024+1,'100']:
            value['message']['photo'][0]['file_size']=invalid
            self.assertIsNone(parse_update(value,bot_id='123456',username='ustarcorp_bot',now=1000))

    def test_photo_transport_bounds_actual_bytes_when_sizes_are_optional(self):
        from unittest.mock import MagicMock,Mock
        from ustar_connect.telegram_api import TelegramClient
        api=TelegramClient('123456:'+'x'*30);api._call=Mock(return_value={'file_path':'photos/synthetic.jpg'})
        api._opener=MagicMock();response=api._opener.open.return_value.__enter__.return_value
        response.read.return_value=b'photo'
        self.assertEqual(b'photo',api.download_bot_photo('synthetic_photo',None))
        response.read.assert_called_once_with(512*1024+1)
        for data in [b'',b'x'*(512*1024+1)]:
            response.read.return_value=data
            with self.assertRaises(ValueError):api.download_bot_photo('synthetic_photo',None)

    def test_photo_transport_checks_each_provided_size(self):
        from unittest.mock import MagicMock,Mock
        from ustar_connect.telegram_api import TelegramClient
        api=TelegramClient('123456:'+'x'*30);api._opener=MagicMock()
        api._opener.open.return_value.__enter__.return_value.read.return_value=b'photo'
        for declared,metadata in [(6,5),(5,6),(None,6),(5,True)]:
            api._call=Mock(return_value={'file_path':'photos/synthetic.jpg','file_size':metadata})
            with self.assertRaises(ValueError):api.download_bot_photo('synthetic_photo',declared)

    def test_old_message_date_is_not_used_as_callback_click_time(self):
        from ustar_connect.bot_handler import parse_update
        update={'update_id':11,'callback_query':{'id':'query-1','from':{'id':55,'is_bot':False},
            'data':'cb4_'+'A'*43,'message':{'message_id':7,'date':1,'chat':{'id':55,'type':'private'},
            'from':{'id':123456,'is_bot':True}}}}
        value=parse_update(update,bot_id='123456',username='ustarcorp_bot',now=1000)
        self.assertEqual(value.payload['auth_date'],1000)
        self.assertEqual(value.payload['callback'],'cb4_'+'A'*43)

    def test_public_navigation_button_can_only_open_current_menu(self):
        from ustar_connect.bot_handler import parse_update
        update={'update_id':12,'callback_query':{'id':'query-2','from':{'id':55,'is_bot':False},
            'data':'uc4_menu','message':{'message_id':7,'date':1,'chat':{'id':55,'type':'private'},
            'from':{'id':123456,'is_bot':True}}}}
        value=parse_update(update,bot_id='123456',username='ustarcorp_bot',now=1000)
        self.assertIsNotNone(value)
        self.assertEqual(value.payload['text'],'Меню')
        self.assertEqual(value.payload['callback'],'')
        update['callback_query']['data']='uc4_approve'
        self.assertIsNone(parse_update(update,bot_id='123456',username='ustarcorp_bot',now=1000))

    def test_notification_keeps_receipt_and_adds_button_navigation_only_when_enabled(self):
        from ustar_connect.telegram_api import TelegramClient
        from unittest.mock import Mock
        api=TelegramClient('123456:'+'x'*30);api._send=Mock(return_value={})
        api._role_navigation=True
        api.send_notification(55,'tasks','action',1000,event='task_assigned',ack={'action':'ua1_'+'A'*43,'expires':2000})
        payload=api._send.call_args.args[1]
        self.assertEqual(payload['parse_mode'],'HTML')
        buttons=[b for row in payload['reply_markup']['inline_keyboard'] for b in row]
        self.assertTrue(any(b.get('callback_data')=='uc4_menu' for b in buttons))
        self.assertTrue(any(b.get('callback_data')=='ua1_'+'A'*43 for b in buttons))


class BotDurableFlow(unittest.TestCase):
    def test_real_sqlite_receipt_prevents_second_business_request_and_send(self):
        import tempfile
        from unittest.mock import Mock
        from ustar_connect.receiver_journal import Journal
        from ustar_connect.bot_handler import BotHandler,parse_update
        with tempfile.TemporaryDirectory() as root:
            os.chmod(root,0o700);journal=Journal(root,'123456',clock=lambda:1000)
            try:
                api=Mock();api.send_bot_view.return_value={'message_id':8,'chat':{'id':55,'type':'private'}}
                bridge=Mock();bridge.request.return_value={'ok':True,'result':TelegramBotInterface().view()}
                event=parse_update(TelegramBotInterface().message(),bot_id='123456',username='ustarcorp_bot',now=1000)
                handler=BotHandler(api,bridge,journal,tenant='123456',academy_origin='https://ustar.local',clock=lambda:1000)
                handler.handle(event);handler.handle(event)
                self.assertEqual(1,bridge.request.call_count);self.assertEqual(1,api.send_bot_view.call_count)
                self.assertEqual('bot.update',bridge.request.call_args.args[0])
                self.assertNotIn('userid',bridge.request.call_args.args[1])
                stored=journal._db.execute('SELECT * FROM events').fetchone()
                self.assertEqual('sent',stored['state']);self.assertEqual('bot',stored['kind'])
            finally:journal.close()

    def test_uncertain_send_is_never_automatically_reissued(self):
        import tempfile
        from unittest.mock import Mock
        from ustar_connect.receiver_journal import Journal
        from ustar_connect.bot_handler import BotHandler,parse_update
        from ustar_connect.telegram_api import TelegramError
        with tempfile.TemporaryDirectory() as root:
            os.chmod(root,0o700);journal=Journal(root,'123456',clock=lambda:1000)
            try:
                api=Mock();api.send_bot_view.side_effect=TelegramError('transport_unavailable',uncertain=True)
                bridge=Mock();bridge.request.return_value={'ok':True,'result':TelegramBotInterface().view()}
                event=parse_update(TelegramBotInterface().message(),bot_id='123456',username='ustarcorp_bot',now=1000)
                handler=BotHandler(api,bridge,journal,tenant='123456',academy_origin='https://ustar.local')
                handler.handle(event);handler.handle(event)
                self.assertEqual(1,bridge.request.call_count);self.assertEqual(1,api.send_bot_view.call_count)
                self.assertEqual('uncertain',journal._db.execute('SELECT state FROM events').fetchone()[0])
            finally:journal.close()

    def test_photo_download_requires_canonical_upload_authorization_first(self):
        from unittest.mock import Mock
        from ustar_connect.bot_handler import parse_update
        from ustar_connect.bot_upload import upload_photo
        from ustar_connect.bridge import BridgeRejected
        message=TelegramBotInterface().message('');message['message']['photo']=[{'file_id':'synthetic_photo','file_size':100}]
        event=parse_update(message,bot_id='123456',username='ustarcorp_bot',now=1000)
        api=Mock();bridge=Mock();bridge.request.side_effect=BridgeRejected('invalid_bot_upload')
        with self.assertRaises(BridgeRejected):upload_photo(api,bridge,event)
        api.download_bot_photo.assert_not_called()


if __name__ == '__main__':
    unittest.main()
