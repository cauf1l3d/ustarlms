# Протокол работы с USTAR

1. Следовать единственному порядку чтения из [START_HERE](../../START_HERE.md). Последняя команда владельца17Oct: контекст/сбор фактов, повторное review, затем репо/пульт/Telegram; она приоритетнее исторических next-step runbooks.
2. Работать от свежего main. Application baseline, repository head и deployed core/host различать явно. [Ветки и происхождение](../runtime/repository_state_20261016.json).
3. Native Academy состоит из local_ustar и theme_ustar. Legacy frontend исключён из release/CI. JS/AMD/Mustache/native browser tests остаются.
4. Не подменять owner receipt, synthetic CI и native acceptance. Для каждой поставки фиксировать exact SHA, реальные проверки и открытые gates.
5. Не повторять закрытые native этапы ради нового статуса. Архив передан сисадмину; новый restore пока ожидается. Moodle5.2/PG17 отложены.
6. Соблюдать actor/capability/scope/sesskey/revision/lock/idempotency, одну бизнес-модель и XMLDB parity; проверять прямые URL/files/view-as и отказные сценарии по масштабу изменений.
7. Не публиковать runtime secrets/config/data/logs. Сначала подготовить reviewable план операции; не отправлять private backup/key или сообщения через Telegram из этой задачи.
8. Сопровождать изменения актуальными STATE/ACTIVE/BACKLOG/SESSION_LOG и context/source inventories. Исходный аудит и dated receipts сохранять.
9. Генерировать current/checkpoint из STATE/BACKLOG; design/access inventory из исходников. Входящие server facts валидировать до записи, не удалять errors и не подменять их нулевыми/здоровыми значениями. Сбор не выполняет Moodle bootstrap или HTTP к приложению.

[Следующий этап INF-06](../runtime/control_panel_telegram_20261016.md) и [сверка аудита](../audits/AUDIT_RECONCILIATION_20261016_RU.md). Документы не добавляют новых permission loops: действовать в уже разрешённом владельцем объёме.
