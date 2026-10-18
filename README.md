# USTAR Academy

Рабочая Академия — Moodle с локальным плагином `moodle/local/ustar` и темой `moodle/theme/ustar` (parent `boost`). Каталог `frontend/` — сохранённый legacy Next.js клиент: он не используется на проде и исключён из текущего CI и релизного пакета. Native PHP, Mustache, AMD/JS и браузерные проверки темы/плагина остаются обязательными.

Начните с [START_HERE](START_HERE.md). [Текущий статус](context/roadmap/STATE.yaml), [очередь](context/tasks/ACTIVE.md) и [сверка с аудитом на16Oct](context/audits/AUDIT_RECONCILIATION_20261016_RU.md) определяют текущую работу. Датированные отчёты не являются live-запросами к серверу.

Подтверждены: расширение SSD11Oct, Moodle5.1.8/повторный upgrade/краткая браузерная приёмка13Oct, AppArmor и host updates/reboot14Oct. Архив копии получен и инструкция передана сисадмину; результата нового восстановления ещё нет. Переход на **Moodle5.2 и PostgreSQL17 отложен**.

Оба owner server1 snapshots17Oct [проверены и импортированы](context/reviews/20261017/runtime_reconciliation.md):526 plugin/theme files совпали; подтверждены DB/code permission risks, оставшиеся измерения явно перечислены. Контекст готов к повторному review владельца. Затем — [пульт управления с Telegram](context/runtime/control_panel_telegram_20261016.md). Auth/read-only API/UI и новый прототип уже подготовлены в [PR103](https://github.com/cauf1l3d/ustarlms/pull/103), ещё не внедрены в main/production; бот остаётся отдельной работой.

## Работа с репозиторием

- `main` — канонический код и контекст. Новую работу начинать от обновлённого main.
- [Роли веток и сохранённые SHA](context/runtime/repository_state_20261016.json). Старые integration/release/prod-sync/snapshot/codex ветки — история, а не доказательство текущего deployment.
- [Архитектура](context/architecture/overview.md), [границы](context/architecture/boundaries.md), [карта кода](context/code_map/README.md).
- [CI и выпуск](context/runtime/CI_SCOPE_20261016.md): native source, DB/upgrade/repeat, paired rollback; legacy frontend не входит.
- [Копия для сисадмина без остановки прода](context/runtime/production_copy_20261015.md), [статус передачи](context/runtime/sysadmin_handoff_result_20261016.json).

Moodle core, рабочие config.php, базы, moodledata, ключи и архивы не коммитятся. GitHub CI не является production-приёмкой. Публикация кода не запускает обновление server1.
