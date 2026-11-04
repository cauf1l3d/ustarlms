# Пакет исправлений 04.11.2026

Поручение: углубить аудит и подготовить архив с командами; реализация разрешена.
База приложения 5e61c0d2, документации 9504afa1; main не объединять, production не трогать.
Первый пакет: U01/U02/U05 (employee discovery/search/navigation). U03 admin picker отдельно: требует scoped pagination, не увеличения лимитов.
Решение: сохранить canonical ACL и writers, убрать ложные CTA, читать grants порциями без cap500, хранить только текущую страницу и ключи дедупликации. Общие N+1 не объявлять устранёнными.
Никаких новых схем/миграций/worker/публичного доступа. Frozen installers неизменны; новый successor от установленного SCORM5e61.
Проверки: regression RED→GREEN, native edge cases, действующие контракты/CI, operator failure/recovery/metadata/lock/lint, immutable archive identity.
Субагенты не запускаются по поручению владельца.

04Nov: application5dd12889: PHP regression RED(oldest absent)→GREEN, DOM RED(parent absent)→GREEN; полный npm PASS. 13operator tests:12PASS/1actualUID33 skipped locally (chown33 unsupported); dedicated sudo CI обязателен. Initial context CI выявил stale manifest: regenerated candidate NOT_DEPLOYED, production receipt preserved.
Углублённый Studio probe:6,563,690/65,637,890 sourcejson bytes для100/1000 проектов. U09/U10 отдельно, не входят в первый overlay.

Final self-review: bound untrusted page integer before multiplication (719bf47c). Source-only console registration203b3337. Package app now719bf47c; intermediate archive4e70764c not delivered and superseded before qualification.
