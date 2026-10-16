# USTAR инструкции агентам

Текущая инструкция владельца16Oct: начать пульт с архитектуры, health, метрик/алертов и UI/UX Linear/Apple. GitHub-сверка с06Oct завершена в PR104; в PR103 реализован первый read-only срез пульта; native установка ещё не выполнена. Архив получен, инструкция передана сисадмину, результат восстановления ожидается. Следующий этап — пульт с Telegram. **Moodle5.2/PostgreSQL17 отложены.** Старые поручения запускать storage/patch/reboot или подготовку5.3 не являются текущей задачей.

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

## Текущая задача

Перед реализацией читать [архитектуру пульта](server-console/ARCHITECTURE.md), [метрики/health](server-console/OBSERVABILITY.md) и [UI/UX](server-console/DESIGN.md). Не считать проектные пороги, demo-прототип и архитектуру native acceptance.


[INF-06 пульт и Telegram](context/runtime/control_panel_telegram_20261016.md): same-host, логин/пароль, Linear style, метрики/свежесть, адреса/сроки стендов, SFTP, ручные backup/restore, алерты и выдача последнего проверенного зашифрованного архива. Сначала защищённый read-only срез; операции — через ограниченные проверенные adapters, не произвольный shell. Runtime acceptance на настоящем сервере остаётся отдельной.
