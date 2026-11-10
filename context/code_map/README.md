# Карта кода

Актуально06Nov: source `5cc02944e1059ba015afcc7c5e8068ca56c34a92`, main local2900/theme2700.
Приложение: 606файлов, 103 XMLDB tables.
Worker267e5587 имеет отдельную поставку. Это Git inventory, не свежий whole-host manifest.
[Интеграция](../runtime/connect_feed_integration_order_20261101.json),
[owner2900](../runtime/connect_role_bot_owner_deploy_20261029.json),
[owner267](../runtime/connect_visual_owner_deploy_20261101.json).

Generated: [manifest](generated/source_manifest.json), [классы](generated/classes.md),
[таблицы](generated/database_tables.md), [API](generated/web_services.md).
Обновление: bash scripts/build_code_map.sh EXACT_SOURCE_COMMIT.
[Токены](../design/tokens.yaml), [capability references](../architecture/access_matrix.yaml),
[домены](../domains). Unmerged PR114/app53c9f16e — development_source.json:660файлов/108таблиц, квалификация форм/поиска управления Библиотекой, не deployment receipt.
Owner installed5Nov app719bf47c/toolaa61d595:649файлов/108таблиц;
receipt library_discovery_owner_deploy_20261105.json.
Предыдущая установка marketplace4715383b/tool59f принята3Nov:
[receipt](../runtime/library_marketplace_owner_deploy_20261103.json).
Generated/source_manifest.json и STATE.code_baseline описывают main2900;
STATE.production — установленный0200. Флаг deployed в отдельном unmerged manifest
берётся из STATE и ссылается на owner receipt; это не новый независимый серверный probe.
PR112/113/114 ещё не объединены.
connect_role_bot_development_source.json сохраняет исторический snapshot.

[Connect](../domains/connect.yaml) использует текущие Academy writers/права/историю.
frontend — legacy; server-console имеет отдельную ops identity и собственный CI.

Аудит установленного app5e61/worker267: [отчёт](../reviews/20261103/AUDIT_RU.md), [метрики](../reviews/20261103/code_metrics.json).
