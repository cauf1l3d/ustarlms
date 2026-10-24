# USTAR инструкции агентам

**24Oct — локальный handoff подготовлен; публикация требует явного разрешения.**
Автоматическая проверка дважды отклонила git push в существующую ветку PR106,
несмотря на сверку GitHub profile/repo/head и отсутствие production secrets в diff.
Не обходить отказ другим API. Source/rollback/owner preflight сохранены локально;
23новых tests:22PASS/1local UID33 skip, combined31/1skip. Native CI не запускался.
[Квитанция](context/runtime/infra_app_handoff_local_checks_20261024.json) содержит5ошибок окружения общего r16 прогона.
Дальше явное разрешение на публикацию в design/ustar-connect-20261018, затем CI;
на server1 app/config/ownership не переключались. Ручные preflight/создание ролей не повторять.

**24Oct — INF02 owner preflight принят; coordinator в проверке.**
Pinned3d1938ae `--check`: application_ready/local2300/ACKon, host_changes=false.
Public3479 =657tables+656sequences+2166indexes, все mdl_/moodle. Единственный
custom definer ustar_console.sample(): owner_stats/superuser0, app USAGE0/EXECUTE0,
search_path pg_catalog,pg_temp. Функцию/схему не менять. [Receipt](context/runtime/infra_readonly_owner_20261023.json).
Роли/OID/login/этот ручной preflight не повторять. Следующий шаг — qualification
`scripts/infra/postgres_app_handoff.py`: scoped ownership transaction + config/HTTPS
hash handoff, metadata-only undo/recover, frozen journals и backup admin сохранены.
23focused tests:22PASS/1local UID33 namespace skip; strict UID33 и generated SQL в
существующем CI ещё pending. На server1 cutover не выполнен; production команды
apply пока не выдавать.0subagents/author review/no SSH/provider sends/main merge.

**24Oct — INF02: отдельная app role проверена в CI; production handoff следующий.**
Code933497e0/Review40043036549/connect-db120719333841 и Context40043036469 SUCCESS.
Обе PHP1085r16/153skips, runtime/browser PASS; старые158native/830assertions идут до
role handoff. Новый delta: ordinary ustar_app/five flags0,3159public objects/0pg_catalog,
14operation checks, five42501 denials,2200→2300/repeat, selected cron/app dump/admin
SET ROLE restore/100USTAR-schema и selected data equality PASS. Artifact12540597495
скачан/hash-verified; SQL/dumps/env не опубликованы. Bootstrap/POSTGRES_USER сохранены
административными; existing backup engine/helper pins не менять. [Разбор](context/runtime/infra_db_privilege_consumers_20261023.md),
[квитанция](context/runtime/infra_db_privilege_ci_20261023.json). Owner24Oct подтвердил server1 moodle/OID10/superuser и независимый admin/OID109344.
Owner создал ordinary ustar_app/OID110395: required-SCRAM login
прошёл, five flags0/login1/owner0/memberships0. Создание обеих ролей больше не повторять.
Следующий [read-only block](context/runtime/infra_readonly_baseline_20261023.md#app-handoff-preflight-24oct) проверяет
current controller и SQL definer/ownership inventory; config/public ownership/HTTPS
handoff ещё не выполнен. Полный INF02/03 открыт,
на server1 этим CI ничего не меняли. Existing owner stands reuse;0subagents.

**23Oct — прежние стенды засчитываются; следующий scope — только DB privilege delta.**
Нового owner/server1 стенда/full restore/repackage/UI круга по умолчанию не нужно.
Проверить DB consumers и дополнить существующий CI недостающими сценариями прав.
Latest fixture admin ustar_fixture и restore code ordinary ustar_app имеют разные
границы доказательств. [Разбор](context/runtime/infra_readonly_baseline_20261023.md).

**23Oct — отдельный PostgreSQL admin создан, два SCRAM-входа приняты.**
Owner corrected retry: ustar_pg_admin, PID9976/9995, session/current role match,
login/superuser=t. [Receipt](context/runtime/infra_readonly_owner_20261023.json), admin_creation_success.
Создание больше не повторять. moodle пока не демотирован; далее copy rehearsal
privileges/upgrade/backup/restore, затем согласованный DB/file hardening.

**23Oct — первый admin createuser отказал до создания роли; команды исправлены.**
`--maintenance-db` отсутствует в createuser/dropuser PostgreSQL16. В
[runbook](context/runtime/infra_readonly_baseline_20261023.md) параметр удалён из creation/
rollback; native retry + два SCRAM-входа ещё pending. Роль/права/config не менялись.

**23Oct — Docker/config follow-up получен; INF02 additive admin — следующий write.**
[Та же receipt](context/runtime/infra_readonly_owner_20261023.json), поле owner_followup:
primary config33:33/0755 writable, static dbuser=moodle; оба containers running/restarts0/
OOMfalse, accepted images, без healthcheck/json-file limits. Повторять probes не нужно.
[Runbook](context/runtime/infra_readonly_baseline_20261023.md) даёт createuser с private tty
и два отдельных required-SCRAM login; owner execution ещё pending. Demotion/permissions
не применялись. HTTPS capture сохраняет metadata config; будущий hardening требует
coordinated state handoff, copy/deploy/restore proof. Полные INF01/02/03 открыты.

**23Oct21:08МСК — INF-01 owner metadata получены частично.**
[Receipt](context/runtime/infra_readonly_owner_20261023.json): единственный найденный
superuser moodle; local_ustar и version.php writable под www-data33. Свежие scheduled
cron/HDD backup успешны,48G свободно. Docker inspect потерян на missing Health key,
primary config.php уровнем выше ещё не измерен. Исправленный узкий follow-up в
[runbook](context/runtime/infra_readonly_baseline_20261023.md); весь baseline не повторять.
Никакого hardening ещё не выполнено. Не демотировать единственный admin до отдельного
проверенного входа и проверки upgrade/backup; полные INF01/02/03 остаются открыты.

**23Oct — текущий приоритет: инфраструктурная нормализация; Connect на паузе.**
Owner просит вернуться к server1 hardening. [Остаток и точка продолжения](context/runtime/infra_normalization_resume_20261023.md).
Первый срез — INF-01 read-only refresh, затем конкретная подготовка P0 INF-02/03:
DB admin/runtime privileges и полные code/config/key permissions. Не повторять принятые
SSD growth, Moodle518/OS patch/reboot/LAN HTTPS/backup schedule/Connect2300.
Telegram delivery/ACK/history уже owner accepted; действующие workers не останавливались.
Напоминание24Oct15:00Europe/Moscow создано: KeenDNS rules + mobile off-LAN check.
Это не автоматическое возобновление/публикация. PR106 остаётся draft/unmerged;
0subagents/no agent SSH/provider sends. Ниже — предыдущие датированные checkpoint.

**23Oct — owner принял Telegram2300: доставка, видимый ACK/время и история работают.**
[Owner receipt](context/runtime/connect_telegram_finish_owner_20261023.json): tool3d1938ae/app+worker6c56/local2300/theme0202;
ACK/presentation active, notify+receiver active/enabled, DB available. Owner подтвердил
новую задачу в боте, постоянное «Принято» с временем и правильную историю в Академии.
Установка и эта визуальная приёмка закрыты; старый пилот не повторять.
Полный Telegram-продукт ещё не готов. Owner просит следующий законченный выпуск;
CON03/04 требуют Mini App/actions, Academy auth, external HTTPS и native LMS handoff.
Current source проверен: actor_read даёт только профиль, задач/чек-листов в bridge нет;
рабочий внешний origin/edge пока не подтверждён. Preflight: context/runtime/
connect_telegram_next_preflight_20261023.md. Reserve/group deferred;0subagents,
no agent SSH/provider sends/main merge. Ниже датированная история, не current status.

**23Oct — Telegram source/release handoff готов; owner execution следующий.**
Tool3d1938ae/app+worker6c56/local2300/theme0202,557files/100tables.
Context39978015123/Review39978015139 SUCCESS: обе PHP1077r16/153skips,
strictroot11zero-skips, actual UID33/systemd/privateTLS и Apache owner/extra AutoReport,
native158/830, v1/v2/v3/all20labels,2200→2300/repeat/paired restore2200 PASS.
Ordinary.py31153214B SHA6f772945… сверен побайтно CI/Git; [команды и recovery](context/runtime/connect_telegram_finish_20261023.md).
План5tasks закрыт как source handoff; server1 ещё534/local2200/ACK active.
Новый файл сохраняет fence/keys/cutoffs/cursor/preferences/старые journals; disable возвращаетv2/ACKon.
0subagents/author review, main unmerged; резерв/group live acceptance deferred.
Ниже — датированные checkpoint предыдущих стадий, не текущие команды развёртывания.


**23Oct — owner: резерв позже, сначала завершить Telegram.**
[Суженный проект](context/proposals/USTAR_CONNECT_TELEGRAM_FINISH_DESIGN_20261023.md),
[план самостоятельной реализации](context/proposals/USTAR_CONNECT_TELEGRAM_FINISH_PLAN_20261023.md).
B1 primary/fallback/новый route store отложены. Текущий scope: видимый ACK/точное время,
bounded event labels в explicit v3 без изменения v1/v2, личная история и сохранение
существующих preferences, native/runtime CI и отдельная coordinated successor поставка.
Task1 visible ACK19tests PASS; v3/enum/config/history реализованы,57focused PASS.
Native RED c7fe216:157tests/805assertions, ровно3новых отказа; прежние154PASS.
Candidate2300/557files/100tables. Source9984 both PHP/runtime PASS; native158/830
без assertion failures, strict notice в новом test query исправлен source6c56.
6c56 PHP8.3/runtime PASS, PHP8.2 timeout на apt/Chromium; native gate skipped, не passed.
Successor controller/builder написаны: exact534/4394→2300, новый journal, ACK key/cutoff
и cursor сохранены; disable-presentation возвращает v2 с ACK on. Local successor20tests
и legacy53tests/1local GID skip PASS; actual systemd/HTTPS owner новый gate pending.
Код ещё не поставлен: server1 app/worker534/local2200, ACK active/два owner ACK подтверждены.
0subagents/no agent SSH/provider sends/main merge; прежний pilot не повторять, группа deferred.
Ниже датированные checkpoint; B1 design-review больше не текущая задача.

**23Oct — server1 обновлён до2200, ACK runtime и два живых ACK подтверждены владельцем.**
Exact controller4394/проверенный SHA: --check baseline_ready (historical indexes),
--upgrade app_ready после fresh backup/cold pair/двух миграций/cron/public release;
--enable-ack runtime_active, receiver+notify active/enabled, DB available.
Application/worker53476482, local2200/theme0202,557files/100tables по fixed payload.
UID130 notices179(task_assigned)/180(task_reminder) оба acknowledged, по1попытке;
это разные события, не повторная доставка. Last-revoked21Oct теперь история.
Отдельный AutoReport192.168.1.60:9443 принят узкой HTTPS owner-state reconciliation;
Apache config/CA/private bridge сохранены. [Owner receipt](context/runtime/connect_con02_server1_owner_20261023.json),
[результат и границы](context/runtime/connect_con02_server1_20261023.md).
Далее [проект B.1 primary/fallback/preferences](context/proposals/USTAR_CONNECT_CON02B_ROUTING_DESIGN_20261023.md)
готов к review; новый routing/presentation код ещё не написан. Видимая ACK отметка/
различение assigned/reminder включены в проект; причина раннего reminder не доказана.
0subagents/no agent SSH/provider sends/main merge; old pilot не повторять/group deferred.
Ниже датированные предыдущие checkpoint, не текущие команды повторного deploy.

**23Oct — historical index compatibility verified; new owner --check ready.**
Owner b971 --check refused schema_indexes;99table diagnostic identified only missing
UNIQUE coin_accounts(userid)/test_results(attemptid). Fix accepts strict XMLDB OR
exact paired historical omissions on frozen2000; no production index/data repair.
Tool4394b994: Context39947880072/Review39947880106 SUCCESS; both PHP1048/153skips,
strictroot11/11, actual runtime/HTTPS and native154/800+strict AND historical direct
2000→2200/repeat/paired restore PASS, duplicate legacy rows preserved, new9ACKindexes.
Exact CI ordinary.py/30512272bytes SHA331c5c54eb605e142c401d6b84cbda02a1ba468be40b2ffc1da768b9429d322b;557payload/worker/legacy/16modules
match Git. [Incident and current runbook](context/runtime/connect_con02_index_compat_20261023.md). Owner --check next;
production2000+overlay/a86 unchanged;UID130 last revoked.0subagents/author review,
pilot not repeated/group deferred,FullRC skipped/no SSH/provider sends/main merge.
Below dated prior handoffs; b971 ordinary.py superseded for this baseline.

**23Oct — CON-02 controller проверен; обычный.py готов для owner --check.**
План22Oct закрыт:9задач/45шагов, self implementation/author review,0subagents.
Tool b971bc38: Context39844480447/Review39844480386 SUCCESS. PHP8.2/8.3 каждый
1040r16/153environment skips; strict root state11/11 без skips, native154/800,
actual systemd/UID33/private TLS и HTTPS-owner PASS. Direct2000→2200/repeat/restore,
9 fresh indexes/4 SQL denials проверены по скачанным JSON. FullRC skipped, не passed.
Файл `ustar-con02-update-b971bc38.py`, SHA `692de510d7864e723885f6764ec8363d6ba70ebbee2f26021f361a4c9a89a484`;
CI bytes = exact Git build, fixed app/worker534,557files/100tables.
[Команды и recovery](context/runtime/connect_con02_deployment_20261023.md); точная CI-квитанция рядом с runbook.
Дальше owner --check, затем отдельные upgrade/outage и enable-ack. На server1 остаются
application2000+overlay/worker a86;UID130 last revoked. Pilot не повторять, группа deferred.
No SSH/provider sends/main merge; независимого reviewer не было. Minor: generic readonly
legacy-guard refusal diagnostic deferred. Ниже — датированная история предыдущих состояний.

**22Oct — письменный проект поставки принят; план реализации готов к review.**
Owner: «потверждаю». [План](context/proposals/USTAR_CONNECT_CON02_DEPLOYMENT_PLAN_20261022.md):
9 задач/45 шагов — fixed contracts, durable images/phased locks, app+cron transaction,
v2/ACK profiles/runtime recovery, plain.py/CLI, native direct upgrade и actual TLS/systemd.
Метод уже выбран: самостоятельно,0subagents. Новый controller ещё не написан;
следующий gate — review только плана, без повторного согласования проекта/метода.
Designf0c00d5e Context39789336484/Review39789336561 SUCCESS; FullRC skipped.
App/worker534 и source2200/557files/100tables сохранены. Server1 application2000+
policy overlay/worker a86 unchanged; pilot/group tests не повторять. No SSH/provider sends.
Ниже — датированная история предыдущих состояний.

**22Oct — следующий этап: письменный проект согласованной поставки CON-02.**
Owner: «Переходи к следующему». [Проект](context/proposals/USTAR_CONNECT_CON02_DEPLOYMENT_DESIGN_20261022.md)
готов к review: один controller, отдельные app upgrade и ACK enable/disable;
direct2000+overlay→2200/repeat с fresh pair/cron2200, v1 сохранён до enable.
HTTPS owner/config/vhosts/renewal сохраняются побайтно; legacy journals не переписывать.
Поставка обычным.py/короткими командами; прежний UID/subject fence/revoke/cursor
сохраняются, pilot/group tests не повторять. Новый controller/его план ещё не написаны;
следующий gate — review именно письменного проекта, затем план самостоятельной реализации.
A.2 завершён: финальный34e185f0 Context39786095318/Review39786095327 SUCCESS,
source8.2/8.3/runtime/native PASS; FullRC skipped, не passed. Server1 application2000+
policy overlay/worker a86 сохранён.0subagents/no SSH/provider sends/main merge.
Ниже — завершённые этапы и датированная история.

**22Oct — CON-02A.2 реализован и проверен в CI; на server1 не установлен.**
Telegram «Принял» сохраняет первое личное ACK через тот же receiver и отдельный
ACK-only ключ; v1/a86 совместимость и default-off сохранены. Candidate2200/theme0202,
557files/100tables. Source53476482: Context39782650830/Review39782650814 SUCCESS;
154native/800assertions,3callback PG races,v1/v2 wire,2100→2200/repeat/paired restore
и отдельный actual UID33/systemd/credentials/private TLS handback PASS. PHP8.2/8.3
каждый974r16/152environment skips; Chromium ACK5widths и прежние UI gates PASS.
[Отчёт](context/runtime/connect_con02_callback_20261022.md), [точная квитанция](context/runtime/connect_con02_callback_ci_20261022.json).
Все9 задач принятого плана закрыты как source slice; author self-review,0subagents.
Следующий шаг — отдельный guarded2000+overlay→2100→2200 installer/fresh pair и
cron+HTTPS ownership handoff, затем B routing/preferences и C Bitrix. Установщика
этого обновления пока нет. Server1 application2000+policy overlay/worker a86 сохранён;
UID130 last revoked, принятый Tasks pilot не повторять. Live group tests deferred
до полного запуска, не passed; FullRC jobs skipped, не passed. Нет agent SSH,
provider sends или main merge. Ниже — датированная история, не текущие инструкции.

**22Oct — A.2 implementation explicitly approved and running; no new permission loop.**
Owner: «начинай выполнение». [Progress](context/runtime/connect_con02_callback_progress_20261022.md).
Transport/sender/callback/private config RED→GREEN; full local973tests/54env skips.
Native schema RED verified:140tests/687assertions, exactly2 missing-table failures;
138 prior tests pass. Source2200/100table metadata implemented; native lifecycle
GREEN/service RED observed; canonical PHP ACK implemented, native GREEN pending.
PHP v2 router and final acceptance remain.
0subagents/self implementation; server1 application2000+policy overlay/a86 unchanged.
Accepted pilot not repeated, live group tests deferred. No agent SSH/provider sends.

**22Oct — письменный проект A.2 принят; конкретный план реализации готов к review.**
Owner: «переходи к реализации». [План A.2](context/proposals/USTAR_CONNECT_CON02_TELEGRAM_ACK_PLAN_20261022.md):
9 задач/45 проверяемых шагов; schema/lifecycle, current-recipient ACK, v2 bridge,
Telegram transport/sender/callback, отдельные credentials и native recovery/CI.
Способ выполнения уже выбран: самостоятельно,0subagents, текущий PR106 checkout.
По writing-plans следующий gate — review именно письменного плана, затем выполнение.
Код A.2 ещё не написан; candidate2100/554files/99tables сохранён.
Docs1211fe6e: Context39694294801/Review39694294751 SUCCESS;
941 context tests/152environment skips, runtime/source8.2+8.3/connect-db SUCCESS.
Старые FullRC jobs skipped по существующим conditions, не passed.
Server1 application2000+policy overlay/worker a86; принятый pilot не повторять,
UID130 last revoked; manual group tests deferred до полного запуска.
Нет agent SSH/provider sends/main merge; author self-review, независимого reviewer не было.

**22Oct — CON-02A.2: вариант в диалоге согласован, письменный проект готов к review.**
[Технический проект Telegram «Принял»](context/proposals/USTAR_CONNECT_CON02_TELEGRAM_ACK_DESIGN_20261022.md): отдельный permit v2,
opaque token/TTL/current link revision, ACK-only credential и тот же receiver.
Первое ACK сохраняется в Академии; UI callback answer и provider receipt отдельны.
Token/table100/plugin2200 — только проект; candidate остаётся2100/554files/99tables.
A.2 product code/implementation plan не написаны; следующий шаг — owner review
конкретного письменного проекта, затем план самостоятельной реализации.
A.1 source4fb4cd17 CI verified; docs332 Context39682045417 SUCCESS.
Server1 application2000+policy overlay/worker a86 сохранены; pilot принят,
UID130 последний раз revoked. Не повторять pilot и не включать группу.
Manual group live acceptance deferred до полного запуска, не passed.
Author self-review,0subagents; нет agent SSH/provider sends/main merge.

**21Oct — CON-02A.1 реализован и проверен в CI; на server1 не установлен.**
В native inbox добавлено личное «Принял»: собственный active actor, POST/sesskey,
capability, source=local; первое время сохраняется при read/ACK races и повторах.
Прочитано, подтверждено, provider receipt и выполнение задачи различаются.
[Поведение, review и решения](context/runtime/connect_con02_ack_20261021.md), [точная CI-квитанция](context/runtime/connect_con02_ack_ci_20261021.json).
План подтверждён владельцем; candidate2100/theme0202/554files/99tables, без новой схемы.
Source4fb4cd17: Context39679569635/Review39679569515 SUCCESS;138native/685assertions,
4differential races,actual2000→2100/repeat/HTTP/paired restore,frozen a86 packets PASS.
PHP8.2/8.3:941r16/152env skips;ACK Chromium5 widths each. Exact limits in receipt.
Author self-review,0subagents; независимого reviewer не было;main unmerged.
Production application2000 с принятым policy overlay и worker/tool a86 сохранены.
Принятый one-user Tasks pilot не повторять; UID130 последний раз revoked.
Следующий срез — отдельный CON-02A.2 Telegram callback, затем B routing/preferences
и C Bitrix после проверки фактического portal/API. Группа1–10 design-only;
ручные live tests deferred до полного запуска, не passed. Нет agent SSH/provider sends.

**21Oct — подготовлен проект управляемой группы Telegram; review перед реализацией.**
[Проект следующего выпуска](context/proposals/USTAR_CONNECT_TELEGRAM_ROLLOUT_DESIGN_20261021.md): один sender и явные 1–10 Moodle UID,
личная привязка/подписка, согласованные UID/subject списки, новые события.
Отдельный controller принимает только проверенный a86 baseline; исходный журнал
пилота сохранён, disable и возврат к пилоту различаются, сбой возвращает прежний состав.
Код, сервер и состав получателей не изменены; план реализации ещё не написан.
Следующий шаг — review конкретного проекта владельцем, затем план реализации.
Принятый one-employee Tasks pilot ниже остаётся закрытым; UID130 последний раз revoked.
Последний docs commit8338515f: Context39612526373 / Review39612526329 SUCCESS.
0subagents; нет agent SSH/provider sends или изменений main.

**21Oct — one-employee Telegram Tasks pilot fully owner accepted.**
Owner exact a86 cache repair reports0755/content_changed=false; same frozen a86
activation completes sender_started,notification_pilot_active,pilot_userids=[130],
sender_active=true,linking_preserved=true,historical_replay=false,schema_upgraded=false.
Event cutoff21Oct14:56:57MSK is notify_since,not activation completion time.
[Owner receipt](context/runtime/connect_notification_pilot_owner_20261021.json). Do not repeat access repair or activation.
Owner screenshot confirms Telegram Tasks notification and native button, event12:10UTC.
Owner assigned a new task after revoke and observed no new Telegram message.
Readonly repeatable-read SQL now proves revoked1/linked0 while preferences enabled
and Tasks selected: full link-revoke negative test accepted,not merely opt-out.
Owner also confirms native button opens Academy/tasks and requires normal native
login without a session. One-employee Tasks pilot is accepted end to end.
Recipient was revoked at last readonly snapshot;personal same-Telegram re-link
can resume use without root reactivation. Do not repeat accepted pilot tests.
Next separate work:controlled multi-employee Telegram rollout release/plan,then
CON02 delivery/preferences/ACK and Bitrix decisions. Broad rollout not activated.
Source fix17261061:both PHP jobs and runtime SUCCESS;strict UID33 pilot7/0skips PASS.
[CI receipt](context/runtime/connect_notification_pilot_access_ci_20261021.json);Context39595204430/Review39595203810 SUCCESS,including native Connect DB.
Active worker/tool remain a86;new source fix is not deployed. CA/browser trust
remains a separate open item;no hostname change.0subagents/no agent provider sends.

**21Oct14:35MSK — notification activation refused; old receiver recovered.**
Owner resumed Telegram after DNS recovery; browser CA trust remains a separate open item.
Frozen a86/UID130 reaches receiver_switch; new probe cannot read receiver.pyz (EACCES).
Old receiver active/polling, pilot inactive, notify off. Reproduced CLI umask077
leaving release directory0700; safe mkdir0755 before publication fixes staging.
Nine targeted tests and full937r16/50environment skips PASS; strict new CI pending.
Owner recovery reuses exact a86 journal/package after verifying cached public-code
bytes/metadata and changing only its non-current release directory to0755.
[Incident and guarded command](context/runtime/connect_notification_pilot_access_20261021.md), [owner receipt](context/runtime/connect_notification_pilot_access_owner_20261021.json).
Live repair/new-task delivery/revoke pending; no repeated app/rights/TLS/egress setup.
0subagents/self-review; no agent SSH/provider sends; main unmerged.

**21Oct13:23MSK — server mDNS publication recovered; office browser acceptance next.**
Owner needs whole office LAN access without DHCP/router or per-device settings.
Avahi GetState1=REGISTERING after09:43MSK restart; new persistent publisher waited.
Only Avahi restarted;10:23:28UTC startup complete,10:23:29UTC same publisherPID
Established under name `ustar.local`,GetState2=RUNNING,
local resolve192.168.1.60. Root cause of the stuck state/restart remains unknown;
no proof DNS changed today or that prior alias was temporary. avahi-utils already
installed;unit `/etc/systemd/system/ustar-academy-mdns.service` enabled,no-reverse/no-fail.
[Receipt](context/runtime/academy_mdns_owner_20261021.json),[incident/runbook](context/runtime/academy_mdns_incident_20261021.md).
Server-side publication accepted,physical LAN/browser/VPN/boot stability not proven.
Next owner tests office Wi-Fi phone and PC without hosts,then resumes frozena86 pilot.
Do not repeat installs/rights/TLS or per-PC NRPT as whole-network solution;notify off.
Owner terminal evidence,no agent SSH/provider calls,0subagents.

**21Oct — notification pilot frozen and ready for owner activation.**
Sourcea86e2080:Context39573641861/Review39573641220 SUCCESS. Both PHP8.2/8.3:
936r16/152environment skips;actualUID33 pilot6tests/0skips;nativeMoodle127/633,
6concurrency,paired restores,real systemd/privateTLS separate fixtures PASS.
Artifact12333470816 `ustar-notify-pilot-a86e2080.pyz`,SHA d06b88dc081028ce860774fbbe290dadb1c7a55bef82bd1ae135de8a5bd1ad33;
CI bytes equal independent exact Git build. [Runbook](context/runtime/connect_notification_pilot_20261021.md)
and [CI receipt](context/runtime/connect_notification_pilot_ci_20261021.json).
Operator selects numeric Moodle UID of the already linked ordinary employee;
one-user canonical fence + separate worker TG subject,personal opt-in,new events only.
/start neutral,fixed native section button,no provider login/no raw private content.
One exact PHP overlay preserves version2000/schema/cron/HTTPS/token/cursor/link;
durable deactivate restores prior config/source/receiver,never business DB/journal.
Next owner --activate,then own Tasks opt-in/new assignment/Telegram/native access/revoke.
Server1 notify still off until owner execution;0subagents/self-review;main unmerged.

**21Oct — продолжение notification pilot после принятой привязки.**
Shared6ad87278 прочитан до owner «делай» и agent10:39:35MSK. Не повторять
права/установку/HTTPS/egress/bridge; обычный тестовый сотрудник уже связан.
[План и ledger](context/proposals/USTAR_CONNECT_NOTIFICATION_PILOT_PLAN_20261021.md).
/start теперь нейтрален; сообщение даёт фиксированную кнопку native Academy.
Canonical UID fence до enqueue/lease/begin + независимый worker TG-subject fence.
Отдельный notification-only256bit key,новая временная отсечка,один явный pilot UID,
личный opt-in;никакой массовой/исторической рассылки,MiniApp или provider-login.
Root transaction сохраняет link/token/cursor/HTTPS,один точный PHP overlay без
schema/version upgrade,managed credentials/probe/receiver handoff и durable recovery.
Локально936r16/50environment skips PASS;8package/transaction tests PASS.
Новый strict UID33/native Moodle/CI и frozen owner package ещё pending.
Server1/provider агентом не менялись,notify остаётся off;0subagents/self-review.

**21Oct — personal employee link accepted by owner.**
Academy shows linked, Telegram ID matches on ordinary test employee account.
Plain /start gives generic connection instructions: source receiver dispatch does
not query link state in link-only mode; it does not revoke the link. Earlier agent
request to use /start as link-status acceptance was misleading. No repeat linking
required. UX copy/status gap recorded; revoke test and notifications still pending.
Receipt: context/runtime/connect_activation_owner_20261021.json.0subagents.


**21Oct — owner egress recovery and bridge activation accepted.**
Refresh SHA4d922d29 OK:10candidates, node9TLS_VERIFIED, egress_refreshed.
Frozen27117c8c reached scope_verified; bridge_active,receiver_active=true,
linking_enabled=true,notifications_enabled=false; no app upgrade/business restore.
Receipt: context/runtime/connect_activation_owner_20261021.json.
Next: own /local/ustar/connect.php → Telegram claim → same-browser confirm → revoke.
Do not repeat parent/app/HTTPS/egress/bridge setup.0subagents; full user flow pending.


**21Oct — activation rolled back; Telegram egress recovery next.**
Owner probe transport_unavailable; recovery_state=inactive, old receiver active.
Root cause boundary is current VPN outbound connectivity; refresh helper prepared
with7local tests PASS, no live refresh yet. See context/runtime/connect_egress_failure_20261021.md.
Do not repeat rights/app/TLS preparation.0subagents.

**21Oct — parent preparation accepted; link activation package ready.**
Source27117c8c25b7dda28385c787f79e99636af10216: Review39530156361 and
Context39530156402 SUCCESS. Source PHP8.2/8.3, connect-runtime/connect-db PASS;
full RC/moodle-db/rollback jobs skipped. Activation12tests including actualUID33 pass.
Frozen artifact12315336864, PYZ SHA256
b7ef07134897d400903e0d0c7bc9bd35728cd51f65c47d0fe3c331b992cc2d9b.
Owner include_parent_prepared accepted; do not repeat parent/app/cron/TLS preparation.
Next owner --activate then personal Academy→Telegram→same-session confirm/unlink.
Notifications off; no live activation claimed.0subagents/author self-review.
Activation ledger: context/proposals/USTAR_CONNECT_ACTIVATION_PROGRESS_20261021.md.


**21Oct — права родителей приняты владельцем; реализация активации.**
Exact87fe3da7/SHA76562298:include_parent_prepared,оба UID0/GID33/0755,
без рекурсии/config changes/restart/key/bridge. Внешние /opt/ustar и /data
исправлены прямыми командами. Не повторять подготовку прав/upgrade/cron/HTTPS.
Продолжается coordinated activation installer, отдельный link.claim-only key,
managed include/credentials/receiver switch;[ledger](context/proposals/USTAR_CONNECT_ACTIVATION_PROGRESS_20261021.md).
Source/tests написаны;CI и live activation ещё pending.0subagents.

**21Oct — исправление всей измеренной цепочки родителей готово к применению.**
Owner frozen e583 отказал на `/opt/ustar/data/moodle`33:33/0775 после busy;
изменений не было. Повторять e583 бессмысленно. Новый frozen source87fe3da7,
PYZ SHA76562298c6e5b8ac17d485a8189dfb376fb32798021da46f9b85cb1551b05428,
artifact12315050039/run39528533688. Targeted strict UID33 gate16tests/0skips PASS;
полный run отменён последующим docs-only коммитом, whole CI SUCCESS не заявлен.
Explicit prepare обрабатывает `/moodle`33:33/0775→0:33/0755 и
`/moodle/public`33:33/0755→0:33/0755,без рекурсии/restart/config writes.
Все неизвестные ancestors проверяются до записи;inode-bound schema2 журнал,
ACL/partial prepare/restore guards. Файл передан владельцу; execution pending.
После include_parent_prepared продолжить coordinated activation installer,
а не новый diagnostic package;его реализация ещё не завершена.
Application2000/cron/backup/HTTPS приняты ранее и не переустанавливаются.0subagents.

**21Oct — owner UID33 refusal учтён; guarded parent preparation проверена.**
[Owner receipt](context/runtime/connect_private_bridge_parent_owner_20261021.json):frozen e4 SHA OK,
unsafe_include_parent `/opt/ustar/data/moodle/public`,33:33/0755,configuration_changed=false.
Это первый отказ:последующие transport/receiver/liveHTTPS checks этим запуском не приняты.
Новая explicit --prepare-include-parent сначала проверяет все остальные prerequisites и
root ancestors,затем меняет только UID этой папки33→0,сохраняя GID33/mode0755/xattrs/children.
Durable inode-bound before-image и guarded restore;никаких рекурсивных chown/chmod,
ключей,bridge activation,остановки/перезапуска служб или новой app/cron/backup/TLS установки.
Sourcee58354d3:Context39519644446/Review39519644489 SUCCESS;895/145skips на обоих PHP.
Runtime118960063858 и nativeMoodle124/629+6concurrency/paired restores PASS.
9real UID33 parent/restore/drift/rename-denial tests без skips PASS.
[Пакет и команды](context/runtime/connect_private_parent_prepare_20261021.md),
[CI receipt](context/runtime/connect_private_parent_prepare_ci_20261021.json):artifact12311131323,
PYZ e58354d3/SHA20038cba;CI bytes equal local exact Git build. Owner --prepare-include-parent pending.
Default PLAN_ONLY/--check остаются без изменения прав.0subagents/self-review.

**21Oct — private bridge source CI принят; следующий шаг — owner check.**
Source `ec9af9e4`:[CI receipt](context/runtime/connect_private_bridge_ci_20261021.json) — Context39516048080/Review39516048076 SUCCESS.
PHP8.2/8.3:886tests/136skips;native Moodle124/629+6concurrency,scope/no-nonce-write,
private include UID33 read/no-write,paired restore PASS. Apache TLS/public denial/
renewal/recovery и реальные systemd credentials PASS в отдельных fixtures.
Plugin-only include идёт после core bootstrap:иначе Moodle превращает true в строку"1".
Application1e8a8819/2000 не менялся;live bridge/key/receiver activation ещё не выполнены.
Следующий ограниченный шаг — [readonly check e4d7a154](context/runtime/connect_private_bridge_check_20261021.md),artifact12307432021,
SHA14de5484;owner JSON ещё не получен. Нужны данные о правах родителя private include,
не повторять update/cron/TLS/whole inventory. Затем coordinated root activation package.
0subagents/self-review;server1/provider/main агентом не менялись.

**21Oct — application2000 установлен; новый scheduled cron и browser smoke приняты.**
[Owner receipt](context/runtime/connect_chat_link_update_owner_20261021.json):exact6aa/e0d364ab SHA OK,
CHAT_LINK_UPDATE=PASS,upgrade_complete/public_released=true. Effective payload1e8a8819,
local2026102000/theme2026100202/552files/99tables следуют из fixed guarded installer.
Свежий scheduled cron20Oct22:08:01→22:08:03UTC:exit0,task log4775692→4775719,
adhoc0,new failures[],USTAR errors0. Overdue19 и2historical failed сохранены;
wrapper error17:33UTC — история. Owner:«завершилось академия открывается»;
это general browser smoke,не полный role/learning/link QA. ПаузаD/jbd2 при cold copy
завершилась успешно;disk failure не установлен. Не повторять update/backup prep/TLS/cron repair.
Дальше private TLS bridge/link-only key и receiver update с сохранением token/journal/cursor,
затем same-session link/revoke и отдельный opt-in notify. Bridge/bot activation pending.
Owner terminal evidence,не independent SSH;0subagents.

**21Oct — HTTPS backup preparation принята; следующий шаг application1905→2000.**
[Owner receipt](context/runtime/connect_backup_https_owner_20261021.json):pinned6aa/e0d364ab SHA OK,
backup_HTTPS_compatibility_prepared с exact producer a604fbcd и scheduler a97039a8;
backup/остановка/units/расписание не запускались и не менялись этим этапом.
Последующий --check:ready_for_chat_link_update,local1905→2000/theme0202,
достаточные bytes/inodes,activation=false. Повторять подготовку или старые probes не нужно.
Дальше тот же root-owned PYZ --upgrade --acknowledge-outage в окно обслуживания,
затем --status,реальный scheduled cron2000 и browser login/material acceptance.
Само application update/fresh backup/bridge/link/notify ещё не выполнено.
Это owner terminal evidence,не независимый SSH;точное время отчёта не предоставлено.

**21Oct — cron-only принят; source6aa83a95 проверен,следом HTTPS backup.**
[Owner receipt](context/runtime/connect_cron_contract_owner_20261021.json):exactc5eabdce/local1905,scheduled20Oct21:07:02Z,
18overdue/2historical failed сохранены. Source552/99/HTTPS заново не собирались.
CI6aa:Context39500936977/Review39500936916 SUCCESS;869r16/136CI skips наPHP8.2/8.3,
19privileged file/UID33 tests,actualTLS и native1905→2000/repeat/paired restore PASS.
Новый пакет фиксирован6aa/e0d364ab; [CI receipt](context/runtime/connect_chat_link_update_ci_20261021.json).
Следующий owner шаг:--prepare-backup-https,затем --check;без backup/остановки.
Application2000/bridge/link/notify ещё не приняты;installer02 не повторять.0subagents.

**20Oct21:31МСК — отказ preflight установлен:код cron0750.**
[Owner metadata](context/runtime/connect_delivery_owner_20261020.json):root-owned code0750,
SHA c5eabdce exact;private installation/last-run0600 и parents безопасны.
Ошибка reader private=True для кода,а не неисправность подтверждённого producer.
Исправление сохраняет root/no group-world write/hash/regular-single-link guards;
JSON остаётся private. Новый `--cron-only` не повторяет source/SQL/Docker/HTTPS.
19targeted/1local privileged skip и840r16/32skips PASS;точные bytes c5eabdce+0750
в read-only fixture PASS. Native cron-only receipt pending;новые CI ещё проверяются.
Полный baseline552/99 и HTTPS уже приняты;не менятьchmod/chown,не повторять
cron apply,старый installer или whole preflight.0subagents.

**20Oct21:25МСК — owner delivery preflight получен.**
[Квитанция](context/runtime/connect_delivery_owner_20261020.json):552/552 source match,
local1905/theme0202/core518/99tables,HTTPS owned hashes,loopback8082/gateway172.18.0.1,
free18443 и service states measured. Единственный refusal:cron unsafe_control_input,
точный файл/права не выведены. Fixture воспроизводит отказ при script0755 из-за
лишнего private=True;actual server mode/cause ещё не подтверждены. Дальше только
[metadata stat+SHA](context/runtime/connect_delivery_preflight_20261020.md);не повторять
полный preflight,cron apply или installer. Успешный source/DB snapshot принят;
bridge/link/notify ещё pending,private contents не запрашивать,0subagents.

**20Oct20:38МСК — cron восстановлен и принят владельцем.**
Preflight `4d70de5c` готов:835r16/135CI skips,14тестов preflight и настоящий UID33 на CI-host,
оба source PHP8.2/8.3 и runtime SUCCESS. Owner delivery check ожидается;поставка
chat-link/bridge ещё не применялась. Инструкция и exact hash по ссылке ниже.
Два настоящих scheduled-прохода завершились17:36:03Z и17:37:23Z:exit0,
без новых failures/USTAR errors. Task log4767930→4768022→4768051,adhoc2→0,
overdue107→18;2 прежних failed сохранены. Старый wrapper error17:33Z — история,
не текущий отказ. [Квитанция](context/runtime/cron_release_update_20261020.json).
Действующий cron v3 SHA c5eabdce/local1905;расписание и locks сохранены.
Не повторять cron apply/диагностику,HTTPS cutover или installer02b0548c.
Продолжается private bridge/scoped keys и поставка chat-link2000 с обязательным
согласованием HTTPS ownership/renewal и следующего точного cron-контракта.
Receiver/Academy current830 остаются;Mini App ждёт external HTTPS,0subagents.
Следующий ограниченный шаг — [проверка контрактов поставки](context/runtime/connect_delivery_preflight_20261020.md);
[ledger](context/proposals/USTAR_CONNECT_PRIVATE_DELIVERY_PROGRESS_20261020.md).
Ниже датированная история.

**20Oct19:59МСК — HTTPS принят владельцем; сначала инцидент cron.**
Owner check/cutover/status PASS: Academy `https://ustar.local`, новый leaf до
19Nov16:56:28UTC, компьютер/телефон/VPN открывают Академию. [Квитанция](context/runtime/connect_https_owner_20261020.json).
Повторный HTTPS cutover/установка не нужны. Owner сообщает cron alarm:
последний отчёт04:20МСК при свежей проверке19:59. Это не доказанное отключение
cron.service. [Путь данных и read-only команды](context/runtime/cron_incident_20261020.md).
Сначала wrapper --status, schedule/service и сравнение current DB task time;
причина и исправление ещё pending. Не запускать cron/install/rebind по догадке,
не удалять locks и не скрывать тревогу. После свежего scheduled success — private
bridge/scoped keys, отдельная поставка chat-link2000, двусторонний link/notify.
Prod addon830/1905 сохранён; Mini App ждёт external HTTPS; 0 subagents.

**20Oct — canonical HTTPS: CI завершён, команды владельцу готовы.**
Source `d1e61a3b` проверен: Context39459332144 и Review39459332116 SUCCESS.
PHP8.2/8.3: 811 r16 tests / 134 environment skips; local HTTPS tests 15/15 PASS.
Настоящий Apache/OpenSSL/PHP проверил proxy/HTTP302/renewal/recovery; отдельный
Moodle5.1.8/PostgreSQL16 gate подтвердил HTTPS config и Secure session cookie.
[CI-квитанция](context/runtime/connect_academy_https_ci_20261020.json),
[закреплённые команды check/cutover/status/rollback](context/runtime/connect_academy_https_20261020_commands.md),
[progress](context/proposals/USTAR_ACADEMY_HTTPS_CUTOVER_PROGRESS_20261020.md).
На server1 пока принят static TLS probe и phone LAN WiFi/VPN; canonical Academy
cutover и browser login/material acceptance ожидают владельца. Не повторять
probe/receiver/Academy installer или сбор старых фактов. После приёмки — private
bridge/scoped keys, отдельная поставка chat-link candidate2000 и opt-in notify;
Mini App ждёт внешнего HTTPS. Agent server1 не менял, main не объединён, 0 subagents.
Ниже сохранены предыдущие датированные checkpoints.

**20Oct19:01МСК: owner подтвердил phone+VPN по локальному WiFi.**
Static TLS browser gate принят для office WiFi/phone/VPN;не public/mobile-data acceptance.
Сертификат leaf до19Nov2026,canonical Academy пока HTTP. Не повторять probe install/
prepare,порты/CA проверки или browser gate. Следующий шаг — canonicalHTTPS+standard
Moodle sslproxy+постоянное продление сертификата с guard/recovery. В прежнем HTTP
vhost есть Include,его пути/правила ещё не прочитаны;перед proxy cutover нужен
только узкий read-only вывод ServerName/ProxyPass/Include изustar.conf.
[Owner receipt](context/runtime/connect_https_owner_20261020.json),
[следующий шаг](context/runtime/connect_https_tls_probe_20261020_commands.md).
Это подготовка существующей схемы,не новый auth/client subsystem;bridge/link/notify pending.
0subagents;server1 агентом не менялся.


**20Oct18:49МСК: static TLS probe применён владельцем.**
Pinned3303069b SHA PASS;check ready_for_TLS_probe,prepare tls_probe_ready.
CA/leaf fingerprints и expiry19Nov15:47:49UTC записаны в owner receipt.
Первый desktop screenshot показывает правильный /__ustar_tls_probe__/ready.html
без видимого cert warning. Второй /ustar_tls_probe/ready.html без double underscores
ожидаемо403;это не ошибкаTLS/Apache. Повторныйprepare/rollback не требуется.
Phone/VPN acceptance не установлена desktop screenshots. Canonical Academy ещё HTTP,
redirect false;далее phone trust и отдельный canonicalHTTPS+permanent renewal,
затем private bridge/link/notify. Это30day probe,не завершённая постояннаяTLS схема.
[Owner receipt](context/runtime/connect_https_owner_20261020.json).
0subagents;не independentSSH;prod effective addon830/1905 сохранён.


**20Oct18:43МСК: исправление первого TLS-хоста проверено.**
Source3303069b964cebab111e1fa2b03b0aed01a3ccab,tree8e2277f78fbc549608e92d899a051958bd21dfb8.
Owner подтвердил отсутствие443-vhosts и plainHTTP200/TLS SSLError на443.
Новый explicit --first-tls-vhost требует эту topology+live protocol,не снимает
старый guard по умолчанию. Это намеренный перевод443 с plainHTTP на TLS static
probe;80-vhosts/Academy config/CA/прочие службы сохранены,rollback возвращаетplain443.
Context39450474010 SUCCESS;Review39450473975 runtime118724086800 SUCCESS,
обе source jobs118724087534/118724087832 SUCCESS:796r16/134skips,381PHP/24JS/57templates/99tables,
browser60mobile/20onboarding/28link/100actor. Actual Apache оба режима PASS,
включая rollback и потерю reload ACK;artifact12278386111. Local796/31skips PASS.
[Команды](context/runtime/connect_https_tls_probe_20261020_commands.md),
[квитанция](context/runtime/connect_academy_tls_probe_ci_20261020.json).
Owner apply/browser trust ещё pending;не выдавать source CI за server acceptance.
Connect DB cumulative unchanged-source job118725551305 ещё running;не gate TLS.
0subagents;agent не применял изменений на server1. Старые диагностические остановки ниже закрыты.


**20Oct18:32МСК: причина TLS refusal установлена владельцем.**
Apache dump содержит только *:80 (default server1.local и ustar.local);443-vhosts нет.
Live loopback443:TLS SSLError,plain HTTP200. Новый explicit `--first-tls-vhost`
разрешает только этот случай:нет никаких443-vhosts,ssl_module загружен,живой
TLS handshake получает WRONG_VERSION_NUMBER и plain HEAD443 возвращает200.
Это намеренный перевод443 с plain HTTP на TLS static probe;не сохранение старого
plain443 default. Все80-vhosts/config/CA/службы сохраняются,rollback возвращает443
к прежнему plain HTTP. Старый wildcard-only режим по умолчанию сохранён.
Новый код/native CI ещё pending;старый52a8a1ca prepare не использовать.
0subagents;agent не подключался к server1.


**20Oct18:29МСК: owner --check отказал до применения.** Download hash PASS;
`wildcard_TLS_binding_required` означает отсутствие группы `*:443` в parsed dump.
Конкретные IP-vhosts и отсутствие443-vhosts пока не различены. Нельзя снимать guard
или запускать prepare по прежней команде. Нужен только filtered443 binding-type/
SSL-module report из текущего [runbook](context/runtime/connect_https_tls_probe_20261020_commands.md).
Apache/config/HTTP/сеть не менялись;rollback не требуется.0subagents.


**20Oct17:43МСК: narrow followup завершён.** Apache владеет80/443,пульт8443.
wwwroot — непрямое выражение;config hash закреплён,PHP/RHS не выполнялись.
Далее additive static TLS probe для `ustar.local`:отдельный30day leaf от console CA,
только health URL,config/HTTP не меняются,только Apache graceful reload с guards/recovery.
[План](context/proposals/USTAR_ACADEMY_TLS_PROBE_PLAN_20261020.md),[progress](context/proposals/USTAR_ACADEMY_TLS_PROBE_PROGRESS_20261020.md).
Source52a8a1ca:792CI/134skips на PHP8.2/8.3 PASS,21targeted local PASS.
Context39447539405 и native runtime118713514071 SUCCESS;SNI/default/HTTP/CA/recovery actual Apache PASS.
[Точные команды](context/runtime/connect_https_tls_probe_20261020_commands.md),[CI receipt](context/runtime/connect_academy_tls_probe_ci_20261020.json).
Это stage1,не canonical HTTPS cutover/renewal/private bridge/бот activation.
Повторных diagnostic loops,receiver/installer reinstall или сетевых изменений не требуется.
Production effective830/1905 сохранён;new link source2000 не установлен;0subagents.


**20Oct17:32МСК:** details получен:origin_expression,Academy Apache80/proxy8082/noTLS/Include;default Caddyfile безAcademy,CA/signing-key protected metadata present. Все listeners labels other из-за узкой allowlist,не доказательство владельца443. [Receipt](context/runtime/connect_https_owner_20261020.json),[короткий cgroup/wwwroot-feature read-only блок](context/runtime/connect_https_preparation_20261020.md). Не повторять прежние большие probe и не заменять динамический wwwroot/unknown443 по догадке. Config RHS/CA key не публиковались;TLS и сеть не менялись,0subagents.

**20Oct17:16МСК: HTTPS readiness получен.** [Owner receipt](context/runtime/connect_https_owner_20261020.json):Moodle loopback8082/running,Apache+Caddy active,80/443/8443 заняты,18443 свободен;origin parser unavailable без причины. Не считать это поломкой Академии и не занимать443 вслепую. Расширен тот же read-only probe `--check --details`:fixed reason,filtered listener owners,static Academy vhosts/Caddy hints и CA metadata без чтения ключа.12targeted tests PASS;новый source не установлен. Дальше точный details report,затем конкретная staged HTTPS процедура;не менять shared services/сеть и не обходить TLS guards. Подробнее [runbook](context/runtime/connect_https_preparation_20261020.md). Ниже проверенный chat-link source и датированная история.0subagents.

**20Oct, после owner04:39МСК: chat-link source проверен,далее HTTPS.** Владелец подтвердил «Академию проверил все открывается»;это general browser smoke,не полный role/learning QA. Подтверждён текущий `http://ustar.local`. [План](context/proposals/USTAR_CONNECT_CHAT_LINK_PLAN_20261020.md),[progress](context/proposals/USTAR_CONNECT_CHAT_LINK_PROGRESS_20261020.md),[точная CI-квитанция](context/runtime/connect_chat_link_ci_20261020.json),[HTTPS команда](context/runtime/connect_https_preparation_20261020.md). Source1e8a8819/candidate2026102000/552/99:private `/start uc1_...`,отдельный linking_enabled defaultOFF,link-only bridge key,второй owner/session confirm;Mini App не требуется. Context39361024810/Review39361024698 SUCCESS:766r16,124native/629assertions+6concurrency+paired93→99→93,28link-browser,actual systemd/SQL guards PASS. Native REDa40ea20f наблюдался по2новым assertions. HTTP guards не ослаблять,forwarded TLS не подделывать. Следующий серверный шаг — только read-only HTTPS metadata;не повторять принятый installer02 с payload830/1905. Production effective addon830/1905 сохраняется;new source не установлен,bridge/notify не включены. Main/сеть/receiver unchanged,0subagents.

**20Oct04:36МСК: owner выполнил установку Академии.** [Санитизированная квитанция](context/runtime/connect_academy_owner_install_20261020.json):installer02b0548c/archive53d9983b...,cold pair PASS,UPGRADE_REPEAT=PASS,CONNECT_ACADEMY_UPDATE=PASS,phase=upgrade_complete/public_released=true,systemd Finished. Это owner terminal report,не independent SSH. Effective addon payload830d3703/552files/local2026101905/theme2026100202/99tables следует из проверок pinned installer;old05Oct manifest/evidence остаются датированной историей,не текущей effective addon версией. Browser/login/theme/material QA владельцем ещё не получен. BOT_ACTIVATION=PENDING:private bridge/scoped keys/direct-chat account link/controlled notify — следующий этап;Mini App ждёт HTTPS. Не повторять installer,receiver или source RC. Code/config/data cold pair сохранить;после открытия новые записи нельзя молча откатывать. Main/сеть/receiver не менялись;0subagents.

**20Oct04:11МСК: operator installer02b0548c проверен.** Context39351249166/Review39351249243 SUCCESS;743r16/31local skips и134CI skips,отдельный actual UID33/root-group gate PASS. Native Connect121/611+6concurrency,93→99→93 и реальные SQL versions/tasks/columns/indexes PASS. [Точная квитанция](context/runtime/connect_academy_installer_ci_20261020.json),[точные команды и hash](context/runtime/connect_academy_install_02b0548c_commands.md),[процедура/recovery](context/runtime/connect_academy_install_20261020.md). Archive53d9983b.../17978054B,immutable Moodle payload830,только2addon,fresh HDD+cold code/config/data/physicalPG pair. До public-write boundary automatic recovery,после explicit discard consent. Prod/main/receiver/сеть не менялись;0subagents. Дальше owner execution/receipt и private bridge/direct-chat account link/controlled delivery. Установка не активирует бота;Mini App ждёт HTTPS. При получении owner результата не повторять source RC или receiver install.

**Актуально19Oct22:01МСК:** source RC830d3703 завершён:Context39305805813/FullRC39305805820 SUCCESS,все9jobs. Native Connect121tests/611assertions,6concurrency,93→99→93 paired recovery;обе Moodle suites288/1266 PASS. [Точная CI-квитанция](context/runtime/connect_academy_rc_ci_20261019.json),[комплект и следующий этап](context/runtime/connect_academy_rc_20261019.md). Архивa67a08d8.../552files — source RC,не production installer. Дальше guarded addon upgrade/recovery с fresh paired backup,private bridge и проверенная привязка/доставка. Текущий link использует Mini App;без public HTTPS direct-chat link ещё требует реализации. Не переоткрывать RC/receiver/preflight по прежним checkpoint ниже. Private report/его local commits исключены;main/prod/сеть не менялись.0agents. Ниже предыдущие датированные этапы.

**Текущий этап19Oct:** owner preflight получен приватно;не запрашивать повторно ради истории. Разрешена публикация кода/тестов без полного server report и содержащих его private commits. [План source RC и paired recovery](context/proposals/USTAR_CONNECT_ACADEMY_RELEASE_20261019.md), [progress](context/proposals/USTAR_CONNECT_ACADEMY_RELEASE_PROGRESS_20261019.md). Candidate552/99 остаётся Git/fixture reference,не deployment claim. Новый native paired/full-RC pending;Mini App ждёт HTTPS.0subagents.

**Owner19Oct18:59МСК:** первый запуск @ustarcorp_bot подтверждён текстом «окей работает». Receiver-only этап принят владельцем; скриншоты не прочитаны, точные PID/uptime/ответ не выдумывать. Следом — [подготовка подключения Академии](context/proposals/USTAR_CONNECT_ACADEMY_PREPARATION_20261019.md): read-only preflight текущих файлов/версий/схемы и затем отдельный выпуск candidate552/99, private bridge/links/уведомления. Mini App ждёт HTTPS; native handoff/step-up/role screens остаются отдельными gates.0subagents.


**Owner19Oct18:53МСК:** install/configure и read-only Telegram identity probe подтверждены на server1. Journal: telegram_verified/ustarcorp_bot, service Deactivated successfully. Ошибка admin start — ложный отказ после GC успешного oneshot, не отказ Telegram. Исправлен synchronous probe + проверка exact managed units/private conditions;15deploy/695r16 PASS(30skips), новый actual-host CLI gate pending. Владелец может выполнить systemctl enable --now ustar-connect-receiver.service после подтверждённого probe; service status и личный /start ACK ещё ожидаются. [Квитанция](context/runtime/connect_receiver_server1_20261019.json).0subagents.


**Owner19Oct18:41МСК:** server1 install b01bd942 подтверждён, службы не запущены. configure упал до запроса токена: дефект open('/dev/tty','r+') воспроизведён и исправлен на write-only output stream.11deploy/691r16 PASS(30environment skips), реальный PTY/ECHO test RED→GREEN. Использовать [исправленную настройку](context/runtime/connect_configure_tty_recovery_20261019.md); не переустанавливать пакет и не править immutable manifest. Настройка/start/live ACK ещё ожидаются. Субагенты не использовались.


Receiver-only комплект sourceb01bd942 проверен actual Ubuntu host job118145618545; archive12197369243 скачан и сверен с Git. [Команды установки](context/runtime/connect_receiver_delivery_20261019.md), [точная квитанция](context/runtime/connect_receiver_release_20261019.json). Следующее действие — owner install/configure/start и личный /start ACK; server1 ещё не установлен агентом. Общий PR native Moodle regression queued, whole PR green не заявлен; standalone пакет Академию не мигрирует.

**Текущий приоритет owner19Oct17:44МСК:** Mini App откладывается до HTTPS, token business bot уже перевыпущен. Подготовить receiver-only комплект и systemd-службы, затем запуск владельцем. Последний agent остановился перед реализацией. C4 завершён наfd9eea80 (Context39259305496/Review39259305507 SUCCESS,121native/611assertions); не переоткрывать C4 или начинать native handoff вместо этого поручения. [План первого запуска](context/proposals/USTAR_CONNECT_RUNTIME_PLAN_20261019.md). Новая поставка не мигрирует Академию: onboarding=false, /start,/academy,/help сообщают о неподключённом Mini App; notify подготовлен, выключен. Субагенты запрещены. Token только private tty на server1, не запрашивать в чате.


**C4,19Oct:** реализованы отдельная provider-read Connect-сессия и собственный профиль Mini App, candidate2026101905/552files/99tables. Fresh linked-active proof, явная activation, idle15min/absolute8h, current user/link/password/role/org guards, native MFA/SSO/forced-password block; defaultOFF. Проверки14WSGI/full675r16/30local skips PASS; native REDa06ed50e118tests/12expected new errors и browser RED26429064 подтверждены. Exact итоговые native/browser CI и commit/tree — в актуальной квитанции [PR106](https://github.com/cauf1l3d/ustarlms/pull/106); не переносить квитанцию C3 на новый код. Продакшен/main/сеть не менялись. Далее full native handoff/recovery/MFA/step-up и role actions, затем external HTTPS/runtime acceptance.


**Точка продолжения19Oct15:31МСК:** C3 завершён на941a853cbfd03eaefd691fdbdf413607d9a92aba: Context39251446669/Review39251446110 SUCCESS;106native/530assertions,5concurrency/schema parity,661r16/133CI skips,24link/20onboarding/60mobile PASS. Не переоткрывать C1/C2/C3 по датированным записям ниже. Текущий C4 — отдельная provider-read Connect-сессия и собственный реальный профиль в Mini App; [план](context/proposals/USTAR_CONNECT_CON01C4_PLAN_20261019.md), [ledger](context/proposals/USTAR_CONNECT_CON01C4_PROGRESS_20261019.md). Full Moodle handoff/recovery/MFA/step-up/role actions остаются отдельными этапами. Native MFA/forced password/alternate login блокируют provider-read; default activation OFF. Новая реализация и native/browser CI ещё pending. Продакшен e1d57f5b/526files/93tables и main не менялись.0subagents/no live provider/network operations.


**Точка продолжения19Oct12:55МСК:** CON01C.2: управление собственными messenger links в native Moodle и signed uc1_ claim в Mini App. Личный кабинет → Мессенджеры → персональная ссылка → Telegram → второй Academy confirm в той же сессии; owner-only revoke/list. RAM receipt не onboarding/employee grant; timeout uncertain без автоповтора. Candidate2026101903/546files/99tables, production e1d57f5b/526/93 unchanged. 8new WSGI tests,31web/full647r16 OK/30environment skips. Native RED d9114daa/39234234916: exactly6missing-class errors,78previous tests pass. Browser RED2c971f93/39235237752: missing confirmation screen; prior20onboarding/60mobile pass. Final new native/browser CI receipt — PR106; читать exact final receipt перед продолжением, не переоткрывать C2 по старой pre-CI записи. Новый business bot @ustarcorp_bot, секрет из чата не использован/не сохранён, требуется перевыпуск владельцем. CrazeDNS em2025.netcraze.pro предложен; rule/cert/availability ещё не приняты. Full canonical remote login/recovery/MFA/actor step-up, canonical notification leases/delivery/preferences/ACK и role screens — остаток.0subagents/no main merge/no prod/router/network changes.

[План C2](context/proposals/USTAR_CONNECT_CON01C2_PLAN_20261019.md), [ledger](context/proposals/USTAR_CONNECT_CON01C2_PROGRESS_20261019.md). Субагенты запрещены владельцем; будущие запуски только после согласования. Существующий отдельный feature checkout продолжает PR106, не main. Journal/heartbeat и platform upgrades deferred.

## Перед работой

Читать [START_HERE](START_HERE.md), [project](context/project.yaml), [индекс](context/CONTEXT_INDEX.md), [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md), [сверку аудита](context/audits/AUDIT_RECONCILIATION_20261016_RU.md), затем relevant architecture/decisions/domains/runtime/code_map и [протокол](context/roadmap/AGENT_PROTOCOL.md).

`main` содержит канонический код и контекст. Новые ветки от свежего main; PR в main. [Старые ветки](context/runtime/repository_state_20261016.json) сохраняют историю. Различать exact application baseline, repository head, core/runtime и факт deployment.

## Область продукта и тестов

Рабочий UI: `moodle/local/ustar`, `moodle/theme/ustar` (theme_ustar, parent boost). `frontend/` — legacy, исключён из активного CI и release manifests/archives. Не обновлять его зависимости ради текущего gate. JS/AMD, Mustache, PHP и browser tests native UI сохраняются. Будущий server-console — отдельный operations UI, не возобновление legacy Next.js.

Не повторять принятые действия: SSD/GPT/ext4, Moodle5.1.8 upgrade/repeat/browser, AppArmor repair, r6 cold archive, host install/reboot. Точные границы — в STATE/аудите. Новая копия сисадмина ещё не принята; нет основания объявлять полный DR завершённым.

## Инварианты

- Сохранять границы core/plugin/theme и одну модель сотрудников, completion/evidence, кошелька и сообщений. View-as и строка должности не дают прав записи.
- Любой write проверяет реального actor, capability, current scope, sesskey/API contract, revision, locks и idempotency. Не исправлять реальные роли/историю/персональные данные по предположению.
- XMLDB install/upgrade parity и release gate обязательны при schema changes. Не править исторические release-копии вместо `moodle/`.
- Не публиковать config.php, токены, dumps, moodledata, private logs или ключи. Не запускать PHP/Moodle probe на проде до проверки его write impact.
- Исходники, ADR, CI и owner receipts отвечают на разные вопросы. CI с modeled Docker/SQL не доказывает native restore. Не объявлять missing evidence багом.
- Обновлять STATE/ACTIVE/BACKLOG/SESSION_LOG и generated inventories при поставке. Датированные оригинальные отчёты и аудит05/06Oct сохранять.
- Уже данное пользователем разрешение действует в пределах задачи. Документы не являются причиной повторно запрашивать подтверждение. GitHub cleanup не разрешает остановку Академии или отправку бэкапа/ключа в Telegram.

## Следующая задача

[INTEGRATIONS-01](context/proposals/USTAR_CONNECT_RFC_20261018.md): один business contract и текущие Academy permissions. registration_service/staffing_requests/target_core остаются canonical writers; B3 signup, B4 BFF/form и C1 receiver не переделывать с нуля. Далее canonical login/recovery/link и независимый actor+step-up для HRD commands; затем canonical outbox leases/delivery/preferences/ACK. Выбор external HTTPS relay/domain и live provider/runtime acceptance отдельный deployment этап. Server-console accounts/token не являются Academy auth, entry token не Moodle login/employee capability. Legacy handler source не получен, не выполнять ради аудита. Субагенты запрещены.
