# USTAR Academy

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

На 05.10.2026 актуализированы аудит, контекст и harness. Application baseline — PR76 `e1d57f5bc28f6964afd20aeff7620345b34a80fe`, версии `2026100204 / 2026100202`, исторический полный CI #456. Owner manifest 03.10: 526 matching plugin/theme; cron v3 success 04.10 23:57. [Серверные факты](context/runtime/evidence_20261005.yaml) получены от владельца, имеют дату и не покрывают весь core/все сценарии. Ручная копия/isolated restore есть, регулярности пока нет.

Сейчас разрешена только актуализация GitHub. **После публикации агент останавливается и ждёт следующей команды.** Намеченный INF-01 и текущий цикл описаны в полном плане; backup-автоматизация последняя, на healthy HDD, не SERVEREXPRESS. Mobile/Privacy/B2B/LTS остаются отдельными будущими проектами. Слияние в main не меняет production.

Исторические RC, аудиты и копии исходников в `release/` сохранены для трассировки; новый код меняется в `moodle/`. Секреты, production DB, moodledata и recovery archives в Git не хранятся.
