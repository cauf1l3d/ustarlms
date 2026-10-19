"""Single-consumer Telegram polling and bounded personal-launch orchestration."""
import re
import secrets
import time

from .bridge import _token
from .identity import ProviderIdentity
from .telegram_api import TelegramError


class ReceiverStopped(Exception):
    pass


def _id(value):
    return type(value) is int and 0 < value < 2**52


def _command(update, username, now):
    message = update.get('message')
    if not isinstance(message, dict) or set(update) != {'update_id', 'message'}:
        return None
    if any(name in message for name in ('forward_origin','forward_from','forward_from_chat','sender_chat',
                                        'via_bot','business_connection_id','guest_query_id','guest_bot_caller_user')):
        return None
    sender, chat = message.get('from'), message.get('chat')
    if (not isinstance(sender, dict) or not isinstance(chat, dict) or not _id(sender.get('id'))
            or sender.get('is_bot') is not False or chat.get('type') != 'private'
            or type(chat.get('id')) is not int or chat['id'] != sender['id']):
        return None
    date, text = message.get('date'), message.get('text')
    if type(date) is not int or not now-120 <= date <= now+30 or not isinstance(text, str) or len(text) > 80:
        return None
    match = re.fullmatch(r'/(start|academy|help)(?:@([A-Za-z0-9_]{5,32}))?', text)
    if match is None or (match[2] is not None and match[2].lower() != username.lower()):
        return None
    entities = message.get('entities')
    if not isinstance(entities, list) or not any(isinstance(e,dict) and e.get('type')=='bot_command'
            and type(e.get('offset')) is int and e['offset']==0
            and type(e.get('length')) is int and e['length']==len(text) for e in entities):
        return None
    return sender['id'], match[1]


class TelegramReceiver:
    def __init__(self, api, bridge, journal, *, bot_id, bot_username, onboarding_enabled=False, clock=time.time):
        if (not isinstance(bot_id, str) or not re.fullmatch(r'[1-9][0-9]{0,19}', bot_id)
                or not isinstance(bot_username, str) or not re.fullmatch(r'[A-Za-z0-9_]{5,32}', bot_username)
                or type(onboarding_enabled) is not bool):
            raise ValueError('invalid_receiver_configuration')
        self._api, self._bridge, self._journal, self._clock = api, bridge, journal, clock
        self._bot_id, self._username, self._enabled = bot_id, bot_username, onboarding_enabled
        self._ready = False

    def _start(self):
        me = self._api.get_me()
        if (not isinstance(me,dict) or type(me.get('id')) is not int or str(me['id']) != self._bot_id
                or me.get('is_bot') is not True or me.get('username','').lower() != self._username.lower()):
            raise ReceiverStopped('bot_identity_mismatch')
        webhook = self._api.get_webhook_info()
        if not isinstance(webhook,dict) or webhook.get('url') != '':
            raise ReceiverStopped('webhook_present')
        if self._enabled and me.get('has_main_web_app') is not True:
            raise ReceiverStopped('main_app_not_configured')
        self._journal.recover()
        self._ready = True

    def _delay(self, error):
        if error.reason in ('unauthorized', 'consumer_conflict'):
            raise ReceiverStopped(error.reason) from None
        health = self._journal.health()
        delay = error.retry_after or min(300, 2**min(health['failures']+1, 8)+secrets.randbelow(3))
        self._journal.backoff(delay)

    def step(self, *, stop=None):
        if stop is not None and stop.is_set():
            return {'status':'stopping'}
        now = int(self._clock())
        health = self._journal.health()
        if health['backoff_until'] > now:
            return {'status':'waiting','retry_after':health['backoff_until']-now}
        try:
            if not self._ready:
                self._start()
            if stop is not None and stop.is_set():
                return {'status':'stopping'}
            health = self._journal.prepare_poll()
            updates = self._api.get_updates(health['next_offset'])
            if not isinstance(updates,list) or len(updates)>50:
                raise TelegramError('invalid_response')
            previous = -1
            for update in updates:
                uid = update.get('update_id') if isinstance(update,dict) else None
                if type(uid) is not int or not 0 <= uid < 2**63-1 or uid <= previous:
                    raise TelegramError('invalid_response')
                previous = uid
            self._journal.polled(not updates)
            processed = 0
            for update in updates:
                if stop is not None and stop.is_set():
                    break
                self._process(update, int(self._clock()))
                processed += 1
                if self._journal.health()['backoff_until'] > int(self._clock()):
                    break
            return {'status':'polled','processed':processed}
        except TelegramError as error:
            self._delay(error)
            return {'status':'backoff','reason':error.reason}

    def _process(self, update, now):
        uid = update['update_id']
        command = _command(update, self._username, now)
        subject, kind, identity = None, 'ignored', None
        if command is not None:
            subject, verb = command
            kind = ('launch' if self._enabled else 'disabled') if verb != 'help' else 'help'
            identity = ProviderIdentity('telegram',self._bot_id,str(subject)).key()
        if not self._journal.begin(uid, identity, kind):
            return
        if command is None:
            self._journal.finish(uid,'ignored','unsupported_or_stale')
            return
        if not self._journal.allowed(identity,kind):
            self._journal.finish(uid,'ignored','identity_rate_limit')
            return
        try:
            if kind == 'launch':
                self._journal.phase(uid,'creating')
                value = self._bridge.request('launch.create', {'provider':'telegram','tenant':self._bot_id,'subject':str(subject)})
                result = value.get('result') if isinstance(value,dict) and value.get('ok') is True else None
                if not isinstance(result,dict):
                    raise ValueError('invalid_bridge_response')
                ticket = _token(result.get('launch'),'ul1')
                expiry = result.get('expires_at')
                current = int(self._clock())
                if type(expiry) is not int or not current < expiry <= current+600:
                    raise ValueError('invalid_launch_expiry')
                self._journal.phase(uid,'sending')
                response = self._api.send_launch(subject,'https://t.me/'+self._username+'?startapp='+ticket)
            else:
                self._journal.phase(uid,'sending')
                response = self._api.send_notice(subject)
            chat = response.get('chat') if isinstance(response,dict) else None
            message_id = response.get('message_id') if isinstance(response,dict) else None
            if (not isinstance(chat,dict) or type(chat.get('id')) is not int or chat['id'] != subject
                    or chat.get('type') != 'private' or type(message_id) is not int or not 0 < message_id < 2**63):
                raise TelegramError('invalid_response',uncertain=True)
            self._journal.finish(uid,'sent',message_id=message_id)
        except TelegramError as error:
            self._journal.finish(uid,'uncertain' if error.uncertain else 'rejected',error.reason)
            if error.retry_after or error.uncertain or error.reason in ('unauthorized','consumer_conflict'):
                self._delay(error)
        except (ValueError, OSError):
            self._journal.finish(uid,'uncertain','bridge_unavailable')
