# Журнал выполнения roadmap

## 2026-09-20 — первый этап рефакторинга, R01 review

Владелец: Codex. Код: `e657cda7dc29750511535b94eee85b22ca7fdb86`,
[PR #2](https://github.com/cauf1l3d/ustarlms/pull/2) к `integration/ustar-20260919`.
Приняты шесть последовательных этапов, описанных в `context/architecture/refactor_20260920.md`.

Реализованы текущий PR CI и отдельные DB/rollback стенды, генераторы данных, preview/reset/reward guards,
корректный статус пустого стандарта, защита frontend и manifest/preflight. Исправлен реальный дефект
чистой установки capabilities. В rollback устранены отсутствие Moodle bootstrap при чтении версии,
подстановка ERR-переменных Compose и несовместимый pg_dump.

[Run 35527954308](https://github.com/cauf1l3d/ustarlms/actions/runs/35527954308):
source, frontend, moodle-db, rollback и gate — success.
Тестируемый merge SHA `e3ae87c5a9df1071f80d4aeff730b572965f002d`.
10 DB tests / 36 assertions (1 notice), 21 Python tests, 49 isolated PHP assertions,
88 DOM assertions, 4 frontend security tests и HTTP smoke собранного Next.
Полный синтетический restore DB/moodledata/source/config — PASS.
Подробности и артефакты: `context/runtime/20260920_stage1_ci.md`.

Production не менялся. Схема metadata нормализована без изменения эффективной структуры;
rollback восстанавливает весь согласованный комплект, а не только PHP-файлы.
R01 остаётся review: branch protection enforcement не подтверждён. R00 production browser/DR acceptance открыт.
Следующее действие: принять PR и required gate; дальнейшие изменения — модель сотрудника/организации второго этапа.

## 2026-09-07 — опубликован исходный roadmap

**Тип поставки:** документация и статический аудит. Реализация R00–R20 ещё не выполнена этой поставкой.

- Прочитаны четыре отчёта Fable и продуктовая стратегия владельца.
- Проверены контекст/ADR и выбранная база `e71bca2856bc0f80e39cb9ed1cee6098934e232a`; установлен разрыв с исходным main `5443bf5…`.
- Прослежены route completion, studio, scope, economy, game mastery/competition, achievements/dashboard, evidence, organization, adaptation, migration и contextd paths.
- Для contextd выполнено воспроизведение нарушения границы чтения на искусственном файле; реальные чувствительные файлы не читались.
- Подготовлены 21 ticket, условия приёмки студии/наград, раздельные code/validation/deployment statuses и вход из main.
- Отмечены подготовленные ранее UI-пакеты, их неподтверждённый deployment не назван завершённым.
- PHP/Moodle DB/browser/prod acceptance не запускались. Application code, DB, Docker, branch protection и default branch не менялись.

**Следующее действие:** R00; независимые ready-задачи R01/R04/R16. После публикации идентификатор документационного commit доступен в Git history этого файла.

## Шаблон следующей записи

Дата; ticket ID; owner; branch/code SHA; что изменено; какие acceptance проверены и где; not-run и причина; migration/rollback; deployment evidence или «не установлен»; следующий ticket/blocker. Не включать персональные данные/секреты и не заменять результаты тестов общим словом PASS.

## 2026-09-12 — R00 source consolidation and GitHub synchronization

Code: `89ba18b2130040fbdf7f0ca02bef4999d5ef1fdb`. Published verified patch payloads on integration/ustar-20260912; updated canonical context and harness separately on main. Application code on main is unchanged. See release publication scope for four source-only exclusions. Validation and separate deployment evidence: [release](../../release/20260912/README.md), [runtime](../runtime/20260912_release.md). R00 is review, not done: full production manifest and broader smoke remain outstanding. No deployment, schema mutation or history rewrite during repository synchronization.

## 2026-09-12 — verified source and snapshot publication

Source `48326c6a011f58b985d251cb0462835bc1d37a16`. User approved public publication of R16, tours, four production differences and reviewed dated context. 367 source files verified including separate theme config. R16 server installation and 21 tests supplied by user; daemon not started. R00 review; schema and live tour acceptance outstanding. No backups or private diffs published. No production files changed by Git publication.


## 2026-09-20 — второй этап, пакет организации и доступа

[PR #4](https://github.com/cauf1l3d/ustarlms/pull/4), head `2502281af5e687a7bcb094b2bad8593d480304d7`,
stacked на PR #2. Единое разрешение должности/подчинения, временные назначения,
конфликты, границы команды, сохранение ручных reporting decisions и read-only dry-run.
Native CI run `35544164856`: все пять jobs SUCCESS; Moodle 24 tests / 89 assertions /
3 notices, install/upgrade/repeat/schema parity и полный synthetic rollback проходят.
R10/R12 остаются in progress; employment state, explicit role migration и полный
consumer/writer переход ещё впереди. Production не менялся.
Доказательства: `context/runtime/20260920_stage2_org.md`.
