# USTAR — инструкции агентам

Перед любой задачей читать [START_HERE.md](START_HERE.md), затем `context/project.yaml`, `context/CONTEXT_INDEX.md`, `context/architecture/`, `context/decisions/`, `context/domains/`, `context/runtime/`, `context/code_map/`, `context/tasks/ACTIVE.md`, `context/agents/astra.md` и `context/roadmap/AGENT_PROTOCOL.md`.

Код и текущий контекст теперь совместно поддерживаются в **main**. Новые ветки создавать от обновлённого main; PR направлять в main. Старые integration/codex ветки и release mirrors — provenance, не актуальная база. Точный application baseline и отдельно deployment evidence — в `context/roadmap/STATE.yaml`.

Текущая инструкция владельца имеет приоритет над историческим планом. Код описывает реализацию, ADR — согласованные границы, серверные наблюдения — deployment. Не подменять один источник другим. Не объявлять missing evidence багом и не объявлять CI production-приёмкой.

Сохранять Moodle core / local plugin / theme boundaries. Не вводить второй store сотрудников, completion, кошелька или чатов. Не удалять историю, не менять реальные роли по строкам должностей, не выполнять миграцию из старого персонального плана. Записи проверяют real actor, capability, current scope, sesskey/эквивалентный API contract, revision, locks и idempotency по реализации домена. View-as не предоставляет права записи.

Database changes требуют XMLDB install/upgrade parity и release gate. Source code меняется в `moodle/`, не в исторических `release/` копиях. Не публиковать config.php, токены, dumps, moodledata или персональные журналы. Не запускать CLI probe на production, пока не проверен его write impact.

Текущая поставка — `DOC-02`: аудит, полный план, harness и контекст в GitHub. После публикации **остановиться и ждать новой команды владельца**. Следующая намеченная работа — `INF-01` по `context/roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md`; ready не означает разрешение выполнять её сейчас. Сохранять стабильный web, нужные почту/DNS/ISPConfig, один cron v3 и историю.

Читать `context/audits/AUDIT_RECONCILIATION_20261005_RU.md` вместе с оригиналом аудита. Root occupancy тестовым стендом — гипотеза владельца, не основание удалить его. Автокопии — финал текущего инфраструктурного цикла на исправный HDD, не на SERVEREXPRESS; нынешний HDD провалил long SMART. Разовые страховочные копии перед изменениями сохраняются. Mobile/Privacy/B2B — отдельные последующие проекты, не бесконечная зависимость финального backup-этапа.

Каждая поставка содержит точный implementation SHA, реально выполненные проверки и отдельный deployment status. Обновлять STATE/ACTIVE/BACKLOG/SESSION_LOG и generated code map при изменении исходников. Документация не является причиной повторно запрашивать уже данное пользователем разрешение.
