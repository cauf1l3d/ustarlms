# Stage 4 — стандарты и маршруты

Дата: 2026-09-21

Этот пакет продолжает шесть этапов рефакторинга после регистрации и HR-пульта
(PR #6). Он не меняет production и не переносит кадровые данные.

## Граница пакета

- `local_ustar_route_points` остаётся стабильной логической точкой маршрута.
- История содержания хранится в `local_ustar_route_versions`; опубликованная
  версия выбирается только после проверки её требований.
- `route_scope` остаётся единственным resolver для parent/local/override в
  runtime и Route Studio. Старый materialisation используется только как
  совместимость для схемы до появления таблицы scope.
- `route_commands` — application boundary между HTTP/CLI-входом и route model.
  HTTP-страница проверяет Moodle sesskey/capability, команда повторно проверяет
  target point, должность и границы локального override.

## Атомарность

`route_model::save_point_version()` под одним route lock и одной транзакцией:

1. блокирует точку и проверяет `expectedmodified`;
2. валидирует фазу, активность и requirements;
3. сохраняет metadata точки и новую immutable version;
4. при публикации проверяет опубликованный контент, архивирует прежнюю
   опубликованную версию и фиксирует `effectivedate`;
5. при любой ошибке откатывает metadata и version вместе.

`route_model::publish_version()` публикует уже проверенный draft без изменения
его payload и идемпотентно возвращает уже опубликованную версию.

`route_model::reorder()` принимает полный список активных точек и отклоняет
неполный или устаревший `revision`; это предотвращает тихое частичное сохранение.

## Второй пакет — diff перед публикацией

Route Studio теперь получает diff только для новейшего draft относительно текущей
published-версии. `route_model::version_diff()` сравнивает immutable payload полей
названия, описания и renewal policy, а также нормализованные requirements. В UI
изменения показываются как «Опубликовано → Черновик»; добавленные и удалённые
условия перечислены отдельно. Если draft ещё не опубликован, сотрудник продолжает
видеть прежнюю публикацию. История archived-версий не становится редактируемой и
не подмешивается в текущий diff.

## Третий пакет — безопасные scope-команды

`route_commands::create_override()` и `revert_override()` теперь сами получают
тот же route lock и delegated transaction, что и save/publish. Поэтому прямой
CLI/API-вызов не может обойти атомарность HTTP-студии. Повторное создание
переопределения остаётся идемпотентным, возврат переводит scope в `reverted`,
отключает только локальную физическую точку и сохраняет её версии/факты.

## Четвёртый пакет — lifecycle evidence

Все шесть типов evidence (`learning`, `assessment`, `practice`,
`manager_review`, `checklist`, `certification`) используют один
immutable lifecycle. Продление создаёт новый факт с отдельным idempotency key и
связывает его с прежним событием `renewed`. Отзыв и восстановление добавляют
`revoked/restored` в журнал, не удаляя и не переписывая исходные факты.
Текущее состояние выводится по последнему событию с дополнительной проверкой
`validfrom/expiresat`.

## Пятый пакет — явная сущность стандарта

Добавлены `local_ustar_standards` и `local_ustar_standard_ver`. Стабильный
код и название стандарта отделены от immutable requirement-версий. Публикация
атомарно архивирует прошлую версию и переключает `activeversionid`; повторная
публикация идемпотентна. Требования нормализуются по поддерживаемым типам evidence,
а маршруты остаются независимым presentation/order-слоем.

## Шестой пакет — browser acceptance Route Studio

CI запускает Route Studio в jsdom и проверяет пользовательский контракт:
перемещение стрелками и drag lifecycle, перенумерацию, фактический порядок
`pointids[]`, блокировку крайних кнопок, revision/expectedmodified, а также
формы create override и revert. Полный DB job отдельно проверяет команды,
транзакции, install/upgrade/repeat-upgrade и PostgreSQL.

## Статус этапа 4

Все шесть пакетов собраны в PR #7. Production не менялся; deployment возможен
только после review, merge и отдельного подтверждённого выпуска. Проверки должны
ссылаться на точный commit и CI; наличие класса или локальный lint не считается
production deployment.
