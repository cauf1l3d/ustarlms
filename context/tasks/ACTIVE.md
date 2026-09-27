# Активная работа — 2026-09-27

## G03 — черновые исходники, приёмка открыта

[PR №24](https://github.com/cauf1l3d/ustarlms/pull/24), SHA
`3a15a432216dd16909998167129be433aced6830`: чек-листы работают внутри
вкладки задач, старый адрес совместим, определения версионируются, финальная
отправка и исправление сохраняют отдельные события. [PR №25](https://github.com/cauf1l3d/ustarlms/pull/25),
SHA `65a7baf122fbf3c008718ba428a5da840b66ed84`: задачи и блокнот имеют
постраничный scoped поиск, вложения разных форматов через Moodle File API,
проверку права при скачивании, защиту pending и отдельный просмотр истории.
При редактировании поручения проверяются область и версия.

CI №244 и №247: source/frontend SUCCESS, rollback и Moodle DB SKIPPED.
Локально `source_checks --skip-php`, `git diff --check`; PHP CLI недоступен.
Upgrade `2026092705`, воспроизведение браузером, privacy export/delete,
сквозные ACL/file сценарии и измерения на согласованном стенде не выполнены.
Production остаётся на зафиксированном G00 SHA. Следующий пакет по приоритету
плана — G04/N03 модель должностей; общий G01 UI/files contract продолжает
дорабатываться, приёмка G02/G03 отложена до полного кандидата по решению владельца.

## G01/G02 — исходники следующего кандидата

[PR №20](https://github.com/cauf1l3d/ustarlms/pull/20) вводит явное право HRD
на адаптацию и исправляет HR-сохранение сотрудника без должности; G01 как
полный общий контракт ещё открыт. [PR №21](https://github.com/cauf1l3d/ustarlms/pull/21)
содержит N01 (старый адрес «Оргпространства» → «Команда») и N04
(названия подразделений в уведомлениях и ссылка на защищённую заявку).
[PR №22](https://github.com/cauf1l3d/ustarlms/pull/22) добавляет N08:
форму регистрации с сохранением рисунка, поиском, ошибками полей и
защитой от повторного нажатия. Точный общий head `add125f5da90e488df594c25fbafd5636b34df39`.

GitHub run №239 для PR21 и №242 для PR22: source/frontend SUCCESS;
rollback, prepare-rc, Moodle DB и gate SKIPPED. Миграция уведомлений
`metadatajson` и upgrade `2026092702` написаны, но не проверены против
Moodle DB. Кандидат не развёрнут. Нужны G01 contracts, доступная визуальная
сверка G02, затем остальные пакеты и общий DB прогон frozen SHA.

## G00 — подготовка следующего RC

Владелец выпустил PR №16 и предоставил read-only manifest от 27.09:
`codex/ustar-postrelease-fixes-20260926@0e1eba2ca08b11f929730ab09df7bc91732f0e62`
совпадает с 424 файлами production plugin/theme без drift. DB plugin versions:
`local_ustar=2026092601`, `theme_ustar=2026092601`.
Подробности и границы доказательства — [runtime G00](../runtime/20260927_prod_g00.md).

[План восьми приоритетов и аудита](https://github.com/cauf1l3d/ustarlms/blob/codex/ustar-product-plan-20260927/context/roadmap/USTAR_PRODUCT_ARCHITECTURE_PLAN_20260927_RU.md)
опубликован в docs-only PR №17. Подготовительный PR №18 добавляет
`rollback_only`; его обычный PR-run №237 прошёл source/frontend,
но отдельный rollback будет запускаться на frozen candidate.
Следующее действие: получить performance baseline на согласованном стенде,
затем G01 (общие UI/ACL/files контракты). Все 8 новых функций, 60 audit ID
и 13 старых замечаний остаются предметом реализации/приёмки; source match
не закрывает их автоматически.

Далее сохранена историческая запись этапов 1–2 от 20.09. Её фразы о
неизменённом production и прежней кодовой базе относятся только к той дате.

## Второй этап — in progress

[PR #4](https://github.com/cauf1l3d/ustarlms/pull/4) поверх PR #2: единый read-only
источник должности, проверка PRIMARY/ACTING и сроков, границы команды,
сверка legacy без изменения данных. R10/R12 начаты, не закрыты.
[Проверки и остаток этапа](../runtime/20260920_stage2_org.md).
Полный этап требует employment/approval status, явных ролей и проверенной миграции.
Production не изменён. Установка до сверки кадровых конфликтов не подтверждена.

## Первый этап рефакторинга — review

[PR #2](https://github.com/cauf1l3d/ustarlms/pull/2): актуальный CI, изолированный Moodle/PostgreSQL,
regression tests, preview/reset/квалификация, защита frontend, manifest/preflight и rollback drill.
Владелец: Codex, ветка `codex/refactor-stage-1-20260920`.
[Доказательства и ограничения](../runtime/20260920_stage1_ci.md).
[Все шесть этапов](../architecture/refactor_20260920.md).
R00 сохраняет review; production и кадровые данные не менялись. Новая регистрация и HR-пульт — следующие этапы.

Канонический application source:

`integration/ustar-20260919@378d397152a8c83f8b0d046e2e561ab2732d6b02`

Канонический статус:

`main/context/roadmap/STATE.yaml`

## R00 — review

Production source reconciliation выполнена.

Подтверждено пользовательским запуском reconciliation script:

- 328 файлов `moodle/local/ustar`;
- 53 файла `moodle/theme/ustar`;
- 381 publishable source файл проверен byte-for-byte перед commit;
- `INDEX_SOURCE_BYTE_MATCH_OK`;
- remote branch и local commit совпали;
- 33 runtime/developer backup artifact классифицированы существующим `.gitignore` и не опубликованы как активный source;
- полный inventory после cleanup pass: 414 файлов.

Recovery snapshot:

`USTAR_FULL_CURRENT_20260919_113924.tar.gz`

SHA256:

`598177f211333d7d036abc025b9d21c3c7c3eb4cfb0bc10d004ff0fcff473f41`

Следующее действие R00:

1. read-only проверка DB schema / plugin versions / upgrade-path против exact SHA `378d397...`;
2. browser smoke текущего production по критическим маршрутам;
3. native tour records/visual acceptance;
4. отдельный clean-server DR restore test созданного recovery snapshot;
5. после приёмки решить, удалять ли 33 ignored runtime remnants с production.

R01/R04/R17 не считать автоматически завершёнными из-за source synchronization.

Старую `integration/ustar-20260912` и старые ZIP/RC использовать только как исторический provenance, не как текущую базу.
