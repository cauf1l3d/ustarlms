# Редактор точки маршрута — аудит и следующий пакет, 05.11.2026

Основание: owner сообщил ошибку «501 invalid parametr» при снятии активности/выборе черновика и сложность формы. Установленный app719bf47c, toolaa61d595; установка подтверждена отдельно. Этот документ фиксирует ревью и требования к следующему пакету; исправления редактора ещё не реализованы.

## Воспроизведённый дефект и граница вывода

[Probe](route_editor_probe.cjs) выполняет настоящий PHP-фрагмент проекции выбранных требований из route_studio.php, рендерит настоящий Mustache, затем вызывает настоящий route_point_access::validate_for_requirements. Модули37/40 синтетические; это не обращение к одноимённым production IDs. [Результат](route_editor_probe_result.json).

Версия содержит обязательный SCORM и необязательный Quiz. Проекция последовательно присваивает `selectedcm`, оставляя только последнюю активность. После рендера отправляется один Quiz; старые SCORM-правила остаются и сервер выдаёт invalid_parameter_exception «Правила повторного доступа применяются к точке со SCORM-курсом или SCORM-активностью». Исходные два требования проходят эту же проверку. Дефект подтверждён независимо от active/status: пересборка выполняется и при снятии активности, и при сохранении черновика. Checkbox active корректно отсутствует в POST, а controller использует default0 — сам PARAM_BOOL не является подтверждённой причиной.

Это подтверждённый сценарий того же класса ошибки, но **точная причина конкретной ошибки владельца пока не установлена**: нет полного текста исключения, ID/семьи точки и payload. HTTP501 не измерялся. В пакете нужны также cases удалённой активности, неопубликованного материала, недопустимого срока, stale revision и редактирования общего шага из вкладки должности; нельзя отключать валидатор ради прохождения формы.

## Находки

| ID | Факт и доказательство | Последствие / исправление следующего пакета |
|---|---|---|
| R01 / P1 | Controller919–925 хранит только один course/cm/assessment/native; форма — single select. Probe: `[37,40] → [40]`. | При простом сохранении исчезают существующие требования; при несовместимой accesspolicy отказ. Нужен список требований с точным round-trip, порядком и явным удалением; старые supported записи сохранять. |
| R02 / P1 | Controller197 и шаблон edit: один общий required, всегда checked. Probe исходный Quiz optional становится required. Completionmode для всех content также общий. | Обычная правка может менять обязательность/ознакомление. Параметры задавать на каждой строке; неизменённые сохранять точно. |
| R03 / P1 | Снятие active идёт через save_version → requirements_from_request → save_point_version; состояние точки и новый контент связаны одной формой. | Скрытие блокируется ошибками не меняемого содержания. Отдельная visibility-команда под теми же ACL/scope/lock/revision, без создания версии/пересборки требований. |
| R04 / P2 | admin_view_route3000 использует points(routeid) без includeinactive; points117 добавляет active=1. | Скрытая точка пропадает и из редактора. Добавить авторский список «Скрытые» и действие возврата; сотруднику скрытые не выдавать. |
| R05 / P2 | Draft не архивирует прежнюю published; current_published_version ищет опубликованную независимо от latest draft. Это существующий корректный version contract. | Пользователь ожидает удаления шага при выборе draft. Явно разделить «Сохранить черновик», «Опубликовать изменения», «Скрыть шаг». Указать кто продолжает видеть текущую публикацию. |
| R06 / P2 | POST transaction rollback выбрасывает исключение наружу (controller724), нет render form errors/input state. | Пользователь получает техническую страницу вместо понятной ошибки и продолжения редактирования. Возвращать введённое, field errors, summary; при конфликте не подменять revision автоматически. |
| R07 / P2 | Probe:29 input/select/textarea,4fieldset,0details только в edit; одновременно показываются разные источники обучения, навыки, SCORM-политика, повтор и публикация. | Когнитивная сложность подтверждена owner; counts описывают форму, не usability score. Основные поля и список обучения сверху, дополнительные настройки раскрываются по запросу/если изменены. |
| R08 / P2 | Controller строит каталог всех материалов и модулей, имена модулей читает по одному; затем на каждую точку latest/published/scope/revision/active и отдельные версии в admin_view. | Стоимость зависит от всего маршрута/каталога, даже для одной открытой формы. Разделить presenter и input codec, редактируемую форму строить только для выбранной точки; замерить DB calls/rows/bytes, затем batch/bounded pickers. Это статический риск; production latency не измерена. |

## Принцип изменения

Существующие route_commands/route_model/route_scope остаются canonical writers и областью прав. ADR0004 сохраняется: общий шаг меняется в общем маршруте, должностной override — в своей области. Нет удаления published versions, progress, attempts, completion cycles или наград. Черновик и скрытая точка — разные состояния.

[Спецификация следующего пакета](../../../docs/superpowers/specs/2026-11-05-route-editor-design.md), [план реализации](../../../docs/superpowers/plans/2026-11-05-route-editor-remediation.md). База следующего immutable owner-архива — установленный719bf47c, не5e61 и не main2900. Старыйaa61 после установки не повторять.

## Точные источники установленной версии

- [Проекция требований](https://github.com/cauf1l3d/ustarlms/blob/719bf47c1ba5942c094a04312898890ec7de00c1/moodle/local/ustar/route_studio.php#L919).
- [Сборка требований POST](https://github.com/cauf1l3d/ustarlms/blob/719bf47c1ba5942c094a04312898890ec7de00c1/moodle/local/ustar/route_studio.php#L193).
- [Сохранение active через новую версию](https://github.com/cauf1l3d/ustarlms/blob/719bf47c1ba5942c094a04312898890ec7de00c1/moodle/local/ustar/route_studio.php#L589).
- [Валидация SCORM-политики и для draft](https://github.com/cauf1l3d/ustarlms/blob/719bf47c1ba5942c094a04312898890ec7de00c1/moodle/local/ustar/classes/route_model.php#L1062).
- [Требование SCORM](https://github.com/cauf1l3d/ustarlms/blob/719bf47c1ba5942c094a04312898890ec7de00c1/moodle/local/ustar/classes/route_point_access.php#L50).
- [Published reader](https://github.com/cauf1l3d/ustarlms/blob/719bf47c1ba5942c094a04312898890ec7de00c1/moodle/local/ustar/classes/route_model.php#L295).
- [Отсечение неактивных в admin view](https://github.com/cauf1l3d/ustarlms/blob/719bf47c1ba5942c094a04312898890ec7de00c1/moodle/local/ustar/classes/route_model.php#L3000).

Воспроизведение: `USTAR_PHP=/path/to/php node context/reviews/20261105/route_editor_probe.cjs`.
Нужны существующие зависимости `tests/release_20260912`; не подключается к Moodle/БД/сети. Probe фиксирует установленный дефект; после исправления используется как dated historical reproduction, а новые regression tests должны требовать сохранности round-trip.
