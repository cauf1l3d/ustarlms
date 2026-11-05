# Следующий пакет: редактор точки маршрута

> Исполнение одним агентом, без субагентов. Ревью и спецификация подготовлены по поручению owner05Nov; этот план не означает готовый source fix или новый deploy.

**Цель:** исправить невозможность скрыть шаг и потерю требований, сделать редактирование понятным.
**Архитектура:** существующие canonical route writers; новый codec/presenter внутри домена, без второй модели маршрутов.
**Стек:** PHP8.3/Moodle5.1.8, PostgreSQL16, Mustache/AMD/SCSS.
**Spec:** [дизайн](../specs/2026-11-05-route-editor-design.md).
**База следующего архива:** app719bf47c/toolaa61d595, owner5Nov active.

## 1. Доказательства и точный регрессионный контракт

Файлы: `context/reviews/20261105/`, `moodle/local/ustar/tests/route_studio_commands_test.php`, `tests/release_20260912/route_studio_dom.cjs`.

- [x] Прочитать ADR0004 и traced controller→commands→model→policy; воспроизвести loss `[SCORM,Quiz]→[Quiz]` настоящими projection/template/policy.
- [x] Отделить production report501 от воспроизведённого synthetic invalid_parameter; сохранить owner receipt отдельно.
- [ ] RED native test: несколько cm/курсов и mixed required; draft и metadata edit сохраняют все поля и порядок. Сохранить fixture с policy manual/timed и последним Quiz.
- [ ] RED HTTP/form cases: inactive POST не должен требовать пересборки source; неверный срок, отсутствующий source, stale revision, foreign point и role/view-as. Если владелец даст точный текст501, добавить его exact case; отсутствие trace не блокирует исправление уже подтверждённых дефектов.

## 2. Отдельная команда видимости

Modify: `classes/route_commands.php`, `route_studio.php`, `classes/route_model.php` только необходимый writer/presenter путь; tests как выше.

- [ ] Добавить `route_commands::set_point_active(array $command): stdClass`: routeid/pointid/actorid/expectedmodified/positionid/positionediting/active. Require positive expectedmodified; тот же real actor/structure manager/view-as, route membership, inherited/override scope, route lock, row lock и transaction. Не доверять переданному route/position без серверной проверки.
- [ ] Делегировать существующему metadata writer под lock; не вызывать create_version или требования публикации. Idempotent same-state не меняет историю; concurrent stale request даёт понятный отказ без частичной записи.
- [ ] Контроллер принимает dedicated action со своим POST/sesskey/revision. Общая точка скрывается только из общего маршрута; положение/override сохраняются.
- [ ] Native tests: active→inactive→active, нет новых route_versions/attempts/progress/coins; неактивная точка доступна редактору в «Скрытых», сотруднику отсутствует; возврат не публикует draft. Старые direct launch guards продолжают запрещать скрытую точку.

## 3. Представление требований без потерь

Create: `classes/route_studio_form.php` (input codec, без записи/публикации); modify `route_studio.php`, `templates/route_studio.mustache`, AMD src/build; tests native+DOM.

- [ ] `route_studio_form::rows(array $requirements): array` — стабильные строки всех supported требований; `::decode(array $input,array $base): array` — проверенные изменения относительно server-loaded base version. Точный immutable baseversion и point revision обязательны; hidden raw JSON не даёт права добавлять source.
- [ ] Форма повторяет несколько course/cm/assessment/native; required и content completionmode хранятся на строке. Skill primary/previous_adaptation сохраняют прежний contract. Удаление явное, порядок сохраняется; unresolved источники видны и не исчезают при unrelated save.
- [ ] Старые scalar параметры поддержать только как явный compatibility input; нельзя бесшумно заменить многозначный current набор однозначной формой. Missing/malformed array → отказ, не пустой набор.
- [ ] Native round-trip свойства для0/1/нескольких требований, Unicode/long labels, deleted/inactive sources, optional+required, mixed completionmode; публикация по-прежнему проверяет готовность источников и compatible policy.

## 4. Упростить UI и обработать ошибки

Create: `classes/route_studio_presenter.php` (read-only view model), focused Mustache partials при необходимости. Modify: controller, existing styles/route_v2.css и route_access_policy partial/AMD compiled build.

- [ ] Отдельные «Сохранить черновик» и «Опубликовать изменения», собственное «Скрыть/Вернуть». Текст явно объясняет published/draft/active и область воздействия.
- [ ] Основное: название/описание/список обучения; дополнительные секции навыков/повторения/SCORM с резюме текущих отличий. Новые JS controls keyboard-operable, default progressive enhancement; no-JS формы работоспособны.
- [ ] After rollback: error summary + inline errors + sanitized posted input; не терять title/summary/requirements. При stale revision оставить введённое для сверки и потребовать явного нового действия. Не проглатывать неожиданные exceptions под generic success.
- [ ] DOM/browser tests реального шаблона: add/remove/reorder rows, mixed required, collapsed controls submit, invalid row opens section/focus, Escape/back/unsaved input, parent vs override labels, mobile/reflow/contrast. Проверить реальные task flows автором отдельно от synthetic tests.

## 5. Стоимость чтения и готовность пакета

- [ ] Characterisation:10/100точек и100/1000источников — DBcalls/rows/bytes, form creation только выбранной точки, не JSON/версии всех точек для одной формы.
- [ ] Batch metadata/имена модулей и bounded searchable pickers; ACL до pagination/counts. Сначала измерить, затем изменить reader; writer/reconciliation не переносить в GET.
- [ ] Запустить focused native PostgreSQL, route/completion/SCORM/Quiz/override/history contracts, real DOM/browser и полный RC на точном source SHA.
- [ ] Самостоятельное отдельное ревью diff по trace: actor→scope→codec→writer→view, hidden point direct URL, current published vs draft, transaction/lock order, no deleted history. Зафиксировать отсутствие независимого reviewer по запрету субагентов.
- [ ] Новый immutable source-only successor от719bf47c; старыйaa61 не менять. Test precheck/source drift/locks/owner metadata/actualPHP/cache purge/interruption/recovery. Build/rebuild/CI/сохранённая копия побайтно равны. Дать владельцу архив и короткие check/apply/postcheck команды.

**Готово:** archive qualified и выдан; source implementation/CI и owner deployment остаются отдельными статусами. Нельзя закрывать reported501 как подтверждённо устранённый именно на его точке до owner retry/точного fixture. Остальные T01–T10 сохраняются; текущий запрос повышает приоритет редактора маршрута, не отменяет backlog.
