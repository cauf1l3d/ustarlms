# USTAR — инструкции агентам

Перед любой задачей читать [START_HERE.md](START_HERE.md), затем `context/project.yaml`, `context/CONTEXT_INDEX.md`, `context/architecture/`, `context/decisions/`, `context/domains/`, `context/runtime/`, `context/code_map/`, `context/tasks/ACTIVE.md`, `context/agents/astra.md` и `context/roadmap/AGENT_PROTOCOL.md`.

Код и текущий контекст теперь совместно поддерживаются в **main**. Новые ветки создавать от обновлённого main; PR направлять в main. Старые integration/codex ветки и release mirrors — provenance, не актуальная база. Точный application baseline и отдельно deployment evidence — в `context/roadmap/STATE.yaml`.

Текущая инструкция владельца имеет приоритет над историческим планом. Код описывает реализацию, ADR — согласованные границы, серверные наблюдения — deployment. Не подменять один источник другим. Не объявлять missing evidence багом и не объявлять CI production-приёмкой.

Сохранять Moodle core / local plugin / theme boundaries. Не вводить второй store сотрудников, completion, кошелька или чатов. Не удалять историю, не менять реальные роли по строкам должностей, не выполнять миграцию из старого персонального плана. Записи проверяют real actor, capability, current scope, sesskey/эквивалентный API contract, revision, locks и idempotency по реализации домена. View-as не предоставляет права записи.

Database changes требуют XMLDB install/upgrade parity и release gate. Source code меняется в `moodle/`, не в исторических `release/` копиях. Не публиковать config.php, токены, dumps, moodledata или персональные журналы. Не запускать CLI probe на production, пока не проверен его write impact.

В текущем этапе сохранять стабильный web. Следующая работа — `MOB-01` в `context/roadmap/MOBILE_CLIENT.md`: карта покрытия API и согласование архитектуры Android/iOS. Стек мобильного клиента ещё не выбран. Не заменять приложение утверждением, что PWA уже завершает запрос владельца.

Каждая поставка содержит точный implementation SHA, реально выполненные проверки и отдельный deployment status. Обновлять STATE/ACTIVE/BACKLOG/SESSION_LOG и generated code map при изменении исходников. Документация не является причиной повторно запрашивать уже данное пользователем разрешение.
