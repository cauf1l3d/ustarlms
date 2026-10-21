# USTAR вход в проект

**21Oct — владелец поручил CON-02; source review и первый план готовы.**
[Границы и очередь CON-02](context/proposals/USTAR_CONNECT_CON02_SCOPE_20261021.md), [план A.1: личное подтверждение](context/proposals/USTAR_CONNECT_CON02_ACK_PLAN_20261021.md).
Уже есть canonical inbox/delivery, Telegram lease/begin/finish и quiet/preferences;
ACK writer/ackat тоже существуют, но нет защищённого личного flow и общего routing.
Сначала own native ACK с сохранением первого времени/read race safety; затем
отдельные Telegram callback, общие preferences и Bitrix adapter. Это планирование,
не реализованный новый API/код/deployment. Для Bitrix actual portal/API ещё неизвестны.
Группа1–10 остаётся design-only; live приёмка deferred до полного запуска.
Принятый one-user Tasks pilot не повторять, worker/tool на сервере остаётся a86.
Последний headb7d3e93a:Context39622043988/Review39622044309 SUCCESS, не новый CON02 CI.
Следующий шаг — review конкретного плана, затем самостоятельная реализация без субагентов.

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


**20Oct17:32МСК:** details уже получен:Apache Academy80/noTLS/proxy8082/Include,
origin_expression,default Caddyfile безAcademy,CA/signer protected metadata present.
`other` labels не идентифицируют owner443. [Следующий узкий cgroup/wwwroot-feature
блок](context/runtime/connect_https_preparation_20261020.md) без PHP/config output;
не повторять прежние большие probe. [Receipt](context/runtime/connect_https_owner_20261020.json).
HTTPS/сеть/Academy не менялись.

**20Oct17:16МСК:** owner выполнил HTTPS probe;[квитанция](context/runtime/connect_https_owner_20261020.json).
Moodle loopback8082,Apache/Caddy active,443/8443 заняты;origin parser unavailable,
не outage. Следом расширенный `--check --details` того же probe для владельцев
listeners/static Academy vhosts/причины parse failure. [Команды](context/runtime/connect_https_preparation_20261020.md).
Никаких TLS/service/network изменений ещё нет;chat source1e8a8819 не установлен.

**20Oct, следующий этап после принятого browser smoke04:39МСК.** Owner подтвердил работу Академии и текущий `http://ustar.local`. [Chat-link progress](context/proposals/USTAR_CONNECT_CHAT_LINK_PROGRESS_20261020.md),[CI](context/runtime/connect_chat_link_ci_20261020.json),[HTTPS команда](context/runtime/connect_https_preparation_20261020.md). Source1e8a8819/2026102000/552/99 добавляет private-chat claim без Mini App,с прежним вторым Academy confirm и defaultOFF. Context39361024810/Review39361024698 SUCCESS:766r16,124native/629assertions+6concurrency+paired93→99→93,28link-browser и actual host gates PASS. HTTP блокирует безопасную активацию. Далее read-only HTTPS readiness и отдельная staged процедура,без изменения текущего HTTP/сети. Effective production payload830/1905 остаётся;новый source не установлен,installer/receiver не повторять,0subagents. Ниже датированная история.

**20Oct04:36МСК: Академия обновлена владельцем через installer02b0548c.** [Owner receipt](context/runtime/connect_academy_owner_install_20261020.json):cold pair,upgrade/repeat PASS;upgrade_complete/public_released=true,systemd Finished. Effective payload830/552/99,local2026101905/theme2026100202 — по contract pinned installer,не independent probe. Browser QA ещё pending. BOT_ACTIVATION=PENDING:далее private bridge/direct-chat link/controlled notify;Mini App ждёт HTTPS. Installer/receiver повторно не запускать;main/сеть не менялись,0subagents.

**20Oct04:11МСК: installer02b0548c готов.** [Точные команды](context/runtime/connect_academy_install_02b0548c_commands.md),[квитанция](context/runtime/connect_academy_installer_ci_20261020.json),[recovery](context/runtime/connect_academy_install_20261020.md). Context39351249166/Review39351249243 SUCCESS;743r16,actual UID33/root-group,native121/611+6concurrency+paired93→99→93 и installer SQL/schema guards PASS. Archive53d9983b.../17978054B,фиксированный payload830. Production не обновлён;следом owner execution/receipt,private bridge/direct-chat link/delivery. Бот/сеть не активируются этим installer;Mini App ждёт HTTPS.0subagents.

**Актуально19Oct22:01МСК:** source RC830d3703 проверен полным CI39305805820,9jobs SUCCESS;Context39305805813 SUCCESS. [Квитанция](context/runtime/connect_academy_rc_ci_20261019.json),[комплект/следующий этап](context/runtime/connect_academy_rc_20261019.md). Native paired recovery93→99→93 и source/data/config restoration подтверждены в изолированном fixture;обе Moodle suites288/1266 PASS. Следом guarded addon upgrade/recovery и account-link/delivery,не повторный collector или receiver install. Текущий link зависит от Mini App;direct chat до HTTPS ещё нужно реализовать. Source RC не объявлен установленным. Full private report/локальная история с ним не опубликованы. Ниже предыдущие checkpoints.

**Текущий этап19Oct:** preflight получен приватно;повторный сбор не требуется. Публикуются код/тесты и технический план без полного server report и private commits. [Source RC и paired recovery](context/proposals/USTAR_CONNECT_ACADEMY_RELEASE_20261019.md);новая native CI pending. Candidate552/99 не объявлен установленным;Mini App ждёт HTTPS. Ниже датированная история.0subagents.

**Owner19Oct18:59МСК:** первый запуск @ustarcorp_bot подтверждён текстом «окей работает». Receiver-only этап принят владельцем; скриншоты не прочитаны, точные PID/uptime/ответ не выдумывать. Следом — [подготовка подключения Академии](context/proposals/USTAR_CONNECT_ACADEMY_PREPARATION_20261019.md): read-only preflight текущих файлов/версий/схемы и затем отдельный выпуск candidate552/99, private bridge/links/уведомления. Mini App ждёт HTTPS; native handoff/step-up/role screens остаются отдельными gates.0subagents.


**Owner19Oct18:53МСК:** install/configure и read-only Telegram identity probe подтверждены на server1. Journal: telegram_verified/ustarcorp_bot, service Deactivated successfully. Ошибка admin start — ложный отказ после GC успешного oneshot, не отказ Telegram. Исправлен synchronous probe + проверка exact managed units/private conditions;15deploy/695r16 PASS(30skips), новый actual-host CLI gate pending. Владелец может выполнить systemctl enable --now ustar-connect-receiver.service после подтверждённого probe; service status и личный /start ACK ещё ожидаются. [Квитанция](context/runtime/connect_receiver_server1_20261019.json).0subagents.


**Owner19Oct18:41МСК:** server1 install b01bd942 подтверждён, службы не запущены. configure упал до запроса токена: дефект open('/dev/tty','r+') воспроизведён и исправлен на write-only output stream.11deploy/691r16 PASS(30environment skips), реальный PTY/ECHO test RED→GREEN. Использовать [исправленную настройку](context/runtime/connect_configure_tty_recovery_20261019.md); не переустанавливать пакет и не править immutable manifest. Настройка/start/live ACK ещё ожидаются. Субагенты не использовались.


Receiver-only комплект sourceb01bd942 проверен actual Ubuntu host job118145618545; archive12197369243 скачан и сверен с Git. [Команды установки](context/runtime/connect_receiver_delivery_20261019.md), [точная квитанция](context/runtime/connect_receiver_release_20261019.json). Следующее действие — owner install/configure/start и личный /start ACK; server1 ещё не установлен агентом. Общий PR native Moodle regression queued, whole PR green не заявлен; standalone пакет Академию не мигрирует.

**Текущий приоритет owner19Oct17:44МСК:** Mini App откладывается до HTTPS, token business bot уже перевыпущен. Подготовить receiver-only комплект и systemd-службы, затем запуск владельцем. Последний agent остановился перед реализацией. C4 завершён наfd9eea80 (Context39259305496/Review39259305507 SUCCESS,121native/611assertions); не переоткрывать C4 или начинать native handoff вместо этого поручения. [План первого запуска](context/proposals/USTAR_CONNECT_RUNTIME_PLAN_20261019.md). Новая поставка не мигрирует Академию: onboarding=false, /start,/academy,/help сообщают о неподключённом Mini App; notify подготовлен, выключен. Субагенты запрещены. Token только private tty на server1, не запрашивать в чате.


**C4,19Oct:** реализованы отдельная provider-read Connect-сессия и собственный профиль Mini App, candidate2026101905/552files/99tables. Fresh linked-active proof, явная activation, idle15min/absolute8h, current user/link/password/role/org guards, native MFA/SSO/forced-password block; defaultOFF. Проверки14WSGI/full675r16/30local skips PASS; native REDa06ed50e118tests/12expected new errors и browser RED26429064 подтверждены. Exact итоговые native/browser CI и commit/tree — в актуальной квитанции [PR106](https://github.com/cauf1l3d/ustarlms/pull/106); не переносить квитанцию C3 на новый код. Продакшен/main/сеть не менялись. Далее full native handoff/recovery/MFA/step-up и role actions, затем external HTTPS/runtime acceptance.


**Точка продолжения19Oct15:31МСК:** C3 завершён на941a853cbfd03eaefd691fdbdf413607d9a92aba: Context39251446669/Review39251446110 SUCCESS;106native/530assertions,5concurrency/schema parity,661r16/133CI skips,24link/20onboarding/60mobile PASS. Не переоткрывать C1/C2/C3 по датированным записям ниже. Текущий C4 — отдельная provider-read Connect-сессия и собственный реальный профиль в Mini App; [план](context/proposals/USTAR_CONNECT_CON01C4_PLAN_20261019.md), [ledger](context/proposals/USTAR_CONNECT_CON01C4_PROGRESS_20261019.md). Full Moodle handoff/recovery/MFA/step-up/role actions остаются отдельными этапами. Native MFA/forced password/alternate login блокируют provider-read; default activation OFF. Новая реализация и native/browser CI ещё pending. Продакшен e1d57f5b/526files/93tables и main не менялись.0subagents/no live provider/network operations.


## Текущий этап 19 октября 2026

Обновление19Oct12:55МСК: CON01C.2: управление собственными messenger links в native Moodle и signed uc1_ claim в Mini App. Личный кабинет → Мессенджеры → персональная ссылка → Telegram → второй Academy confirm в той же сессии; owner-only revoke/list. RAM receipt не onboarding/employee grant; timeout uncertain без автоповтора. Candidate2026101903/546files/99tables, production e1d57f5b/526/93 unchanged. 8new WSGI tests,31web/full647r16 OK/30environment skips. Native RED d9114daa/39234234916: exactly6missing-class errors,78previous tests pass. Browser RED2c971f93/39235237752: missing confirmation screen; prior20onboarding/60mobile pass. Final new native/browser CI receipt — PR106; читать exact final receipt перед продолжением, не переоткрывать C2 по старой pre-CI записи. Новый business bot @ustarcorp_bot, секрет из чата не использован/не сохранён, требуется перевыпуск владельцем. CrazeDNS em2025.netcraze.pro предложен; rule/cert/availability ещё не приняты. Full canonical remote login/recovery/MFA/actor step-up, canonical notification leases/delivery/preferences/ACK и role screens — остаток.0subagents/no main merge/no prod/router/network changes.

[План C2](context/proposals/USTAR_CONNECT_CON01C2_PLAN_20261019.md), [ledger](context/proposals/USTAR_CONNECT_CON01C2_PROGRESS_20261019.md). Ниже сохранены датированные предыдущие checkpoints.

Обновление10:33МСК: **CON01C.1 — Telegram polling receiver**, [план](context/proposals/USTAR_CONNECT_CON01C1_PLAN_20261019.md)/[ledger](context/proposals/USTAR_CONNECT_CON01C1_PROGRESS_20261019.md). Fixed TLS API, private commands, durable SQLite cursor/dedup/uncertainty и персональный startapp, runtime opt-in.41receiver tests/local639r16/30skips PASS; exact CI receipt PR106. B4 **завершён** на26219dac: Context39214124458/Review39214124509 SUCCESS,20browser/78native399/schema+concurrency PASS. Ниже предыдущие датированные checkpoints. Main/server1/network/пульт не менялись,0subagents. Следом login/recovery/link/step-up и canonical delivery; выбранный external HTTPS relay и реальный API ACK ещё нужны до активации.

Обновление09:55МСК: владелец попросил остаток этапов и следующую реализацию. **CON01B.3 проверен**: d4f7c1bd, Context39192666105/Review39192666066 SUCCESS,78native tests/399assertions + schema/concurrency PASS. Текущий **CON01B.4** — приватный browser BFF и мобильная форма регистрации: [план](context/proposals/USTAR_CONNECT_CON01B4_PLAN_20261019.md), [ledger](context/proposals/USTAR_CONNECT_CON01B4_PROGRESS_20261019.md). Без нового HR store/schema, без public listener/реального бота/установки. Local598r16/30skips PASS, exact final CI receipt — PR106. Внешний HTTPS relay, canonical login/step-up, delivery/receiver и ролевой клиент ещё впереди; далее Bitrix/WhatsApp. Предыдущие строки ниже — checkpoints, не повод переоткрывать закрытые native gates.

Приоритет владельца01:28МСК — **INTEGRATIONS-01**, продолжение05:30 — **CON01B.3: canonical signup/pending/first link**, [PR106/RFC](context/proposals/USTAR_CONNECT_RFC_20261018.md), [контракты](context/proposals/USTAR_CONNECT_CONTRACTS_20261019.md). В00:41 владелец подтвердил учётные записи пульта и личную Telegram-привязку; журнал всё ещё показывает прежнее сообщение. Журнал и внешний heartbeat явно отложены. [Датированный receipt](context/runtime/connect_owner_priority_20261019.json).

Рабочий продукт — Moodle + `local_ustar` + `theme_ustar`, parent boost. `frontend/` — legacy Next.js, исключённый из активного CI/выпуска. Проверки native UI темы и плагина сохранены.

- **Сделано на сервере:** SSD расширен11Oct; Moodle5.1.8 upgrade/repeat и краткая браузерная/SCORM приёмка13Oct; AppArmor,63 пакета, reboot/kernel147/Docker29.9.0 приняты14Oct. Не повторять.
- **Ожидание:** владелец16Oct подтвердил получение архива и передачу инструкции сисадмину. Отдельная копия, её вход/SCORM/reboot и полный host DR ещё не приняты.
- **Текущая задача:** проектирование USTAR Connect: Academy identity/capabilities, Telegram/Bitrix24/WhatsApp adapters, Mini Apps и внешний HTTPS edge без открытия офисных портов. Review регистрации и CRM=задачи выполнен; реализованы Python primitives и внутренний PHP link/challenge/proof service с candidate schema; публичный registration API отсутствует; INF-06 остаток отложен, INF-07 HTTPS Академии сохраняется отдельным gate полного LMS.
- **Отложено:** Moodle5.2 и PostgreSQL17. Прежняя подготовка5.3/PR101 сохранена как история, не следующий запуск.

[Review и первый вход](context/proposals/USTAR_CONNECT_REVIEW_20261019.md), [план CON01B.3](context/proposals/USTAR_CONNECT_CON01B3_PLAN_20261019.md). Base81a1be76 bridge/entry native22tests/87assertions PASS. Candidate теперь добавляет atomic canonical signup+receipt и собственный status, переиспользует HRD review/notifications. Endpoint выключен; внешние формы, actor/step-up remote HRD commands и receiver ACK ещё впереди. Exact final CI receipt — в PR106, не production acceptance.

## Порядок чтения

1. [project](context/project.yaml), [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md).
2. [Сверка выполненного и остатка с аудитом](context/audits/AUDIT_RECONCILIATION_20261016_RU.md), [BACKLOG](context/roadmap/BACKLOG.yaml).
3. [Архитектура](context/architecture/overview.md), [границы](context/architecture/boundaries.md), [ADR](context/decisions/README.md), [domain maps](context/CONTEXT_INDEX.md).
4. [Runtime](context/runtime/README.md), [карта кода](context/code_map/README.md), [протокол](context/roadmap/AGENT_PROTOCOL.md).

## Источники истины

`main` — код и текущий контекст. Точный plugin/theme baseline PR76/e1d57f5b,526 файлов, local2026100204/theme2026100202 сохранён; это не номер нового core и не текущий repository head. [Датированные runtime evidence](context/runtime/evidence_20261016.yaml) отделяют версии/установку от статического source и CI.

[Ветки16Oct](context/runtime/repository_state_20261016.json) — история консолидации. Текущие unmerged работы: PR103, рабочая поставка пульта sourcee5b87818/contextde4544d9; PR105, датированные server facts02fd394f; PR106, текущий Connect architecture + offline foundation. Их актуальные границы/точные refs — в новом receipt. Старые release/integration/prod-sync branches не использовать как базу. Не считать PR103 только прототипом и не считать unmerged work уже частью main.

[Инструкция сисадмину](context/runtime/production_copy_20261015.md) использует существующий HDD-архив и не останавливает прод. Не путать `--pack`, `--pack-production`, постоянную parallel copy и временный lab. Бэкап с ключом нельзя помещать в Git/чат/Telegram.

Нет прямого подключения агента к server1. После сверки репозитория не запускать native update/capture/reboot/restore без самостоятельного основания. [CI scope](context/runtime/CI_SCOPE_20261016.md) описывает реально выполняемые и отдельные опциональные проверки.

Candidate2026101902/542source files/99tables хранится в [отдельном manifest](context/code_map/development_source.json). Это unmerged разработка, не новый production receipt. Accepted source inventory526/93 остаётся датированным baseline.
