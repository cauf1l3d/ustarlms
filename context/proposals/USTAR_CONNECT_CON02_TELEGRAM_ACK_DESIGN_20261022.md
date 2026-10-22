# CON-02A.2: личное «Принял» в Telegram

22.10.2026, Europe/Moscow. Статус: **owner_approved_execution_in_progress**.
Владелец подтвердил в диалоге пятишаговый вариант A.2: кнопка в новом личном
уведомлении, проверка Академией, сохранение первого ACK, безопасный повтор и
отдельный opt-in контракт с одним receiver. Этот документ конкретизирует вариант;
Письменный проект принят22Oct ответом «переходи к реализации».
[Конкретный план реализации](USTAR_CONNECT_CON02_TELEGRAM_ACK_PLAN_20261022.md)
принят ответом «начинай выполнение»; выполнение идёт по плану,
[точный progress](../runtime/connect_con02_callback_progress_20261022.md). Сервер не изменён. Работа самостоятельно, без субагентов.

## 1. Результат для сотрудника и границы

Под новым личным уведомлением доступны две кнопки: «Принял» и «Открыть в Академии».
Нажатие первой сохраняет собственное подтверждение уведомления в том же inbox,
что и native A.1. После подтверждённого ответа Академии Telegram показывает
«Подтверждено · <первое время, UTC>», кнопка ACK убирается, ссылка остаётся.
Повторное нажатие в гонке возвращает прежнее время. Если связь отозвана,
учётная запись недоступна или кнопка истекла, подтверждения нет; сотрудник может
открыть Академию и пройти обычный вход. Ошибка доставки ответа в Telegram
не отменяет уже сохранённое подтверждение.

ACK не выполняет, не принимает на проверку и не оценивает задачу. Прочтение,
ACK, provider receipt и бизнес-действия остаются отдельными фактами. A.2 не
добавляет provider login, Mini App, роль HRD, Bitrix, групповые чаты, digest,
выбор резервного канала или расширение списка сотрудников. Полный rollout
1–10 и ручные live-тесты остаются отдельными: последние deferred до полного
запуска, не passed. Принятый one-user Tasks pilot не повторяется.

## 2. Точные исходные точки

| Область | Состояние до A.2 |
|---|---|
| Repository head | `332bfc8d64a7103bd87fa2a015745024bf85d4a3`, draft PR #106 |
| A.1 source и CI | `4fb4cd17dc4fa9a73c659950cfeaa64aa11d4551`; [результат](../runtime/connect_con02_ack_20261021.md), [квитанция](../runtime/connect_con02_ack_ci_20261021.json) |
| Development candidate | local_ustar2026102100 / theme2026100202, 554 files / 99 tables; не установлен |
| Server1 | application2026102000, 552 files / 99 tables, принятый overlay `classes/connect/notification_delivery.php`; worker/tool `a86e208029b6104150e85d0e90384f84da214c22` |
| Последний получатель | UID130 revoked, preferences включены; нового relink не заявлено |
| Transport | один receiver и один sender; private TLS bridge, текущий proxy и bot token сохраняются |

В текущем `notification_worker.py` permit проверяется по точному набору полей;
`notification.begin` нельзя расширить без нарушения frozen a86. Receiver принимает
только `message`; его проверка `message.date` в пределах120с относится к командам.
Дата сообщения с кнопкой не является датой нажатия callback. Native
`notification_acknowledgement::own()` зависит от реального `$USER` и не вызывается
под искусственной сессией бота. Общий race-safe writer —
`target_core::acknowledge_notification`.

## 3. Выбранный подход

Выбран inline callback через существующий receiver: он даёт подтверждение прямо
в Telegram и требует отдельного узкого write scope. Альтернатива — только native
URL с входом — уже работает и сохраняется как fallback, но не даёт ACK в Telegram.
Текстовая команда с кодом потребовала бы той же проверки владельца и токена при
большем числе действий сотрудника, поэтому не вводится.

Разделение ответственности:

- Moodle выдаёт ограниченный action token и единолично проверяет право на ACK,
  хранит canonical receipt и первое время.
- Sender получает новый permit v2 и передаёт непрозрачный token в callback_data.
  Он не выбирает userid и не получает ACK credential.
- Тот же receiver проверяет Telegram envelope и отправляет один подписанный ACK
  запрос с отдельным credential. Его launch/link credentials права ACK не получают.
- Telegram API transport отвечает интерфейсу и убирает кнопку после успеха;
  эти операции не являются записью ACK.

## 4. Версионирование без изменения v1

HMAC envelope `USTAR-CONNECT-BRIDGE-v1`, private endpoint, ±30с и durable nonce
сохраняются. Версия envelope и версия notification permit — разные вещи.
Добавляются две операции; все payload имеют точный набор полей и прежние правила
canonical JSON. Старые `notification.lease/begin/finish` и их ответы не меняются.

| Операция | Payload | Scope и ответ |
|---|---|---|
| `notification.begin_v2` | `delivery`, `lease` — прежние строки | Новый sender key: только `notification.lease`, `notification.begin_v2`, `notification.finish`. Ответ sending содержит прежние поля плюс `version: 2`, `ack`; cancelled/retry_wait сохраняют прежние краткие ответы |
| `notification.ack` | `action`, `message_id`, `provider`, `subject`, `tenant` — строки | Отдельный receiver key: только `notification.ack`, provider telegram, текущий tenant. Успех: `{status: acknowledged, acknowledged_at: <int>, category: <allowlisted string>}` |

`ack` в permit v2 — либо `null`, либо ровно `{action: <ua1 token>, expires: <int>}`.
`action` не является delivery id, lease, link code или Moodle session.
`message_id` — положительное десятичное число из проверенного callback message.
`subject` выводится receiver из Telegram `from.id`, а не из текста пользователя.
Payload ACK не принимает userid, notificationid, статус задачи, URL или имя PHP
метода. Для известного/просроченного/чужого token возвращается единый фиксированный
отказ `ack_unavailable`, без раскрытия владельца и существования уведомления.
Отключённый A.2 возвращает `ack_disabled` до изменения delivery/token/ACK;
эти два кода добавляются в узкие facade/client allowlists.

Новый begin использует существующие delivery строки, lease, попытки и finish;
`connectversion=1` остаётся версией хранилища доставки, а не permit. Не создаются
вторая очередь или второй delivery на событие. Shared quiet/cooldown и busy
проверки охватывают тот же набор строк; пять попыток, durable429 и uncertain
сохраняются. Общая внутренняя транзакция begin возвращает v1 либо v2 согласно
явно вызванной операции, но не меняет форму старого ответа.

## 5. Ограниченный action token и схема

Предлагается одна таблица XMLDB `local_ustar_conn_acktokens`:

| Поля | Назначение |
|---|---|
| `id`, `tokenhash` | Первичный ключ и уникальный SHA256 с domain separator `ustar-connect-notification-ack-v1\0` |
| `notificationid`, `deliveryid`, `attempt` | Только соответствующее local уведомление и текущая попытка отправки; unique(deliveryid,attempt) |
| `userid`, `provider`, `tenant`, `subject` | Получатель, telegram и конкретный bot tenant |
| `linkid`, `linkrevision` | Привязка, действовавшая при выдаче |
| `timecreated`, `expires` | Серверное время выдачи и срок действия |

XMLDB: все поля NOTNULL; id — sequence int(10), остальные числовые поля int(10),
tokenhash char(64), provider char(16), tenant/subject char(128), как в conn_links.
Помимо primary/двух unique indexes нужны userid_idx и expires_idx; foreign keys
ведут к user, conn_links, notifications и notify_delivery. Новый источник истины
для ACK или отдельный token status не добавляются: они выводятся из текущих
notification/delivery/link, а не синхронизируются между двумя автоматами.

Action — `ua1_` + canonical base64url32 random bytes без padding:47 ASCII bytes.
В БД сохраняется только hash; raw token не пишется в логи/SQLite, config или
диагностические receipts. Sender/receiver держат raw только в памяти запроса;
Telegram получает его как callback_data, поэтому token всегда проверяется вместе
с отправителем и текущей привязкой. Он не является самостоятельной авторизацией.

Token создаётся атомарно с переходом delivery в sending в begin_v2, только если
native критерии собственного ACK уже выполнены. В остальных разрешённых случаях
отправки permit содержит `ack: null` — например, pending registration recipient.
`expires` равен существующему `connexpires`: reminder/feed1ч, остальные события24ч
от создания уведомления. Отдельного продления нет. Новая попытка выдаёт новый
token; token предыдущей попытки больше не подходит по attempt. Потерянный ответ
begin не даёт разрешения отправить сообщение и не выдаёт raw token повторно.

Предлагаемая версия после реализации — local_ustar2026102200, 100 таблиц;
это **не текущий candidate**. Точный file count определяется новым manifest
после кода. Fresh install и upgrade2100→2200 создают одну и ту же XMLDB схему;
существующие inbox/delivery/ackat не переписываются. Privacy provider включает
token rows в экспорт/удаление пользователя, raw token экспортировать невозможно.
Существующая prune task удаляет по500 истёкших rows старше expires+7дней за запуск;
удаление token не стирает canonical ACK/его время.

## 6. Проверка на стороне Академии

В одной существующей identity transaction/lock проверяются:

1. Узкий ACK key, HMAC, nonce, provider/tenant и включение A.2. Текущий configured
   pilot UID fence обязателен; A.2 не расширяет состав получателей.
2. Canonical token/hash и срок `expires > now`. Из row определяется link/реальный
   userid. Current link должен быть linked с теми же provider/tenant/subject,
   id/revision; current notification должен принадлежать этому userid.
3. Реальный подтверждённый business account, не guest/deleted/suspended;
   employment ACTIVE и текущий `local/ustar:use`. Никаких login-as/view-as,
   произвольных actor session или подмены `$USER`. Общая проверка account/receipt
   выделяется из A.1 узко, native session boundary A.1 сохраняется.
4. Notification имеет status unread/read/acknowledged и source local. Текущий
   `notification_policy::permitted` целиком не переиспользуется: он требует unread
   для доставки и сломал бы ACK прочитанного уведомления и повтор.
5. Delivery совпадает по notification/link/attempt и находится в sending,
   provider_accepted либо uncertain. Для failed/retry_wait/cancelled token не
   принимается. Если providerref уже сохранён, message_id обязан совпасть.
6. Вызов того же canonical writer и чтение первого ackat внутри transaction.
   Ни status/providerref delivery, ни задача, ни права доступа не меняются.

Callback может прийти до finish либо после потери send/finish ответа. При ещё
неизвестном providerref допускается ACK по opaque token и проверенному private
bot-origin callback текущего получателя; не требуется сначала выдумать provider
receipt. ACK не исправляет uncertain и не подтверждает доставку провайдером.
После фиксации providerref несовпадение отклоняется даже при уже существующем ACK.

Полный revoke/relink, даже с тем же Telegram ID, инвалидирует старый token revision.
Отключение личных уведомлений останавливает отправки, но не отменяет возможность
подтвердить своё уже доставленное уведомление по ещё действующей связи/token.
Quiet hours/cooldown не блокируют прямое действие человека. Утрата права видеть
саму задачу не выдаёт это право через ACK: подтверждается собственная запись inbox,
как в A.1; бизнес-действие и открытие задачи независимо проверяются native UI.
Global kill switch A.2 прекращает новые callback ACK немедленно.

Доверенная граница здесь — receiver, получающий updates через проверенный TLS
Bot API, и его scoped key; CallbackQuery не имеет независимой пользовательской
HMAC-подписи. Компрометация receiver/key не объявляется защищённым сценарием.
Token не даёт ключу подтвердить произвольное уведомление другого пользователя.

## 7. Private callback parser и один consumer

При `acknowledgements_enabled=false` getUpdates явно запрашивает только message;
при true — message и callback_query. Старые команды и их guards сохраняются.
Не добавляются webhook, второй polling process или отдельный getUpdates для ACK.
Полученные неподдерживаемые queued updates безопасно завершаются как ignored;
нет drop_pending_updates, reset cursor при переключении или удаления journal.

Новый parser принимает ровно update_id+callback_query. Нужен query id: строка
1–256 printable ASCII bytes без пробелов/управляющих символов (не integer),
data строго формата ua1, человеческий from.id/is_bot=false; доступный message с
положительным message_id/date, private chat.id=from.id и message.from.id=verified
bot tenant/is_bot=true. Известные forwarding/via_bot/sender_chat/business/guest
origins отклоняются; inline_message_id/game callbacks/groups/channels/topics и
inaccessible message не поддерживаются. Unknown optional metadata не превращается
в команду; конфликтующие origin fields отклоняются. Caption/text и reply_markup
не служат доказательством token/полномочий и не пересылаются в Moodle.
Message с message_thread_id/is_topic_message также отклоняется в этом срезе.

Original message.date проверяется как дата существующего сообщения без будущего
времени >30с, но не по command TTL120с. Свежесть действия обеспечивают server token
expiry/current rights и HMAC запроса; timestamp нажатия Telegram не предоставляет.
Callback id не становится ключом бизнес-команды. Новое нажатие после потери ответа
получает прежний canonical ACK, если token/actor по-прежнему допустимы.

## 8. Journal, сбои и ответ интерфейсу

SQLite schema/user_version1, private permissions, cursor, lock и terminal states
сохраняются; добавляется только kind `ack`. Для него отдельный лимит10 на identity
за60с; launch/link/help лимиты не ослабляются. Записываются update_id, hash identity,
kind/phase/fixed reason и message_id — без token/query body/private text.
Один update выполняется один раз в journal, canonical ACK идемпотентен между
разными updates и native A.1. Незавершённый event после restart, как прежде,
становится uncertain и автоматически не выполняется снова. Дубли terminal update
не вызывают повторный bridge/API запрос и не откатывают cursor.

Порядок для нового допустимого нажатия: durable received → creating → один bridge
ACK → sending → answerCallbackQuery → best-effort editMessageReplyMarkup → terminal.
Bridge callback timeout5с, UI методы по3с, без retry в обработке этого update.
При bridge timeout запись могла сохраниться: ответ «Результат пока неизвестен.
Проверьте уведомление в Академии или нажмите ещё раз»; ACK не откатывается,
кнопка остаётся. При отказе — «Не удалось подтвердить. Откройте Академию».
Успех показывается только после валидного canonical ответа с первым временем.

Transport расширяется строго по method: answerCallbackQuery принимает только
result === true; editMessageReplyMarkup для нашего private non-inline message —
только Message с ожидаемыми chat.id/message_id. Прежние getMe/getWebhookInfo/
sendMessage dict и getUpdates list проверки не ослабляются. UI answer использует
cache_time=0, не отправляет URL и содержит только короткий fixed текст/ACK time.
Markup строится по allowlisted category из canonical ответа: прежняя фиксированная
native ссылка, без доверия URL из incoming message. Новых sendMessage при ACK нет.

Если canonical ACK сохранён, но UI answer не подтверждён, journal заканчивается
uncertain с fixed reason; первая запись Академии остаётся истиной. При успешном
answer и неудачном edit — terminal sent с fixed reason ui_edit_unconfirmed;
оставшаяся кнопка безопасна для повтора. 429 вызывает существующий durable receiver
backoff, 401/consumer conflict останавливают receiver, batch не подтверждается
за ещё не обработанные updates. Existing sender backoff и finish сохраняются.
Не обещается exactly-once UI, мгновенное удаление кнопки либо автоматический retry
неизвестного результата. Frozen a86 способен открыть тот же journal schema1,
игнорировать callbacks и продолжить старые команды; это проверяется fixture.

## 9. Включение и безопасное отключение

Новая Academy конфигурация: `ustar_connect_ack_enabled=false` по умолчанию и
положительный integer `ustar_connect_ack_since`, необходимый при включении.
Action выдаётся только событиям с timecreated >= ack_since; старые queued события
могут получить прежний URL-only формат. История не пересоздаётся и не рассылается.
Отключение notification opt-in и отключение A.2 — разные настройки.

Будущий coordinated release сначала устанавливает проверенную схему/код с OFF,
stage проверяет отдельные v2-sender/ACK-receiver credentials и actual private TLS
scope denial, сохраняет token/cursor/link/prefs/notify_since/pilot fences и
managed ownership. После остановки старого sender/receiver и завершения/истечения
прежних leases переключаются те же службы и включается A.2 с новой отсечкой.
Разрешён один consumer, не одновременная работа старого и нового. До включения
native backend/worker readiness взаимно проверены; malformed/disabled v2 не
вызывает автоматическую отправку по v1 вместо неизвестного результата.

Экстренное отключение: OFF в Академии, остановка sender, затем прежние a86
receiver/sender configs/keys на сохранённом journal, запуск одного consumer.
Академия2200 остаётся со старым v1 API; уже созданные ACK сохраняются. Кнопки в
старых сообщениях могут остаться без обработчика, native ссылка работает.
Отключение не понижает DB version, не удаляет tokens/ACK и не восстанавливает
business DB. Возврат исходников Академии2100/2000 после2200 — отдельная paired
recovery с явной границей потери новых данных, не скрытый шаг disable.

На server1 пока2000: будущий guarded installer должен уметь проверенную цепочку
2000+точный pilot overlay→2100→2200, fresh paired backup, coordinated cron target,
HTTPS ownership/renewal/recovery. Старые source-bound installers не патчатся и
не выдаются за installer2200. В этом проекте такого production пакета ещё нет;
он оформляется отдельным deployment срезом после source/native CI.

## 10. Проверки до утверждения реализации как готовой

- Native PHP/PG: свой/чужой получатель, wrong tenant/subject/key/message, token
  expiry/boundary/canonical encoding, revoked/relinked revision, inactive account,
  capability loss и current pilot fence; unread/read/repeated ACK, native-vs-callback
  и read-vs-callback races сохраняют первое ackat. Failed/cancelled/retry_wait не ACK.
- Sending-before-finish, uncertain, lost begin/send/finish/ACK response, providerref
  mismatch, repeat after expiry/revoke и отсутствие изменений задачи/delivery.
- Exact v2 packets и отрицательные unknown fields/type tests; frozen a86 принимает
  фактические v1 PHP packets без нового поля. Никакого raw content в permit.
- Callback envelope: чужой/private/group/inline/forward/business/guest/inaccessible,
  старое original message при действующем token, malformed/bool IDs; разные
  callback ids, duplicate update, crash в каждой фазе, recovered uncertain,
  cursor monotonic в batch, persisted429 и same-SQLite a86 возврат.
- Typed answerCallbackQuery true, wrong result type, timeout/400/401/429 и
  edit failure; API UI не создаёт новый ACK и не отправляет дополнительное сообщение.
- Actual Moodle upgrade2100→2200, fresh/repeat/schema100, unchanged inbox history,
  privacy/prune и paired DB+source+config+moodledata recovery. Старый A.1 runner
  закрепляется на frozen4fb4cd17 для его2000→2100/99table no-delta проверки;
  новый A.2 lifecycle не подменяет прежнюю проверку источниками2200.
- Actual systemd/UID33/private credentials/scope/TLS и один consumer; переход
  v1→v2→disabled/a86 с сохранением token/cursor/link/preferences и без replay.
  Существующий native filter включает новый callback test; прежние native/browser
  regression и source/context gates сохраняются. Provider fixtures synthetic.

Результаты проверок A.1 не выдаются за A.2. Live provider calls и ручная групповая
приёмка не нужны для написания source tests и не объявляются выполненными.

## 11. Review и следующий checkpoint

Author self-review: устранены смешение message.date/click freshness, зависимость
ACK от unread-only delivery policy, выдача provider receipt из ACK, изменение v1
permit и потеря canonical receipt при UI ошибке. В проекте явно определены TTL,
attempt/revision, callback-before-finish, scope, schema, отключение и границы
native/production evidence. Независимого reviewer и субагентов не было.

Письменный проект принят владельцем22Oct. Следующий шаг — review
[конкретного implementation plan](USTAR_CONNECT_CON02_TELEGRAM_ACK_PLAN_20261022.md).
Способ самостоятельного выполнения без субагентов уже сохранён. Статус текущего
этапа — `implementation_in_progress`; завершение определяется actual source/native CI.

Официальная база протокола, сверена22OctMSK: [CallbackQuery](https://core.telegram.org/bots/api#callbackquery),
[InlineKeyboardButton](https://core.telegram.org/bots/api#inlinekeyboardbutton),
[getUpdates](https://core.telegram.org/bots/api#getupdates),
[answerCallbackQuery](https://core.telegram.org/bots/api#answercallbackquery),
[editMessageReplyMarkup](https://core.telegram.org/bots/api#editmessagereplymarkup).
Решения о TTL, правах и миграции — проект USTAR, не требования Telegram.
