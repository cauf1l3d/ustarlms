# Дополнительные наблюдения server1 — 17 октября 2026

[observations.json](observations.json) — отдельный структурированный производный отчёт из owner `ustar-extra.neTVl2.zip`. Это не исходный stdout и не четвёртый снимок 31-block collector. [Происхождение](../../core_extra_received_20261017.json) содержит hashes исходного ZIP и всех 33 файлов probes; [разбор](../../../reviews/20261017/core_extra_reconciliation.md) объясняет результаты и ограничения.

Проверены CRC, безопасные имена и все checksums. SMART сообщает 09:59:14 UTC; остальные probes не содержат отдельных временных меток. Архивы переданы владельцем в 10:05:42 UTC. Старый task snapshot — 01:39:24 UTC; эти измерения не atomic и не одновременные.

Сохранены выбранный публичный NTP peer, состояния служб, фиксированные DB flags, все экспортированные SMART attributes (27 SSD / 17 HDD), ATA error logs и self-test history. Серийные номера, WWN, остальные NTP peers и полный сырой SMART не опубликованы. Vendor raw values сохранены как числа без выдуманных единиц TBW/ресурса. Исходные архивы не редактировались.

Оба SMART exit 64 означают установленный bit 6 — записи ATA error log; JSON/данные получены. ACL отсутствует в отчёте: команды завершились ошибкой из-за отсутствующего `getfacl`. Native bootstrap/task run, новый self-test, SQL/module activation, рестарты и service changes не выполнялись.
