# Индекс текущего контекста

**20Oct — canonical HTTPS: CI завершён, команды владельцу готовы.**
Source `d1e61a3b` проверен: Context39459332144 и Review39459332116 SUCCESS.
PHP8.2/8.3: 811 r16 tests / 134 environment skips; local HTTPS tests 15/15 PASS.
Настоящий Apache/OpenSSL/PHP проверил proxy/HTTP302/renewal/recovery; отдельный
Moodle5.1.8/PostgreSQL16 gate подтвердил HTTPS config и Secure session cookie.
[CI-квитанция](runtime/connect_academy_https_ci_20261020.json),
[закреплённые команды check/cutover/status/rollback](runtime/connect_academy_https_20261020_commands.md),
[progress](proposals/USTAR_ACADEMY_HTTPS_CUTOVER_PROGRESS_20261020.md).
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
[Owner receipt](runtime/connect_https_owner_20261020.json),
[следующий шаг](runtime/connect_https_tls_probe_20261020_commands.md).
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
[Owner receipt](runtime/connect_https_owner_20261020.json).
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
[Команды](runtime/connect_https_tls_probe_20261020_commands.md),
[квитанция](runtime/connect_academy_tls_probe_ci_20261020.json).
Owner apply/browser trust ещё pending;не выдавать source CI за server acceptance.
Connect DB cumulative unchanged-source job118725551305 ещё running;не gate TLS.
0subagents;agent не применял изменений на server1. Старые диагностические остановки ниже закрыты.


**Owner19Oct18:59МСК:** первый запуск @ustarcorp_bot подтверждён текстом «окей работает». Receiver-only этап принят владельцем; скриншоты не прочитаны, точные PID/uptime/ответ не выдумывать. Следом — [подготовка подключения Академии](proposals/USTAR_CONNECT_ACADEMY_PREPARATION_20261019.md): read-only preflight текущих файлов/версий/схемы и затем отдельный выпуск candidate552/99, private bridge/links/уведомления. Mini App ждёт HTTPS; native handoff/step-up/role screens остаются отдельными gates.0subagents.


**Owner19Oct18:53МСК:** install/configure и read-only Telegram identity probe подтверждены на server1. Journal: telegram_verified/ustarcorp_bot, service Deactivated successfully. Ошибка admin start — ложный отказ после GC успешного oneshot, не отказ Telegram. Исправлен synchronous probe + проверка exact managed units/private conditions;15deploy/695r16 PASS(30skips), новый actual-host CLI gate pending. Владелец может выполнить systemctl enable --now ustar-connect-receiver.service после подтверждённого probe; service status и личный /start ACK ещё ожидаются. [Квитанция](runtime/connect_receiver_server1_20261019.json).0subagents.


**Owner19Oct18:41МСК:** server1 install b01bd942 подтверждён, службы не запущены. configure упал до запроса токена: дефект open('/dev/tty','r+') воспроизведён и исправлен на write-only output stream.11deploy/691r16 PASS(30environment skips), реальный PTY/ECHO test RED→GREEN. Использовать [исправленную настройку](runtime/connect_configure_tty_recovery_20261019.md); не переустанавливать пакет и не править immutable manifest. Настройка/start/live ACK ещё ожидаются. Субагенты не использовались.


**C4,19Oct:** реализованы отдельная provider-read Connect-сессия и собственный профиль Mini App, candidate2026101905/552files/99tables. Fresh linked-active proof, явная activation, idle15min/absolute8h, current user/link/password/role/org guards, native MFA/SSO/forced-password block; defaultOFF. Проверки14WSGI/full675r16/30local skips PASS; native REDa06ed50e118tests/12expected new errors и browser RED26429064 подтверждены. Exact итоговые native/browser CI и commit/tree — в актуальной квитанции [PR106](https://github.com/cauf1l3d/ustarlms/pull/106); не переносить квитанцию C3 на новый код. Продакшен/main/сеть не менялись. Далее full native handoff/recovery/MFA/step-up и role actions, затем external HTTPS/runtime acceptance.


**Точка продолжения19Oct15:31МСК:** C3 завершён на941a853cbfd03eaefd691fdbdf413607d9a92aba: Context39251446669/Review39251446110 SUCCESS;106native/530assertions,5concurrency/schema parity,661r16/133CI skips,24link/20onboarding/60mobile PASS. Не переоткрывать C1/C2/C3 по датированным записям ниже. Текущий C4 — отдельная provider-read Connect-сессия и собственный реальный профиль в Mini App; [план](proposals/USTAR_CONNECT_CON01C4_PLAN_20261019.md), [ledger](proposals/USTAR_CONNECT_CON01C4_PROGRESS_20261019.md). Full Moodle handoff/recovery/MFA/step-up/role actions остаются отдельными этапами. Native MFA/forced password/alternate login блокируют provider-read; default activation OFF. Новая реализация и native/browser CI ещё pending. Продакшен e1d57f5b/526files/93tables и main не менялись.0subagents/no live provider/network operations.


- **Текущий C2:** [план](proposals/USTAR_CONNECT_CON01C2_PLAN_20261019.md), [ledger](proposals/USTAR_CONNECT_CON01C2_PROGRESS_20261019.md); native owner management + Mini App link receipt. Final exact CI receipt PR106. Полный remote login/step-up/delivery ещё не реализованы.

- **Текущий CON01C.1:** [Telegram receiver plan](proposals/USTAR_CONNECT_CON01C1_PLAN_20261019.md), [ledger](proposals/USTAR_CONNECT_CON01C1_PROGRESS_20261019.md). B4 browser20/native78/399 SUCCESS; exact new receiver CI receipt PR106, no activation.
- [B4 browser plan](proposals/USTAR_CONNECT_CON01B4_PLAN_20261019.md), [ledger](proposals/USTAR_CONNECT_CON01B4_PROGRESS_20261019.md) — завершён26219dac, не pending browser gate.

[START_HERE](../START_HERE.md) → [project](project.yaml) → [STATE](roadmap/STATE.yaml) → [ACTIVE](tasks/ACTIVE.md).

- [Выполнено с06Oct и остаток по аудиту](audits/AUDIT_RECONCILIATION_20261016_RU.md), [полный backlog](roadmap/BACKLOG.yaml).
- [Connect RFC](proposals/USTAR_CONNECT_RFC_20261018.md), [API/domain contracts](proposals/USTAR_CONNECT_CONTRACTS_20261019.md), [legacy reuse](proposals/USTAR_CONNECT_LEGACY_BOT_REUSE_20261018.md) — текущий INTEGRATIONS-01/PR106; CON01B.1 Moodle storage development.
- [Review первого входа](proposals/USTAR_CONNECT_REVIEW_20261019.md), [план CON01A](proposals/USTAR_CONNECT_CON01A_PLAN_20261019.md).
- [План CON01B.1](proposals/USTAR_CONNECT_CON01B_PLAN_20261019.md), [unmerged source manifest](code_map/development_source.json).
- [Owner19Oct](runtime/connect_owner_priority_20261019.json): accounts/Telegram link пульта работают; journal/heartbeat deferred. [Ранние границы пульта](runtime/control_panel_telegram_20261016.md) — история INF06, не следующий старт с нуля. [Передача сисадмину](runtime/sysadmin_handoff_result_20261016.json) — restore ожидается.
- [Runtime evidence](runtime/evidence_20261016.yaml), [карта сервера](runtime/server_map_20261016.md), [реестр поставок](runtime/RELEASE_LEDGER.md).
- [CI scope](runtime/CI_SCOPE_20261016.md), [ветки](runtime/repository_state_20261016.json), [карта source](code_map/README.md).
- [Архитектура](architecture/overview.md), [границы](architecture/boundaries.md), [решения](decisions/README.md).

Domain maps: [organization](domains/organization.yaml), [registration](domains/registration.yaml), [adaptation](domains/adaptation.yaml), [routes](domains/routes.yaml), [learning](domains/learning.yaml), [evidence](domains/evidence.yaml), [grades](domains/grades.yaml), [work](domains/work.yaml), [economy](domains/economy.yaml), [feed](domains/feed.yaml), [messaging](domains/messaging.yaml), [mobile](domains/mobile_web.yaml).

[Прежние указатели](archive/reconciliation_20261016/README.md) и [архив02Oct](archive/handoff_20261002/README.md) — история. Moodle5.2/PG17 отложены; подготовка5.3 не является текущей задачей.
