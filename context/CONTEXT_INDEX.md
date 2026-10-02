# Индекс контекста USTAR

Актуальность: 02.10.2026. **Канонический код и контекст — main.** Application SHA и границы подтверждения: [STATE](roadmap/STATE.yaml).

| Вопрос | Авторитетный источник |
|---|---|
| Что за продукт, какой стек | [project](project.yaml), [architecture](architecture/overview.md) |
| Какие ограничения приняты | [boundaries](architecture/boundaries.md), [ADR index](decisions/README.md), [constraints](constraints.yaml) |
| Как работают домены | [domains](domains/), [карта кода](code_map/README.md) |
| Где source и что реально установлено | [STATE](roadmap/STATE.yaml), [release ledger](runtime/RELEASE_LEDGER.md) |
| Как проверить/выпустить/откатить | [operations](runtime/OPERATIONS.md) |
| Над чем работать дальше | [ACTIVE](tasks/ACTIVE.md), [BACKLOG](roadmap/BACKLOG.yaml), [mobile](roadmap/MOBILE_CLIENT.md) |
| Как агенту продолжать | [astra](agents/astra.md), [protocol](roadmap/AGENT_PROTOCOL.md) |
| Какие классы/таблицы/API существуют | [generated manifest](code_map/generated/source_manifest.json), [schema](code_map/generated/database_tables.md), [API](code_map/generated/web_services.md) |
| Почему старый документ говорит другое | Дата документа, [архив консолидации](archive/handoff_20261002/README.md), [session log](roadmap/SESSION_LOG.md) |

`context/index/context_index.json` — машинный поисковый индекс с hash содержимого; он не определяет статус релиза. Датированные audit/release/runtime документы сохраняют свои исходные утверждения и не становятся текущими от того, что находятся в main.
