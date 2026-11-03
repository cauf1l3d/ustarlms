# Карта кода

Актуально02Nov: source `5cc02944e1059ba015afcc7c5e8068ca56c34a92`, main local2900/theme2700.
Приложение: 606файлов, 103 XMLDB tables.
Worker267e5587 имеет отдельную поставку. Это Git inventory, не свежий whole-host manifest.
[Интеграция](../runtime/connect_feed_integration_order_20261101.json),
[owner2900](../runtime/connect_role_bot_owner_deploy_20261029.json),
[owner267](../runtime/connect_visual_owner_deploy_20261101.json).

Generated: [manifest](generated/source_manifest.json), [классы](generated/classes.md),
[таблицы](generated/database_tables.md), [API](generated/web_services.md).
Обновление: bash scripts/build_code_map.sh EXACT_SOURCE_COMMIT.
[Токены](../design/tokens.yaml), [capability references](../architecture/access_matrix.yaml),
[домены](../domains). PR114/app4715383b — development_source.json:649файлов/108таблиц.
Owner3Nov установил navigationf89dde49/tool5131 поверх Библиотеки0200:
[receipt](../runtime/library_navigation_owner_deploy_20261103.json).
Generated/source_manifest.json и STATE.code_baseline описывают main2900;
STATE.production — установленный0200. Маркетплейс по отделам — отдельный
[candidate](../runtime/marketplace_department_fix_20261103.json), ещё не установлен.
PR113/114 ещё не объединены.
connect_role_bot_development_source.json сохраняет исторический snapshot.

[Connect](../domains/connect.yaml) использует текущие Academy writers/права/историю.
frontend — legacy; server-console имеет отдельную ops identity и собственный CI.
