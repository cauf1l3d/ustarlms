# Дополнительный сбор — 17 октября 2026, 10:59:16 UTC

[observations.json](observations.json) — allowlisted производный отчёт из owner `ustar-gaps.GEOh4P.zip`. [Provenance](../../server_gaps_received_20261017.json) содержит hash ZIP, hashes всех 24 probe-файлов и ограничения происхождения. [Разбор](../../../reviews/20261017/gaps_reconciliation.md), [validation](../../../reviews/20261017/gaps_validation.json).

Все шесть probes имеют exit 0 и пустой stderr. Исходная ACL-выгрузка 6 415 419 bytes содержит 44 175 уникальных объектов; полный raw ZIP/ACL остаётся приватным. В derivative сохранены counts, выбранные anchors/parents, все 23 extra metadata/hashes, SSH allowlist, Caddy listener identity и четыре selected du values. Файлы приложения/config contents не экспортировались.

Источник — inline command предыдущего ответа, выполненный владельцем; self-hash скрипта в архиве нет. ZIP folder не содержит соседнего tar. Все explicit probe timestamps имеют точность 1s и равны 10:59:16 UTC; это не утверждение нулевой длительности или atomic snapshot. Владелец отдельно сообщил установку пакета acl2.3.2-1build1.1; apt transcript не входит в checksums. Перезапусков/изменений служб агент не выполнял, actual live daemon acceptance не утверждается.
