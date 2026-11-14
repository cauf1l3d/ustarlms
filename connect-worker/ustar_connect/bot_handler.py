"""Button-first role bot. Only authenticated personal Bot API updates reach the scoped facade."""
from dataclasses import dataclass
import re
import time

from .bridge import _token, BridgeRejected
from .identity import ProviderIdentity
from .notification_callback import ORIGINS
from .telegram_api import TelegramError
from .bot_graphics import presentation
from .bot_cards import render_view
from .bot_widget import widget_receipt


@dataclass(frozen=True,repr=False)
class BotUpdate:
    update_id:int
    subject:int
    message_id:int
    query_id:str
    payload:dict
    photo:tuple=()
    media_message:bool=False
    widget_message:bool=False


def _positive(value):
    return type(value) is int and 0<value<2**52


def parse_update(update, *, bot_id, username, now):
    if (not isinstance(update,dict) or type(update.get('update_id')) is not int
            or not 0<update['update_id']<2**52):return None
    callback='';query_id='';photo=()
    if set(update)=={'update_id','message'}:
        message=update['message']
        if not isinstance(message,dict) or ORIGINS.intersection(message):return None
        sender=message.get('from');stamp=message.get('date');text=message.get('text','')
        if type(stamp) is not int or not now-120<=stamp<=now+30 or not isinstance(text,str) or len(text)>4000:return None
        if re.fullmatch(r'/start(?:@[A-Za-z0-9_]{5,32})? uc1_[A-Za-z0-9_-]{43}',text):return None
        invite = re.fullmatch(r'/start(?:@([A-Za-z0-9_]{5,32}))? (ui1_[A-Za-z0-9_-]{43})', text)
        if invite:
            if invite[1] and invite[1].lower() != username.lower():
                return None
            text = '/start ' + invite[2]
        elif text.startswith('/'):
            match=re.fullmatch(r'/([a-z_]{1,32})(?:@([A-Za-z0-9_]{5,32}))?',text)
            if not match or (match[2] and match[2].lower()!=username.lower()):return None
            text='/'+match[1]
        if 'photo' in message:
            if invite:
                return None
            sizes=message['photo']
            if not isinstance(sizes,list) or not 1<=len(sizes)<=10:return None
            candidates=[s for s in sizes if isinstance(s,dict) and isinstance(s.get('file_id'),str)
                and re.fullmatch(r'[A-Za-z0-9_-]{1,256}',s['file_id']) and ('file_size' not in s or
                (type(s['file_size']) is int and 0<s['file_size']<=512*1024))]
            if not candidates:return None
            # Telegram may omit file_size. Prefer a known bounded size; downloads remain bounded too.
            chosen=max(candidates,key=lambda s:s.get('file_size',0))
            photo=(chosen['file_id'],chosen.get('file_size'))
            text=message.get('caption','')
            if not isinstance(text,str) or len(text)>1000:return None
    elif set(update)=={'update_id','callback_query'}:
        q=update['callback_query']
        if (not isinstance(q,dict) or ORIGINS.intersection(q) or 'inline_message_id' in q
                or 'game_short_name' in q or not isinstance(q.get('id'),str)
                or not re.fullmatch(r'[!-~]{1,256}',q['id'])):return None
        message=q.get('message');sender=q.get('from')
        if not isinstance(message,dict) or ORIGINS.intersection(message):return None
        origin=message.get('from')
        if not isinstance(origin,dict) or type(origin.get('id')) is not int or str(origin['id'])!=bot_id or origin.get('is_bot') is not True:return None
        if type(message.get('date')) is not int or not 0<message['date']<=now+30:return None
        if q.get('data')=='uc4_menu':
            text='Меню'
        else:
            try:callback=_token(q.get('data'),'cb4')
            except ValueError:return None
            text=''
        query_id=q['id'];stamp=now
        # Original message.date is not click time. The server owns action expiry/revision.
    else:return None
    chat=message.get('chat')
    if (not isinstance(sender,dict) or not _positive(sender.get('id')) or sender.get('is_bot') is not False
            or not isinstance(chat,dict) or chat.get('type')!='private' or type(chat.get('id')) is not int
            or chat['id']!=sender['id'] or not _positive(message.get('message_id'))):return None
    payload={'auth_date':stamp,'callback':callback,'event':str(update['update_id']),'media':'',
        'message':str(message['message_id']),'provider':'telegram','subject':str(sender['id']),'tenant':bot_id,'text':text}
    return BotUpdate(update['update_id'],sender['id'],message['message_id'],query_id,payload,photo,
                     bool(query_id and message.get('photo')), bool(query_id and 'widget' in message))


class BotHandler:
    def __init__(self,api,bridge,journal,*,tenant,academy_origin,clock=time.time):
        self.api,self.bridge,self.journal=api,bridge,journal
        self.tenant,self.origin,self.clock=tenant,academy_origin,clock

    def handle(self,event):
        key=ProviderIdentity('telegram',self.tenant,str(event.subject)).key()
        if not self.journal.begin(event.update_id,key,'bot'):return
        if not self.journal.allowed(key,'bot'):
            self.journal.finish(event.update_id,'ignored','identity_rate_limit');return
        self.journal.phase(event.update_id,'creating')
        try:
            if event.query_id:self.api.answer_bot_callback(event.query_id)
            payload=dict(event.payload)
            if event.photo:
                from .bot_upload import upload_photo
                payload['media']=upload_photo(self.api,self.bridge,event)
            value=self.bridge.request('bot.update',payload)
            if not isinstance(value,dict) or set(value)!={'ok','result'} or value['ok'] is not True:
                raise ValueError('invalid_bot_response')
            view=value['result']
            self.journal.phase(event.update_id,'sending')
            widget=view.get('widget') if isinstance(view,dict) else None
            if widget is not None and self._send_widget(event, view, widget):
                return
            view={k:v for k,v in view.items() if k!='widget'} if widget is not None else view
            if view.get('plain') is True:
                rendered = render_view({k: v for k, v in view.items() if k != 'plain'}, academy_origin=self.origin)
            else:
                rendered = presentation(view, academy_origin=self.origin, now=self.clock())
            for photo in view.get('photos',[]):
                self.api.send_bot_photo(event.subject,photo)
            result=(self.api.edit_bot_view(event.subject,event.message_id,rendered,media_message=event.media_message)
                    if event.query_id and not event.widget_message
                    else self.api.send_bot_view(event.subject,rendered))
            mid=self._message_id(result, event.subject)
            self.journal.finish(event.update_id,'sent',message_id=mid)
        except BridgeRejected as error:
            self.journal.finish(event.update_id,'rejected','bot_action_rejected')
            self.api.send_bot_hint(event.subject,error.reason)
        except TelegramError as error:
            self.journal.finish(event.update_id,'uncertain' if error.uncertain else 'rejected',error.reason)
            if error.retry_after or error.reason in ('unauthorized','consumer_conflict'):raise
        except (ValueError,OSError):
            self.journal.finish(event.update_id,'uncertain','bot_result_unconfirmed')
            self.api.send_bot_hint(event.subject,'uncertain')

    @staticmethod
    def _message_id(result, subject):
        chat = result.get('chat') if isinstance(result, dict) else None
        mid = result.get('message_id') if isinstance(result, dict) else None
        if (not isinstance(chat, dict) or chat.get('type') != 'private' or not _positive(chat.get('id'))
                or chat['id'] != subject or not _positive(mid)):
            raise TelegramError('invalid_response', uncertain=True)
        return mid

    def _send_widget(self, event, view, widget):
        markup = render_view({k: v for k, v in view.items() if k != 'widget'},
                             academy_origin=self.origin)['reply_markup']
        try:
            result = self.api.send_checklist_widget(event.subject, view['title'], widget, markup)
        except TelegramError as error:
            if not error.uncertain and error.reason == 'api_rejected':
                return False
            raise
        mid = self._message_id(result, event.subject)
        if not isinstance(result.get('widget'), dict):
            raise TelegramError('invalid_response', uncertain=True)
        reason = ''
        try:
            bound = self._widget_request(event.update_id, event.subject, mid, widget['action'], 'bind')
            if bound != {'status': 'bound'}:
                raise ValueError('invalid_widget_binding')
        except (ValueError, OSError):
            # The message is already visible and its ordinary form remains usable.
            reason = 'widget_binding_unconfirmed'
        self.journal.finish(event.update_id, 'sent', reason, mid)
        return True

    def _widget_request(self, event, subject, message, action, command):
        envelope = self.bridge.request('bot.update', {
            'auth_date': int(self.clock()), 'callback': action, 'event': str(event),
            'media': '', 'message': str(message), 'provider': 'telegram', 'subject': str(subject),
            'tenant': self.tenant, 'text': '', 'widget': command,
        }, timeout=5)
        if not isinstance(envelope, dict) or set(envelope) != {'ok', 'result'} or envelope['ok'] is not True:
            raise ValueError('invalid_widget_response')
        return envelope['result']

    def handle_widget(self, query):
        key = ProviderIdentity('telegram', self.tenant, str(query.subject)).key()
        if not self.journal.begin(query.update_id, key, 'bot'):
            return
        if not self.journal.allowed(key, 'bot'):
            self.journal.finish(query.update_id, 'ignored', 'identity_rate_limit')
            return
        self.journal.phase(query.update_id, 'creating')
        try:
            result = widget_receipt(self._widget_request(query.update_id, query.subject, query.message_id,
                                                        query.action, query.answers))
        except BridgeRejected as error:
            result = {'status': 'retry' if error.reason == 'rate_limited' else 'stale'}
        except (ValueError, OSError):
            result = {'status': 'retry'}
        self.journal.phase(query.update_id, 'sending')
        reason = ''
        try:
            if self.api.answer_widget_query(query.query_id, result) is not True:
                raise ValueError('invalid_widget_answer')
        except (TelegramError, ValueError, OSError) as error:
            reason = 'widget_answer_unconfirmed'
            if isinstance(error, TelegramError) and (error.retry_after or error.reason in ('unauthorized', 'consumer_conflict')):
                self._finish_widget(query, result, reason)
                raise
        if result['status'] == 'saved':
            try:
                if self.api.complete_checklist_widget(query.subject, query.message_id, result) is not True:
                    raise ValueError('invalid_widget_edit')
            except (TelegramError, ValueError, OSError) as error:
                reason = reason or 'widget_edit_unconfirmed'
                if isinstance(error, TelegramError) and (error.retry_after or error.reason in ('unauthorized', 'consumer_conflict')):
                    self._finish_widget(query, result, reason)
                    raise
        self._finish_widget(query, result, reason)

    def _finish_widget(self, query, result, reason):
        state = {'saved': 'sent', 'stale': 'rejected', 'retry': 'uncertain'}[result['status']]
        self.journal.finish(query.update_id, state, reason, query.message_id)
