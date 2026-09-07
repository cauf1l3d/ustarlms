# Протокол продолжения для ИИ

## Канонический статус и ветки

Roadmap, STATE и BACKLOG ведутся в `main:context/roadmap/`. Ссылки из feature-контекста указывают сюда. Не заводить вторую независимо редактируемую копию статусов в feature, Notion или чате.

До R00 код main не является проверенной современной базой. Сначала прочитать START_HERE, STATE и соответствующий BACKLOG ticket, затем получить код по `code_baseline.commit`. Если HEAD сдвинулся, проверить diff релевантных файлов и обновить evidence; нельзя применять старый patch только по совпадению имени.

Если checkout находится в feature-ветке, прочитать канонические документы через `git show origin/main:context/roadmap/STATE.yaml` и соседние файлы либо по GitHub-ссылкам. Если работа офлайн и refs не обновлены — явно назвать время/commit своей копии. Метка CURRENT без даты и источника запрещена.

## Порядок чтения

1. START_HERE.md и `context/roadmap/STATE.yaml` текущего main.
2. `context/tasks/ACTIVE.md`, нужная запись BACKLOG и связанные audit findings.
3. **Из указанной базы кода:** `context/project.yaml`, `context/CONTEXT_INDEX.md`, `context/architecture/`, `context/decisions/`, `context/domains/`, `context/runtime/`, `context/code_map/`, `context/tasks/ACTIVE.md`, `context/agents/astra.md`, `AGENTS.md` и constraints.
4. Код всех входов/записей/чтений изменяемого сценария. Проверить свои предположения по XMLDB и runtime evidence.

Текущая инструкция пользователя имеет приоритет над историческими рекомендациями внешнего аудита. Код показывает фактическое поведение, ADR — принятое архитектурное решение, runtime — наблюдение конкретного deployment. Эти источники нельзя подменять друг другом. Противоречие фиксируется, а не разрешается произвольным «весом доверия».

## Взять задачу

- Выбрать `ready` задачу без незакрытых dependencies; ближайшая — `STATE.next_task`.
- `planned` задачу можно готовить, но зависимости обязательны до merge/release соответствующей реализации. `deferred` не выполнять без нового приоритета.
- Изменить status на `in_progress`, указать owner/session, branch и started_at. Проверить, что другой исполнитель не владеет ей. Не перезаписывать чужие изменения статуса.
- Фиксировать проверяемое поведение до рефакторинга для важных операций с состоянием. Для небольшого CSS-исправления достаточно целевой браузерной проверки; не писать тесты, повторяющие CSS.
- Сохранять границы Moodle core / local plugin / theme. Не менять БД из UI-патча. Не удалять историю и не выполнять кадровые merge/remove из старых приватных планов.

## Статусы и готовность

| Поле/status | Значение |
|---|---|
| ready | Можно начать определённую работу; это не «готово к prod» |
| in_progress | Есть владелец и рабочая ветка |
| blocked | Есть конкретный blocker и уже сделанные полезные шаги |
| review | Есть implementation commit и результаты проверок для ревью |
| stage_verified | Проверен точный SHA на изолированном runtime |
| released | Код/миграции установлены, manifest и post-deploy smoke приложены |
| done | Все acceptance выполнены; для кода production evidence есть; для docs/исследования достаточен опубликованный результат |
| deferred | Намеренно за текущими этапами |

В ticket отдельно указывать `implementation_commit`, `validation_evidence`, `deployment_evidence`. «Класс существует», «архив отправлен», «lint зелёный» и «работает в production» — разные статусы. Не ставить done автоматически после генерации файла.

## Что сдавать

1. Проблема и поведение после изменения, ticket ID, кодовые ссылки.
2. Изменённые файлы, schema/ACL/business-policy impact.
3. Реально выполненные проверки, точный SHA, environment, ограничения. `not_run` с причиной честнее PASS из чужого отчёта.
4. Проверяемый артефакт/PR, manifest, preflight и откат для production-поставки.
5. Обновлённые BACKLOG/STATE/ACTIVE и SESSION_LOG в каноническом main или docs-PR к нему с ссылкой на кодовый PR. Ссылки должны совпадать по SHA; не переписывать audit snapshot задним числом.

## При блокировке runtime-доступа

Закончить всё, что можно проверить по коду: diff, fixtures, manifest template, migration preflight, тесты/пакет. Затем записать конкретное недостающее evidence. Не объявлять entire project blocked и не обходить preflight. Старые `check_route_user`/assessment probes сначала прочитать: часть запускает sync и пишет данные, несмотря на диагностическое имя. Не запускать их на production как якобы read-only.

## Обновление контекста

STATE меняется при принятии стадии/следующей задачи; BACKLOG — при изменении task state; SESSION_LOG — после каждой поставки. При runtime observation сохранять measured_at, source, code SHA, image/version/schema и known drift. Не публиковать dumps, credentials, персональные кадровые решения и необезличенные журналы. Автогенератор индекса должен исключать собственный output и не выдавать жёстко заданные пути за измерение.

Не менять default branch, branch protection, runtime и продуктовую архитектуру только ради публикации документа. После R00 отдельно согласовать/реализовать перенос проверенного кода в нормальную integration/release ветку и обновить pointers. Историческая feature-ветка не является вечным новым main.
