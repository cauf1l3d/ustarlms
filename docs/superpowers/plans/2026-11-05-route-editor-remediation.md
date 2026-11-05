# Пакет редактора точки маршрута — выполнение05Nov2026

Исполнение одним агентом по поручению owner, без субагентов. База — установленный app719bf47c/toolaa61d595. [Спецификация](../specs/2026-11-05-route-editor-design.md), [первичное ревью](../../../context/reviews/20261105/ROUTE_EDITOR_REVIEW_RU.md), [решения реализации](../../../context/reviews/20261105/ROUTE_EDITOR_IMPLEMENTATION.md).

## Реализовано

- [x] R01/R02: codec всех строк относительно immutable server base, индивидуальные required/completionmode/primary, точное покрытие базовых строк и явное удаление. Порядок сохраняется; чужой/повторный индекс и оборванный список отвергаются. Сервер разрешает новые источники из канонического каталога. Старый scalar controller input не поддерживается новой формой: вместо молчаливой потери списка — отказ.
- [x] R03/R04: отдельный set_point_active с real actor/capability/view-as/scope/revision, route+row lock и транзакцией. Нет создания версии; скрытые карточки видны редактору, employee readers сохраняют прежний active-only default. Общие шаги меняются в общем маршруте; override использует существующий возврат общего варианта по ADR0004.
- [x] R05: draft/publish/visibility разделены. Черновик оставляет прежнюю публикацию сотрудникам. Структурно корректная несовместимая SCORM-policy может храниться в черновике; обе публикационные ветки по-прежнему проверяют совместимость.
- [x] R06: summary ошибки с фокусом, сохранение scalar input и строк, отсутствие автоматического обновления stale base/revision. Неожиданные DML/permission ошибки не маскируются успехом. Удалённый источник — понятный отказ.
- [x] R07: основная форма — название, описание и строки; SCORM/повторение в details с резюме текущих правил. Раздельные кнопки с объяснениями. Поиск/добавление строк работают обычным POST без JS, сохранённые источники не исчезают при поиске. Сохранён direct file/video upload. Исправлены HTML min/step для минутного интервала и перемещение стрелками через скрытые карточки.
- [x] R08 в ограниченной области: имена модулей batch по типу, форма строится только для выбранной точки, до200 совпадений нового выбора с сохранением выбранного. [Probe](../../../context/reviews/20261105/module_names_probe_result.json):100/1000модулей двух типов →2 вызова DB API,100/1000строк. Это синтетические вызовы/строки, не PostgreSQL latency.
- [x] Новый immutable source-only successor; прежние операторы/журналы неизменны,653fixed files/108tables, схема/worker/business data не меняются. Tests: source drift/shared locks/private journal/metadata/xattrs/cache purge/interruption/recovery; actual PHP и UID33 в CI.

- [x] R09: контекст PAGE задаётся после авторизации до форматирования каталога оценок. Подтверждённый native HTTP500 исправлен; ранний обязательный HTTP job проверяет открытие и сохранение редактора.

## Проверки и выдача

- [x] Исторический RED `[SCORM,Quiz]→[Quiz]` настоящими projection/Mustache/policy; новый PHP codec RED missing class→GREEN. DOM regressions минутного интервала, hidden reorder и upload RED→GREEN.
- [x] Реальные Mustache/DOM и browser fixtures1440/390: значения строк, независимая обязательность, advanced controls в FormData, сохранение ввода, отсутствие горизонтального выхода. Визуально просмотрены оба снимка; это не live production UI.
- [x] Самостоятельный trace review actor→scope→codec→writer→view, draft vs published, сохранение истории, route lock/baseversion. Независимого reviewer нет по запрету субагентов.
- [x] Итоговый native PostgreSQL5.1.1/5.1.8 + настоящий authenticated GET/POST draft/hide/restore/invalid input; полный RC на точном операторном SHA. Квитанция заполняется после завершения, не по ожиданию.
- [x] Rebuild/CI artifact/сохранённая выдаваемая копия побайтно равны; архив/SHA/команды выданы владельцу.
- [ ] Установка и повтор проблемного действия владельцем — отдельный runtime receipt, не вывод из CI.

## Границы и остаток

Промежуточные кандидаты не выданы. Первый native прогон40c5b4a0 отвергнут: продолжение теста после Moodle delegated rollback, сравнение порядка associative keys и cookie expiry0 в HTTP harness. Fixtures исправлены. Затем реальный HTTP выявил отсутствие PAGE context перед форматированием; исправлено6c4f30b6, сценарии5.1.8 прошли45e9d6b7. Последний отказ historical5.1.1 вызван отсутствием Python3 в тестовом образе; зависимость добавлена1c43377b, финальный RC1c43377b прошёл полностью.

Полная стоимость route reader/admin history остаётся O(N); массовая гидратация метаданных и часть runtime_ready ещё читают весь каталог. Замеры10/100точек, bytes/SQL/latency, ordinary-workday PERF-01 и общий T04 остаются открыты. Batch двух типов не доказывает весь бюджет страницы.

Per-field error mapping, Escape/back/unsaved guard, arbitrary requirement drag reordering, контраст/WCAG и испытание реальными авторами не заявлены как законченные. Сохранение порядка, keyboard штатных кнопок, no-JS server preview и synthetic reflow проверяются в указанной области. Редактор не добавляет вторую модель маршрутов или должностное исключение общего шага.

Reported owner invalidparameter точно не локализован без trace. Исправлен подтверждённый сценарий потери требований и независимое скрытие; окончательное закрытие owner case — после retry. Остальные T01–T10 и исходные аудиты не отменены этим пакетом.

Квалифицирован app6c4f30b6/tool45e9d6b7 на1c43377b: [квитанция](../../../context/runtime/route_editor_qualification_20261105.json). Установка владельцем и точная приёмка его точки остаются открыты.
