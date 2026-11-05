# Профиль архитектурного агента USTAR

Порядок входа — [START_HERE](../../START_HERE.md); правила в [AGENTS](../../AGENTS.md), [протоколе](../roadmap/AGENT_PROTOCOL.md), актуальное поручение в [STATE](../roadmap/STATE.yaml). `main` — канонический исходный код и текущий контекст.

Main: app2900/source5cc02944; production:app719bf47c/local0200/theme0200,worker267. PR110/111
объединены в main после общей FullRC. [Поручение/интеграция](../runtime/connect_feed_integration_order_20261101.json),
[owner acceptance](../runtime/connect_visual_owner_deploy_20261101.json). Задачи/возврат/фото
и читаемость приняты; остальные процессы с руководством с2Nov. Публичный HTTPS и
Mini App ждут согласования. PERF-01: пассивное наблюдение рабочего дня не получено.
Старые17Oct snapshots и platform receipts — история, не повод повторять принятую работу.
Moodle5.2/PG17/Docker rotation отложены; agent SSH/subagents/provider sends запрещены.

Перед изменением смотреть реальные entrypoints/services/readers/writers/XMLDB/ACL/tasks/тесты. Код описывает реализацию, ADR — решения, owner receipts — датированные наблюдения, CI — результат определённого runtime/synthetic scope. Не объявлять отсутствие evidence дефектом или synthetic PASS production-приёмкой.

Native UI — local_ustar/theme_ustar; frontend/legacy не входит в текущий CI/release. Не создавать вторые stores сотрудников/completion/rewards/messages. Сохранять историю и реальные роли, current scope, view-as read-only и install/upgrade parity.

03Nov: owner installed marketplace app4715383b/tool59f, success/exit0/postcheck active,
«Отлично работает». [Receipt](../runtime/library_marketplace_owner_deploy_20261103.json).
Production0200,649 fixed files/108tables; main2900 remains separate, PR112/113/114 open.
Marketplace default hidden; admin enables exact departments; source files editor-only.
Previous grants/mandatory route retained. No repeat installation/recovery.
Audit03–04Nov completed: context/reviews/20261103/AUDIT_RU.md and20261104/DEEP_AUDIT_RU.md.
Owner5Nov installed discovery app719bf47c/toolaa61d595: exit0/postcheck active.
Receipt: context/runtime/library_discovery_owner_deploy_20261105.json.
Current ROUTE-EDITOR-01: reviewed multi-requirement loss and UX; spec/plan ready,
implementation pending. Read context/reviews/20261105/ROUTE_EDITOR_REVIEW_RU.md.
Exact owner501 not identified without trace; do not claim universal fix from synthetic probe.
