"""One leased canonical notification per step. Moodle owns all delivery state."""
from contextlib import ExitStack
import re
import time

from .bridge import _token
from . import notification_broadcast
from .telegram_api import TelegramError
from .notification_events import event_label

CATEGORIES = {'registration','tasks','feed','grades'}
SEVERITIES = {'normal','action','critical'}


def private_subject(value):
    return isinstance(value, str) and bool(re.fullmatch(r'[1-9][0-9]{0,15}', value)) and int(value) < 2**52


def provider_reference(response, subject, count):
    messages = response if count > 1 else [response]
    if not isinstance(messages, list) or len(messages) != count:
        return None
    ids = []
    for message in messages:
        chat = message.get('chat') if isinstance(message, dict) else None
        if (not isinstance(chat, dict) or chat.get('type') != 'private'
                or type(chat.get('id')) is not int or str(chat['id']) != subject
                or type(message.get('message_id')) is not int or not 0 < message['message_id'] < 2**63):
            return None
        ids.append(message['message_id'])
    return str(ids[0]) if len(set(ids)) == count else None


class NotificationWorker:
    def __init__(self, api, bridge, *, bot_id, bot_username, clock=time.time, allowed_subjects=None,
                 notification_contract=1):
        if type(notification_contract) is not int or notification_contract not in (1, 2, 3):
            raise ValueError('invalid_notification_contract')
        self.notification_contract = notification_contract
        if allowed_subjects is not None and (not isinstance(allowed_subjects,(list,tuple))
                or not 1<=len(allowed_subjects)<=10
                or any(not isinstance(s,str) or not re.fullmatch('[1-9][0-9]{0,15}',s)
                       or int(s)>=2**52 for s in allowed_subjects)
                or len(set(allowed_subjects))!=len(allowed_subjects)):
            raise ValueError('invalid_pilot_subjects')
        self.api,self.bridge,self.bot_id,self.bot_username,self.clock=api,bridge,bot_id,bot_username,clock
        self.allowed_subjects=None if allowed_subjects is None else frozenset(allowed_subjects)
        self.checked=False
        self.stopped=None
        self.next_attempt=0

    def request(self,operation,payload):
        envelope=self.bridge.request(operation,payload)
        if (not isinstance(envelope,dict) or set(envelope)!={'ok','result'}
                or envelope['ok'] is not True or not isinstance(envelope['result'],dict)):
            raise ValueError('invalid_bridge_response')
        return envelope['result']

    def step(self):
        now=int(self.clock())
        if self.stopped:return {'status':'stopped','reason':self.stopped}
        if now<self.next_attempt:return {'status':'backoff','retry_after':self.next_attempt-now}
        if not self.checked:
            try:
                me=self.api.get_me()
                if (type(me.get('id')) is not int or str(me['id'])!=self.bot_id
                        or me.get('is_bot') is not True or me.get('username','').lower()!=self.bot_username.lower()):
                    self.stopped='bot_identity_mismatch'
                    return {'status':'stopped','reason':self.stopped}
                self.checked=True
            except TelegramError as error:
                if error.reason=='unauthorized':
                    self.stopped='unauthorized'
                    return {'status':'stopped','reason':self.stopped}
                if error.reason=='rate_limited':
                    self.next_attempt=now+error.retry_after
                    return {'status':'backoff','retry_after':error.retry_after}
                return {'status':'unavailable'}
            except Exception:return {'status':'unavailable'}
        with ExitStack() as resources:
            return self._deliver(now, resources)

    def _deliver(self, now, resources):
        broadcast = None
        try:
            options = {'limit': '1'}
            if self.notification_contract == 3:
                options['broadcast'] = '1'
            lease = self.request('notification.lease', options)
            if lease=={'status':'empty'}:return {'status':'waiting','retry_after':5}
            fields = {'delivery', 'lease', 'expires'}
            if self.notification_contract == 3 and 'broadcast' in lease:
                fields.add('broadcast')
            if (set(lease) != fields
                    or type(lease['delivery']) is not int or not 0<lease['delivery']<2**63
                    or type(lease['expires']) is not int or not now<lease['expires']<=now+90):
                return {'status':'unavailable'}
            _token(lease['lease'],'ud1')
            payload={'delivery':str(lease['delivery']),'lease':lease['lease']}
            if 'broadcast' in lease:
                details = lease['broadcast']
                subject = details.get('subject') if isinstance(details, dict) else None
                if not private_subject(subject) or (self.allowed_subjects is not None and subject not in self.allowed_subjects):
                    return {'status': 'unavailable'}
                broadcast = notification_broadcast.stage(details, self.bridge, payload, resources,
                                                         self.clock, lease['expires'])
            operation = {1: 'notification.begin', 2: 'notification.begin_v2', 3: 'notification.begin_v3'}[self.notification_contract]
            begin_requested = int(self.clock())
            begin = {**payload, 'stamp': broadcast[0]} if broadcast is not None else payload
            permit=self.request(operation,begin)
        except Exception:return {'status':'unavailable'}
        if permit in ({'status':'cancelled'},{'status':'retry_wait'},{'status':'failed'}):return permit
        # A lost or malformed begin must not authorize a send. Server retires sending as uncertain.
        fields = {'status','subject','category','severity','occurred_at','send_before'}
        if self.notification_contract in (2, 3): fields |= {'version', 'ack'}
        if self.notification_contract == 3: fields.add('event')
        if broadcast is not None: fields.add('stamp')
        if (not isinstance(permit,dict) or set(permit)!=fields
                or permit['status']!='sending' or not private_subject(permit['subject'])
                or not isinstance(permit['category'], str)
                or permit['category'] not in CATEGORIES or not isinstance(permit['severity'], str)
                or permit['severity'] not in SEVERITIES or type(permit['occurred_at']) is not int
                or not 0<permit['occurred_at']<=begin_requested+30 or type(permit['send_before']) is not int
                or not int(self.clock())<permit['send_before']<=begin_requested+10):
            return {'status':'unavailable'}
        if self.notification_contract in (2, 3):
            if type(permit['version']) is not int or permit['version'] != self.notification_contract:
                return {'status':'unavailable'}
            ack = permit['ack']
            if ack is not None:
                if (not isinstance(ack, dict) or set(ack) != {'action','expires'}
                        or type(ack['expires']) is not int
                        or not int(self.clock()) < ack['expires'] <= permit['occurred_at']+86400):
                    return {'status':'unavailable'}
                try: _token(ack['action'], 'ua1')
                except ValueError: return {'status':'unavailable'}
        if self.notification_contract == 3:
            if broadcast is None:
                try: event_label(permit['category'], permit['event'])
                except ValueError: return {'status':'unavailable'}
            elif (permit['event'] != notification_broadcast.EVENT or permit['category'] != 'feed'
                  or permit['severity'] != 'normal' or permit['stamp'] != broadcast[0]
                  or permit['subject'] != subject or (len(broadcast[2]) > 1 and permit['ack'] is not None)):
                return {'status': 'unavailable'}
        outcome,ref,reason,retry='uncertain','','invalid_response',0
        self.next_attempt=now+1
        if self.allowed_subjects is not None and permit['subject'] not in self.allowed_subjects:
            try:
                result=self.request('notification.finish',{**payload,'outcome':'failed','provider_ref':'',
                    'reason':'forbidden','retry_after':'0'})
                return {'status':'failed'} if result=={'status':'failed'} else {'status':'receipt_unconfirmed'}
            except Exception:return {'status':'receipt_unconfirmed'}
        try:
            kwargs = {'ack': permit['ack']} if self.notification_contract in (2, 3) else {}
            if self.notification_contract == 3: kwargs['event'] = permit['event']
            if broadcast is None:
                response=self.api.send_notification(int(permit['subject']),permit['category'],permit['severity'],
                                                    permit['occurred_at'],**kwargs)
                count = 1
            else:
                _, text, files = broadcast
                response = self.api.send_broadcast(int(permit['subject']), text, files, ack=permit['ack'])
                count = max(1, len(files))
            reference = provider_reference(response, permit['subject'], count)
            if reference is not None:
                outcome, ref, reason = 'provider_accepted', reference, ''
        except TelegramError as error:
            reason=error.reason
            if error.uncertain:outcome='uncertain'
            elif reason in ('rate_limited','unauthorized'):
                outcome,retry='retry_wait',error.retry_after if reason=='rate_limited' else 300
                self.next_attempt=now+retry
                if reason=='unauthorized':self.stopped=reason
            elif reason in ('forbidden','api_rejected'):outcome='failed'
            else:reason='invalid_response'
        except Exception:
            # A send may have happened even if an adapter raises unexpectedly.
            reason='interrupted'
        try:
            receipt=self.request('notification.finish',{**payload,'outcome':outcome,'provider_ref':ref,
                'reason':reason,'retry_after':str(retry)})
            if not isinstance(receipt,dict) or receipt.get('status') not in ('provider_accepted','retry_wait','uncertain','failed'):
                raise ValueError()
        except Exception:return {'status':'receipt_unconfirmed'}
        if self.stopped:return {'status':'stopped','reason':self.stopped}
        return {'status':receipt['status'],**({'retry_after':retry} if receipt['status']=='retry_wait' else {})}
