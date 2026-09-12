# USTAR — начать здесь

Обновлено: **2026-09-12**. **Актуальный код патчей находится в integration/ustar-20260912**, каталог `moodle/`. Кодовая поставка: `89ba18b2130040fbdf7f0ca02bef4999d5ef1fdb`; последующие изменения контекста не меняют этот код.

1. [STATE.yaml](context/roadmap/STATE.yaml) — точная база и отдельно production evidence.
2. [ACTIVE.md](context/tasks/ACTIVE.md) — ближайшие действия.
3. [BACKLOG.yaml](context/roadmap/BACKLOG.yaml) и [протокол](context/roadmap/AGENT_PROTOCOL.md).
4. [Сводка поставки](release/20260912/README.md), [итоговые хэши патчей](release/20260912/effective_files.json), [runtime evidence](context/runtime/20260912_release.md).
5. [Индекс контекста](context/CONTEXT_INDEX.md), [project](context/project.yaml), [constraints](context/constraints.yaml), [architecture](context/architecture/), [ADR](context/decisions/), [domains](context/domains/), [code map](context/code_map/), [agent](context/agents/astra.md).

## Что актуализировано

Восстановлена база из предоставленных исходников/runtime и установленного UX RC1; затем сведены Page/native переходы, командный тест, карьерная лестница, профиль, сохранение HRD-оценок и итоговый SCORM. Использовать последний код: промежуточные BELOW/FLUSH-варианты superseded. В финальном SCORM нет внешней плашки; переход после «Изучено» и подтверждения Moodle; высота плеера задаётся в пикселях под навбаром.

Пользователь сообщил: «отлично вроде работает». Это предварительная пользовательская проверка финального результата, **не полный измеренный manifest сервера**. R00 остаётся review: код сведён, независимая сверка всех production-файлов/схемы и полный smoke ещё нужны. Не запускать старые ZIP поверх текущего кода.

## История и границы

Исходный аудит 07.09: `e71bca2856bc0f80e39cb9ed1cee6098934e232a`, прежняя feature `feature/route-position-parent-override`. Сохранены история ветки, ADR и датированные runtime-снимки. Базовый [аудит](context/roadmap/AUDIT_2026-09-07.md) описывает состояние на свою дату, а не повторную проверку сегодняшнего кода.

Не создавать второй Org/Evidence/Economy-домен, не менять Moodle core, не удалять историю и не подделывать completion. Рабочая студия, надёжное прохождение, награды за новые подтверждённые точки, геймификация и достижения остаются приоритетами. Дальнейшие задачи не считаются выполненными только из-за синхронизации репозитория.

Границы публикации и исключения: [PUBLICATION_SCOPE](release/20260912/PUBLICATION_SCOPE.md). Перед изменением кода переключитесь на интеграционную ветку. Генерируемые code map и исторические runtime-снимки имеют собственные даты; они не являются измерением текущего production.
