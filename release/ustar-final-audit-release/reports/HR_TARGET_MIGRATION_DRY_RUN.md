# USTAR HR TARGET migration — recovered owner scope and dry-run gate

Дата: 2026-08-23

Статус: **OWNER OVERRIDES RECOVERED / APPLY BLOCKED**

Production: **не изменён; release не разрешён**

## Источник решений

Canonical server-side Architecture Studio прочитана в read-only режиме:

- path: `/opt/ustar/state/architecture-studio/target-decisions.json`;
- updated: `2026-08-22T13:38:37Z`;
- SHA-256: `4309e6c0f06bec3f837ab211467222aff0e9d3b268940c957753d5fc9e39af4a`;
- 30/30 верхнеуровневых decisions имеют `status=resolved`;
- `review.confirmed=false`.

Это доказывает, что прежние owner overrides существуют, но не превращает Studio revision в подтверждённый непротиворечивый TARGET.

## Восстановленный кадровый scope

Из canonical Studio извлечены 37 person overrides:

| Действие | Количество | Статус |
|---|---:|---|
| `REMOVE` | 26 | owner override recovered |
| `CHANGE` | 8 | owner override recovered; free text requires normalization |
| `MERGE` | 2 | participants recovered; survivor/source direction not explicit |
| `UNDECIDED` | 1 | unresolved |

Отдельно восстановлены account-type, organization, position, management и access-profile overrides. Принятые TARGET access-role names остаются:

`employee`, `manager`, `retail_manager`, `hr`, `hrd`, `ceo`, `system_admin`.

Точный ID-level пакет сохранён только локально вне Git/release bundle:

`private-hr-migration/HR_TARGET_ACTIONS_STUDIO_2026-08-22.json`

Его SHA-256:

`17794cc109072db69ba0818180796bdf43fd187afbcbfe6b29e30c04964b84e3`

Файл не публикуется в GitHub и не добавляется в переносимый source bundle, потому что это private кадровый release-control материал.

Локальный verifier `private-hr-migration/Test-HrTargetDryRun.ps1` выполнен против CURRENT export: 16/16 assertions PASS. Итог — `HR_TARGET_DRY_RUN=EXPECTED_BLOCK`; это доказательство корректного fail-closed gate, а не разрешение на apply.

## CURRENT reconciliation

Owner override IDs сопоставлены с CURRENT inventory от 2026-08-22:

- все 37 person IDs существуют в snapshot;
- три `REMOVE` уже имеют `deleted=1` и должны обрабатываться как audited no-op/archive verification;
- один `REMOVE` уже `suspended=1`;
- 54 undeleted CURRENT users не имеют явного person override и не могут автоматически считаться owner-approved `KEEP`;
- snapshot digests для users, custom fields, cohort memberships и role assignments закреплены в private action package.

## Blocking conflicts

Apply намеренно заблокирован до решения следующих групп конфликтов:

1. Final Review Studio не подтверждён.
2. Два MERGE-участника заданы без structured survivor/source direction.
3. Один человек одновременно имеет несовместимые person/account-type/organization решения.
4. Один кадровый аккаунт остаётся `UNDECIDED`.
5. Для одного административного аккаунта person и account-type решения не завершены одинаково.
6. Moodle Guest является системной записью и не удаляется как обычный сотрудник.
7. Core Moodle archetype roles нельзя физически удалить из-за бизнес-решения о семи TARGET access profiles; они должны остаться technical-only либо очищаться на уровне assignments/capabilities.
8. Для 54 действующих аккаунтов отсутствует explicit `KEEP / CHANGE / REMOVE / MERGE`.
9. Не утверждена collision policy для enrolments, course/module completion, grades, route progress, evidence, games/mastery, XP/USCOIN, boards и audit trail при merge.
10. Free-text `CHANGE` ещё не нормализован в точные department/position/access-profile mappings.

## Безопасный порядок следующего gate

До любой записи требуются:

1. owner-approved versioned action list для каждого undeleted CURRENT account;
2. явное направление каждой merge-пары;
3. разрешение всех конфликтов и `UNDECIDED`;
4. normalized current → target mapping;
5. dry-run отчёт с dependency counts и collision policy;
6. immutable migration audit и reversible transaction plan;
7. encrypted pre-change backup;
8. isolated apply + independent rollback rehearsal;
9. role/privacy acceptance по семи TARGET profiles;
10. отдельное production-release разрешение.

До этого момента `DELETE` трактуется как бизнес-намерение owner override, а не как разрешение выполнить Moodle user deletion.
