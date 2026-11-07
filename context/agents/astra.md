# Профиль архитектурного агента USTAR

Порядок входа — [START_HERE](../../START_HERE.md); правила в [AGENTS](../../AGENTS.md), [протоколе](../roadmap/AGENT_PROTOCOL.md), актуальное поручение в [STATE](../roadmap/STATE.yaml). `main` — канонический исходный код и текущий контекст.

Main: app2900/source5cc02944; last full production receipt:app719bf47c/local0200/theme0200,worker267. PR110/111
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
Previous qualified cumulative package app901ac9eb/toolf4d81940 from installed719bf47c.
Includes uninstalled45e Route Studio changes and supported request-local YUI global bootstrap under unchanged CSP.
Read context/reviews/20261105/STUDIO_CSP_IMPLEMENTATION.md, context/runtime/studio_csp_qualification_20261105.json and docs/STUDIO_CSP_INSTALL_RU.md.
Owner confirms45e NOT installed. Source qualification is distinct from owner deployment; last full terminal receipt is719.
Native RED reproduced both exact CSP/YUI errors; patched5.1.8 browser passes actual save/fresh mobile reload and strict CSP/sandbox probes. Full gate2984b066:4workflows/11jobs PASS; native430/1988 each, Connect172/875, operator14 actual PHP/UID33. Owner install pending.
Earlier server-side invalidparameter must be retried by owner; this JS trace does not identify its original cause. No independent reviewer/subagents/SSH/provider calls/merge.

06Nov current STUDIO-PREVIEW-01: owner says points hide; Preview images fail with parent blob URL errors. Exact901 deployment not proven by terminal receipt. App2cb233d9/tool ed292313 creates document-owned blobs inside opaque srcdoc and hashes app bytes in cache identity. Same CSP/sandbox;2files/0added,653/108. Browser regressions pass locally and real Moodle5.1.1/5.1.8, PNG/font/API/fresh reload/eval/parent denial.16operator actualPHP/UID33 tests PASS, archive cf8c9b0b equals CI and retainedcopy. Full qualification ed292313:4CI/11FullRC PASS; native430/1988 each, Connect172/875, source PHP8.2/8.3 each1649tests/274conditional skips. Await owner deployment/functional acceptance. Read context/reviews/20261106/STUDIO_PREVIEW_IMPLEMENTATION.md, context/runtime/studio_preview_qualification_20261106.json and docs/STUDIO_PREVIEW_INSTALL_RU.md. Guards require exact901 + private active CSP journal before outage. Do not force over another host profile or mark production installed before owner receipt.

Owner06Nov accepts Preview generally and requests next audit package. Read context/runtime/studio_preview_owner_acceptance_20261106.json: no exact terminal receipt. Current LIBRARY-MANAGEMENT-01 appd63a63d3 U03/U04/U09/U10 fixes forms/search/history; localGREEN, native qualification pending. Spec docs/superpowers/specs/2026-11-06-library-management-design.md; plan docs/superpowers/plans/2026-11-06-library-management.md; implementation context/reviews/20261106/LIBRARY_MANAGEMENT_IMPLEMENTATION.md. Exact2cb + active Preview journal required before owner outage. No writer/schema/worker changes;660fixed files/108tables/0200. Do not close T03 query growth/full gate search or claim host deployment from source qualification.
