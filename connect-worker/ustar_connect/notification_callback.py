"""One private human receipt. Moodle owns authorization and the first ACK timestamp."""
from dataclasses import dataclass
import re
import time

from .bridge import _token, BridgeRejected
from .identity import ProviderIdentity
from .telegram_api import TelegramError

ORIGINS = {'forward_origin', 'forward_from', 'forward_from_chat', 'sender_chat', 'via_bot',
           'business_connection_id', 'guest_query_id', 'guest_bot_caller_user', 'guest_bot_caller_chat',
           'message_thread_id', 'is_topic_message'}
CATEGORIES = {'registration', 'tasks', 'feed', 'grades'}


@dataclass(frozen=True, repr=False)
class AckQuery:
    update_id: int
    query_id: str
    subject: int
    message_id: int
    action: str


def parse_callback(update, *, bot_id, now):
    if (not isinstance(update, dict) or set(update) != {'update_id', 'callback_query'}
            or type(update['update_id']) is not int or not 0 <= update['update_id'] < 2**63-1):
        return None
    query = update['callback_query']
    if (not isinstance(query, dict) or ORIGINS.intersection(query)
            or 'inline_message_id' in query or 'game_short_name' in query
            or not isinstance(query.get('id'), str) or not re.fullmatch(r'[!-~]{1,256}', query['id'])):
        return None
    sender, message = query.get('from'), query.get('message')
    if (not isinstance(sender, dict) or type(sender.get('id')) is not int
            or not 0 < sender['id'] < 2**52 or sender.get('is_bot') is not False
            or not isinstance(message, dict) or ORIGINS.intersection(message)):
        return None
    chat, origin = message.get('chat'), message.get('from')
    if (not isinstance(chat, dict) or chat.get('type') != 'private'
            or type(chat.get('id')) is not int or chat['id'] != sender['id']
            or not isinstance(origin, dict) or type(origin.get('id')) is not int
            or str(origin['id']) != bot_id or origin.get('is_bot') is not True
            or type(message.get('message_id')) is not int or not 0 < message['message_id'] < 2**63
            or type(message.get('date')) is not int or not 0 < message['date'] <= now+30):
        return None
    try:
        action = _token(query.get('data'), 'ua1')
    except ValueError:
        return None
    # Original message.date is NOT a click timestamp. Moodle checks token expiry.
    return AckQuery(update['update_id'], query['id'], sender['id'], message['message_id'], action)


class AcknowledgementHandler:
    def __init__(self, api, bridge, journal, *, tenant, clock=time.time):
        self.api, self.bridge, self.journal = api, bridge, journal
        self.tenant, self.clock = tenant, clock

    def handle(self, query):
        identity = ProviderIdentity('telegram', self.tenant, str(query.subject)).key()
        if not self.journal.begin(query.update_id, identity, 'ack'):
            return
        if not self.journal.allowed(identity, 'ack'):
            self.journal.finish(query.update_id, 'ignored', 'identity_rate_limit')
            return
        self.journal.phase(query.update_id, 'creating')
        state, outcome, reason, acknowledged_at, category = 'uncertain', 'uncertain', 'bridge_unavailable', 0, None
        try:
            value = self.bridge.request('notification.ack', {'action': query.action,
                'message_id': str(query.message_id), 'provider': 'telegram',
                'subject': str(query.subject), 'tenant': self.tenant}, timeout=5)
            result = value.get('result') if isinstance(value, dict) else None
            if (not isinstance(value, dict) or set(value) != {'ok', 'result'} or value['ok'] is not True
                    or not isinstance(result, dict) or set(result) != {'status', 'acknowledged_at', 'category'}
                    or result['status'] != 'acknowledged' or type(result['acknowledged_at']) is not int
                    or not 0 < result['acknowledged_at'] <= int(self.clock())+30
                    or not isinstance(result['category'], str) or result['category'] not in CATEGORIES):
                raise ValueError('invalid_acknowledgement_response')
            acknowledged_at, category = result['acknowledged_at'], result['category']
            state, outcome, reason = 'sent', 'acknowledged', ''
        except BridgeRejected as error:
            if error.reason in ('ack_disabled', 'ack_unavailable'):
                state, outcome, reason = 'rejected', 'unavailable', error.reason
        except (ValueError, OSError):
            pass
        self.journal.phase(query.update_id, 'sending')
        try:
            if self.api.answer_notification_ack(query.query_id, outcome, acknowledged_at) is not True:
                raise TelegramError('invalid_response')
        except (TelegramError, ValueError, OSError) as error:
            # UI uncertainty never rolls back a canonical receipt or permits automatic replay.
            self.journal.finish(query.update_id, 'uncertain', 'ui_answer_unconfirmed', query.message_id)
            self._backoff(error)
            return
        if outcome == 'acknowledged':
            try:
                self.api.edit_notification_ack(query.subject, query.message_id, category,
                                               acknowledged_at=acknowledged_at)
            except (TelegramError, ValueError, OSError) as error:
                self.journal.finish(query.update_id, 'sent', 'ui_edit_unconfirmed', query.message_id)
                self._backoff(error)
                return
        self.journal.finish(query.update_id, state, reason, query.message_id)

    @staticmethod
    def _backoff(error):
        if isinstance(error, TelegramError) and (error.retry_after or error.reason in ('unauthorized', 'consumer_conflict')):
            # The existing receiver persists backoff/stops after this event is durable.
            raise error
