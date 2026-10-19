# USTAR Connect — единый бот, роли и внешние приложения

Актуализация19Oct09:55МСК: canonical signup B3 d4f7c1bd verified78native/399/schema+concurrency. [B4](USTAR_CONNECT_CON01B4_PLAN_20261019.md) добавляет приватный browser BFF и узкую мобильную форму. Для6полей выбран dependency-free JS/CSS вместо предложенного React/Vite; API не меняется, legacy frontend не возобновляется. Private worker проверяет initData, edge не получает ключи; outbound relay/external origin и bot receiver не включены. Full role Mini App/native LMS и внешняя доступность остаются отдельными gates.

Статус: **PROPOSED API, редакция 3 от 19.10.2026, Europe/Moscow**. Первая редакция18Oct сохранена в истории PR106. Это проект контрактов, а не реализованный API, принятый ADR или установка. Исходный запрос владельца00:49 и результаты пульта00:41: [receipt](../runtime/connect_owner_priority_20261019.json). Субагенты не запускались.

Проверены main d9655074db9a06281fe16f378dbdbe1d279104a0, application baseline e1d57f5bc28f6964afd20aeff7620345b34a80fe (526 plugin/theme файлов,93 таблицы), PR103 head de4544d97f4f77036cfbb358ab79273361e6a6e5 и PR105 head02fd394fb90b44ad299374fd678b12543e7681ab. PR103/105 не merged. Текущая работа — архитектура INTEGRATIONS-01; журнал служб пульта и внешний heartbeat отложены владельцем.

## 1. Решение и альтернативы

Рекомендуется новый Connect facade и адаптеры над существующими доменами Академии. Пользователь имеет одну Moodle-учётку, подтверждённые связи с мессенджерами и одни актуальные права. Разные каналы показывают одно бизнес-состояние.

| Подход | Следствие | Решение |
|---|---|---|
| Автономный бот каждого канала со своими ролями/сроками | Расхождения и повторное выполнение | Не выбирать |
| Новый HR/LMS backend с копией пользователей, оценок и маршрутов | Вторые writers и миграция истории | Не выбирать |
| Новый transport/auth facade над действующей Академией | Единые данные/ACL, постепенный перенос legacy | Предлагается |

«Один бот» — один продукт и бизнес-контракт. Telegram, Bitrix24 и WhatsApp технически требуют отдельных provider IDs/credentials. HRD, HR, руководители, гендиректор и сотрудники используют один бизнес-бот в каждом канале с разным разрешённым меню. Им не создаются отдельные базы или боты по ролям.

Существующий INF-06 бот обслуживает сервер/backup/jobs. Его локальные owner/operator accounts и Telegram links **не являются аккаунтами Академии**. Не импортировать эти связи как HR-права, не объединять bot tokens, auth DB или service credentials. Бизнес-бот не получает shell/root/Docker/backup/key. Общими могут быть протестированные транспортные утилиты и renderer, но не полномочия.

Нынешний код Moodle/local_ustar остаётся владельцем кадрового lifecycle, организации, assessment, route progress, задач и чек-листов. Moodle core сохраняет auth/capabilities, Quiz/SCORM, completion, messages и File API. Legacy frontend/Next.js не возобновляется.

## 2. Компоненты и размещение

~~~mermaid
flowchart TD
    C["Telegram · Bitrix24 · WhatsApp"] --> E["HTTPS edge: webhook и Mini App"]
    B["Внешний браузер сотрудника"] --> E
    W["Connect worker на server1"] -->|"Исходящее TLS-соединение"| E
    W -->|"Фиксированный локальный API"| A["Connect facade в local_ustar"]
    A --> D["Домены Moodle и USTAR"]
    D --> Q["Канонические уведомления и outbox"]
    Q -->|"Claim через facade"| W
    W -->|"Polling и доставка"| C
~~~

Стрелка worker→edge обозначает инициатора соединения; запросы к серверу возвращаются по этому установленному каналу. Входящие порты на офисном маршрутизаторе/server1 не открываются.

| Компонент | Ответственность | Граница доверия |
|---|---|---|
| Connect facade внутри local_ustar | Link/session, actor, scope, разрешённые reads/commands, receipts | Никакого wildcard PHP/SQL вызова |
| Существующие домены | Состояние, writers, audit, сроки и эскалации | Нет второго HR/grade/progress store |
| Один worker с адаптерами | Provider ingest/send/edit, offsets, bounded relay, retry | Нет прямого SQL, config.php, root и доступа ко всему LAN |
| Public HTTPS edge | TLS, статические UI assets, provider ingress, лимиты, relay к fixed connector | Не выдаёт роли, не хранит Moodle administrator token |

Начальный polling-релиз — facade + один worker. Redis/Kafka/отдельная бизнес-БД не нужны для достоверности: durable business state остаётся в PostgreSQL Академии. Допустим небольшой transport journal worker/edge для доставочных receipts; он не хранит независимые оценки/дедлайны. Внешний edge добавляется для браузера и webhook-провайдеров.

Предлагаемый технический стек: facade и domain adapters — PHP внутри local_ustar; transport worker — Python3.12 в собственной venv/systemd-службе, отдельно от venv/UID пульта. Зависимости фиксируются с hashes при реализации; второй Moodle cron не запускается. Mini App — один отдельный статический TypeScript/React клиент с Vite, используя уже знакомую по пульту цепочку сборки, но со своей Academy auth. Это новый небольшой Connect entrypoint, не восстановление legacy frontend и не копия серверного пульта. Telegram/Bitrix/browser используют один bundle с тонкими platform adapters, WhatsApp открывает тот же web UI по разрешённой ссылке. Native LMS/theme остаётся полноценным учебным интерфейсом после отдельного origin gate.

Service authentication worker подтверждает только транспорт. Пользовательский actor разрешается по активной подтверждённой provider identity или Connect session. Поле userid в payload не выбирает исполняющего пользователя. Общий admin service token с подстановкой любого actor недопустим.

Для обычных bot updates worker является доверенным verifier происхождения provider event; это явная граница, не криптографическое доказательство каждого сообщения для Moodle. Компрометация worker/bot token может затронуть низкорисковые действия уже связанных пользователей этого канала. Поэтому bot assertions имеют минимальные scopes/TTL, а чтение приватных HR-ответов, оценивание и кадровые решения требуют независимой Academy web session/step-up. Incident response отзывает transport credentials, bot-linked sessions и незавершённые actions; защита не сводится к одному mTLS сертификату.

## 3. Внешняя связь без нарушения сети

Предлагаются две реализации одной топологии:

1. **Контролируемый компанией HTTPS edge + ограниченный исходящий connector.** Предпочтителен при доступном размещении и возможности сопровождать небольшой публичный узел. Только Connect operations и фиксированный локальный API.
2. **Managed HTTP tunnel.** Меньше собственного сопровождения; зависимость от доступности/ограничений провайдера. Cloudflare Tunnel — проверенный пример outbound-only модели, а не выбранный для компании поставщик.

Только polling без edge подходит для команд Telegram и поддерживаемого Bitrix, но не закрывает внешний Mini App и WhatsApp webhook. Это первый срез, а не вся целевая система.

Connector: отдельный UID, явный endpoint/credential, TLS verification, allowlist target/operations, лимиты размера/concurrency/timeouts. Не generic CONNECT/SOCKS и не произвольный upstream URL. Не нужны TUN, exit node, subnet router, default route, изменение DNS-resolver/NAT или Happ на домашнем устройстве. Tailscale/WireGuard не входят в этот проект. Остановка/удаление connector не требует восстановления маршрутизации.

Цель исходящего транспорта — TLS443; конкретный managed tunnel может требовать другой порт/протокол, что проверяется до выбора. Существующий Xray разрешает Telegram-only egress; доступность Bitrix, edge и WhatsApp проверяется отдельно, без расширения proxy до общего VPN.

Публичный HTTPS адрес нужен: браузер вне офиса должен куда-то обратиться. Он расположен на edge/провайдере, с доверенным сертификатом и ограниченными маршрутами. Пульт8443, SSH, Docker, ISPConfig, почта/DNS через этот edge не публикуются.

| Канал | Получение | UI/ограничения |
|---|---|---|
| Telegram | Сначала getUpdates long polling; позже webhook вместо него | Команды/кнопки/карточки и Mini App HTTPS; один активный consumer/token |
| Bitrix24 | imbot.v2.Event.get FETCH при поддержке фактическим порталом; иначе callback на edge | Bot и поддерживаемый embedded UI, отдельная проверка редакции/сессии |
| WhatsApp Business Platform | Provider-reachable HTTPS webhook на edge | Поддерживаемые сообщения/interactive формы и web links, не Telegram Mini App API |
| Web/PWA | Edge→connector→facade | Те же данные и формы вне конкретного messenger |

Polling/webhook одного источника не включаются одновременно. При переключении сохраняются cursor/receipts и проверяются дубли; drop_pending_updates по умолчанию не применяется. Telegram не хранит неполученные updates дольше24ч: после длительного разрыва показывается gap, а бизнес-события восстанавливаются из Academy outbox. Для Bitrix используется его documented cursor contract, а не предположение о совпадении с Telegram.

## 4. Связь учёток, быстрый вход и отзыв

### Первый контакт и регистрация

По дополнению владельца19Oct01:28 регистрация доступна из бота через ограниченную HTTPS Mini App. Она использует registration_service::register: логин/email/имя/фамилия/подразделение/пароль, canonical user + employment pending + staff request. Пароль не вводится в чат. До одобрения доступны только собственная заявка и onboarding; после — текущие права роли. Доставка registration_requested не создаёт вторую заявку.

Одобрение сохраняет staffing_requests::review / employment::approve_registration и требует approveregistration + hrmanage. Обычный manager не получает новые права. Manager endorsement — отдельное будущее изменение домена. Повтор после timeout/второй канал не создаёт дубль аккаунта: receipt, transaction, uniqueness и canonical writer обязательны. Existing account → login/recovery/link; совпадение имени/телефона не подтверждает владение.

[Полное ревью и сценарий](USTAR_CONNECT_REVIEW_20261019.md), [план CON-01A](USTAR_CONNECT_CON01A_PLAN_20261019.md). CON-01A содержит начальную реализацию transport primitives; HTTP API/регистрация/DB/production ещё не подключены.

### Привязка существующей учётки и второго канала

1. Сотрудник аутентифицируется в действительной Академии: LAN-сессия либо отдельно принятый публичный login flow. Одного доступа к messenger недостаточно.
2. Академия выпускает challenge: случайный секрет256бит в едином формате uc1_, hash-at-rest, TTL10мин, конкретные channel/tenant и Moodle actor, лимит попыток.
3. Пользователь подтверждает challenge из своего личного provider account. Группы/пересланные сообщения и подставленный user ID отклоняются. Bitrix учитывает tenant; WhatsApp phone/wa_id не связывается по совпадению номера в профиле.
4. Академия показывает подтверждённую пару, пользователь подтверждает привязку. Перенос provider account на другого Moodle user требует отзыва прежней связи и новой проверки, не silent upsert.
5. Для каждой связи выпускается новый одноразовый код с atomic consume. «Единый токен» — общий механизм и Academy identity, не постоянный межканальный пароль. Provider credentials раздельны. Worker не хранит пароль. Активный tuple (channel,tenant,subject) уникален; в первом релизе одна личная связь/user/channel.

Первый вход сотрудника вне LAN без прежней привязки требует доверенного public auth flow Академии. Пароль не собирается в чате. HR invitation — приглашение к проверяемой активации, не доказательство личности само по себе.

### Сессии

Telegram initData проверяется сервером по official HMAC либо Ed25519 third-party алгоритму, bot identity, auth_date и ограниченному окну; дополнительно session challenge/replay protection. initDataUnsafe не является доказательством. Выбранный алгоритм фиксируется при реализации; third-party signature позволяет verifier не получать bot token.

Проверенный provider proof + активная связь разрешаются в актуального Moodle actor. Предлагаются Connect-сессии15мин idle/8ч absolute с server-side revocation. Для web — Secure/HttpOnly cookie; SameSite/embedding отдельно проверяются в Telegram WebView и Bitrix iframe. При блокировке third-party cookies Bitrix открывает защищённую top-level форму. Секреты не хранятся в localStorage/query-string/messages/сторонней аналитике.

Быстрый переход в полную Академию требует **нового отдельного auth handoff**: одноразовый ticket TTL60с, binding к browser/session nonce и относительной разрешённой цели, POST exchange в доверенном Moodle auth handler, затем обычная Moodle-сессия. State/nonce и browser binding защищают от login CSRF и пересылки билета. Не передавать Moodle cookie из worker и не применять общий admin token. Это proposed contract, не существующий endpoint.

Оценивание, кадровые решения и массовые назначения требуют свежего step-up и явного подтверждения цели/значений; предлагается окно5мин с binding к команде. Наличие MFA в Академии проверяется отдельно: TOTP пульта не равен Moodle MFA. До принятого step-up такие действия ведут в существующий защищённый UI.

«Учётные данные» показывают логин, имя, должность/подразделение, состояние и безопасное восстановление. Действующий пароль не читается и не отправляется. Username messenger, должность и переключение вида UI не дают capabilities.

### Отзыв

На каждом read/write и перед sensitive delivery: current deleted/suspended, employment, capability, scope, link/session state. При HRD→HR, увольнении, смене подразделения/ACTING, unlink и инциденте отзываются связанные сессии/pending actions. Под lock/транзакцией перед mutation проверка повторяется. Старый callback не сохраняет прежние полномочия. Transport credential не заменяет user step-up.

## 5. Роли и сценарии

Матрица — продуктовый scope, а не автоматический grant. Все роли сохраняют свои личные сценарии; совмещение ролей объединяет разрешённые возможности, view-as остаётся read-only.

| Профиль | Чтение/уведомления | Разрешённые действия |
|---|---|---|
| Сотрудник | Мой маршрут/следующий шаг, результаты, задачи, чек-листы, напоминания | Свои отчёты/ответы/подтверждения; быстрый вход; нет чужих данных |
| Руководитель | Текущее подразделение/поддерево, просрочки и проверки, собственные уровни эскалаций | Review/назначения/remediation по доменным правам; без HRD решений и выхода из scope |
| HR | Очередь аттестаций, опубликованные материалы активных маршрутов, разрешённые профили и сводки | Ручная оценка при hr_access::can_grade; остальные команды по capability; ограничения recruiter сохранены |
| HRD | Разрешённый HR-контур, adaptation decisions, HRD escalation inbox и company summaries | manageadaptation/approvals/private analytics только по явным правам; не Moodle superuser |
| Гендиректор | Company dashboard и только конечные исключения | Company-read не даёт HRD write или private development answers; промежуточных reminder push нет |

[Матрица реальных writers и API](USTAR_CONNECT_CONTRACTS_20261019.md). HR сегодня может иметь company-read по действующему map: не сужать/расширять его по одному ярлыку. HR/HRD материалы выдаются через content/File API с published version/cycle. Оценка относится к finished attempt и canonical grading writer, а не к баллу из messenger.

Один mobile-first UI: «Сейчас», «Обучение», «Задачи/чек-листы», «Результаты», «Уведомления»; team/HR/company разделы появляются по правам. Простая команда — кнопка/подтверждение, сложная форма/график — общий защищённый web UI. ACL проверяется и по прямому API/URL. Карточки показывают source time и неизвестность данных явно. Личные HR-результаты не выводятся в общий chat.

CRM по уточнению владельца19Oct01:28 означает контур задач Академии: task_workspace и отдельно личные checklist_service. Bitrix — канал к тем же данным. Deals/contacts, второй CRM backend и /crm adapter не требуются.

## 6. Согласованность, команды и доставка

«Синхронная архитектура» — одно каноническое состояние во всех клиентах. Reads/commands идут в Академию, mutation возвращает её receipt/revision. Доставка по внешним сетям асинхронна; распределённая транзакция с тремя провайдерами и exactly-once отправка не обещаются.

Business writer/transaction → canonical notification/outbox → worker → provider receipt. Основа — local_ustar_notifications и local_ustar_notify_delivery, не новый центр уведомлений. Core Moodle notifications имеют свой namespaced source ID: одинаковые числовые ID не смешиваются.

Delivery уже имеет unique (notificationid,channel), attempts, nextattempt, providerref, lasterror. Для одного endpoint/channel это основа. Нужные lease/cancellation/transport receipts добавляются после schema-delta review. Старые Bitrix pending/disabled не включать массово: сначала история legacy worker. Неактивный новый канал показывает disabled, не fake pending/delivered.

Provider update: проверка происхождения → bounded parse → durable dedup (provider,tenant,event_id) → cursor confirmation. Webhook2xx подтверждает приём, не бизнес-успех. Интерактивные mutations имеют expires_at и expected_revision: просроченный запрос не выполняется молча после ночного восстановления связи.

Command key связывается с actor/target/op и hash нормализованного payload. Тот же ключ с другим payload — conflict; одинаковый повтор возвращает прежний receipt. Двойное действие из разных каналов дополнительно проверяет domain revision/state под lock, поэтому разные update IDs не создают вторую оценку.

Delivery worker: lease, bounded retry/backoff/jitter/Retry-After, сроки и dead-letter. Timeout после send может означать uncertain outcome и повтор видимого сообщения: это отмечается, а не объявляется exactly-once. Перед retry заново проверяются recipient, права и актуальность события.

provider_accepted / delivered / read / acknowledged / business_resolved — разные факты. Telegram API success не доказывает чтение; кнопка «Принял» не закрывает бизнес-просрочку. После решения в Академии старые кнопки во всех каналах отвергаются, update/edit карточки — best effort.

Preferences: основной канал/fallback, opt-in, категории, urgency, digest/repeat, quiet hours/timezone. Default — Academy inbox + выбранный основной канал, без тройного fan-out. Обязательные бизнес-уведомления и critical exceptions определяет принятая policy; выключение push не отменяет саму эскалацию.

## 7. Эскалации и гендиректор

Существующий task policy задаёт direct120 / parent540 / hrd1080 рабочих минут и durable task_escalations. Это defaults задач, а не универсальные сроки всех учебных/HR доменов. Assessment/adaptation сохраняют собственные lifecycle/policies. Connect не создаёт второй scheduler по Telegram.

Конечный CEO-уровень — **новое расширение предметных политик**, его нет в текущем recipient enum. Предлагается:

1. Normal overdue остаётся сотруднику/руководителю/HRD.
2. После HRD-уровня case становится terminal_candidate только при открытой проблеме, истёкшем настраиваемом grace и разрешённой executive категории.
3. HRD может решить, назначить ответственного/срок либо обоснованно отложить до ограниченного времени. Acknowledgement не закрывает case навсегда.
4. Передача CEO автоматическая после grace либо по HRD confirmation, согласно опубликованному режиму. До выбора категорий/сроков/режима CEO delivery disabled; catch-all всех просрочек отсутствует.
5. CEO видит масштаб/подразделение, ответственного, срок, состояние решения и защищённую ссылку. Private answers/рефлексии не включаются в chat.
6. Dedup — object+cycle+terminal policy revision+recipient; решение создаёт recovery/update case.

Transport acknowledgement/delivery общие, но business deadlines/transitions/recipients принадлежат исходному домену. Отсутствующий manager — явная HRD exception queue с audit, не адресат, угаданный по имени или строке должности.

## 8. Mini App, материалы и полный LMS

Первый внешний Mini App: route/results read, разрешённые файлы/видео с авторизацией и range streaming, task/checklist quick forms, HR inbox. Те же writers: «просмотрено» не становится Quiz/SCORM completion. Moodle File API проверяет actor/context/version; private file не копируется в Telegram CDN для обхода доступа.

Upload — owner-bound draft area, bounded type/size/target и финализация канонической командой. Нет приватного service-worker cache, offline grading и независимой синхронизации прогресса в первом релизе.

Полный Quiz/SCORM требует отдельного gate: доступный trusted Moodle HTTPS origin, auth handoff, canonical wwwroot, cookies/redirects/absolute links, pluginfile, uploads/range, CSP/iframes и WebView. Второй случайный hostname и HTML URL-rewrite не обеспечивают эту совместимость.

Нужно отдельно сохранить офисную доступность при выборе общего canonical hostname: split DNS/local TLS возможен, но требует согласованного инфраструктурного плана. Если офисный DNS/origin менять нельзя, первый внешний срез остаётся узким Mini App; весь SCORM не объявляется доступным. Это часть будущего INF-07 Academy HTTPS; работающий сертификат пульта её не закрывает.

## 9. Сбои, приватность и наблюдаемость

| Сценарий | Обязательное поведение |
|---|---|
| Forged callback/initData/tenant | Отказ до actor resolution |
| Увольнение, HRD→HR, новый отдел | Current rights и revoke, в том числе перед sensitive send |
| Replay/double tap/lost response | Inbox dedup + command receipt + domain revision/lock |
| Компрометация edge | Нет admin credential или generic LAN proxy; user step-up и domain ACL сохраняются |
| Нет связи edge/provider | LAN работает; внешний UI offline/stale, outbox bounded retry, без ложного успеха |
| Send timeout после фактической отправки | uncertain delivery; business mutation не повторяется |
| Нагрузка/upload | Лимиты, pagination, timeouts и resource budget; сеть не блокирует Moodle cron |

До activation определяются допустимые данные/обработчики, retention, opt-in и чувствительные категории. Не делать без проверки категорических региональных/правовых заявлений. Для WhatsApp фактически проверяются account/number/API eligibility, доставка, текущие шаблонные/тарифные ограничения и поддерживаемые interactions. WhatsApp Web session automation не входит в проект.

Connect health: last receive/send, cursor lag/gap, outbox age/depth, retries/uncertain/dead-letter, rejected/replayed command counts, latency/error, connector state/version. Только агрегаты/request IDs/reason codes, без token/password/initData/текста личных ответов. Пульт может читать узкий health snapshot, не бизнес-права. Heartbeat и текущий journal bug отдельно deferred.

## 10. Срезы поставки

| Срез | Результат | Acceptance |
|---|---|---|
| CON-00 | Review выполнен, CRM уточнён | Остальные provider/edge решения не блокируют первый код |
| CON-01A | Provider identity, Telegram launch verifier, формат кода | Offline tests; нет endpoint/session/DB writes |
| CON-01B.1, текущий | Internal Moodle links/challenges/proofs и отзыв | Native DB fresh/upgrade/repeat/concurrency gate; public API ещё нет |
| Остаток CON-01B / CON-01 | Canonical регистрация/pending/HRD approval, Academy link/revoke, один Telegram business bot, route/results/tasks и уведомления | Negative ACL/replay/revocation, отдельный token от INF-06, offsets recovery |
| CON-02 | Общая доставка/preferences/ack и Bitrix adapter | Одна история из двух каналов, retry/uncertain, фактический portal API |
| CON-03 | Public HTTPS edge и общая Mini App/PWA с быстрыми действиями | Office/home/mobile TLS/auth, никаких route/DNS изменений connector, disconnect/rollback сохраняют LAN |
| CON-04 | HR/HRD/manager формы и native LMS handoff/полное обучение | Step-up, files/cookies/Quiz/SCORM, отдельный canonical origin/network gate |
| CON-05 | Terminal CEO policies и WhatsApp adapter | Приняты категории/grace/режим и provider eligibility/реальная доставка |

Каждый code slice: exact SHA; XMLDB fresh/upgrade/repeat; role/scope/File negative tests; concurrency/replay/network fault; browser/WebView; provider fixture и реальный receiver ACK отдельно; latency/load budget; runbook/rollback и owner receipt. Эти gates не приписываются дизайну заранее. Moodle/PG upgrade для проекта не нужен.

## 11. Решения для review

- Bitrix cloud/box, редакция/доступность imbot.v2 и app permissions.
- CRM определён владельцем: задачи Академии, не Bitrix deals/contacts.
- Edge — допустимое размещение/домен/TLS, достижимость из офиса и мобильных сетей, кто сопровождает.
- Допустим ли будущий canonical Academy hostname/DNS/TLS план при сохранении LAN; узкий API этого не требует.
- CEO terminal категории, grace и automatic/manual режим.
- Безопасно полученные source/dependencies/hash legacy handler; файл не исполнять ради аудита.

Review и план CON-01A готовы; transport primitives реализуются. API и остальные срезы ещё не реализованы. Этот PR не создаёт credentials, внешние аккаунты, сервисы или таблицы.

## 12. Первичные источники, проверены19Oct2026

- [Telegram Bot API: polling/webhook, offsets, retention](https://core.telegram.org/bots/api#getting-updates).
- [Telegram Mini Apps: initData и signatures](https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app).
- [Bitrix24 Event.get: FETCH/cursor](https://apidocs.bitrix24.com/api-reference/chat-bots/chat-bots-v2/imbot.v2/events/event-get.html).
- [Bitrix24 register и eventMode](https://apidocs.bitrix24.com/api-reference/chat-bots/chat-bots-v2/imbot.v2/bots/bot-register.html).
- [Meta official WhatsApp collection: webhook](https://www.postman.com/meta/whatsapp-business-platform/folder/tduohwq/webhook-payload-reference).
- [Cloudflare Tunnel: пример outbound-only connectivity](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/).

## Продолжение19Oct04:09

[План CON01B.1](USTAR_CONNECT_CON01B_PLAN_20261019.md). Начальная постоянная identity реализация в Moodle candidate2026101900; baseline production не менялся. Нет trusted worker bridge/onboarding sessions/signup API/UI. Native CI и deployment — разные gates.

## Продолжение19Oct04:47

[CON01B.2](USTAR_CONNECT_CON01B2_PLAN_20261019.md): private scoped HMAC bridge и одноразовые browser-bound onboarding sessions реализованы в candidate2026101901. Endpoint по умолчанию выключен; новых сетевых настроек/служб/провайдеров не включалось. Предыдущий5f9aa3fb native13tests/47assertions PASS; новый CI отдельно, exact final receipt в PR106. Signup receipt+pending link/HRD outbox/forms и external edge остаются следующим срезом. Эта запись заменяет отрицание bridge/session в предыдущем dated checkpoint, не меняет production evidence.

## Продолжение19Oct05:30

Base81a1be76 завершён:22native tests/87assertions + concurrent entry PASS. [CON01B.3](USTAR_CONNECT_CON01B3_PLAN_20261019.md) добавляет private canonical signup+pending first link/receipt и own status; использует existing staffing_requests HRD approval/target_core notification outbox. Новая metadata table, version2026101902; password не хранится в receipt и не передаётся в чат. Обязательна одна transaction с canonical registration email lock до commit, а не outer Connect transaction вокруг старого register. Forms/public BFF/remote actor-step-up и реальные delivery receipts ещё отсутствуют; endpoint disabled. Test-only57111574 доказал2expected failures на missing canonical completion; implementation CI фиксируется отдельно в PR106. Production baseline/сеть не менялись,0субагентов.
