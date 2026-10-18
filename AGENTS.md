# USTAR инструкции агентам

Текущий запрос владельца19Oct01:28МСК: CRM означает задачи Академии; регистрация первого касания в боте с canonical pending/HRD approval, единая identity и общий одноразовый код. [Review](context/proposals/USTAR_CONNECT_REVIEW_20261019.md), [план CON01A](context/proposals/USTAR_CONNECT_CON01A_PLAN_20261019.md). Разработку после review владелец разрешил. CON01A — offline Python primitives, ещё не auth endpoint/registration integration. **Субагентов не запускать без предварительного согласования и обоснования; в этой задаче запрещены.** Journal/heartbeat явно отложены. Moodle5.2/PG17 и sysadmin restore сохраняют прежний статус.

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

[INTEGRATIONS-01 / CON-01A](context/proposals/USTAR_CONNECT_RFC_20261018.md): один business contract, текущая Moodle identity/capabilities/scope, transport adapters и узкий внешний edge. Сохранить существующие domain writers/эскалации; server-console accounts/token не являются Academy auth. Legacy handler найден владельцем, но его source ещё не прочитан; не выполнять его при аудите и не публиковать секреты. Review выполнен; CON01A реализует transport primitives без изменения сети, ролей, schema или production. Следующий CON01B — persistent identity/nonce/registration facade и Moodle DB tests.
