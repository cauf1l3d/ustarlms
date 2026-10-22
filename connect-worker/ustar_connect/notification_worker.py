"""One leased canonical notification per step. Moodle owns all delivery state."""
import re
import time

from .bridge import _token
from .telegram_api import TelegramError

CATEGORIES = {'registration','tasks','feed','grades'}
SEVERITIES = {'normal','action','critical'}


class NotificationWorker:
    def __init__(self, api, bridge, *, bot_id, bot_username, clock=time.time, allowed_subjects=None,
                 notification_contract=1):
        if type(notification_contract) is not int or notification_contract not in (1, 2):
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
        try:
            lease=self.request('notification.lease',{'limit':'1'})
            if lease=={'status':'empty'}:return {'status':'waiting','retry_after':5}
            if (not isinstance(lease,dict) or set(lease)!={'delivery','lease','expires'}
                    or type(lease['delivery']) is not int or not 0<lease['delivery']<2**63
                    or type(lease['expires']) is not int or not now<lease['expires']<=now+90):
                return {'status':'unavailable'}
            _token(lease['lease'],'ud1')
            payload={'delivery':str(lease['delivery']),'lease':lease['lease']}
            operation = 'notification.begin_v2' if self.notification_contract == 2 else 'notification.begin'
            permit=self.request(operation,payload)
        except Exception:return {'status':'unavailable'}
        if permit in ({'status':'cancelled'},{'status':'retry_wait'}):return permit
        # A lost or malformed begin must not authorize a send. Server retires sending as uncertain.
        fields = {'status','subject','category','severity','occurred_at','send_before'}
        if self.notification_contract == 2: fields |= {'version', 'ack'}
        if (not isinstance(permit,dict) or set(permit)!=fields
                or permit['status']!='sending' or not isinstance(permit['subject'],str)
                or not re.fullmatch(r'[1-9][0-9]{0,15}',permit['subject'])
                or not 0<int(permit['subject'])<2**52 or permit['category'] not in CATEGORIES
                or permit['severity'] not in SEVERITIES or type(permit['occurred_at']) is not int
                or not 0<permit['occurred_at']<=now+30 or type(permit['send_before']) is not int
                or not int(self.clock())<permit['send_before']<=now+10):
            return {'status':'unavailable'}
        if self.notification_contract == 2:
            if type(permit['version']) is not int or permit['version'] != 2:
                return {'status':'unavailable'}
            ack = permit['ack']
            if ack is not None:
                if (not isinstance(ack, dict) or set(ack) != {'action','expires'}
                        or type(ack['expires']) is not int
                        or not int(self.clock()) < ack['expires'] <= permit['occurred_at']+86400):
                    return {'status':'unavailable'}
                try: _token(ack['action'], 'ua1')
                except ValueError: return {'status':'unavailable'}
        outcome,ref,reason,retry='uncertain','','invalid_response',0
        self.next_attempt=now+1
        if self.allowed_subjects is not None and permit['subject'] not in self.allowed_subjects:
            try:
                result=self.request('notification.finish',{**payload,'outcome':'failed','provider_ref':'',
                    'reason':'forbidden','retry_after':'0'})
                return {'status':'failed'} if result=={'status':'failed'} else {'status':'receipt_unconfirmed'}
            except Exception:return {'status':'receipt_unconfirmed'}
        try:
            kwargs = {'ack': permit['ack']} if self.notification_contract == 2 else {}
            response=self.api.send_notification(int(permit['subject']),permit['category'],permit['severity'],permit['occurred_at'],**kwargs)
            chat=response.get('chat',{}) if isinstance(response,dict) else {}
            if (type(response.get('message_id')) is int and 0<response['message_id']<2**63
                    and isinstance(chat,dict) and chat.get('type')=='private'
                    and type(chat.get('id')) is int and str(chat['id'])==permit['subject']):
                outcome,ref,reason='provider_accepted',str(response['message_id']),''
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
