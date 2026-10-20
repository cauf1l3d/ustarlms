"""Narrow Bot API transport. Credentials stay private, upstream errors stay fixed."""
import json
import re
import ssl
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, build_opener, HTTPSHandler, ProxyHandler

from .bridge import _NoRedirect, _token

REASONS = {'transport_unavailable', 'invalid_response', 'unauthorized', 'consumer_conflict',
           'rate_limited', 'forbidden', 'api_rejected'}
NOTICE = ('Это бот Академии USTAR. Для персонального входа отправьте /start. '
          'Если запуск ещё готовится, попробуйте позже. Пароль в чат не отправляйте. '
          'Существующую учётную запись не нужно регистрировать повторно.')


class TelegramError(Exception):
    def __init__(self, reason, *, retry_after=0, uncertain=False):
        if reason not in REASONS or type(retry_after) is not int or not 0 <= retry_after <= 86400:
            raise ValueError('invalid_transport_error')
        super().__init__(reason)
        self.reason, self.retry_after, self.uncertain = reason, retry_after, bool(uncertain)


def _unique(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError('duplicate_field')
        value[key] = item
    return value


def _error(code, parameters, sending):
    reason = {401: 'unauthorized', 409: 'consumer_conflict', 429: 'rate_limited',
              403: 'forbidden', 400: 'api_rejected'}.get(code, 'invalid_response')
    retry = 0
    if code == 429:
        retry = parameters.get('retry_after') if isinstance(parameters, dict) else None
        if type(retry) is not int or not 1 <= retry <= 86400:
            retry = 86400  # Unknown flood limit must not become a hot retry.
    return TelegramError(reason, retry_after=retry, uncertain=sending and (code >= 500 or reason == 'invalid_response'))


class TelegramClient:
    def __init__(self, token, *, proxy=None):
        if not isinstance(token, str) or not re.fullmatch(r'[1-9][0-9]{0,19}:[A-Za-z0-9_-]{20,256}', token):
            raise ValueError('invalid_bot_configuration')
        proxies = {}
        if proxy is not None:
            parsed = urlsplit(proxy)
            if (parsed.scheme != 'http' or parsed.hostname not in ('127.0.0.1', '::1')
                    or parsed.username or parsed.password or parsed.path or parsed.query or parsed.fragment
                    or parsed.port is None or not 1 <= parsed.port <= 65535):
                raise ValueError('invalid_proxy_configuration')
            proxies = {'https': proxy}
        self._token = token
        self._opener = build_opener(ProxyHandler(proxies), HTTPSHandler(context=ssl.create_default_context()), _NoRedirect())

    def _call(self, method, payload):
        if method not in ('getMe', 'getWebhookInfo', 'getUpdates', 'sendMessage'):
            raise ValueError('invalid_api_method')
        sending = method == 'sendMessage'
        body = json.dumps(payload, separators=(',', ':'), allow_nan=False).encode()
        if len(body) > 16384:
            raise ValueError('api_payload_too_large')
        request = Request('https://api.telegram.org/bot'+self._token+'/'+method, data=body,
                          headers={'Content-Type': 'application/json'}, method='POST')
        try:
            with self._opener.open(request, timeout=35 if method == 'getUpdates' else 10) as response:
                raw = response.read(2097153)
        except HTTPError as error:
            code, parameters = error.code, None
            try:
                raw = error.read(16385)
                value = json.loads(raw) if len(raw) <= 16384 else None
                parameters = value.get('parameters') if isinstance(value, dict) else None
            except (OSError, ValueError, RecursionError):
                pass
            finally:
                error.close()
            raise _error(code, parameters, sending) from None
        except (URLError, OSError):
            raise TelegramError('transport_unavailable', uncertain=sending) from None
        try:
            if len(raw) > 2097152:
                raise ValueError('response_too_large')
            value = json.loads(raw, object_pairs_hook=_unique,
                               parse_constant=lambda _: (_ for _ in ()).throw(ValueError()))
            if not isinstance(value, dict) or type(value.get('ok')) is not bool:
                raise ValueError('invalid_response')
            if not value['ok']:
                code = value.get('error_code')
                if type(code) is not int or not 100 <= code <= 599:
                    raise ValueError('invalid_response')
                raise _error(code, value.get('parameters'), sending)
            result = value.get('result')
            if not isinstance(result, list if method == 'getUpdates' else dict):
                raise ValueError('invalid_result')
            return result
        except (ValueError, RecursionError):
            raise TelegramError('invalid_response', uncertain=sending) from None

    def get_me(self):
        return self._call('getMe', {})

    def get_webhook_info(self):
        return self._call('getWebhookInfo', {})

    def get_updates(self, offset):
        if type(offset) is not int or not 0 <= offset < 2**63:
            raise ValueError('invalid_offset')
        return self._call('getUpdates', {'offset': offset, 'limit': 50, 'timeout': 25, 'allowed_updates': ['message']})

    def _send(self, subject, payload):
        if type(subject) is not int or not 0 < subject < 2**52:
            raise ValueError('invalid_subject')
        return self._call('sendMessage', {'chat_id': subject, 'protect_content': True, **payload})

    def send_launch(self, subject, url):
        if not isinstance(url, str) or not re.fullmatch(r'https://t\.me/[A-Za-z0-9_]{5,32}\?startapp=ul1_[A-Za-z0-9_-]{43}', url):
            raise ValueError('invalid_launch_url')
        _token(url.split('startapp=', 1)[1], 'ul1')
        return self._send(subject, {'text': 'Откройте Академию по персональной кнопке. '
                                   'Она действует 10 минут и только для вашего Telegram-аккаунта. '
                                   'Пароль вводите в защищённой форме.',
            'reply_markup': {'inline_keyboard': [[{'text': 'Открыть Академию', 'url': url}]]},
            'link_preview_options': {'is_disabled': True}})

    def send_notice(self, subject, *, ready=True):
        text = NOTICE if ready else ('Бот Академии USTAR подключён. Mini App пока не подключён: '
            'для него готовится защищённый HTTPS-доступ. Регистрация, персональный профиль '
            'и учебные действия здесь пока недоступны. Пользуйтесь привычным входом в Академию. '
            'Пароль и другие секреты в чат не отправляйте.')
        return self._send(subject, {'text': text})

    def send_link_notice(self, subject, outcome):
        texts = {
            'claimed': ('Telegram ID: ' + str(subject) + '\n\nПодтверждение отправлено в Академию. '
                'Привязка ещё не завершена. Вернитесь в тот же браузер Академии, '
                'нажмите «Проверить подтверждение Telegram», сверьте этот ID и подтвердите связь. '
                'Доступ определяют текущая учётная запись и права Академии.'),
            'instructions': ('Академия USTAR · Подключение Telegram\n\n'
                'Откройте «Личный кабинет → Мессенджеры» в Академии через доверенный HTTPS. '
                'Создайте персональную ссылку, откройте её здесь и затем подтвердите связь '
                'в том же браузере Академии. Mini App для привязки через чат не требуется. '
                'Пароль и персональную ссылку другим людям не отправляйте.'),
            'rejected': ('Ссылка не принята: она могла истечь или уже использоваться. '
                'Проверьте состояние в том же браузере Академии. Если подтверждения нет, '
                'создайте там новую ссылку. Связь автоматически не переносится.'),
            'uncertain': ('Результат отправки подтверждения неизвестен. Автоматического повтора не будет. '
                'Вернитесь в тот же браузер Академии и нажмите «Проверить подтверждение Telegram». '
                'Если оно не получено, создайте новую персональную ссылку. Привязка ещё не завершена.'),
        }
        if not isinstance(outcome, str) or outcome not in texts:
            raise ValueError('invalid_link_outcome')
        return self._send(subject, {'text': texts[outcome], 'link_preview_options': {'is_disabled': True}})

    def send_notification(self, subject, category, severity, occurred_at):
        from datetime import datetime, timezone
        titles={'registration':'Регистрация','tasks':'Задачи','feed':'Лента','grades':'Грейды'}
        labels={'normal':'Новое событие','action':'Требуется действие','critical':'Важное событие'}
        if category not in titles or severity not in labels or type(occurred_at) is not int or not 0<occurred_at<2**32:
            raise ValueError('invalid_notification')
        date=datetime.fromtimestamp(occurred_at,timezone.utc).strftime('%d.%m.%Y %H:%M UTC')
        return self._send(subject,{'text':f'Академия USTAR · {titles[category]}\n{labels[severity]}\n{date}\n\n'
            'Подробности доступны в Академии с учётом ваших текущих прав. Для персонального входа отправьте /start.',
            'link_preview_options':{'is_disabled':True}})
