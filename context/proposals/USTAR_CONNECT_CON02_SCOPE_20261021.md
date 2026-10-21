# CON-02: исходное состояние и последовательность работ

**Актуализация после A.1:** owner approved; native «Принял» реализован и проверен
в CI, candidate2100 не установлен. [Результат и границы](../runtime/connect_con02_ack_20261021.md),
[точная квитанция](../runtime/connect_con02_ack_ci_20261021.json). Следующий срез
A.2 — отдельный Telegram callback план. Source inventory ниже фиксирует состояние
на начало этапа, а не отсутствие уже реализованного A.1.

21.10.2026, Europe/Moscow. Владелец поручил приступить к CON-02 после пояснения
общей доставки, настроек, подтверждений и Bitrix. Это начало нового этапа,
не заявление о готовности всех его функций. Основа — [RFC, разделы 6 и 10](USTAR_CONNECT_RFC_20261018.md)
и [контракты](USTAR_CONNECT_CONTRACTS_20261019.md).

На момент начала: PR #106, branch `design/ustar-connect-20261018`, head
`b7d3e93a4d487833ab87b39ebb13bf03c6f18ff4`. Context39622043988 и
Review39622044309 SUCCESS. Эти CI относятся к предыдущему head, не к CON-02.
На сервере остаётся принятый a86 pilot; нового server1 probe не было.

## Решения владельца и границы

- Пилот одного сотрудника Tasks принят целиком, его приёмку не повторять.
- Ручная live-приёмка группы отложена на полный запуск, статус deferred, не passed.
- Проект группы 1–10 подготовлен, но controller/обновление группы ещё не реализованы.
  Поручение начать CON-02 меняет текущий приоритет; разработка группы не считается завершённой.
- Работа самостоятельно в текущем feature checkout; субагенты запрещены.
- Нет доступа агента к server1; нет разрешения отправлять служебные/тестовые сообщения
  людям из инструментов агента. Код и fixtures не требуют реальных credentials.
- Академия, личные связи, очереди и принятый пилот сохраняются. Текущая сеть,
  ustar.local, CA, VPN/proxy, порты, Moodle/PG major versions не меняются.

## Проверено по текущим исходникам

| Возможность | Факт | Что остаётся |
|---|---|---|
| Каноническая история | `workflow_notifications.php`, `target_core.php`: inbox и per-channel delivery в одной transaction, idempotency key | Использовать эти записи, не создавать второй inbox |
| Telegram transport | `connect/notification_delivery.php`, `notification_worker.py`: lease/begin/finish, максимум 5 попыток, durable429, uncertain/cancelled | Сделать общие интерфейсы каналов без ослабления fencing |
| Личные настройки | `connect/notification_preferences.php`: opt-in, четыре категории, severity, quiet hours/TZ, interval | Основной/резервный канал и digest пока отсутствуют |
| Подтверждение | `target_core::acknowledge_notification`: `acknowledged` и `ackat` уже существуют | Нужен защищённый пользовательский flow и стабильный конкурентный receipt |
| Чтение | `communication::mark_notification`: отдельный `read`; bulk-read не означает ACK | Не смешивать прочитано/подтверждено, исключить перезапись ACK чтением |
| Provider receipt | `notification.finish` сохраняет `provider_accepted`; тест явно проверяет, что notice остаётся unread | Не выдавать отправку провайдеру за прочтение или действие человека |
| Telegram receiver | Один polling consumer; `allowed_updates=['message']`, callback_query не обрабатывается | Отдельный ограниченный callback flow, не второй receiver |
| Bitrix | `identity` и key scope знают provider, но delivery/worker фактически Telegram-only | Нужен адаптер фактического portal API и собственный cursor contract |
| Legacy Bitrix | `target_core` создаёт `disabled` для новых action/critical; существуют исторические pending, внешний handler source не получен | Никакой массовой активации/пересылки старой истории |
| Схема | `local_ustar_notifications.status/ackat` и notify_delivery уже есть; 99 таблиц | Первый native ACK не требует новой таблицы/колонки |

Source review выявил риск гонки: одиночное read обновляет ранее прочитанный целый
record, тогда как ACK меняет тот же status/ackat. Требуется воспроизведение тестом;
это не утверждение, что гонка наблюдалась на server1.

## Независимые последовательные срезы

### CON-02A.1 — каноническое личное подтверждение

Первый самостоятельный результат: в существующем native inbox сотрудник нажимает
«Принял», видит подтверждение и время; повтор не меняет первое время. Это собственный
receipt, не завершение/оценка/отчёт по задаче. Core Moodle `timeread` не превращается
в подтверждение USTAR. GET, read и mark-all не создают ACK. Требуются session,
sesskey, реальный активный actor и отказ view-as/чужому получателю.

[Конкретный план](USTAR_CONNECT_CON02_ACK_PLAN_20261021.md). Этот срез не зависит
от portal URL и не добавляет новой схемы, bridge scope или sender credentials.

### CON-02A.2 — подтверждение из Telegram

Потребуется отдельный план ограниченного callback-контракта: opaque action,
проверенный private sender, provider/tenant/current link revision, TTL и повтор
после потери ответа. Нельзя передать произвольный Moodle userid, использовать
send lease в качестве публичного callback token или превратить actor_read в
общий write. Один существующий receiver, прежние link/cursor/dedup сохраняются.

Старый worker строго проверяет ключи permit; добавление ACK-полей в старый
`notification.begin` сломает совместимость. Новая возможность требует явного
versioned/opt-in контракта, default OFF, отдельного scoped credential и проверенного
перехода. Provider acceptance и uncertain остаются транспортными фактами даже
после человеческого ACK. Конкретные token/schema/activation решения до этого
плана не утверждены.

### CON-02B — общие preferences и выбор канала

Отдельный результат: Academy inbox всегда канонический; сотрудник выбирает один
основной канал и разрешённый fallback, без автоматического fan-out. Сохраняются
нынешние настройки и личное согласие. Миграция существующего v1 opt-in должна быть
явной и обратимо проверяемой. Fallback после uncertain не объявляется безопасным
автоматическим повтором: это может создать второе видимое сообщение. Digest —
отдельная согласованная политика с canonical recipient/event IDs, не фиктивная
группировка текста на стороне worker. Эти решения оформляются отдельным планом
после A, с тестами двух synthetic providers, независимо от реального Bitrix.

### CON-02C — Bitrix adapter

Отдельный результат: один business bot в фактическом портале, связь с Academy
actor, общая история и личные настройки. Для исходящего fetch публичный webhook
не требуется, но доступность нужных методов/редакции/прав на нашем портале пока
не измерена. Нужны адрес, cloud/box, версия REST, app/bot ownership и scopes,
проверка отсутствия второго consumer/непрочитанной legacy очереди. Секреты
предоставляются только приватному configure на сервере, не в чат/репозиторий.

`imbot.v2.Event.get` подтверждает события через offset; использовать возвращённый
`nextOffset` только после durable обработки всей страницы. Старый lastEventId
не угадывается, Telegram-правила сброса offset после пустого poll не переносятся.
Частично обработанная страница не подтверждается целиком. Нужны отдельные
fixtures queue replay, private sender, dialog/bot/tenant, command origin,
rate-limit/auth/timeout и receiver restart. До native portal acceptance — OFF.

## Источники протоколов, проверены 21.10.2026

- [Bitrix Event.get](https://apidocs.bitrix24.com/api-reference/chat-bots/chat-bots-v2/imbot.v2/events/event-get.html): fetch, offset/nextOffset, один consumer, imbot scope.
- [Bitrix changelog](https://apidocs.bitrix24.com/api-reference/chat-bots/chat-bots-v2/change-log.html): переход lastEventId→nextOffset и прекращение старого формата25.09.2026.
- [Bitrix register](https://apidocs.bitrix24.com/api-reference/chat-bots/chat-bots-v2/imbot.v2/bots/bot-register.html), [send](https://apidocs.bitrix24.com/api-reference/chat-bots/chat-bots-v2/imbot.v2/messages/chat-message-send.html), [keyboards](https://apidocs.bitrix24.com/api-reference/chat-bots/chat-bots-v2/imbot.v2/messages/message-keyboards.html).
- [Telegram Bot API](https://core.telegram.org/bots/api#callbackquery): callback_query и отдельный answerCallbackQuery; ответ интерфейсу не canonical ACK.

Документация API не является измерением возможностей нашего портала. Все планы
CON-02 опираются на текущие source anchors, а не на dated19Oct строки PROPOSED
как на факт действующего public API. MiniApp/SSO/role commands/CEO/WhatsApp остаются
CON-03..05; ручные live tests не блокируют разработку, но не помечаются пройденными.
