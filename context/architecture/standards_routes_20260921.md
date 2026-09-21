# Stage 4 — стандарты и маршруты: первый кодовый пакет

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

## Что ещё не закрыто этапом 4

- единый визуальный diff draft/published;
- browser acceptance Route Studio и drag-and-drop на интегрированном host;
- renewal/revert для всех видов evidence;
- перенос remaining controller logic и explicit standard entity после проверки
  фактической production-схемы.

Проверки этого пакета должны ссылаться на точный commit и CI; наличие класса или
локальный lint не считается production deployment.
