# Сверка Moodle core — 17 октября 2026, 10:00:15 UTC

[core_comparison.json](core_comparison.json), [collector.exit](collector.exit) и [checksums.sha256](checksums.sha256) сохранены побайтно из owner ZIP; вложенный `core-report.tar.gz` содержит те же байты. [Происхождение и hashes](../../core_extra_received_20261017.json), [разбор](../../../reviews/20261017/core_extra_reconciliation.md), [validation](../../../reviews/20261017/core_extra_validation.json).

Эталон — официальный Moodle 5.1.8, commit `cc0bf17da8b5fbd290faf95b2b14a03d64331923`. Все 24 742 reference paths после исключений совпали; changed/missing/errors/skipped — 0. Набор matching независимо сопоставлен с точным Git tree. `complete=true`, `match=false`, exit 3: обнаружены 23 дополнительных имени, а не ошибка выполнения collector.

Это не полный манифест всего deployment: config, hidden paths, tests, node_modules, root vendor и перечисленные в отчёте plugin roots исключены. Extras — только имена, без содержимого/hash/прав или HTTP-доступности. Проход не atomic; идентификация хоста основана на сообщении владельца. Отдельный plugin/theme manifest от 01:39:24 UTC не переименован в новое измерение.

Из загруженного `reference.git` использованы только pack/idx для чтения объектов в новом доверенном bare repository. Загруженные config/hooks/скрипты не запускались и в Git проекта не добавлены. Большой JSON намеренно выходит за бюджет общего поискового индекса; открывать его напрямую по ссылке выше.
