# CON-02: согласованная поставка Академии2200 и Telegram ACK

22.10.2026, UTC/Europe-Moscow. Статус: **written_design_ready_for_owner_review**.
Владелец после закрытия A.2: «Переходи к следующему». Следующий этап из принятого
handoff — подготовка guarded installer, свежей пары и согласование cron/HTTPS/worker.
Этот файл — конкретный проект этой поставки. Код нового controller и его план
реализации ещё не написаны; сервер не изменён. Работа самостоятельно,0subagents.

## 1. Результат и исходные точки

Оператор получает проверяемый обычный `.py` и короткие команды. Первая операция
обновляет native Академию с2000+принятым overlay до2200, сохраняет работающие
Telegram-привязки и v1 sender. Вторая явная операция включает Telegram «Принял»
через тот же receiver. Обе имеют durable состояние, точные before/after и recovery.

| Область | Точная исходная точка |
|---|---|
| Repository | PR106, `design/ustar-connect-20261018`, head34e185f032d72625c0a6b978dd8d9e5a0ae576d7; main unmerged |
| Проверенное приложение/worker A.2 | source534764821c4f65437ab50b3c7f25578d538e9504; local2200/theme0202,557files/100tables; [квитанция](../runtime/connect_con02_callback_ci_20261022.json) |
| Проверки исходного кода | Context39782650830/Review39782650814 SUCCESS;154native/800assertions, wire/races/2100→2200/recovery/runtime PASS |
| Финальная документация A.2 | Context39786095318/Review39786095327 SUCCESS; source8.2/8.3/runtime/native completed, FullRC jobs skipped |
| Server1 application | payload1e8a8819f50785ebb4fb2640eeb9386c969d59d1, local2026102000/theme2026100202,552files/99tables; единственный разрешённый overlay notification_delivery.php SHA256 `2f6c0c8b25941ac435237fc9df4c4de738b885f467ccbab80b9988c0bb42e498` |
| Server1 worker/tool | a86e208029b6104150e85d0e90384f84da214c22; принятое исправление доступа к каталогу0755/content_changed=false |
| Получатель | Точный прежний UID/subject fence из root-private pilot state/config; owner receipt указывает UID130 и последний revoke. Личные preference/link не изменяются |
| Cron | Принятый v3 source SHA256 `ae5fa1b075ff5f97300f5d5675d51367b95d821e0acf73c7c4458200af7e9751`, contract2000/theme0202 и текущая installation pair |
| HTTPS/backup | https://ustar.local, private bridge127.0.0.1:18443, действующие CA/leaf/renewal; принятая HTTPS-compatible backup pair a604fbcd/a97039a8 |

Это owner terminal receipts, а не новый independent SSH probe. Старый полный
preflight, пилот, parent preparation, HTTPS cutover и egress refresh не повторяются.
Новый controller проверяет необходимые фактические входы под locks непосредственно
перед своей транзакцией; неизвестный drift не принимает.

## 2. Выбранная граница выпуска

| Вариант | Свойства | Решение |
|---|---|---|
| Один controller, две явные операции: app upgrade, затем ACK enable | У приложения и runtime отдельные границы recovery; v1 продолжает работу после upgrade; меньше изменений за один раз | Выбран |
| Одна общая операция app+cron+runtime+ACK | Единая команда, но отказ внешнего транспорта задерживает весь выпуск и смешивает границы DB recovery/runtime fallback | Для этого выпуска не нужен |
| Ручная копия addons и отдельные правки PHP/systemd/cron | Нет единого durable before-image и ownership contract | Не используется |

Новых получателей, группового controller, новых личных согласий и нового бота нет.
Sender сохраняет точный существующий UID/subject fence. Если UID130 revoked,
это допустимый baseline: upgrade/ACK enable не выполняют relink, не требуют его
для подготовки и не делают существующий link активным. Любая доставка по-прежнему
требует текущих native account/capability/link/preferences и разрешённого subject.
Live group tests deferred владельцем до полного запуска, не passed. Принятый
one-user Tasks pilot не переоткрывается. B routing/preferences и C Bitrix отдельно.

## 3. Контракт приложения и cron

Целевые addon bytes фиксируются по source53476482 и его manifest; worker также
фиксируется отдельно по53476482. Будущий tool_commit относится только к controller
и builder. Эти три поля не сливаются в одно source_commit. Публикация документации
не создаёт новую версию приложения. Повторный CI34e185f0 завершён SUCCESS; native job119853663827 и оба
source/runtime jobs прошли. Новый controller проходит собственный CI.

Ставится сразу полный2200 source, затем официальный CLI upgrade дважды.
Промежуточный live source2100 не устанавливается: A.1 не добавлял DDL и отдельного
2100 savepoint в текущем upgrade.php нет. Из2000 с99таблицами штатный2200 savepoint
добавляет только local_ustar_conn_acktokens; после upgrade/repeat100таблиц и parity
с fresh2200. Это новый обязательный direct2000+overlay→2200 test; предыдущие
проверки2000→2100 и2100→2200 сохраняются самостоятельными регрессиями.

У addon registry меняется только версия local_ustar; theme/core/прочие addon,
история/задачи/получатели/preference/доставки и canonical ACK сохраняются.
Все прежние99таблиц/columns/indexes и custom/disabled task flags сверяются;
единственная новая таблица обязана точно совпасть с XMLDB. За пределами двух
addon scopes файлы и metadata не меняются. Существующие права UID33/root-group
и защищённые include parents сохраняются; рекурсивного chown/chmod нет.

Cron source принимается только с полным ae5fa1b0 hash. Меняется ровно одно
вхождение literal VERSIONS2000→2200 при неизменном theme0202. Функция patch
проверяет единственность строки и compile без выполнения; other bytes неизменны.
Хеш точного результата фиксируется в durable after-image и проверяется readback.
installation.json обновляет только версии и script_sha256, сохраняя container
bindings/metadata. Schedule, last-run, errors и timer не сбрасываются; tick
не запускается установщиком. До public release application2200 и cron2200
обязательно составляют одну подтверждённую пару.

## 4. Владение контролями и HTTPS

Новый namespace: `/var/lib/ustar-connect-con02-update`,root0700.
Схема journal имеет раздельные app_transaction и runtime_generation. Original
baseline2000+a86 и before/after последнего runtime enable — разные снимки.
Состояние содержит fixed tool/app/worker commits, фазы, hashes/metadata,
restart policies и service enabled/active; секретные bytes находятся только
в root-private before/after files. Public status не печатает subjects/ключи/текст.

Old pilot/link journals и их before-images не переписываются для принятия нового
commit. Новый controller проверяет исходный active a86 pilot, link/HTTPS guards,
pointer и policy overlay и фиксирует отдельный ownership handoff. После upgrade
его exact2200 policy уже отличается от a86; legacy pilot CLI должен отказать
до stop/write. После ACK enable новые receiver configs/drop-ins/include также
дают legacy link/pilot отказ до записи. Это проверяется фактическими frozen CLI,
а не предположением по коду. Старый инструмент не становится recovery controller
для2200. Неизвестный administrator drift не нормализуется и не перезаписывается.

HTTPS owner остаётся существующим Manager/renewal tool. config.php, vhost80/443,
loopback18443, health, observability include, tool, units/timer, CA/leaf и HTTPS
state сохраняются побайтно в каждой транзакции. Меняется отдельный private
`.ustar-connect/bridge.php`, уже подключённый существующим config.php. Этот файл
принадлежит новому runtime controller; existing HTTPS guards его содержимое
не принимают за config.php/observability include. Поэтому для этой схемы не нужны
новый сертификат, смена vhost или переписывание HTTPS tool/state hashes.
HTTPS lock и проверка guards/hostname/CA/SNI/leaf/Secure cookie обязательны.
Если реализация обнаружит необходимость изменения любого HTTPS-owned файла,
операция отказывает: это изменение письменного проекта, а не скрытый bypass.

Принятые backup producer/scheduler остаются прежними. Нового HTTPS preparation нет.

## 5. Locks, fresh backup и app transaction

Порядок EX locks: новый controller → существующие Academy update → chat-link update
→ HTTPS → cron → backup → receiver-install. Все lock paths/inodes/ownership/mode
проверяются, lock не удаляется. Bounded busy refusal, без циклических захватов.

Fresh HDD запускается принятым producer при удержании только deployment+HTTPS
locks: дочерний producer получает свой backup lock самостоятельно. Нельзя держать
backup lock и вызывать этот же producer. После полного readback success проверяются
timestamp и отсутствие pending backup resume marker. Затем controller захватывает
cron,backup,receiver-install в порядке выше. Это сохраняет существующую дисциплину
locks; сборная frozen bridge.locks, сразу держащая backup/install, для fresh HDD
не используется. Обычный transport backoff во время краткой backup-паузы сохраняется.

App transaction:

1. До любых stop — повтор входных source/versions/overlay/cron/HTTPS/container/bind
   guards, capacity/inodes, новая private run directory и durable marker.
2. После fresh HDD и полного ordered lock set — снимки control files/metadata,
   точных service states и current pointer. Stop sender, затем единственный receiver;
   проверить отсутствие их процессов/активного journal writer. Никакого второго poller.
   Текущий schema1/cursor сохраняются; queue/lease/uncertain не сбрасываются.
3. Recheck baseline SQL/история, stop web, подтвердить отсутствие прочих DB clients,
   clean stop PG и restart=no на время pair. Capture verified cold code/config/
   moodledata/physical-PG pair плюс before-images cron/runtime controls. Journal
   не читается/копируется как неподтверждённый live SQLite main без WAL: используем
   только закрытое и проверенное состояние и фиксируем его hash/cursor.
4. Полный target addon copy с сохранением reviewed metadata. Maintenance, start,
   official upgrade/repeat, native99→100 parity/history/task проверки, file refs.
   ACK остаётся false; private include и worker pointer остаются v1/a86.
5. Apply/readback cron source+installation2200. Verify exact candidate controls,
   неизменный HTTPS contract, roots и private include. Durable public_release
   boundary перед снятием maintenance. После этой записи DB rollback не автоматический.
6. Trusted HTTPS login smoke, восстановление restart policies и прежнего режима
   receiver/sender. Запуск существующих служб сам проверяет identity/egress/scope.
   Если прежний sender был disabled/inactive, обновление не включает его.
   Отказ запуска после public release оставляет2200 и явный resume_required.

Официальный upgrade не включает A.2, не выдаёт tokens и не выполняет history replay.
Статус app_ready означает установленное приложение, а не реальную Telegram-доставку.

## 6. Явный runtime enable/disable ACK

`--enable-ack` разрешён только на app_ready2200 и exact matching cron2200/HTTPS/
native schema100. Этот режим не меняет business DB schema, не создаёт backup вновь
и не повторяет application upgrade. Выбранный режим и cutoff записываются до изменения.

До stop проверяются текущая работоспособность identity/egress/link scope с помощью
существующего no-poll probe: getMe и отказные scope checks, не Telegram
send/getUpdates/положительный lease. Transport nonce guards сохраняются; этот
mutating mode не выдаётся за read-only SQL-проверку.
Новый immutable534 worker stage проверяется до switch, public dirs0755/files0644
явно при umask077; unit bytes прежние. Под ordered locks останавливаются sender,
receiver; фиксируется generation с единственным stable ACK cutoff=now+1.

Два новых различных root-only случайных ключа:

| Ключ | Scope/загрузка |
|---|---|
| `tg_notify_v2` | notification.lease/begin_v2/finish; только sender |
| `tg_ack_v1` | notification.ack; только receiver и probe |

Link key, bot token, proxy и trusted CA сохраняются. Secret bytes и key IDs
не совпадают с link/другим key. Старый v1 sender key сохраняется для guarded
runtime fallback, пока sender v2 использует только новый key. В private include
добавляются scoped entries, positive `ustar_connect_ack_since` и ACK flag.
Старые notify_userids,notification_subjects,notify_since и общий sending flag
сохраняются точно: смена версии контракта не создаёт новый состав/рассылку.
ACK cutoff применяется только к выдаче новых кнопок по согласованному A.2 контракту.

Receiver/probe configs имеют свой credential mount path, ACK=true и отдельный
ack_bridge pair; sender config — notification_contract=2 и v2 key без ACK key.
Новые точные drop-ins добавляют ACK credential receiver/probe и не расширяют
права сервисного UID. PHP lint, config validation, service credential access и
pointer handoff проверяются before/after. daemon-reload, no-poll scope probes,
затем receiver и sender в прежнем enabled/active режиме. Poll/send делают только
обычные службы; controller не посылает пробную задачу или Telegram сообщение.

Raw action и callback_query не логируются; SQLite schema1/cursor/terminal rows
и in-flight delivery uncertainty сохраняются. При неуспешном enable восстанавливается
**предыдущая runtime generation на приложении2200**; business DB не откатывается.
Если transport мешает восстановить активную службу, статус recovery_required,
а не успешный active. После начала polling cursor назад не перематывается.

`--disable-ack` ставит ACK=false, возвращает сохранённый v1 runtime/profile и
первоначальный режим sender; app2200/native ACK/cron2200 остаются. Это не disable
всех уведомлений и не возврат DB2000. Отзыв связи и preferences не меняются.
При повторном enable создаётся новая runtime generation/cutoff. Существующие
opaque actions обрабатываются по исходному TTL/current-link контракту A.2:
во время disabled ACK запрещён; после enable ещё действующий token может быть
допустим. Controller не чистит token table и не обещает новую семантику отзыва.

## 7. Crash/recovery и команды

Каждая запись файла/pointer/state проходит fsync+readback под проверкой полного
снимка. Partial pair допускает только записанные before/after, не произвольную
смесь с неизвестными bytes. Повтор команды продолжает тот же run/generation;
не выдаёт новый cutoff/ключи и не запускает второй consumer.

До durable public_release app error восстанавливает точную cold DB/source/config/
data pair, cron pair и controls, затем проверяет2000+overlay/a86/HTTPS и прежние
службы. Если pair неполна или drift неизвестен, запись прекращается, recovery_required.
После public_release `--recover` проверяет2200 и восстанавливает доступ/службы;
новые записи не теряются. Полный late restore имеет отдельную явную команду
с `--discard-writes-since-snapshot` и downtime acknowledgement, выполняемую
оператором после прочтения статуса. Снижение plugin version отдельно от пары запрещено.

| CLI | Эффект |
|---|---|
| Без аргументов | PLAN_ONLY, без root/host access/network/записей |
| `--check` | Host files/metadata/ordered locks и read-only SQL; без PHP bootstrap/provider calls/signed nonce/stop/state init |
| `--upgrade --acknowledge-outage` | Fresh HDD, cold pair, official direct2000→2200/repeat, cron pair; ACKoff/v1 сохранены |
| `--status` | Read-only exact run/generation/фаза/versions/service state; без секретов и Telegram subject |
| `--enable-ack` | Явный scoped v2/ACK handoff после app_ready; без изменения состава/личного согласия |
| `--disable-ack` | Guarded runtime возврат к v1 на2200; native ACK сохранён |
| `--recover` | Guarded восстановление/завершение по durable boundary; без потери post-release DB writes |
| `--restore --acknowledge-outage --discard-writes-since-snapshot` | Явное полное парное возвращение к2000 при согласии оператора на потерю поздних записей |

Все mutating modes требуют root-private owned artifact с проверенным SHA256;
unknown/combined flags отказывают до host действий. Безопасные команды не создают
журнал ради status/check. Первый check использует уже существующие locks;
новую controller lock directory создаёт только mutating mode. В выводе fixed reasons и progress phases; traceback,
config contents и данные сотрудников остаются в root-private log.

## 8. Поставка, файлы и проверка

Поставка владельцу — один обычный `.py` и SHA256, без ZIP/PYZ/ручной распаковки.
Builder по immutable Git objects встраивает reviewed modules, manifest, target
addon bytes и exact worker payload: module sources как UTF-8 literals, binary
assets как base64 data. Bounded decode и полный sorted manifest/digest/source-ID
check выполняются до compile/import embedded modules. Plain wrapper не импортирует
соседние user-writable файлы. Внутренняя проверенная структура worker releases/
PYZ остаётся штатной; оператор не переносит и не распаковывает её вручную.
Новая упаковка проходит tamper/inventory/dirty-source/reproducibility tests.

Изменения ограничены новыми `scripts/infra/connect_con02_update.py` и
`build_connect_con02_update.py`, переиспользуемыми reviewable pure copy/metadata/
recovery primitives, новыми r16/native/systemd fixtures и workflow gate.
Исторические installer02/chat-link/pilot bytes, helper manifests и их digest guards
не переписываются. Product PHP/Python A.2 source534 сохраняется; обнаруженный
product bug — отдельный RED/GREEN, новая source квитанция до выпуска.
Runbook/CI receipt/STATE/ACTIVE/BACKLOG/SESSION_LOG/index сопровождают поставку.

Обязательные gates до выдачи исполняемых owner команд:

1. Default offline/no-root и read-only check/status: отсутствие network/secret
   output/Moodle bootstrap/state writes; exact baseline и отказ неизвестному drift.
2. Actual filesystem/root+UID33: свежая pair, частичные cron/control writes,
   before/after metadata/ACL/xattrs, lock contention/order/inode replacement,
   crash на каждом checkpoint, SIGTERM/resume, root-parent и umask077.
3. Native Moodle5.1.8/PHP8.3.33/PG16.15: actual2000+overlay→2200/repeat/fresh100,
   custom tasks/history/registry/refs, old99 parity и реальное paired restore2000.
   Прежние A.1/A.2 writer/privacy/wire/race tests сохраняются.
4. Actual systemd/LoadCredential/UID33/private TLS с synthetic provider, отдельный
   actual native PHP dispatch: app upgrade на v1/a86; enable/disable/re-enable,
   старые callbacks/schema1/cursor, lost finish, два key scopes, old CLI отказ,
   same/current/revoked recipient fence и startup/egress failure recovery.
5. Actual Apache/HTTPS renewal: guards+renewal/profile recovery принимают
   неизменные tool/config/vhosts/state после app/runtime handoff; health/login
   CA/SNI/Secure cookie, wrong CA/peer denial. Synthetic fixture не remote acceptance.
6. Exact source/worker/plain artifact hashes, обе PHP source checks, r16/native/
   browser regression, Context inventories и self-review. Skipped FullRC не PASS;
   никаких live Telegram sends/group tests ради этой реализации.

## 9. Self-review и следующий gate

Проверены: один consumer/store; direct upgrade без ложного2100 savepoint; отдельные
app/runtime recovery boundaries; unchanged HTTPS owner; phased locks без вложенного
backup self-deadlock; fixed recipients/revoke/notify cutoff; private credential split;
отсутствие journal rewind и auto late DB rollback; обычный.py вместо user archive.
Авторский self-review, независимого reviewer/субагентов нет. Реализация и все новые
gates ещё не выполнены; ни этот проект, ни старый A.2 CI не являются installer PASS.

Следующий шаг — review владельцем именно этого письменного проекта, затем конкретный
план реализации и самостоятельное выполнение в текущем PR. Предложение выпуска
уже разрешено фразой «Переходи к следующему»; повторного выбора проекта/получателей/
агентов нет. Remote deployment/остановку сервера выполняет оператор по готовым
проверенным командам отдельным явным запуском.
