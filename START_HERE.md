# USTAR вход в проект

**Пульт и Telegram приняты владельцем18Oct14:42МСК; INF-06 завершён в принятом объёме.** [Подтверждение](context/runtime/console_owner_acceptance_20261018.json). Последний выданный пакет — sourceb93e16af,312 backend/72 Chromium и native CI; точный hash установленного модуля в новом сообщении не прислан. Повторять установку, настройку бота или доработку интерфейса не нужно.

**Далее — INF-07: HTTPS самой Академии.** [План подготовки](context/tasks/HTTPS_NEXT_20261018.md): сверить действующий Apache/proxy/wwwroot/DNS, выбрать внутреннее имя и доверие CA, подготовить сертификат/redirects/cookies и откат, проверить вход/SCORM/файлы/сессии на копии. Последний известный адрес Академии — `http://ustar.local`; HTTPS пульта на8443 не заменяет эту работу. Производственное переключение ещё не выполнялось.

Отдельно сохранены INF-06-OPS (наблюдатель, private home access и остальные неподтверждённые native операции) и OPS-02 (ожидание restore/RTO сисадмина). Moodle5.2/PostgreSQL17 отложены. История и остатки аудита — в STATE/BACKLOG и датированных runtime receipts. Эти изменения контекста публикуются в PR103; main ещё не объединён с ним.

## Порядок чтения

Это единственный порядок входа; остальные индексы — тематическая навигация.

1. [current.yaml](context/current.yaml) — генерируемая сводка; [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md), [project](context/project.yaml) — канонические решения и область продукта.
2. [Ревью17Oct](context/reviews/20261017/README.md), [покрытие и неизвестное](context/coverage.yaml), [сверка аудита](context/audits/AUDIT_RECONCILIATION_20261016_RU.md), [BACKLOG](context/roadmap/BACKLOG.yaml).
3. По задаче: [архитектура](context/architecture/overview.md), [границы](context/architecture/boundaries.md), [ADR](context/decisions/README.md), [дизайн](context/design/README.md), [capability inventory](context/architecture/access_matrix.yaml), [domain maps](context/CONTEXT_INDEX.md).
4. [Runtime](context/runtime/README.md), [снимки server facts](context/runtime/server-facts/README.md), [карта кода](context/code_map/README.md), [протокол](context/roadmap/AGENT_PROTOCOL.md).

## Источники истины

`main` — код и текущий контекст. Точный plugin/theme baseline PR76/e1d57f5b,526 файлов, local2026100204/theme2026100202 сохранён; это не номер нового core и не текущий repository head. [Датированные runtime evidence](context/runtime/evidence_20261016.yaml) отделяют версии/установку от статического source и CI.

[Ветки](context/runtime/repository_state_20261016.json): PR98/99/100/102 — источники консолидации, PR101 — отложенная историческая подготовка, PR103 — исходники принятого владельцем пульта и актуальный контекст. Старые release/integration/prod-sync branches не использовать как актуальную базу.

`context/current.yaml` и `.codex/checkpoints/CURRENT.md` строятся из STATE/BACKLOG; не редактировать их как независимое состояние. [Стратегия11Oct](context/strategy/20261011/README.md) — датированный материал, не замена последней команды владельца. Даты production receipts не обновлять датой этого review.

[Инструкция сисадмину](context/runtime/production_copy_20261015.md) использует существующий HDD-архив и не останавливает прод. Не путать `--pack`, `--pack-production`, постоянную parallel copy и временный lab. Бэкап с ключом нельзя помещать в Git/чат/Telegram.

Нет прямого подключения агента к server1. После сверки репозитория не запускать native update/capture/reboot/restore без самостоятельного основания. [CI scope](context/runtime/CI_SCOPE_20261016.md) описывает реально выполняемые и отдельные опциональные проверки.
