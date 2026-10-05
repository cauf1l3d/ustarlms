# USTAR Academy

**Приоритет владельца06.10 00:45MSK:** сначала оптимизировать хранение, обновить карту сервера и настроить автобэкапы на HDD; затем новый стенд и Moodle/ОС/HTTPS. Прежнее правило «автобэкапы в финале» заменено. PR87 `--prepare-stage` приостановлен. [Карта server1](context/runtime/server_map_20261006.md).

Корпоративная академия розничной сети «Хозмагия», работающая во внутренней сети компании. Moodle отвечает за LMS, `local_ustar` — за бизнес-процессы, `theme_ustar` — за интерфейс.

**Код и текущая документация находятся вместе в `main`. Начните с [START_HERE.md](START_HERE.md).**

| Что нужно | Куда идти |
|---|---|
| Состояние и следующий шаг | [STATE](context/roadmap/STATE.yaml), [ACTIVE](context/tasks/ACTIVE.md) |
| Архитектура и ограничения | [Обзор](context/architecture/overview.md), [ADR](context/decisions/README.md) |
| Найти реализацию | [Карта модулей](context/code_map/README.md) |
| Установленный код, CI, последние SHA | [Реестр релизов](context/runtime/RELEASE_LEDGER.md) |
| Эксплуатация, проверка prod, выпуск | [Runbook](context/runtime/OPERATIONS.md) |
| Аудит и полный план исправлений | [Аудит](context/audits/README.md), [пошаговый план](context/roadmap/USTAR_AUDIT_REMEDIATION_PLAN_20261005_RU.md) |
| Метрики, копии, security | [Пульт](context/runtime/MONITORING.md), [backup/restore](context/runtime/BACKUP_RESTORE.md), [baseline](context/runtime/SECURITY_BASELINE.md) |
| Последующий Android/iOS | [План мобильного клиента](context/roadmap/MOBILE_CLIENT.md) |

На 05.10.2026 аудит/контекст/harness опубликованы PR78. Application baseline — PR76 `e1d57f5bc28f6964afd20aeff7620345b34a80fe`, версии `2026100204 / 2026100202`, исторический полный CI #456. Свежий owner manifest 05.10: 526 matching plugin/theme; 468 disk/DB component versions совпали; cron v3 success в 02:13 UTC. [Baseline](context/runtime/baseline_20261005.md) и [серверные факты](context/runtime/evidence_20261005_baseline.yaml) датированы, полного core/business acceptance ещё нет. Историческая ручная copy/restore есть, регулярности нет.

Следующая команда владельца разрешила **1 → 7 → 6**: baseline → Moodle/CI/ОС → HTTPS, остальные пункты после них. INF-01 в работе; историческая остановка DOC-02 больше не действует. Backup-автоматизация позже на healthy HDD, не SERVEREXPRESS. Mobile/Privacy/B2B/LTS остаются отдельными проектами. Слияние в main не меняет production.

Исторические RC, аудиты и копии исходников в `release/` сохранены для трассировки; новый код меняется в `moodle/`. Секреты, production DB, moodledata и recovery archives в Git не хранятся.

Текущий infrastructure checkpoint05Oct20:44UTC: [planning PASS / exact budget](context/runtime/lab_planning_result_20261005.json), [cold rollback copy и отдельный patch workspace](context/runtime/lab_cold_checkpoint_20261005.md). Helper подготовлен, server execution/upgrade pending; HDD newest extended без ошибок, residual198=1 и target configuration остаются explicit.
