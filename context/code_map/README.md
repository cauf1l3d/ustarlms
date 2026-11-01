# Карта кода

Актуально01Nov: source `5cc02944e1059ba015afcc7c5e8068ca56c34a92`, main local2900/theme2700.
Приложение: 606файлов, 103 XMLDB tables.
Worker267e5587 имеет отдельную поставку. Это Git inventory, не свежий whole-host manifest.
[Интеграция](../runtime/connect_feed_integration_order_20261101.json),
[owner2900](../runtime/connect_role_bot_owner_deploy_20261029.json),
[owner267](../runtime/connect_visual_owner_deploy_20261101.json).

Generated: [manifest](generated/source_manifest.json), [классы](generated/classes.md),
[таблицы](generated/database_tables.md), [API](generated/web_services.md).
Обновление: bash scripts/build_code_map.sh EXACT_SOURCE_COMMIT.
[Токены](../design/tokens.yaml), [capability references](../architecture/access_matrix.yaml),
[домены](../domains). Текущий кандидат PR112 — development_source.json:608файлов/103таблицы,
SCORM overlay3f8521ec; не установлен. connect_role_bot_development_source.json —
исторический snapshot. Канонический main/production источник — generated/source_manifest.json
и STATE.code_baseline; квалификация кандидата — в STATE.development_candidate.

[Connect](../domains/connect.yaml) использует текущие Academy writers/права/историю.
frontend — legacy; server-console имеет отдельную ops identity и собственный CI.
