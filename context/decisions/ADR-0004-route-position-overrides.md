# ADR-0004 Route Position Override Model

Status:
Accepted

Date:
2026-09-06

## Context

USTAR использует семейства маршрутов:

Department parent route
        |
        +-- Position routes

Ранее позиционные маршруты могли материализовать копии родительских точек.

Это приводило к:
- расхождению контента;
- сложному контролю изменений;
- невозможности определить источник шага;
- дублированию требований.

## Decision

Единицей истины является физическая точка родительского маршрута.

Изменение для конкретной должности выполняется через override:

parent point
        |
        +-- position override
                |
                +-- sourcepointid

Override:
- принадлежит одной должности;
- сохраняет связь с родителем;
- имеет собственную applicability scope.

## Rules

1. Общая точка редактируется только в parent route.

2. В маршруте должности:
   - если точка принадлежит только должности -> прямое редактирование;
   - если точка общая -> создаётся override.

3. Возврат к общему состоянию:
   - не удаляет историю;
   - меняет состояние scope на reverted.

## Implementation

Affected:

- local/ustar/route_studio.php
- local/ustar/classes/route_scope.php
- local/ustar/templates/route_studio.mustache

## Migration impact

Database:

local_ustar_route_points
- sourcepointid
- inheritstate

local_ustar_route_scope
- state
