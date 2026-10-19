# Индекс текущего контекста

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
