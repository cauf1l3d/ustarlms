# USTAR — начать здесь

**Актуальный план:** [Roadmap](context/roadmap/README.md). **Стадия: S0 — сверка релизной базы и стабилизация. Следующая задача: R00.**

Для продолжения не нужен предыдущий чат. Прочитать:

1. [STATE.yaml](context/roadmap/STATE.yaml) — кодовая база, что известно о production, статусы ранее подготовленных пакетов.
2. [ACTIVE.md](context/tasks/ACTIVE.md) — ближайший шаг.
3. [BACKLOG.yaml](context/roadmap/BACKLOG.yaml) — 21 задача с зависимостями, файлами и проверками.
4. [Проверка аудитов](context/roadmap/AUDIT_2026-09-07.md) — подтверждённые проблемы и исправления устаревших выводов.
5. [Протокол ИИ](context/roadmap/AGENT_PROTOCOL.md) — как брать задачу, проверять результат и обновлять статус.

## Какой код изучать

План опубликован в main, но **проверенная современная база кода находится в другой ветке**:

`feature/route-position-parent-override` · commit `e71bca2856bc0f80e39cb9ed1cee6098934e232a`.

[Открыть код и контекст проверенной базы](https://github.com/cauf1l3d/ustarlms/tree/e71bca2856bc0f80e39cb9ed1cee6098934e232a).

На старте проверки main = `5443bf5ab2c000a0fc019d3b0b30dcff0a60a7ff`. Документационный commit не сливает прикладной код, не меняет default branch и не является release. До закрытия R00 нельзя считать checkout main точной копией сегодняшнего production. Если branch HEAD изменился, сначала сопоставить diff и обновить baseline.

## Обязательный архитектурный контекст

Пока R00 не свёл ветки, читать эти существующие файлы по указанному commit или в checkout соответствующей ветки:

1. [context/project.yaml](https://github.com/cauf1l3d/ustarlms/blob/e71bca2856bc0f80e39cb9ed1cee6098934e232a/context/project.yaml)
2. [context/CONTEXT_INDEX.md](https://github.com/cauf1l3d/ustarlms/blob/e71bca2856bc0f80e39cb9ed1cee6098934e232a/context/CONTEXT_INDEX.md)
3. [context/architecture/](https://github.com/cauf1l3d/ustarlms/tree/e71bca2856bc0f80e39cb9ed1cee6098934e232a/context/architecture)
4. [context/decisions/](https://github.com/cauf1l3d/ustarlms/tree/e71bca2856bc0f80e39cb9ed1cee6098934e232a/context/decisions)
5. [context/domains/](https://github.com/cauf1l3d/ustarlms/tree/e71bca2856bc0f80e39cb9ed1cee6098934e232a/context/domains)
6. [context/runtime/](https://github.com/cauf1l3d/ustarlms/tree/e71bca2856bc0f80e39cb9ed1cee6098934e232a/context/runtime) — датированный snapshot, не проверенный manifest сегодняшнего prod
7. [context/code_map/](https://github.com/cauf1l3d/ustarlms/tree/e71bca2856bc0f80e39cb9ed1cee6098934e232a/context/code_map) — сверять пути с кодом
8. [Исторический context/tasks/ACTIVE.md](https://github.com/cauf1l3d/ustarlms/blob/e71bca2856bc0f80e39cb9ed1cee6098934e232a/context/tasks/ACTIVE.md) — TASK-0001/0002 отражены в текущем [ACTIVE](context/tasks/ACTIVE.md)
9. [context/agents/astra.md](https://github.com/cauf1l3d/ustarlms/blob/e71bca2856bc0f80e39cb9ed1cee6098934e232a/context/agents/astra.md), [constraints](https://github.com/cauf1l3d/ustarlms/blob/e71bca2856bc0f80e39cb9ed1cee6098934e232a/context/constraints.yaml), [AGENTS базы](https://github.com/cauf1l3d/ustarlms/blob/e71bca2856bc0f80e39cb9ed1cee6098934e232a/AGENTS.md)

Не создавать второй Org/Evidence/Economy-домен. ADR-0002 задаёт целевой Org и legacy JSON, ADR-0003 — canonical evidence_rec, ADR-0004 — физическую parent point и scoped override с сохранением истории.

## Зафиксированный приоритет владельца

Рефакторинг через проверенные пользовательские сценарии; рабочая студия маршрутов; отсутствие багов и удобный интерфейс; **каждое новое подтверждённое прохождение точки награждается и геймифицировано**. Существующую геймификацию и достижения не замораживать по старому внешнему плану. XP, USCOIN и competition points имеют разные значения.

Подготовленные ZIP-патчи из предыдущей работы не считать установленными/закоммиченными без evidence. Их статусы уже записаны в STATE. Не выполнять произвольный sync или кадровую миграцию в production ради чтения статуса.
