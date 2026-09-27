# Журнал выполнения roadmap

## 2026-09-27 — G05/N02, явное назначение грейда в draft

[PR №28](https://github.com/cauf1l3d/ustarlms/pull/28) поверх PR27,
head `5c44eace8330ab4607017ca653935b635b36d759`. Вызовы
`reconcile` на GET и при завершении маршрута удалены, совместимый метод
больше не пишет; неизвестный личный грейд не изображает первую ступень.
HR ищет сотрудника и явно назначает первую ступень с основанием. Команда
проверяет actor/capability, активный штатный аккаунт, лестницу, блокировку
и прежнее назначение; событие пишется в HR audit. Текущую запись другой
должности автоматически не переносим. Source/frontend CI №257 SUCCESS;
Moodle DB, browser и runtime по решению владельца до общего кандидата
не запускались. Редактор и версии лестниц, binding, перенос, коррекция,
проверка scope и сквозная UX-приёмка остаются открытыми.

## 2026-09-27 — G04/N03, каталог и контракт публикации в draft

[PR №27](https://github.com/cauf1l3d/ustarlms/pull/27) поверх PR25,
head `ae70b8a37159426a04941c350bc3742077462734`. Каталог открывается
без выбора первой должности; фильтры и статус не вызывают тяжёлые сборки
данных сотрудников, обучения и графа. Карточка имеет пять разделов, граф
доступен отдельно. Новая должность ведёт к требованиям.

Показаны версия и изменения требований перед публикацией; недостающий или
неработающий обязательный источник блокирует публикацию на сервере.
Повтор неизменённой публикации не создаёт новую версию. Сохранение матрицы,
структуры и отдельный HR content API проверяют ревизию под блокировкой.
Frontend получает только нужную модель и агрегированные данные курсов;
старые ID навыков не удаляются без явной миграции.

CI source/frontend №254 SUCCESS на exact head. Никакой DB, browser,
rollback или performance-приёмки
этой ветки не было; production не менялся. Остаток G04: персональный impact,
route scope/override lock, другие писатели структуры, pinned/latest связи,
проверка общей стилистики в браузере.

## 2026-09-27 — G03/N05/N06, рабочие чек-листы и задачи в draft

[PR №24](https://github.com/cauf1l3d/ustarlms/pull/24) поверх PR22:
`3a15a432216dd16909998167129be433aced6830`, plugin version
`2026092705`, XMLDB migration `check_def_ver`, version/revision/submission
links. Старый `checklists.php` оставлен совместимым входом, вкладка Tasks
рендерит саму форму. История старых run без pinned definition обозначена
отдельно; названия сегодняшней версии не подставляются вместо прошлого.

[PR №25](https://github.com/cauf1l3d/ustarlms/pull/25) поверх PR24:
`65a7baf122fbf3c008718ba428a5da840b66ed84`. Задачи/ноутбук
постраничны, фильтруются по названию/статусу; поиск получателя ограничен
управленческой областью. Поручения, заметки и результаты принимают файлы
разных форматов с server limits; Moodle File API и `pluginfile` проверяют
текущего actor; прочие пользователи и pending не читают личный блокнот.
Файлы результата закреплены за версией отправки, удаление заметки удаляет
её вложения. Добавлены два DB-сценария для общего прогона позже.

GitHub CI №244 и №247: source/frontend SUCCESS на указанных SHA. Rollback,
prepare-rc, Moodle DB и gate SKIPPED по плану. Локальный PHP CLI отсутствует;
`source_checks --skip-php` и `git diff --check` прошли. Миграция, браузерные
действия, download ACL, privacy provider и performance остаются открытыми.
Новые PR не установлены на сервер; факт production source — только G00.

## 2026-09-27 — G01/G02, три видимые доработки в draft исходниках

G01 [PR №20](https://github.com/cauf1l3d/ustarlms/pull/20), head
`51a67a7e5eb1e8f6dc49eb8c552ed76bd12a69e0`: explicit adaptation
capability и HR no-position source fix, G01 целиком ещё не завершён.
G02 [PR №21](https://github.com/cauf1l3d/ustarlms/pull/21), head
`bd4561268562e3438f0402555400110f16acc55b`: N01/N04, миграция
nullable `metadatajson` + version `2026092702`, legacy inbox только
читается и разрешается по ключу/заявке. [PR №22](https://github.com/cauf1l3d/ustarlms/pull/22),
head `add125f5da90e488df594c25fbafd5636b34df39`, добавляет N08:
полевая валидация, регистрационная разметка/AMD/CSS, theme version
`2026092701`, plugin version `2026092703`. Business auth flow не изменён.

CI run №239 и №242: source/frontend SUCCESS. Moodle DB, rollback и
browser/visual/performance не запускались на этом SHA; новая миграция
пока не проверена штатным upgrade. Production остаётся PR16 exact source
по отчёту владельца; новые PR не установлены. Следующее — завершить
G01 contracts и визуально проверить G02 на стенде, затем двигаться к G03.

## 2026-09-27 — G00, source baseline следующего RC

Владелец на server1 выполнил read-only manifest против точного SHA
`0e1eba2ca08b11f929730ab09df7bc91732f0e62` (PR №16):
424 файла plugin/theme совпадают, изменённых/лишних/отсутствующих/ошибок — 0.
DB plugin versions `local_ustar=2026092601` и `theme_ustar=2026092601`.
Монтирование проверено; доказательство и его границы — в
[runtime G00](../runtime/20260927_prod_g00.md). По сообщению владельца PR №16
установлен и проверен, но обезличенный performance baseline и подробная
матрица browser-сценариев не переданы. Production не менялся этой поставкой.

Спецификация следующего RC — docs-only PR №17 (8 новых требований,
60 audit ID, 13 старых замечаний). Подготовительный PR №18
`a207c9e7c2b4447e0a0bd0e70dbc06458f7cc0c2` меняет только
workflow: ручной `rollback_only`. Run №237 source/frontend SUCCESS;
rollback, prepare-rc и Moodle DB SKIPPED, поскольку это обычный PR-run.
Плановый общий Moodle DB suite следующего RC не запускался. Канонический
контекст обновлён отдельным docs commit к main; G00 остаётся in_progress
до измерения baseline. Следующее действие — G01 после фиксации условий
измерения и источников прав/файлов.

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
