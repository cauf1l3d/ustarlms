# Свежие наблюдения сервера

Оба owner archives17Oct **получены и импортированы**. [Provenance](../server_facts_received_20261017.json), [сверка](../../reviews/20261017/runtime_reconciliation.md). Первый снимок01:00:21Z содержит17ok/13error, второй01:12:27Z —28ok/2error; SMART не запрашивался. Latest manifest526 совпал с baseline. Scheduled task list/query stats недоступны; clock values неизвестны. Здесь нет результатов локальной песочницы, выданных за production.

[Сбор и импорт](../../../scripts/infra/README_server_facts.md), [разбор внешнего предложения](../../reviews/20261017/README.md). Последний принятый указатель — `STATE.yaml → server_facts.snapshot`; [current.yaml](../../current.yaml) генерируется из STATE.

Каждый каталог `YYYYMMDDTHHMMSSZ-hash` содержит неизменённые `facts.json`, `errors.json`, `manifest.json` и `README.md`. Guard проверяет закрытую схему, все контрольные суммы и согласованность ошибок. Импорт не удаляет неуспехи и не переписывает адреса в проверяемом наборе. Checksums подтверждают целостность, а не цифровую подпись источника.

`complete` означает полноту запрошенного набора наблюдений; это не здоровье сервера, успешный restore, готовность TLS/SMTP или закрытие INF-02/03/07. Частичный снимок остаётся `partial`. Источник — скрипт, выполненный владельцем на host; криптографически независимая идентификация host не утверждается.
