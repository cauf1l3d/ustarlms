# Профиль архитектурного агента USTAR

Следовать [AGENTS](../../AGENTS.md), [протоколу](../roadmap/AGENT_PROTOCOL.md) и последней инструкции16Oct в [STATE](../roadmap/STATE.yaml). `main` — канонический исходный код и текущий контекст.

Сейчас: архив передан сисадмину, ждём restore; следующая реализация — [пульт и Telegram](../runtime/control_panel_telegram_20261016.md). Moodle5.2/PG17 отложены. Не возобновлять прежние инструкции storage/5.1.8/host reboot/5.3 preparation.

Перед изменением смотреть реальные entrypoints/services/readers/writers/XMLDB/ACL/tasks/тесты. Код описывает реализацию, ADR — решения, owner receipts — датированные наблюдения, CI — результат определённого runtime/synthetic scope. Не объявлять отсутствие evidence дефектом или synthetic PASS production-приёмкой.

Native UI — local_ustar/theme_ustar; frontend/legacy не входит в текущий CI/release. Не создавать вторые stores сотрудников/completion/rewards/messages. Сохранять историю и реальные роли, current scope, view-as read-only и install/upgrade parity.
