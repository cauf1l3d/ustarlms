# USTAR инструкции агентам

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
