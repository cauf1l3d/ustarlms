# USTAR инструкции агентам

Текущий запрос владельца19Oct05:30МСК: продолжить единый Academy bot, CRM=задачи Академии, signup→pending→canonical HRD approval. [CON01B.3 plan](context/proposals/USTAR_CONNECT_CON01B3_PLAN_20261019.md), [ledger](context/proposals/USTAR_CONNECT_CON01B3_PROGRESS_20261019.md). Реализован candidate2026101902/542files/99tables: canonical registration completion + first link + receipt в одной transaction; private bridge options/submit/status. Endpoint disabled default, forms/public edge/provider delivery ещё отсутствуют. Base81a1be76 завершён:22native tests/87assertions SUCCESS, не повторять старые pending gates. Test-first57111574 дал ровно2expected native failures; новый implementation CI отдельно, финальный exact receipt в PR106. Production baseline e1d57f5b/526/93 не менять. **Субагентов не запускать без предварительного согласования и обоснования; в этой задаче запрещены.** Journal/heartbeat и Moodle5.2/PG17 отложены, sysadmin restore ожидается.

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

[INTEGRATIONS-01](context/proposals/USTAR_CONNECT_RFC_20261018.md): один business contract и текущие Academy permissions. Не дублировать registration_service/staffing_requests/target_core: CON01B.3 уже оборачивает их atomic metadata completion, не второй кадровый writer. Следом trusted HTTPS BFF/форма, текущий actor+step-up для remote HRD commands и реальные bot delivery/receiver ACK. Server-console accounts/token не являются Academy auth. Entry token не Moodle login/employee capability. Legacy handler source не получен; не выполнять его ради аудита. Runtime endpoint/каналы не включать без своего gate.
