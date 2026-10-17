# USTAR вход в проект

## Текущий этап 18 октября 2026

**Owner01:26MSK:** [Telegram network receipt](context/runtime/console_telegram_network_20261018.json): DNS IPv4 получен, direct TCP443 timeout до TLS/API. Причина/узел не установлены; следующий шаг — direct HTTPS control и same-endpoint TCP с корпоративного Windows. [Проверенный release3305ecc9](context/runtime/console_storage_release_20261018.json):125 backend/45 browser и namespace/rollback CI PASS, artifact передан; на server1 применение ещё не подтверждено. Этот context-only follow-up не новый исполняемый выпуск.

**Owner00:14MSK:** [HDD/SMART диагностика](context/runtime/console_storage_probe_20261018.json) подтверждает host rw/service ro и успешный SMART oneshot с частичными SSD атрибутами. [Исправление и обновление](server-console/STORAGE_FIX_20261018.md) подготовлено с regression/native CI cases; новый owner update ещё не выполнен. Telegram/VPN запрос сохраняется; оставшиеся gates не закрыты.

**Продолжение18Oct:** owner17Oct23:59MSK подтвердил открытие пульта и запросил Telegram/домашний доступ. [Receipt](context/runtime/console_browser_open_20261017.json); [Telegram и частный маршрут](server-console/REMOTE_ACCESS.md). Startup notifier argument defect исправлен с regression; installed567e90d5 ещё требует owner hotfix/receiver ACK. VPN только предложен; source diagnostics и полный browser gate не закрыты.

Рабочий продукт — Moodle + `local_ustar` + `theme_ustar`, parent boost. `frontend/` — legacy Next.js, исключённый из активного CI/выпуска. Проверки native UI темы и плагина сохранены.

- **Сделано на сервере:** SSD расширен11Oct; Moodle5.1.8 upgrade/repeat и краткая браузерная/SCORM приёмка13Oct; AppArmor,63 пакета, reboot/kernel147/Docker29.9.0 приняты14Oct. Не повторять.
- **Ожидание:** владелец16Oct подтвердил получение архива и передачу инструкции сисадмину. Отдельная копия, её вход/SCORM/reboot и полный host DR ещё не приняты.
- **Сейчас:** контекст дополнен gaps archive13:59MSK: ACL44175 объектов, текущий SSH-контекст, Caddy PID862 на2019, metadata/SHA256 всех23 extras и selected du. [Последняя сверка](context/reviews/20261017/gaps_reconciliation.md), [provenance](context/runtime/server_gaps_received_20261017.json), [coverage](context/coverage.yaml). Права UID33 и writable parents сохраняют INF-03; [внешний TCP2019](context/runtime/external_network_result_20261017.json) с домашнего ПК дал False для IPv4 выхода сети, но другие источники/IPv6/NAT/API, SQL stats и native/restore acceptance открыты. Прежние plugin526/core24742/NTP/SMART observations сохранены по своим датам; package acl установлен владельцем, collector не перенастраивал службы. Владелец17Oct16:30MSK разрешил переход к пульту; новый независимый audit result не приписывается. [Изменения пульта по требованиям аудита](server-console/AUDIT_FOLLOWUP_20261017.md).
- **Пульт установлен владельцем:** [receipt23:36MSK](context/runtime/console_native_install_20261017.json), SHA567e90d5: TLS/fresh snapshot/units/web isolation прошли ограниченный gate. HDD critical/probe_failed и SMART no_data/probe_failed требуют диагностики; TOTP/Telegram пропущены, notifier inactive. Browser trust/login/roles/download hash/receiver/observer/jobs/overhead/restore открыты. [PR103](https://github.com/cauf1l3d/ustarlms/pull/103) ещё не merged в main; [границы INF-06](context/runtime/control_panel_telegram_20261016.md).
- **Отложено:** Moodle5.2 и PostgreSQL17. Прежняя подготовка5.3/PR101 сохранена как история, не следующий запуск.

## Порядок чтения

Это единственный порядок входа; остальные индексы — тематическая навигация.

1. [current.yaml](context/current.yaml) — генерируемая сводка; [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md), [project](context/project.yaml) — канонические решения и область продукта.
2. [Ревью17Oct](context/reviews/20261017/README.md), [покрытие и неизвестное](context/coverage.yaml), [сверка аудита](context/audits/AUDIT_RECONCILIATION_20261016_RU.md), [BACKLOG](context/roadmap/BACKLOG.yaml).
3. По задаче: [архитектура](context/architecture/overview.md), [границы](context/architecture/boundaries.md), [ADR](context/decisions/README.md), [дизайн](context/design/README.md), [capability inventory](context/architecture/access_matrix.yaml), [domain maps](context/CONTEXT_INDEX.md).
4. [Runtime](context/runtime/README.md), [снимки server facts](context/runtime/server-facts/README.md), [карта кода](context/code_map/README.md), [протокол](context/roadmap/AGENT_PROTOCOL.md).

## Источники истины

`main` — код и текущий контекст. Точный plugin/theme baseline PR76/e1d57f5b,526 файлов, local2026100204/theme2026100202 сохранён; это не номер нового core и не текущий repository head. [Датированные runtime evidence](context/runtime/evidence_20261016.yaml) отделяют версии/установку от статического source и CI.

[Ветки](context/runtime/repository_state_20261016.json): PR98/99/100/102 — источники консолидации, PR101 — отложенная историческая подготовка, PR103 — прототип следующего пульта. Старые release/integration/prod-sync branches не использовать как актуальную базу.

`context/current.yaml` и `.codex/checkpoints/CURRENT.md` строятся из STATE/BACKLOG; не редактировать их как независимое состояние. [Стратегия11Oct](context/strategy/20261011/README.md) — датированный материал, не замена последней команды владельца. Даты production receipts не обновлять датой этого review.

[Инструкция сисадмину](context/runtime/production_copy_20261015.md) использует существующий HDD-архив и не останавливает прод. Не путать `--pack`, `--pack-production`, постоянную parallel copy и временный lab. Бэкап с ключом нельзя помещать в Git/чат/Telegram.

Нет прямого подключения агента к server1. После сверки репозитория не запускать native update/capture/reboot/restore без самостоятельного основания. [CI scope](context/runtime/CI_SCOPE_20261016.md) описывает реально выполняемые и отдельные опциональные проверки.
