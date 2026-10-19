"""Telegram receiver: real HTTP parsing, SQLite and process locks; no real bot traffic."""
import importlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import Mock
from urllib.error import HTTPError

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT/'connect-worker'))
NOW = 1800000000
TOKEN = '123456:synthetic-test-credential-not-a-real-bot'
USER = 8727627355
USERNAME = 'synthetic_academy_bot'


def update(number=10, text='/start', **changes):
    message = {'message_id': number, 'date': NOW, 'chat': {'id': USER, 'type': 'private'},
               'from': {'id': USER, 'is_bot': False}, 'text': text,
               'entities': [{'offset': 0, 'length': len(text.split()[0]), 'type': 'bot_command'}]}
    message.update(changes)
    return {'update_id': number, 'message': message}


class Response(io.BytesIO):
    def __enter__(self): return self
    def __exit__(self, *args): self.close()


class TransportTests(unittest.TestCase):
    def setUp(self):
        self.assertTrue((ROOT/'connect-worker/ustar_connect/telegram_api.py').is_file(), 'Telegram transport missing')
        self.module = importlib.import_module('ustar_connect.telegram_api')
        self.api = self.module.TelegramClient(TOKEN)
        self.opener = Mock()
        self.api._opener = self.opener

    def reply(self, value):
        self.opener.open.return_value = Response(json.dumps(value).encode())

    def test_poll_has_fixed_origin_filters_positive_offset_and_bounded_timeout(self):
        self.reply({'ok': True, 'result': []})
        self.assertEqual([], self.api.get_updates(11))
        request = self.opener.open.call_args.args[0]
        self.assertEqual('https://api.telegram.org/bot'+TOKEN+'/getUpdates', request.full_url)
        self.assertEqual({'offset': 11, 'limit': 50, 'timeout': 25, 'allowed_updates': ['message']}, json.loads(request.data))
        self.assertEqual(35, self.opener.open.call_args.kwargs['timeout'])
        for offset in (-1, True, 1.2, '1'):
            with self.assertRaises(ValueError): self.api.get_updates(offset)
        self.assertNotIn(TOKEN, repr(self.api))

    def test_tls_redirect_and_proxy_policy(self):
        import ssl
        from urllib.request import HTTPSHandler
        actual = self.module.TelegramClient(TOKEN, proxy='http://127.0.0.1:18081')
        tls = next(x for x in actual._opener.handlers if isinstance(x, HTTPSHandler))._context
        self.assertTrue(tls.check_hostname); self.assertEqual(ssl.CERT_REQUIRED, tls.verify_mode)
        self.assertIsNone(self.module._NoRedirect().redirect_request(None,None,302,'',{},'https://other.invalid'))
        for proxy in ('http://example.invalid:8080', 'socks5://127.0.0.1:1080',
                      'http://user:pass@127.0.0.1:8080', 'http://127.0.0.1:8080/path',
                      'http://127.0.0.1:8080?key=x', 'http://localhost:8080'):
            with self.assertRaises(ValueError): self.module.TelegramClient(TOKEN,proxy=proxy)
        clean = self.module.TelegramClient(TOKEN)
        self.assertFalse(any(getattr(x,'proxies',{}) for x in clean._opener.handlers))

    def test_timeout_send_is_uncertain_and_never_discloses_url_or_payload(self):
        self.opener.open.side_effect = OSError('private '+TOKEN)
        with self.assertRaises(self.module.TelegramError) as caught:
            self.api.send_launch(USER, 'https://t.me/'+USERNAME+'?startapp=ul1_'+'A'*43)
        self.assertTrue(caught.exception.uncertain)
        self.assertEqual('transport_unavailable', str(caught.exception))
        self.assertNotIn(TOKEN, repr(caught.exception))
        self.assertEqual(1, self.opener.open.call_count)

    def test_429_retry_after_and_auth_conflict_are_typed_without_description(self):
        for code, reason in ((401,'unauthorized'),(409,'consumer_conflict'),(429,'rate_limited'),(403,'forbidden')):
            payload = {'ok':False,'error_code':code,'description':TOKEN,'parameters':{'retry_after':120}}
            self.opener.open.side_effect = HTTPError('private',code,TOKEN,{},io.BytesIO(json.dumps(payload).encode()))
            with self.assertRaises(self.module.TelegramError) as caught: self.api.get_updates(0)
            self.assertEqual(reason,caught.exception.reason)
            self.assertNotIn(TOKEN,repr(caught.exception))
            if code==429: self.assertEqual(120,caught.exception.retry_after)

    def test_malformed_duplicate_oversized_and_5xx_send_stay_uncertain(self):
        for raw in (b'no', b'{"ok":true,"ok":false,"result":[]}', b' '*2097153):
            self.opener.open.return_value = Response(raw)
            with self.assertRaises(self.module.TelegramError) as caught: self.api.get_updates(0)
            self.assertEqual('invalid_response',caught.exception.reason)
        self.opener.open.side_effect = HTTPError('private',502,TOKEN,{},io.BytesIO(b'private'))
        with self.assertRaises(self.module.TelegramError) as caught: self.api.send_notice(USER)
        self.assertTrue(caught.exception.uncertain)


class FakeApi:
    def __init__(self):
        self.updates, self.calls, self.sent = [], [], []
        self.me = {'id':123456,'is_bot':True,'username':USERNAME,'has_main_web_app':True}
        self.webhook = {'url':''}
        self.poll_error = self.send_error = None
    def get_me(self): self.calls.append(('getMe',)); return self.me
    def get_webhook_info(self): self.calls.append(('getWebhookInfo',)); return self.webhook
    def get_updates(self,offset):
        self.calls.append(('getUpdates',offset))
        if self.poll_error: raise self.poll_error
        return self.updates
    def send_launch(self,subject,url):
        self.sent.append((subject,url))
        if self.send_error: raise self.send_error
        return {'message_id':500,'chat':{'id':subject,'type':'private'}}
    def send_notice(self,subject):
        self.sent.append((subject,None))
        if self.send_error: raise self.send_error
        return {'message_id':501,'chat':{'id':subject,'type':'private'}}


class FakeBridge:
    def __init__(self): self.calls=[]; self.failure=None
    def request(self,operation,payload):
        self.calls.append((operation,dict(payload)))
        if self.failure: raise self.failure
        return {'ok':True,'result':{'launch':'ul1_'+'A'*43,'expires_at':NOW+600}}


class ReceiverTests(unittest.TestCase):
    def setUp(self):
        self.assertTrue((ROOT/'connect-worker/ustar_connect/receiver.py').is_file(), 'receiver missing')
        self.module=importlib.import_module('ustar_connect.receiver')
        self.storage=importlib.import_module('ustar_connect.receiver_journal')
        self.transport=importlib.import_module('ustar_connect.telegram_api')
        self.temp=tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.directory=Path(self.temp.name)
        self.now=NOW
        self.journal=self.storage.Journal(self.directory,'123456',clock=lambda:self.now)
        self.addCleanup(lambda:self.journal.close())
        self.api,self.bridge=FakeApi(),FakeBridge()
        self.receiver=self.make_receiver()

    def make_receiver(self,enabled=True):
        return self.module.TelegramReceiver(self.api,self.bridge,self.journal,
            bot_id='123456',bot_username=USERNAME,onboarding_enabled=enabled,clock=lambda:self.now)

    def restart(self):
        self.journal.close()
        self.journal=self.storage.Journal(self.directory,'123456',clock=lambda:self.now)
        self.receiver=self.make_receiver()

    def test_private_start_issues_sender_bound_launch_and_button_with_no_business_grant(self):
        self.api.updates=[update()]
        result=self.receiver.step()
        self.assertEqual('polled',result['status'])
        self.assertEqual([('launch.create',{'provider':'telegram','tenant':'123456','subject':str(USER)})],self.bridge.calls)
        self.assertEqual([(USER,'https://t.me/'+USERNAME+'?startapp=ul1_'+'A'*43)],self.api.sent)
        self.assertEqual(11,self.journal.health()['next_offset'])
        self.assertEqual(1,self.journal.health()['states']['sent'])

    def test_shutdown_during_poll_leaves_unprocessed_updates_unacknowledged(self):
        import threading
        stop=threading.Event()
        def poll(offset):
            stop.set();return [update()]
        self.api.get_updates=poll
        self.receiver.step(stop=stop)
        self.assertEqual([],self.bridge.calls);self.assertEqual([],self.api.sent)
        self.assertEqual(0,self.journal.health()['next_offset'])

    def test_replay_and_restart_do_not_send_or_issue_again(self):
        self.api.updates=[update()]; self.receiver.step(); self.restart(); self.receiver.step()
        self.assertEqual(1,len(self.bridge.calls)); self.assertEqual(1,len(self.api.sent))
        self.assertIn(('getUpdates',11),self.api.calls)

    def test_unsafe_group_forward_via_bot_and_non_integer_sender_are_ignored(self):
        variants=[{'chat':{'id':USER,'type':'group'}},{'chat':{'id':USER+1,'type':'private'}},
                  {'from':{'id':True,'is_bot':False}},{'from':{'id':str(USER),'is_bot':False}},
                  {'from':{'id':USER,'is_bot':True}},{'forward_origin':{}},{'via_bot':{'id':999}},
                  {'sender_chat':{'id':USER}},{'business_connection_id':'private'},
                  {'guest_query_id':'private'},{'entities':[]}]
        self.api.updates=[update(i+10,**changes) for i,changes in enumerate(variants)]
        self.receiver.step()
        self.assertEqual([],self.api.sent);self.assertEqual([],self.bridge.calls)
        self.assertEqual(10+len(variants),self.journal.health()['next_offset'])

    def test_free_text_arguments_other_bot_and_expired_commands_not_executed(self):
        self.api.updates=[update(10,'Fixture-Only-Qx9!2026'),update(11,'/start secret'),
                          update(12,'/start@other_bot'),update(13,date=NOW-121),update(14,date=NOW+31)]
        self.receiver.step(); self.assertEqual([],self.api.sent); self.assertEqual([],self.bridge.calls)

    def test_freshness_is_rechecked_after_poll_delay_and_before_each_event(self):
        original=self.api.get_updates
        self.api.updates=[update()]
        def delayed(offset):
            values=original(offset);self.now+=121;return values
        self.api.get_updates=delayed
        self.receiver.step()
        self.assertEqual([],self.bridge.calls);self.assertEqual([],self.api.sent)

    def test_sender_ack_mismatch_does_not_report_sent(self):
        self.api.updates=[update()]
        def wrong_ack(subject,url):
            return {'message_id':500,'chat':{'id':subject+1,'type':'private'}}
        self.api.send_launch=wrong_ack
        self.receiver.step()
        self.assertEqual(1,self.journal.health()['states']['uncertain'])

    def test_journal_file_modes_and_links_cannot_be_repaired_silently(self):
        self.journal.close()
        db=self.directory/'receiver.sqlite3';db.chmod(0o644)
        with self.assertRaises(self.storage.JournalError):self.storage.Journal(self.directory,'123456')
        self.assertEqual(0o644,db.stat().st_mode & 0o777)
        db.chmod(0o600)
        twin=self.directory/'hardlink';os.link(db,twin)
        with self.assertRaises(self.storage.JournalError):self.storage.Journal(self.directory,'123456')

    def test_addressed_own_bot_command_and_help_are_narrow(self):
        self.api.updates=[update(10,'/academy@'+USERNAME),update(11,'/help')]
        self.receiver.step()
        self.assertEqual(1,len(self.bridge.calls)); self.assertEqual(2,len(self.api.sent))
        self.assertIsNone(self.api.sent[-1][1])

    def test_disabled_onboarding_uses_notice_without_launch_or_main_app_requirement(self):
        self.receiver=self.make_receiver(enabled=False); self.api.me['has_main_web_app']=False
        self.api.updates=[update()]; self.receiver.step()
        self.assertEqual([],self.bridge.calls);self.assertEqual([(USER,None)],self.api.sent)

    def test_webhook_wrong_bot_username_or_missing_main_app_stop_before_poll(self):
        variants=[('webhook',{'url':'https://private.invalid/webhook'}),
                  ('me',dict(self.api.me,id=999)),('me',dict(self.api.me,username='other_bot')),
                  ('me',dict(self.api.me,has_main_web_app=False))]
        for key,value in variants:
            with self.subTest(key=key,value=value):
                self.api=FakeApi();setattr(self.api,key,value);self.receiver=self.make_receiver()
                with self.assertRaises(self.module.ReceiverStopped):self.receiver.step()
                self.assertFalse(any(c[0]=='getUpdates' for c in self.api.calls))

    def test_timeout_after_send_becomes_uncertain_and_replay_does_not_resend(self):
        self.api.send_error=self.transport.TelegramError('transport_unavailable',uncertain=True)
        self.api.updates=[update()];self.receiver.step()
        self.assertEqual(1,self.journal.health()['states']['uncertain'])
        self.restart();self.receiver.step()
        self.assertEqual(1,len(self.api.sent));self.assertEqual(1,len(self.bridge.calls))

    def test_crash_with_creating_or_sending_does_not_repeat_external_side_effect(self):
        for phase in ('creating','sending'):
            with self.subTest(phase=phase):
                uid=10 if phase=='creating' else 11
                self.journal.begin(uid,'a'*64,'launch')
                self.journal.phase(uid,phase)
                self.restart();self.api.updates=[update(uid)];self.receiver.step()
                self.assertEqual([],self.bridge.calls);self.assertEqual([],self.api.sent)
        self.assertEqual(2,self.journal.health()['states']['uncertain'])

    def test_actual_send_then_process_exit_recovers_as_uncertain_without_resend(self):
        self.api.send_error=SystemExit(77)
        self.api.updates=[update()]
        with self.assertRaises(SystemExit):self.receiver.step()
        self.assertEqual(1,len(self.api.sent))
        self.restart();self.api.send_error=None;self.receiver.step()
        self.assertEqual(1,len(self.api.sent));self.assertEqual(1,len(self.bridge.calls))
        self.assertEqual(1,self.journal.health()['states']['uncertain'])

    def test_personal_button_signed_launch_is_compatible_with_existing_wsgi_boundary(self):
        self.api.updates=[update()];self.receiver.step()
        from urllib.parse import urlsplit,parse_qs
        ticket=parse_qs(urlsplit(self.api.sent[0][1]).query)['startapp'][0]
        from test_connect_web import BrowserBoundary
        from test_connect_identity import signed
        browser=BrowserBoundary('test_only_signed_launch_is_exchanged_and_cookie_rotates')
        browser.setUp();browser.bootstrap()
        result=browser.call('/api/launch',{'init_data':signed(start_param=ticket)})
        self.assertEqual(200,result['status'])
        operation,payload=browser.remote.calls[0]
        self.assertEqual('launch.exchange',operation)
        self.assertEqual(ticket,payload['launch']);self.assertEqual(str(USER),payload['subject'])
        self.assertEqual('onboarding',result['body']['scope'])
        self.assertNotIn('session',result['body'])

    def test_bridge_timeout_is_uncertain_without_automatic_issue_retry(self):
        self.bridge.failure=ValueError('PRIVATE_INPUT_CANARY');self.api.updates=[update()]
        self.receiver.step();self.restart();self.receiver.step()
        self.assertEqual(1,len(self.bridge.calls));self.assertEqual([],self.api.sent)
        self.assertEqual(1,self.journal.health()['states']['uncertain'])

    def test_429_persists_cooldown_and_does_not_ack_unprocessed_tail(self):
        self.api.send_error=self.transport.TelegramError('rate_limited',retry_after=120)
        self.api.updates=[update(10),update(11)]
        self.receiver.step();self.assertEqual(11,self.journal.health()['next_offset'])
        self.restart();before=len(self.api.calls)
        result=self.receiver.step()
        self.assertEqual('waiting',result['status']);self.assertEqual(before,len(self.api.calls))
        self.now+=121;self.api.send_error=None;self.api.updates=[update(11,date=self.now)]
        self.receiver.step();self.assertEqual(12,self.journal.health()['next_offset'])

    def test_401_and_409_poll_errors_stop_without_cursor_advance(self):
        for reason in ('unauthorized','consumer_conflict'):
            self.api.poll_error=self.transport.TelegramError(reason)
            with self.assertRaises(self.module.ReceiverStopped):self.receiver.step()
            self.assertEqual(0,self.journal.health()['next_offset'])

    def test_poll_transport_backoff_is_durable_and_has_no_hot_retry(self):
        self.api.poll_error=self.transport.TelegramError('transport_unavailable')
        self.receiver.step();self.restart();before=len(self.api.calls)
        self.assertEqual('waiting',self.receiver.step()['status'])
        self.assertEqual(before,len(self.api.calls))

    def test_bad_batch_is_rejected_before_any_effect_or_cursor_change(self):
        for values in ([update(11),update(10)],[update(10),update(10)],[{'update_id':True}], [update()]*51):
            with self.subTest(values=len(values)):
                self.api.updates=values;self.now+=400
                result=self.receiver.step()
                self.assertEqual('backoff',result['status'])
                self.assertEqual([],self.bridge.calls);self.assertEqual(0,self.journal.health()['next_offset'])

    def test_acknowledged_empty_poll_resets_offset_for_next_random_id(self):
        self.api.updates=[update(100000)];self.receiver.step()
        self.api.updates=[];self.receiver.step()
        self.assertEqual(('getUpdates',100001),self.api.calls[-1])
        self.assertEqual(0,self.journal.health()['next_offset'])
        self.now+=8*86400
        self.api.updates=[update(12,date=self.now)]
        self.receiver.step()
        self.assertIn(('getUpdates',0),self.api.calls)
        self.assertEqual(13,self.journal.health()['next_offset'])

    def test_day_offline_sets_gap_and_resets_before_first_poll(self):
        self.api.updates=[update(100000)];self.receiver.step();self.now+=86401;self.restart()
        self.api.updates=[];self.receiver.step()
        self.assertEqual(('getUpdates',0),self.api.calls[-1])
        self.assertTrue(self.journal.health()['history_gap_possible'])

    def test_sender_launch_rate_is_durable_and_ignores_fourth_command(self):
        self.api.updates=[update(i) for i in range(10,14)];self.receiver.step()
        self.assertEqual(3,len(self.bridge.calls));self.assertEqual(3,len(self.api.sent))
        self.assertEqual(14,self.journal.health()['next_offset'])

    def test_metadata_has_no_raw_message_profile_secret_or_launch_and_identity_erasure_keeps_dedup(self):
        self.api.updates=[update()];self.receiver.step()
        from ustar_connect.identity import ProviderIdentity
        key=ProviderIdentity('telegram','123456',str(USER)).key()
        self.journal.forget_identity(key);self.restart();self.receiver.step()
        self.assertEqual(1,len(self.api.sent))
        for filename in self.directory.iterdir():
            if filename.is_file():
                content=filename.read_bytes()
                for secret in (TOKEN, 'ul1_'+'A'*43, str(USER), '/start'):
                    self.assertNotIn(secret.encode(),content)

    def test_state_directory_permissions_symlinks_and_bot_tenant_are_checked(self):
        self.journal.close()
        os.chmod(self.directory,0o755)
        with self.assertRaises(self.storage.JournalError):self.storage.Journal(self.directory,'123456')
        os.chmod(self.directory,0o700)
        with self.assertRaises(self.storage.JournalError):self.storage.Journal(self.directory,'999')
        link=self.directory/'linked';link.symlink_to(self.directory,target_is_directory=True)
        with self.assertRaises(self.storage.JournalError):self.storage.Journal(link,'123456')

    def test_exclusive_lock_across_independent_processes(self):
        code=('import sys; from ustar_connect.receiver_journal import Journal,JournalError; '
              '\ntry: Journal(sys.argv[1],"123456")\nexcept JournalError as e: print(str(e));sys.exit(3)')
        env=dict(os.environ,PYTHONPATH=str(ROOT/'connect-worker'))
        result=subprocess.run([sys.executable,'-c',code,str(self.directory)],env=env,capture_output=True,text=True)
        self.assertEqual(3,result.returncode,result.stderr)
        self.assertEqual('journal_busy',result.stdout.strip())

    def test_pending_duplicate_cannot_advance_cursor_without_restart_recovery(self):
        self.journal.begin(10,'a'*64,'launch')
        with self.assertRaises(self.storage.JournalError):self.journal.begin(10,'a'*64,'launch')
        self.assertEqual(0,self.journal.health()['next_offset'])

    def test_retention_prunes_terminal_receipts_only_and_capacity_stops_before_ack(self):
        self.journal.begin(10,'a'*64,'launch');self.journal.finish(10,'ignored','unsupported_or_stale')
        self.journal.begin(11,'a'*64,'launch')
        self.now+=604801;self.journal.prepare_poll()
        self.assertEqual({'received':1},self.journal.health()['states'])
        with self.journal._db:
            self.journal._db.executemany("INSERT INTO events(update_id,kind,state,createdat) VALUES(?,'ignored','ignored',?)",
                                         [(i,self.now) for i in range(100,10099)])
        with self.assertRaisesRegex(self.storage.JournalError,'journal_full'):
            self.journal.begin(20000,None,'ignored')
        self.assertEqual(11,self.journal.health()['next_offset'])  # Only prior terminal event10 was accepted.

    def test_first_poll_prunes_at_most_500_receipts(self):
        with self.journal._db:
            self.journal._db.executemany("INSERT INTO events(update_id,kind,state,createdat) VALUES(?,'ignored','ignored',?)",
                [(i,NOW-604801) for i in range(1000)])
        self.receiver.step()
        self.assertEqual(500,sum(self.journal.health()['states'].values()))

    def test_atomic_finish_failure_rolls_back_state_and_offset(self):
        self.journal.begin(10,'a'*64,'launch')
        self.journal._db.execute("CREATE TRIGGER fail_cursor BEFORE UPDATE OF next_offset ON state BEGIN SELECT RAISE(ABORT,'synthetic'); END")
        with self.assertRaises(Exception):self.journal.finish(10,'sent',message_id=500)
        self.assertEqual(0,self.journal.health()['next_offset'])
        self.assertEqual({'received':1},self.journal.health()['states'])

    def test_unsafe_ancestor_directory_is_rejected_even_with_private_child(self):
        parent=self.directory/'shared';parent.mkdir(mode=0o777);os.chmod(parent,0o777)
        child=parent/'state';child.mkdir(mode=0o700)
        with self.assertRaises(self.storage.JournalError):self.storage.Journal(child,'123456')


class RuntimeConfigTests(unittest.TestCase):
    def setUp(self):
        self.assertTrue((ROOT/'connect-worker/ustar_connect/receiver_runtime.py').is_file(),'runtime config missing')
        self.module=importlib.import_module('ustar_connect.receiver_runtime')
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.directory=Path(self.temp.name)
        self.token=self.directory/'telegram.token'
        self.token.write_text(TOKEN);self.token.chmod(0o600)
        self.key=self.directory/'bridge.key'
        self.key.write_text('12'*32);self.key.chmod(0o600)
        self.path=self.directory/'receiver.json'
        self.config={'bot_token_file':str(self.token),'bot_username':USERNAME,
            'state_directory':str(self.directory),'telegram_proxy':None,'onboarding_enabled':False,
            'bridge_endpoint':'https://localhost/local/ustar/connect_bridge.php',
            'bridge_key_id':'tg1','bridge_key_file':str(self.key),'bridge_ca_file':None}
        self.save()
    def save(self):
        self.path.write_text(json.dumps(self.config));self.path.chmod(0o600)

    def test_default_cli_validates_locally_without_api_calls_or_state_creation(self):
        env=dict(os.environ,PYTHONPATH=str(ROOT/'connect-worker'))
        result=subprocess.run([sys.executable,'-m','ustar_connect.receiver_runtime','--config',str(self.path)],
                               env=env,capture_output=True,text=True,timeout=10)
        self.assertEqual(0,result.returncode,result.stderr)
        self.assertIn('configuration_valid',result.stdout)
        self.assertNotIn(TOKEN,result.stdout+result.stderr)
        self.assertFalse((self.directory/'receiver.sqlite3').exists())

    def test_private_config_rejects_unknown_fields_duplicate_keys_and_public_secrets(self):
        self.config['userid']=2;self.save()
        with self.assertRaises(ValueError):self.module.load_config(self.path)
        self.config.pop('userid');self.save();self.token.chmod(0o644)
        with self.assertRaises(ValueError):self.module.load_config(self.path)
        self.token.chmod(0o600);self.path.write_text('{"bot_username":"one","bot_username":"two"}')
        with self.assertRaises(ValueError):self.module.load_config(self.path)

    def test_explicit_bridge_ca_cannot_be_writable_by_other_users(self):
        from unittest.mock import patch
        ca=self.directory/'trusted-ca.crt';ca.write_text('synthetic CA metadata fixture');ca.chmod(0o644)
        self.config.update(onboarding_enabled=True,bridge_ca_file=str(ca));self.save()
        with patch.object(self.module,'BridgeClient'):
            self.module.load_config(self.path)
            ca.chmod(0o666)
            with self.assertRaises(ValueError):self.module.load_config(self.path)

    def test_runner_validates_bot_identity_before_network_and_never_prints_bad_config_text(self):
        self.config['bot_username']='invalid '+TOKEN;self.save()
        env=dict(os.environ,PYTHONPATH=str(ROOT/'connect-worker'))
        result=subprocess.run([sys.executable,'-m','ustar_connect.receiver_runtime','--config',str(self.path),'--run'],
                               env=env,capture_output=True,text=True,timeout=10)
        self.assertNotEqual(0,result.returncode)
        self.assertNotIn(TOKEN,result.stdout+result.stderr)

    def test_explicit_run_and_shutdown_use_real_journal_and_injected_api_without_sends(self):
        import contextlib
        import signal
        from unittest.mock import patch
        api=FakeApi();handlers={}
        def poll(offset):
            import time
            handlers[signal.SIGTERM](signal.SIGTERM,None)
            return [update(date=int(time.time()))]
        api.get_updates=poll
        output=io.StringIO()
        with patch.object(self.module,'TelegramClient',return_value=api), patch.object(self.module.signal,'signal',
                side_effect=lambda number,handler:handlers.update({number:handler})), contextlib.redirect_stdout(output):
            code=self.module.main(['--config',str(self.path),'--run'])
        self.assertEqual(0,code)
        self.assertIn('shutdown_requested',output.getvalue());self.assertNotIn(TOKEN,output.getvalue())
        self.assertEqual([],api.sent)
        self.assertTrue((self.directory/'receiver.sqlite3').exists())


if __name__=='__main__': unittest.main()
