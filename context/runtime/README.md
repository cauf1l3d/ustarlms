# Runtime evidence

13.11.2026. Владелец подтвердил работу живых рассылок и поручил перейти к виджетам. [Приёмка](connect_delivery_repair_owner_acceptance_20261113.json).

13.11.2026. Владелец установил `d1e54624`. Рассылка №508 осталась без подтверждения Telegram. Пакет `7fa3233f` прошёл проверки Moodle 5.1.1 и 5.1.8. [Инцидент](connect_broadcast_owner_incident_20261113.json), [проверки](connect_delivery_repair_qualification_20261113.json).

12.11.2026. Владелец установил Академию и Connect `814a99d9` и получил короткое уведомление в Telegram. [Квитанция владельца](connect_team_owner_deploy_20261112.json), [проверки поставки прямой рассылки `d1e54624`](connect_broadcast_qualification_20261112.json).

11.11.2026. Повторная привязка Telegram, app53fab3b6/tool dc3a3c54. 4 CI и 11 задач FullRC прошли. Архив ожидает установки владельцем. [Проверки](connect_relink_qualification_20261111.json), [команды](../../docs/CONNECT_RELINK_INSTALL_RU.md).

06Nov: app2cb233d9/tool ed292313 квалифицирован на exacted292313:4CI/11FullRC PASS.
Исправлены images/fonts в opaque Preview, source2files/0added,653files/108tables,0200.
[Квалификация](studio_preview_qualification_20261106.json), [установка](../../docs/STUDIO_PREVIEW_INSTALL_RU.md),
[реализация](../reviews/20261106/STUDIO_PREVIEW_IMPLEMENTATION.md).
Owner подтвердил скрытие точек, затем сообщил Preview defect; exact installed901 SHA не сообщён.
Новый пакет требует source901 + active CSP journal до outage. Owner installation/acceptance pending;
последняя полная deployment receipt ниже сохраняется.

Последняя полная terminal receipt: discovery app719bf47c/toolaa61d595,owner5Nov — success/exit0,
ready→active,readonly postcheck active. [Квитанция](library_discovery_owner_deploy_20261105.json),
[evidence](evidence_library_discovery0200_20261105.yaml). Local/theme0200,649fixed files/108tables.
Подробные employee browser cases не перечислены; повтор/recovery не требуются.
Предыдущий SCORM app5e61/tool4aee принят3Nov: [receipt](studio_scorm_edit_owner_deploy_20261103.json).
Квалифицированный cumulative пакет — app901ac9eb/toolf4d81940: [реализация](../reviews/20261105/STUDIO_CSP_IMPLEMENTATION.md),
[квалификация](studio_csp_qualification_20261105.json), [установка](../../docs/STUDIO_CSP_INSTALL_RU.md).
Route45e не установлен по owner statement; новый пакет прямо от719 включает Route Studio и strict-CSP YUI bootstrap.
Прежняя [квалификация45e/1c](route_editor_qualification_20261105.json) сохранена как история.
Последний полный terminal receipt719bf47c; owner6Nov частично принял скрытие точек, точный installed SHA не сообщён. Квалификация не означает установку.

Предыдущие установки Библиотеки: [marketplace471/tool59f](library_marketplace_owner_deploy_20261103.json),
[навигация5131](library_navigation_owner_deploy_20261103.json),
[первичная Библиотека](library_owner_navigation_20261103.json).
«Отлично работает» относится к marketplace general acceptance;allowlist/file/purchase
cases не перечислены. [Квалификация6ee](library_qualification_20261102.json) — история.

Main2900 остаётся отдельным исходным кодом,PR112/113/114 открыты;merge не deploy.
[Приложение2900](evidence_connect2900_20261029.yaml).
Worker267,восстановление Telegram и принятые задачи/фото/читаемость:
[owner1Nov](connect_visual_owner_deploy_20261101.json).
[Интеграция main и согласования](connect_feed_integration_order_20261101.json).
Это датированные owner receipts;новый host/cron/performance probe не выполнялся.

[Аудит03–04Nov](../reviews/20261103/AUDIT_RU.md),
[план](../../docs/superpowers/plans/2026-11-03-project-audit-remediation.md).
Первый пакет U01/U02/U05 установлен; остальные исправления остаются в плане.
[Операции](OPERATIONS.md),[backup/restore](BACKUP_RESTORE.md),[реестр](RELEASE_LEDGER.md),
[принятые риски](../roadmap/ACCEPTED_RISKS.yaml).
Docker successor [заморожен](docker_runtime_successor_20261026.md);старые команды
не возобновлять;rotation/health/recreate — согласованное плановое окно.
