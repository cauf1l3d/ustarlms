# Профиль архитектурного агента USTAR

Порядок входа — [START_HERE](../../START_HERE.md); правила в [AGENTS](../../AGENTS.md), [протоколе](../roadmap/AGENT_PROTOCOL.md), актуальное поручение1Nov в [STATE](../roadmap/STATE.yaml). `main` — канонический исходный код и текущий контекст.

Сейчас: app2900/source5cc02944 и worker267e5587 уже установлены; source PR110/111
объединены в main после общей FullRC. [Поручение/интеграция](../runtime/connect_feed_integration_order_20261101.json),
[owner acceptance](../runtime/connect_visual_owner_deploy_20261101.json). Задачи/возврат/фото
и читаемость приняты; остальные процессы с руководством с2Nov. Публичный HTTPS и
Mini App ждут согласования. Следующий этап — PERF-01: пассивное наблюдение рабочего дня.
Старые17Oct snapshots и platform receipts — история, не повод повторять принятую работу.
Moodle5.2/PG17/Docker rotation отложены; agent SSH/subagents/provider sends запрещены.

Перед изменением смотреть реальные entrypoints/services/readers/writers/XMLDB/ACL/tasks/тесты. Код описывает реализацию, ADR — решения, owner receipts — датированные наблюдения, CI — результат определённого runtime/synthetic scope. Не объявлять отсутствие evidence дефектом или synthetic PASS production-приёмкой.

Native UI — local_ustar/theme_ustar; frontend/legacy не входит в текущий CI/release. Не создавать вторые stores сотрудников/completion/rewards/messages. Сохранять историю и реальные роли, current scope, view-as read-only и install/upgrade parity.

03Nov: owner reports Library6ee active/local0200 and opening.
[Текущее поручение](../runtime/library_owner_navigation_20261103.json): вернуть
отдельный вход «Центр управления» → materials, сохранить Библиотеку;
[инструкция/приёмка](../../docs/LIBRARY_GUIDE_ACCEPTANCE_RU.md).
Не повторять закрытую missing-parent установку. Main2900, prod report0200,
nav candidatef89dde49 не установлен; подробная ролевая приёмка ожидается.
