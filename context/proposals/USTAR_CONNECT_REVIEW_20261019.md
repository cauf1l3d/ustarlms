# Connect: ревью первого входа и начало разработки

19.10.2026, запрос владельца01:28МСК. Проверены RFC, contracts, legacy reuse на PR106/770b0982 и актуальный main d9655074. Приложенный Github.txt содержит ссылку на этот репозиторий. Ревью выполнено лично, без субагентов. Владелец поручил после ревью начать разработку; повторный цикл разрешения на начало не требуется. Это не разрешение на merge или production activation.

## Результаты

| Важность | Найдено | Исправленное решение |
|---|---|---|
| Высокая | Предыдущий link flow требует уже существующей Academy session, поэтому не покрывает нового сотрудника | Отдельный bootstrap регистрации: verified provider → ограниченная onboarding session → canonical register → pending → HRD decision → current access |
| Высокая | «Единый токен» можно ошибочно реализовать как постоянный межканальный bearer password | Единая Academy identity и один формат одноразового кода; разные provider secrets. Новый code для каждой привязки, binding к конкретному channel/tenant, TTL600с, одноразовое atomic consume и подтверждение Academy session |
| Высокая | Одобрение руководителем не соответствует текущему writer | Сейчас staffing_requests::review требует approveregistration + hrmanage; employment::approve_registration повторяет проверку. Первый срез сохраняет HRD approval, не выдаёт менеджерам новые права. Manager endorsement — отдельное будущее доменное изменение |
| Высокая | Pending пользователь может оказаться заблокирован общим active-employment guard и не увидеть свою заявку | Отдельные onboarding-only capabilities: создать/увидеть свою заявку, безопасно завершить связь, восстановить доступ. Они не дают route/results/tasks/files или HR actions |
| Средняя | CRM неверно трактовалась как Bitrix CRM | Владелец уточнил: существующий task_workspace Академии и отдельно personal checklist_service. Bitrix служит каналом |
| Высокая | Повторная регистрация Telegram/Bitrix может создать вторую учётку | Нельзя угадывать совпадение людей по ФИО/телефону. Existing account → login/recovery/link. Canonical username/email uniqueness + lock + receipt; конфликт не превращается в новую учётку или автоматическую привязку |
| Высокая | Обещание регистрации «внутри Telegram» может привести к сбору пароля в переписке | Кнопка бота открывает ограниченную HTTPS registration Mini App внутри Telegram; password вводится только в форму Академии, не в messages, callback или worker logs. Без внешнего HTTPS bootstrap вне офиса пока недоступен |
| Средняя | Legacy source не прочитан | Не объявлять его безопасным или переносимым; прежний план приватного аудита сохраняется |

## Канонический сценарий

1. Непривязанный пользователь открывает бизнес-бота. Telegram proof подтверждает provider identity, но не сотрудника и не роль. Бот предлагает «У меня есть учётная запись» / «Зарегистрироваться».
2. Bootstrap session ограничена onboarding. Собирается тот же набор, что register.php/registration_service::register: логин, email, имя, фамилия, подразделение, пароль по Moodle policy. Подразделение — заявление сотрудника, не полномочие. Нельзя отключать текущую политику пароля ради бота.
3. Facade в одной согласованной транзакции создаёт canonical Moodle user + employment pending + staff request и pending provider link. Outer transaction/locking и idempotency receipt проектируются и проверяются до подключения endpoint; повтор после потерянного ответа возвращает ту же заявку. Worker не хранит пароль и не ведёт копию кадровой БД.
4. Existing registration_requested inbox notification доставляется уполномоченным HRD через подтверждённые каналы. Число сообщений не меняет число заявок. Не создавать второе notification событие из worker.
5. HRD открывает защищённую форму из карточки; actual actor + current capabilities + step-up + existing staffing writer назначают должность и принимают решение. Callback с одним requestid не является разрешением. Одновременные approve/reject и устаревшие карточки проверяются по lock/revision/idempotency.
6. После commit approval пользователь получает уведомление и при следующем запросе видит функции по текущему employment/capabilities. «Полный доступ» означает полный доступ своей роли. Не выдавать права заранее по факту отправки или получения сообщения. Reject остаётся pending/rejected business state с разрешённым действием по текущему каноническому процессу, не удаляет пользователя автоматически.
7. Из уже подтверждённого профиля пользователь выпускает одноразовый код для второго канала. Код в Telegram и Bitrix имеет общий формат, но один выданный код не используется дважды. Новый канал подтверждает владение, исходная Academy session подтверждает пару. Первый provider link при регистрации привязан к тому же verified bootstrap context, ручной перенос по username запрещён.

## Граница начатой реализации CON-01A

В этой поставке: исполняемые Python primitives идентичности provider/tenant/subject, bounded Telegram Mini App HMAC verifier и генерация/хеширование кода привязки. Нет HTTP endpoint, polling, bot sends, Moodle login, XMLDB migration или серверной установки. Validated proof не является Academy session и не разрешает чтение бизнес-данных. Проверка freshness не заменяет durable replay/nonce consumption.

Первый код проверяет Telegram auth_date не старше300с, clock skew до30с, payload до16KiB/32полей, duplicate keys/неправильные типы/подпись/ботов. Выбран HMAC на приватном worker, которому уже нужен Bot API token; edge не получает token. Third-party Ed25519 не требуется для этого среза. Один provider tuple отличается от других tenant/channel; raw profile не возвращается наружу.

Следующий срез CON-01B: XMLDB identity/challenge/session/receipts, PHP facade с canonical registration, atomic consume+nonce, duplicate/revoke/concurrent approval tests на Moodle DB. После этого provider transport и onboarding UI/HTTPS edge; без них нельзя проверять регистрацию сотрудника на production.

Внешний edge/domain и фактические Bitrix APIs всё ещё требуют определения перед соответствующими срезами; они не блокируют CON-01A. Journal/heartbeat и upgrade платформы не входят в работу.
