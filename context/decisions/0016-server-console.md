# ADR-0016: Server console

Дата:26.10.2026. Статус: принято в рамках текущей сверки; описывает границы, а не новый deploy.

server-console — same-host operations UI с собственной auth DB, не второй аккаунт
Академии. Разделение web/notifier и токенов сохранено. Привилегированные операции
передаются через фиксированный Unix RPC с проверкой SO_PEERCRED и типизированным
allowlist; произвольный shell из UI запрещён.

Динамическая Telegram-привязка пользователя консоли не расширяет root-bootstrap
allowlist. NoData/свежесть — отдельные состояния. Локальная консоль не доказывает
обнаружение потери самого хоста: независимый observer и доставка остаются отдельной
проверкой. Наличие auth SQLite оправдано отдельным ops trust domain.

Основание: server-console/ADMIN_ALERTS_20261018.md, server-console/HOST_LOSS_20261018.md,
context/runtime/console_accounts_20261018.json. Принятие accounts/link владельцем
не означает приёмку всех аварийных сценариев.
