# USTAR инструкции агентам

**Точка продолжения19Oct10:33МСК:** текущий **CON01C.1 Telegram receiver**: [план](context/proposals/USTAR_CONNECT_CON01C1_PLAN_20261019.md), [ledger](context/proposals/USTAR_CONNECT_CON01C1_PROGRESS_20261019.md). TLS Bot API polling/private commands, SQLite cursor/dedup/exclusive lock, uncertain/crash/rate limits, personal Main Mini App startapp launch, explicit opt-in runtime.41new tests и local639r16/30skips PASS; exact new CI receipt PR106. Не запускать обработчик на anonymous webhook JSON: origin trusted только от fixed Telegram TLS client. Receiver+BFF должны использовать один bridge key ID для launch issue/exchange. Onboarding disabled default в примере; actual bot/relay/server1 не включены. Candidate addon2026101902/542/99 неизменен, accepted production e1d57f5b/526/93 отдельно.

B4 completed26219dac: Context39214124458/Review39214124509 SUCCESS,20browser scenarios,78native/399, schema+4concurrency PASS. B3 completed d4f7c1bd, те же native78/399; не переоткрывать эти pending gates. Следом canonical login/recovery/link/actor step-up и canonical notification outbox consumer; external HTTPS origin/relay и live receiver acceptance требуют своего deployment этапа. **Субагенты запрещены в этой задаче; будущие запуски согласовывать с обоснованием.**0agents/no main merge/no production network changes. Journal/heartbeat и Moodle5.2/PG17 отложены, sysadmin restore ожидается.

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
